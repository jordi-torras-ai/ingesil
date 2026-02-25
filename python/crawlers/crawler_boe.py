#!/usr/bin/env python3
from __future__ import annotations

import argparse
import logging
import os
import random
import re
import sys
import time
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from enum import Enum
from pathlib import Path
from urllib.parse import urljoin

from selenium import webdriver
from selenium.webdriver.chrome.options import Options as ChromeOptions
from selenium.webdriver.common.by import By
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.webdriver.support.ui import WebDriverWait

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover
    def load_dotenv(*args, **kwargs):  # type: ignore[no-redef]
        return None


PROJECT_ROOT = Path(__file__).resolve().parents[2]
PYTHON_SRC = PROJECT_ROOT / "python" / "src"
if str(PYTHON_SRC) not in sys.path:
    sys.path.insert(0, str(PYTHON_SRC))

from ingesil_crawlers.artifacts import ArtifactWriter  # noqa: E402
from ingesil_crawlers.fsm import FSMConfig, FSMRunner  # noqa: E402
from ingesil_crawlers.logging_utils import build_logger  # noqa: E402


class BoeState(Enum):
    HOME = "HOME"
    PREPARE_SEARCH = "PREPARE_SEARCH"
    PARSE_RESULTS_PAGE = "PARSE_RESULTS_PAGE"
    PROCESS_RESULT_ITEM = "PROCESS_RESULT_ITEM"
    OPEN_NOTICE = "OPEN_NOTICE"
    OPEN_NEXT_PAGE = "OPEN_NEXT_PAGE"
    DONE = "DONE"


@dataclass
class CrawlerContext:
    logger: logging.Logger
    driver: WebDriver
    artifacts: ArtifactWriter
    slug: str
    source_id: int
    base_url: str
    timeout_seconds: int
    from_date: date
    to_date: date
    db_conn: object
    pending_results: list[dict[str, object]]
    current_result: dict[str, object] | None = None
    next_page_url: str | None = None


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for BOE source")
    parser.add_argument("--slug", default="boe")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_BOE_BASE_URL"))
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument("--day", default=None, help="Crawl only one day (YYYY-MM-DD).")
    parser.add_argument("--from-date", default=None, help="Crawl window start date (YYYY-MM-DD).")
    parser.add_argument("--to-date", default=None, help="Crawl window end date (YYYY-MM-DD).")
    parser.add_argument("--headless", action="store_true", help="Run Chrome headless")
    parser.add_argument("--headed", action="store_true", help="Force headed mode")
    return parser.parse_args()


def resolve_headless(args: argparse.Namespace) -> bool:
    env_default = os.getenv("CRAWLER_HEADLESS", "0").strip().lower() in {"1", "true", "yes", "on"}
    if args.headed:
        return False
    if args.headless:
        return True
    return env_default


def build_driver(headless: bool) -> WebDriver:
    options = ChromeOptions()
    options.add_argument("--window-size=1600,1200")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    if headless:
        options.add_argument("--headless=new")
    return webdriver.Chrome(options=options)


def wait_dom_ready(driver: WebDriver, timeout_seconds: int) -> None:
    WebDriverWait(driver, timeout_seconds).until(
        lambda d: d.execute_script("return document.readyState") == "complete"
    )


def _set_date_input(driver: WebDriver, element, value: str) -> None:
    # Native send_keys can be flaky on date inputs in headless mode.
    driver.execute_script(
        """
        const el = arguments[0];
        const val = arguments[1];
        el.focus();
        el.value = val;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        """,
        element,
        value,
    )


def parse_eu_date(value: str) -> date:
    return datetime.strptime(value.strip(), "%d/%m/%Y").date()


def parse_iso_date(value: str, *, flag: str) -> date:
    try:
        return datetime.strptime(value.strip(), "%Y-%m-%d").date()
    except ValueError as exc:
        raise RuntimeError(f"Invalid {flag} value {value!r}. Expected YYYY-MM-DD.") from exc


def read_source_data_from_db(slug: str) -> tuple[int, date, str]:
    try:
        import psycopg
    except ImportError as exc:  # pragma: no cover
        raise RuntimeError("psycopg is required to read source data from DB") from exc

    with psycopg.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "5432")),
        dbname=os.getenv("DB_DATABASE", ""),
        user=os.getenv("DB_USERNAME", ""),
        password=os.getenv("DB_PASSWORD", ""),
    ) as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT id, start_at, base_url FROM sources WHERE slug = %s LIMIT 1", (slug,))
            row = cur.fetchone()

    if row is None:
        raise RuntimeError(f"No source found for slug={slug!r}")
    if row[1] is None:
        raise RuntimeError(f"Source slug={slug!r} has NULL start_at")

    source_id = int(row[0])
    start_at = row[1] if isinstance(row[1], date) else datetime.strptime(str(row[1]), "%Y-%m-%d").date()
    base_url = str(row[2] or "").strip()
    return source_id, start_at, base_url


def resolve_crawl_range(
    logger: logging.Logger,
    db_conn: object,
    source_id: int,
    source_start_at: date,
    *,
    day: str | None,
    from_date_raw: str | None,
    to_date_raw: str | None,
) -> tuple[date, date]:
    if day:
        if from_date_raw or to_date_raw:
            raise RuntimeError("Use either --day or --from-date/--to-date, not both.")
        single_day = parse_iso_date(day, flag="--day")
        logger.info("Using explicit single-day crawl window from --day: [%s -> %s]", single_day, single_day)
        return single_day, single_day

    if from_date_raw or to_date_raw:
        if not from_date_raw or not to_date_raw:
            raise RuntimeError("Both --from-date and --to-date are required when using a date range override.")
        from_date = parse_iso_date(from_date_raw, flag="--from-date")
        to_date = parse_iso_date(to_date_raw, flag="--to-date")
        if from_date > to_date:
            raise RuntimeError(
                f"Invalid date range: --from-date ({from_date.isoformat()}) is after --to-date ({to_date.isoformat()})."
            )
        logger.info("Using explicit crawl window from CLI: [%s -> %s]", from_date.isoformat(), to_date.isoformat())
        return from_date, to_date

    cur = db_conn.cursor()
    try:
        cur.execute("SELECT MAX(issue_date) FROM daily_journals WHERE source_id = %s", (source_id,))
        row = cur.fetchone()
    finally:
        cur.close()

    latest_issue_date: date | None = row[0] if row and row[0] else None
    if latest_issue_date is None:
        from_date = source_start_at
        logger.info("No previous BOE daily journals found. Starting from source.start_at=%s", from_date.isoformat())
    else:
        from_date = latest_issue_date + timedelta(days=1)
        logger.info(
            "Latest BOE daily journal=%s. Starting next day=%s",
            latest_issue_date.isoformat(),
            from_date.isoformat(),
        )

    to_date = date.today()
    logger.info("Crawl date window resolved to [%s -> %s]", from_date.isoformat(), to_date.isoformat())
    return from_date, to_date


def extract_next_page_url(driver: WebDriver) -> str | None:
    links = driver.find_elements(By.XPATH, "//li[a/span[contains(@class, 'pagSig')]]/a")
    if not links:
        return None
    href = (links[0].get_attribute("href") or "").strip()
    if not href:
        return None
    return urljoin(driver.current_url, href)


def extract_results_from_page(context: CrawlerContext) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    result_items = context.driver.find_elements(By.CSS_SELECTOR, "li.resultado-busqueda")
    context.logger.info("Results on current page: %d", len(result_items))

    for item in result_items:
        line_dem = item.find_elements(By.CSS_SELECTOR, "p.linea-dem")
        line_pub = item.find_elements(By.CSS_SELECTOR, "p.linea-pub")
        title_nodes = item.find_elements(
            By.XPATH,
            "./p[not(contains(@class,'linea-dem')) and not(contains(@class,'linea-pub'))][1]",
        )
        link_nodes = item.find_elements(By.CSS_SELECTOR, "a.resultado-busqueda-link-defecto")

        if not line_pub or not title_nodes or not link_nodes:
            continue

        department = (line_dem[0].text if line_dem else "").strip()
        pub_text = (line_pub[0].text or "").strip()
        title = (title_nodes[0].text or "").strip()
        href = (link_nodes[0].get_attribute("href") or "").strip()
        if not title or not href:
            continue

        match = re.search(r"(BOE\s+\d+\s+de\s+\d{2}/\d{2}/\d{4})\s*-\s*(.+)$", pub_text)
        if not match:
            context.logger.warning("Skipping result: could not parse linea-pub=%r", pub_text)
            continue

        daily_journal_description = match.group(1).strip()
        category = match.group(2).strip()
        date_match = re.search(r"(\d{2}/\d{2}/\d{4})", daily_journal_description)
        if not date_match:
            context.logger.warning("Skipping result: could not parse issue date from=%r", daily_journal_description)
            continue

        issue_date = parse_eu_date(date_match.group(1))
        if issue_date < context.from_date or issue_date > context.to_date:
            continue

        detail_url = urljoin(context.driver.current_url, href)
        rows.append(
            {
                "department": department,
                "daily_journal_description": daily_journal_description,
                "issue_date": issue_date,
                "category": category,
                "title": title,
                "detail_url": detail_url,
            }
        )

    context.logger.info("Parsed %d valid results in date window on this page.", len(rows))
    return rows


def upsert_daily_journal(
    context: CrawlerContext,
    *,
    issue_date: date,
    description: str,
) -> int:
    daily_journal_url = f"https://www.boe.es/boe/dias/{issue_date:%Y/%m/%d}/"
    cur = context.db_conn.cursor()
    try:
        cur.execute(
            """
            INSERT INTO daily_journals (source_id, issue_date, url, description, created_at, updated_at)
            VALUES (%s, %s, %s, %s, NOW(), NOW())
            ON CONFLICT (source_id, issue_date)
            DO UPDATE SET
                url = EXCLUDED.url,
                description = EXCLUDED.description,
                updated_at = NOW()
            RETURNING id
            """,
            (context.source_id, issue_date, daily_journal_url, description),
        )
        row = cur.fetchone()
    finally:
        cur.close()

    context.db_conn.commit()
    if row is None:
        raise RuntimeError("Failed to upsert daily_journal and return id")
    return int(row[0])


def extract_notice_detail_from_doc_page(context: CrawlerContext) -> dict[str, str]:
    title = ""
    title_elements = context.driver.find_elements(By.CSS_SELECTOR, "h3.documento-tit")
    if title_elements:
        title = (title_elements[0].text or "").strip()
    if not title:
        title = (context.driver.title or "").strip()

    metadata: dict[str, str] = {}
    dt_elements = context.driver.find_elements(By.CSS_SELECTOR, "div.metadatos dl dt")
    for dt in dt_elements:
        label = (dt.text or "").strip().rstrip(":")
        if not label:
            continue
        dd_candidates = dt.find_elements(By.XPATH, "following-sibling::dd[1]")
        if not dd_candidates:
            continue
        metadata[label] = (dd_candidates[0].text or "").strip()

    category = metadata.get("Sección", "").strip()
    department = metadata.get("Departamento", "").strip()

    eli_url = ""
    eli_links = context.driver.find_elements(By.XPATH, "//dt[contains(normalize-space(.), 'Permalink ELI')]/following-sibling::dd[1]//a")
    if eli_links:
        eli_url = (eli_links[0].get_attribute("href") or "").strip()

    content_parts: list[str] = []
    text_container = context.driver.find_elements(By.CSS_SELECTOR, "#textoxslt")
    if text_container:
        nodes = text_container[0].find_elements(By.XPATH, ".//*[self::p or self::h5][normalize-space()]")
        for node in nodes:
            text = (node.text or "").strip()
            if not text:
                continue
            if re.search(r"\.pdf$", text, flags=re.IGNORECASE):
                continue
            content_parts.append(text)

    content = "\n\n".join(content_parts).strip()
    return {
        "title": title,
        "category": category,
        "department": department,
        "url": eli_url or context.driver.current_url,
        "content": content,
        "extra_info": "",
    }


def upsert_notice(
    context: CrawlerContext,
    *,
    daily_journal_id: int,
    title: str,
    category: str,
    department: str,
    url: str,
    content: str,
    extra_info: str,
) -> None:
    cur = context.db_conn.cursor()
    try:
        existing_id: int | None = None
        if url:
            cur.execute(
                """
                SELECT id
                FROM notices
                WHERE daily_journal_id = %s AND url = %s
                ORDER BY id ASC
                LIMIT 1
                """,
                (daily_journal_id, url),
            )
            row = cur.fetchone()
            if row is not None:
                existing_id = int(row[0])

        if existing_id is None:
            cur.execute(
                """
                SELECT id
                FROM notices
                WHERE daily_journal_id = %s AND title = %s
                ORDER BY id ASC
                LIMIT 1
                """,
                (daily_journal_id, title),
            )
            row = cur.fetchone()
            if row is not None:
                existing_id = int(row[0])

        if existing_id is None:
            cur.execute(
                """
                INSERT INTO notices
                    (daily_journal_id, title, category, department, url, content, extra_info, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                """,
                (daily_journal_id, title, category, department, url, content, extra_info),
            )
        else:
            cur.execute(
                """
                UPDATE notices
                SET title = %s,
                    category = %s,
                    department = %s,
                    url = %s,
                    content = %s,
                    extra_info = %s,
                    updated_at = NOW()
                WHERE id = %s
                """,
                (title, category, department, url, content, extra_info, existing_id),
            )
    finally:
        cur.close()

    context.db_conn.commit()


def state_home(context: CrawlerContext) -> BoeState:
    context.logger.info("FSM state=%s opening BOE search page: %s", BoeState.HOME.value, context.base_url)
    context.driver.get(context.base_url)
    wait_dom_ready(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(
        lambda d: len(d.find_elements(By.ID, "desdeFP")) > 0 and len(d.find_elements(By.ID, "hastaFP")) > 0
    )
    created = context.artifacts.capture(context.driver, state=BoeState.HOME.value, note="search_page_opened")
    context.logger.info("Artifacts saved: %s", created)
    return BoeState.PREPARE_SEARCH


def state_prepare_search(context: CrawlerContext) -> BoeState:
    context.logger.info(
        "FSM state=%s setting BOE date range from=%s to=%s",
        BoeState.PREPARE_SEARCH.value,
        context.from_date.isoformat(),
        context.to_date.isoformat(),
    )

    from_input = context.driver.find_element(By.ID, "desdeFP")
    to_input = context.driver.find_element(By.ID, "hastaFP")
    from_value = context.from_date.isoformat()
    to_value = context.to_date.isoformat()
    _set_date_input(context.driver, from_input, from_value)
    _set_date_input(context.driver, to_input, to_value)
    context.logger.info(
        "Date inputs set: desdeFP=%s hastaFP=%s",
        from_input.get_attribute("value"),
        to_input.get_attribute("value"),
    )

    submit = context.driver.find_element(
        By.XPATH,
        "//div[contains(@class,'bloqueBotones')]//input[@type='submit' and @value='Buscar']",
    )
    try:
        submit.click()
    except Exception:
        context.logger.warning("Standard submit click failed, retrying via JS click.")
        context.driver.execute_script("arguments[0].click();", submit)

    before_url = context.driver.current_url
    wait_dom_ready(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(
        lambda d: (
            d.current_url != before_url
            or "accion=Buscar" in d.current_url
            or "id_busqueda=" in d.current_url
            or len(d.find_elements(By.CSS_SELECTOR, "li.resultado-busqueda")) > 0
            or len(d.find_elements(By.CSS_SELECTOR, ".paginacion, .paginacion-mini, nav.paginacion")) > 0
            or "No se han encontrado" in d.page_source
            or "No se ha encontrado" in d.page_source
        )
    )

    context.logger.info("Search response URL: %s", context.driver.current_url)

    created = context.artifacts.capture(context.driver, state=BoeState.PREPARE_SEARCH.value, note="search_submitted")
    context.logger.info("Search submitted. Artifacts saved: %s", created)
    return BoeState.PARSE_RESULTS_PAGE


def state_parse_results_page(context: CrawlerContext) -> BoeState:
    context.logger.info("FSM state=%s parsing result page URL=%s", BoeState.PARSE_RESULTS_PAGE.value, context.driver.current_url)
    context.pending_results = extract_results_from_page(context)
    context.next_page_url = extract_next_page_url(context.driver)
    context.logger.info(
        "Current page parse complete: pending_results=%d next_page=%s",
        len(context.pending_results),
        context.next_page_url or "<none>",
    )
    created = context.artifacts.capture(context.driver, state=BoeState.PARSE_RESULTS_PAGE.value, note="results_page_parsed")
    context.logger.info("Artifacts saved: %s", created)
    return BoeState.PROCESS_RESULT_ITEM


def state_process_result_item(context: CrawlerContext) -> BoeState:
    if context.pending_results:
        context.current_result = context.pending_results.pop(0)
        context.logger.info(
            "FSM state=%s picked result: issue_date=%s category=%s department=%s title=%s",
            BoeState.PROCESS_RESULT_ITEM.value,
            context.current_result["issue_date"],
            context.current_result["category"],
            context.current_result["department"],
            context.current_result["title"],
        )
        return BoeState.OPEN_NOTICE

    if context.next_page_url:
        context.logger.info("No more results on current page. Moving to next page.")
        return BoeState.OPEN_NEXT_PAGE

    context.logger.info("No pending results and no next page. FSM done.")
    return BoeState.DONE


def state_open_notice(context: CrawlerContext) -> BoeState:
    if context.current_result is None:
        return BoeState.PROCESS_RESULT_ITEM

    issue_date = context.current_result["issue_date"]
    if not isinstance(issue_date, date):
        raise RuntimeError(f"Invalid issue_date in current result: {issue_date!r}")

    daily_journal_id = upsert_daily_journal(
        context,
        issue_date=issue_date,
        description=str(context.current_result["daily_journal_description"]),
    )
    context.logger.info(
        "Upserted daily_journal id=%s issue_date=%s description=%s",
        daily_journal_id,
        issue_date.isoformat(),
        context.current_result["daily_journal_description"],
    )

    detail_url = str(context.current_result["detail_url"])
    context.logger.info("Opening BOE notice detail URL=%s", detail_url)
    context.driver.get(detail_url)
    wait_dom_ready(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(
        lambda d: len(d.find_elements(By.CSS_SELECTOR, "h3.documento-tit")) > 0
    )

    detail = extract_notice_detail_from_doc_page(context)
    title = detail["title"] or str(context.current_result["title"])
    category = detail["category"] or str(context.current_result["category"])
    department = detail["department"] or str(context.current_result["department"])
    url = detail["url"] or detail_url
    content = detail["content"]

    upsert_notice(
        context,
        daily_journal_id=daily_journal_id,
        title=title,
        category=category,
        department=department,
        url=url,
        content=content,
        extra_info="",
    )
    context.logger.info(
        "Upserted notice daily_journal_id=%s title=%s category=%s department=%s url=%s content_len=%d",
        daily_journal_id,
        title,
        category,
        department,
        url,
        len(content),
    )

    created = context.artifacts.capture(context.driver, state=BoeState.OPEN_NOTICE.value, note=f"notice_{daily_journal_id}")
    context.logger.info("Artifacts saved: %s", created)
    context.current_result = None
    return BoeState.PROCESS_RESULT_ITEM


def state_open_next_page(context: CrawlerContext) -> BoeState:
    if not context.next_page_url:
        return BoeState.DONE

    context.logger.info("FSM state=%s opening next results page: %s", BoeState.OPEN_NEXT_PAGE.value, context.next_page_url)
    context.driver.get(context.next_page_url)
    wait_dom_ready(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(
        lambda d: len(d.find_elements(By.CSS_SELECTOR, "li.resultado-busqueda")) > 0 or "No se han encontrado" in d.page_source
    )
    created = context.artifacts.capture(context.driver, state=BoeState.OPEN_NEXT_PAGE.value, note="next_page_opened")
    context.logger.info("Artifacts saved: %s", created)
    context.next_page_url = None
    return BoeState.PARSE_RESULTS_PAGE


def simulate_human_transition(context: CrawlerContext, from_state: BoeState, to_state: BoeState) -> None:
    if to_state == BoeState.DONE:
        context.logger.info("Transition %s -> %s (final), skipping human pacing.", from_state.value, to_state.value)
        return

    context.logger.info("Transition %s -> %s: applying human-like pacing.", from_state.value, to_state.value)
    first_pause = random.uniform(0.4, 1.2)
    time.sleep(first_pause)
    context.logger.info("Human pacing: pause %.2fs", first_pause)

    try:
        viewport_height = int(context.driver.execute_script("return window.innerHeight || 900;"))
    except Exception:
        viewport_height = 900

    down_px = random.randint(max(80, int(viewport_height * 0.10)), max(180, int(viewport_height * 0.28)))
    up_px = random.randint(max(30, int(viewport_height * 0.05)), max(120, int(viewport_height * 0.16)))
    context.driver.execute_script("window.scrollBy(0, arguments[0]);", down_px)
    context.logger.info("Human pacing: scrolled down %dpx", down_px)
    time.sleep(random.uniform(0.2, 0.7))
    context.driver.execute_script("window.scrollBy(0, arguments[0]);", -up_px)
    context.logger.info("Human pacing: scrolled up %dpx", up_px)

    second_pause = random.uniform(0.3, 0.9)
    time.sleep(second_pause)
    context.logger.info("Human pacing: pause %.2fs", second_pause)


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()
    headless = resolve_headless(args)

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.boe", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Mode: %s", "headless" if headless else "headed")
    logger.info("Run directory: %s", run_dir)

    db_conn = None
    driver: WebDriver | None = None
    try:
        try:
            import psycopg
        except ImportError as exc:  # pragma: no cover
            raise RuntimeError("psycopg is required to crawl BOE and write DB records.") from exc

        db_conn = psycopg.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_DATABASE", ""),
            user=os.getenv("DB_USERNAME", ""),
            password=os.getenv("DB_PASSWORD", ""),
        )

        source_id, source_start_at, source_base_url = read_source_data_from_db(args.slug)
        base_url = args.base_url or source_base_url or "https://www.boe.es/buscar/boe.php"
        from_date, to_date = resolve_crawl_range(
            logger,
            db_conn,
            source_id,
            source_start_at,
            day=args.day,
            from_date_raw=args.from_date,
            to_date_raw=args.to_date,
        )

        if from_date > to_date:
            logger.info("Nothing to crawl for BOE: from_date=%s is after today=%s", from_date.isoformat(), to_date.isoformat())
            return 0

        driver = build_driver(headless=headless)
        artifacts = ArtifactWriter(run_dir=run_dir)
        context = CrawlerContext(
            logger=logger,
            driver=driver,
            artifacts=artifacts,
            slug=args.slug,
            source_id=source_id,
            base_url=base_url,
            timeout_seconds=args.timeout,
            from_date=from_date,
            to_date=to_date,
            db_conn=db_conn,
            pending_results=[],
        )

        fsm = FSMRunner(
            initial_state=BoeState.HOME,
            terminal_state=BoeState.DONE,
            handlers={
                BoeState.HOME: state_home,
                BoeState.PREPARE_SEARCH: state_prepare_search,
                BoeState.PARSE_RESULTS_PAGE: state_parse_results_page,
                BoeState.PROCESS_RESULT_ITEM: state_process_result_item,
                BoeState.OPEN_NOTICE: state_open_notice,
                BoeState.OPEN_NEXT_PAGE: state_open_next_page,
            },
            on_transition=simulate_human_transition,
            config=FSMConfig(max_steps=20000),
        )
        final_state = fsm.run(context)
        logger.info("Crawler finished with final state=%s", final_state.value)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s: %r", type(exc).__name__, exc)
        if driver is not None:
            try:
                ArtifactWriter(run_dir=run_dir).capture(driver, state="ERROR", note="unhandled_exception")
            except Exception:
                logger.exception("Failed to write error artifacts")
        return 1
    finally:
        if driver is not None:
            driver.quit()
        if db_conn is not None:
            db_conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
