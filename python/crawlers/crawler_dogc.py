#!/usr/bin/env python3
from __future__ import annotations

import argparse
import logging
import os
import sys
from dataclasses import dataclass
from datetime import date, datetime
from enum import Enum
from pathlib import Path
import random
import re
import time
import unicodedata
from urllib.parse import parse_qs, urlparse

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options as ChromeOptions
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.webdriver.support import expected_conditions as EC
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


class DogcState(Enum):
    HOME = "HOME"
    COOKIE_CONSENT = "COOKIE_CONSENT"
    NAVIGATE_TO_START_MONTH = "NAVIGATE_TO_START_MONTH"
    PROCESS_MONTH = "PROCESS_MONTH"
    PICK_PENDING_DAILY_JOURNAL = "PICK_PENDING_DAILY_JOURNAL"
    OPEN_DAILY_JOURNAL = "OPEN_DAILY_JOURNAL"
    PICK_NOTICE_LINK = "PICK_NOTICE_LINK"
    OPEN_NOTICE = "OPEN_NOTICE"
    DONE = "DONE"


@dataclass
class CrawlerContext:
    logger: logging.Logger
    driver: WebDriver
    artifacts: ArtifactWriter
    slug: str
    source_id: int
    base_url: str
    daily_base_url: str
    timeout_seconds: int
    start_issue_date: date
    end_issue_date: date
    db_conn: object
    resume_state: DogcState | None = None
    attempted_daily_journal_ids: set[int] | None = None
    current_daily_journal: dict[str, object] | None = None
    pending_notice_candidates: list[dict[str, str]] | None = None
    current_notice_candidate: dict[str, str] | None = None


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for DOGC source")
    parser.add_argument("--slug", default="dogc")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_DOGC_BASE_URL", "https://dogc.gencat.cat/ca"))
    parser.add_argument(
        "--daily-base-url",
        default=os.getenv("CRAWLER_DOGC_DAILY_BASE_URL", "https://dogc.gencat.cat/ca/sumari-del-dogc/"),
    )
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument(
        "--start-at",
        default=None,
        help="Optional override for source start date (YYYY-MM-DD). If omitted, read from DB sources.start_at.",
    )
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


def normalize_text(value: str) -> str:
    text = unicodedata.normalize("NFD", value)
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return " ".join(text.strip().lower().split())


MONTH_NAME_TO_NUMBER = {
    "gener": 1,
    "january": 1,
    "enero": 1,
    "febrer": 2,
    "february": 2,
    "febrero": 2,
    "marc": 3,
    "march": 3,
    "marzo": 3,
    "abril": 4,
    "april": 4,
    "maig": 5,
    "mayo": 5,
    "may": 5,
    "juny": 6,
    "junio": 6,
    "june": 6,
    "juliol": 7,
    "julio": 7,
    "july": 7,
    "agost": 8,
    "agosto": 8,
    "august": 8,
    "setembre": 9,
    "septiembre": 9,
    "september": 9,
    "octubre": 10,
    "october": 10,
    "novembre": 11,
    "noviembre": 11,
    "november": 11,
    "desembre": 12,
    "diciembre": 12,
    "december": 12,
}


MONTH_YEAR_PATTERNS = [
    re.compile(r"\b([a-zA-ZÀ-ÿ]+)\s+(\d{4})\b"),
    re.compile(r"\b(\d{4})\s+([a-zA-ZÀ-ÿ]+)\b"),
]


def parse_month_year(text: str) -> tuple[int, int] | None:
    normalized = normalize_text(text)
    candidates: list[tuple[int, int]] = []

    for pattern in MONTH_YEAR_PATTERNS:
        for match in pattern.finditer(normalized):
            first, second = match.group(1), match.group(2)
            if first.isdigit():
                year = int(first)
                month_name = second
            else:
                month_name = first
                year = int(second)

            month = MONTH_NAME_TO_NUMBER.get(month_name)
            if month is None:
                continue

            candidates.append((year, month))

    tokens = re.findall(r"[a-zA-Z]+|\d{4}", normalized)
    month = None
    years: list[int] = []
    for token in tokens:
        if token.isdigit() and len(token) == 4:
            years.append(int(token))
            continue
        month = month or MONTH_NAME_TO_NUMBER.get(token)

    if month is not None and years:
        candidates.append((max(years), month))

    if candidates:
        return max(candidates, key=lambda pair: pair[0])

    return None


def month_index(year: int, month: int) -> int:
    return (year * 12) + month


def read_source_data_from_db(slug: str) -> tuple[int, date]:
    try:
        import psycopg
    except ImportError as exc:  # pragma: no cover
        raise RuntimeError("psycopg is required to read source start_at from DB") from exc

    db_connection = os.getenv("DB_CONNECTION", "pgsql")
    if db_connection != "pgsql":
        raise RuntimeError(f"Unsupported DB_CONNECTION={db_connection!r}. Expected 'pgsql'.")

    host = os.getenv("DB_HOST", "127.0.0.1")
    port = int(os.getenv("DB_PORT", "5432"))
    dbname = os.getenv("DB_DATABASE", "")
    user = os.getenv("DB_USERNAME", "")
    password = os.getenv("DB_PASSWORD", "")

    if not dbname or not user:
        raise RuntimeError("DB_DATABASE and DB_USERNAME must be set to read sources.start_at.")

    with psycopg.connect(host=host, port=port, dbname=dbname, user=user, password=password) as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT id, start_at FROM sources WHERE slug = %s LIMIT 1", (slug,))
            row = cur.fetchone()

    if row is None:
        raise RuntimeError(f"No source found for slug={slug!r}")
    if row[1] is None:
        raise RuntimeError(f"Source slug={slug!r} has NULL start_at")

    source_id = int(row[0])
    value = row[1]
    if isinstance(value, date):
        return source_id, value

    return source_id, datetime.strptime(str(value), "%Y-%m-%d").date()


def resolve_source_context(args: argparse.Namespace, logger: logging.Logger) -> tuple[int, date]:
    source_id, db_start_date = read_source_data_from_db(args.slug)
    logger.info("Loaded source id=%s and start_at=%s from DB for slug=%s", source_id, db_start_date.isoformat(), args.slug)

    if args.start_at:
        override_start = datetime.strptime(args.start_at, "%Y-%m-%d").date()
        logger.info("Using --start-at override: %s", override_start.isoformat())
        return source_id, override_start

    return source_id, db_start_date


def find_calendar_month_text(driver: WebDriver, timeout_seconds: int) -> str:
    selectors = [
        (By.CSS_SELECTOR, ".ui-datepicker-title"),
        (By.CSS_SELECTOR, ".datepicker-switch"),
        (By.CSS_SELECTOR, ".flatpickr-current-month"),
        (By.XPATH, "//*[contains(@class, 'calendar') and normalize-space()]"),
        (By.XPATH, "//*[contains(@class, 'datepicker') and normalize-space()]"),
    ]

    deadline = datetime.now().timestamp() + timeout_seconds
    last_error: Exception | None = None
    while datetime.now().timestamp() < deadline:
        for by, selector in selectors:
            try:
                elements = driver.find_elements(by, selector)
                for element in elements:
                    text = (element.text or "").strip()
                    if not text:
                        continue
                    if parse_month_year(text):
                        return text
            except Exception as exc:  # pragma: no cover
                last_error = exc
        WebDriverWait(driver, 1).until(lambda _d: True)

    raise RuntimeError(f"Could not detect calendar month label. last_error={last_error!r}")


def get_calendar_year_month(driver: WebDriver, timeout_seconds: int) -> tuple[int, int, str]:
    deadline = datetime.now().timestamp() + timeout_seconds
    while datetime.now().timestamp() < deadline:
        try:
            month_el = driver.find_element(By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-month")
            month_name = normalize_text(month_el.text)
            month = MONTH_NAME_TO_NUMBER.get(month_name)

            if month is not None:
                year_text = ""
                selected_year = driver.find_elements(
                    By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-year option[selected='selected']"
                )
                if selected_year:
                    year_text = selected_year[0].text.strip()
                else:
                    year_fallback = driver.find_elements(
                        By.CSS_SELECTOR, "#calendari-dogc .customSelect.ui-datepicker-year .customSelectInner"
                    )
                    if year_fallback:
                        year_text = year_fallback[0].text.strip()

                if year_text.isdigit():
                    year = int(year_text)
                    return year, month, f"{month_el.text} {year_text}"
        except Exception:
            pass

        WebDriverWait(driver, 1).until(lambda _d: True)

    month_text = find_calendar_month_text(driver, timeout_seconds=1)
    parsed = parse_month_year(month_text)
    if parsed is None:
        raise RuntimeError("Could not resolve calendar year/month from DOM.")

    year, month = parsed
    return year, month, month_text


def click_previous_month(driver: WebDriver, timeout_seconds: int) -> None:
    selectors = [
        (By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-prev[data-handler='prev']"),
        (By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-prev"),
        (By.CSS_SELECTOR, "#calendari-dogc [data-handler='prev']"),
        (By.XPATH, "//*[self::button or self::a][contains(@aria-label, 'anterior')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@aria-label, 'previous')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@title, 'anterior')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@title, 'previous')]"),
        (By.XPATH, "//*[self::button or self::a][normalize-space(text()) = '<']"),
        (By.XPATH, "//*[contains(@class,'datepicker-prev') and (self::button or self::a)]"),
        (By.XPATH, "//*[contains(@class,'ui-datepicker-prev') and (self::button or self::a)]"),
    ]

    deadline = datetime.now().timestamp() + timeout_seconds
    last_error: Exception | None = None
    while datetime.now().timestamp() < deadline:
        for by, selector in selectors:
            try:
                elements = driver.find_elements(by, selector)
                if not elements:
                    continue

                element = elements[0]
                driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", element)

                try:
                    element.click()
                except Exception:
                    driver.execute_script("arguments[0].click();", element)
                return
            except Exception as exc:
                last_error = exc
                continue

        WebDriverWait(driver, 1).until(lambda _d: True)

    if last_error is not None:
        raise RuntimeError(
            f"Could not find clickable 'previous month' control in calendar. last_error={last_error!r}"
        )

    for by, selector in selectors:
        try:
            element = WebDriverWait(driver, timeout_seconds).until(
                EC.element_to_be_clickable((by, selector))
            )
            element.click()
            return
        except Exception:
            continue

    raise RuntimeError("Could not find clickable 'previous month' control in calendar.")


def click_next_month(driver: WebDriver, timeout_seconds: int) -> None:
    selectors = [
        (By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-next[data-handler='next']"),
        (By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-next"),
        (By.CSS_SELECTOR, "#calendari-dogc [data-handler='next']"),
        (By.XPATH, "//*[self::button or self::a][contains(@aria-label, 'seguent')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@aria-label, 'next')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@title, 'seguent')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@title, 'següent')]"),
        (By.XPATH, "//*[self::button or self::a][contains(@title, 'next')]"),
        (By.XPATH, "//*[self::button or self::a][normalize-space(text()) = '>']"),
        (By.XPATH, "//*[contains(@class,'ui-datepicker-next') and (self::button or self::a)]"),
    ]

    deadline = datetime.now().timestamp() + timeout_seconds
    last_error: Exception | None = None
    while datetime.now().timestamp() < deadline:
        for by, selector in selectors:
            try:
                elements = driver.find_elements(by, selector)
                if not elements:
                    continue

                element = elements[0]
                driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", element)
                try:
                    element.click()
                except Exception:
                    driver.execute_script("arguments[0].click();", element)
                return
            except Exception as exc:
                last_error = exc
                continue
        WebDriverWait(driver, 1).until(lambda _d: True)

    raise RuntimeError(f"Could not find clickable 'next month' control. last_error={last_error!r}")


def build_daily_issue_url(daily_base_url: str, title_query: str) -> str:
    query = title_query.strip()
    if query.startswith("?"):
        return f"{daily_base_url.rstrip('/')}/{query}"
    if query.startswith("http://") or query.startswith("https://"):
        return query
    if query:
        return f"{daily_base_url.rstrip('/')}/?{query.lstrip('?')}"
    return daily_base_url


def extract_clickable_issues_for_visible_month(
    *,
    context: CrawlerContext,
    current_year: int,
    current_month: int,
) -> list[tuple[date, str, str]]:
    rows: list[tuple[date, str, str]] = []
    cells = context.driver.find_elements(By.CSS_SELECTOR, "#calendari-dogc .ui-datepicker-calendar td.has-publicacio")
    context.logger.info("Found %d clickable cells in visible month %04d-%02d.", len(cells), current_year, current_month)

    for cell in cells:
        day_el = cell.find_elements(By.CSS_SELECTOR, "a.ui-state-default, span.ui-state-default")
        if not day_el:
            continue
        day_txt = (day_el[0].text or "").strip()
        if not day_txt.isdigit():
            continue

        issue_day = int(day_txt)
        try:
            issue_date = date(current_year, current_month, issue_day)
        except ValueError:
            continue

        if issue_date < context.start_issue_date or issue_date > context.end_issue_date:
            continue

        title_query = (cell.get_attribute("title") or "").strip()
        if not title_query:
            continue

        issue_url = build_daily_issue_url(context.daily_base_url, title_query)
        query = parse_qs(urlparse(issue_url).query)
        num_dogc = (query.get("numDOGC") or [""])[0]
        description = f"DOGC núm. {num_dogc}" if num_dogc else f"DOGC {issue_date.isoformat()}"
        rows.append((issue_date, issue_url, description))

    rows.sort(key=lambda item: item[0])
    return rows


def upsert_daily_journal(context: CrawlerContext, issue_date: date, issue_url: str, description: str) -> None:
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
            """,
            (context.source_id, issue_date, issue_url, description),
        )
    finally:
        cur.close()
    context.db_conn.commit()


def fetch_pending_daily_journal(context: CrawlerContext) -> dict[str, object] | None:
    attempted = context.attempted_daily_journal_ids or set()
    cur = context.db_conn.cursor()
    try:
        cur.execute(
            """
            SELECT dj.id, dj.issue_date, dj.url, dj.description
            FROM daily_journals dj
            LEFT JOIN notices n ON n.daily_journal_id = dj.id
            WHERE dj.source_id = %s
            GROUP BY dj.id
            HAVING COUNT(n.id) = 0
            ORDER BY dj.issue_date ASC, dj.id ASC
            """,
            (context.source_id,),
        )
        rows = cur.fetchall()
    finally:
        cur.close()

    context.logger.info("Pending daily journals with 0 notices in DB: %d", len(rows))
    for row in rows:
        daily_journal_id = int(row[0])
        if daily_journal_id in attempted:
            continue
        url = (row[2] or "").strip()
        if not url:
            context.logger.warning(
                "Skipping daily_journal id=%s because URL is empty.",
                daily_journal_id,
            )
            continue
        issue_date_value = row[1]
        issue_date_text = (
            issue_date_value.isoformat()
            if hasattr(issue_date_value, "isoformat")
            else str(issue_date_value)
        )
        return {
            "id": daily_journal_id,
            "issue_date": issue_date_text,
            "url": url,
            "description": (row[3] or "").strip(),
        }

    return None


def extract_notice_candidates_from_daily_journal(context: CrawlerContext) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    seen_urls: set[str] = set()
    # DOGC summary rows have one notice link and one PDF "Descarrega" link.
    # Restrict to the real notice link inside each `li.destacat_text`.
    row_elements = context.driver.find_elements(By.CSS_SELECTOR, "div.wrapper-disposicions li.destacat_text")
    context.logger.info("Summary page notice rows detected: %d", len(row_elements))
    for row_el in row_elements:
        anchors = row_el.find_elements(
            By.XPATH,
            ".//div[contains(@class,'destacat_text_cont')]"
            "//a[contains(@href, 'document-del-dogc') and not(ancestor::div[contains(@class,'download')])]",
        )
        if not anchors:
            continue

        link = anchors[0]
        href = (link.get_attribute("href") or "").strip()
        title = (link.text or "").strip()
        if not href or not title:
            continue
        if "document-del-dogc" not in href:
            continue
        if href in seen_urls:
            continue

        category = ""
        department = ""
        try:
            category_el = row_el.find_element(By.XPATH, "preceding::h2[1]")
            category = (category_el.text or "").strip()
        except Exception:
            pass
        try:
            department_el = row_el.find_element(By.XPATH, "preceding::h3[1]")
            department = (department_el.text or "").strip()
        except Exception:
            pass

        rows.append({"title": title, "url": href, "category": category, "department": department})
        seen_urls.add(href)

    context.logger.info(
        "Extracted %d notice candidates from daily journal page.",
        len(rows),
    )
    return rows


def extract_notice_detail(context: CrawlerContext) -> dict[str, str]:
    full_text = None
    full_text_els = context.driver.find_elements(By.CSS_SELECTOR, "#fullText")
    if full_text_els:
        full_text = full_text_els[0]

    title = ""
    if full_text is not None:
        title_els = full_text.find_elements(By.XPATH, "./h1[normalize-space()] | .//h1[normalize-space()]")
        if title_els:
            title = (title_els[0].text or "").strip()

    if not title:
        page_title = (context.driver.title or "").strip()
        if page_title and normalize_text(page_title) != "diari oficial de la generalitat de catalunya":
            title = page_title

    paragraphs: list[str] = []
    if full_text is not None:
        paragraph_els = full_text.find_elements(By.XPATH, ".//p[normalize-space()]")
        for paragraph in paragraph_els:
            text = (paragraph.text or "").strip()
            if not text:
                continue
            normalized = normalize_text(text)
            if normalized == "descarrega":
                continue
            if re.search(r"\.pdf$", text, flags=re.IGNORECASE):
                continue
            if re.search(r"_cat\.pdf$", text, flags=re.IGNORECASE):
                continue
            paragraphs.append(text)

    content = "\n\n".join(paragraphs).strip()
    if title and content.startswith(title):
        content = content[len(title):].lstrip()

    metadata: dict[str, str] = {}
    side_items = context.driver.find_elements(By.CSS_SELECTOR, "#disposicions_cos_bloc li")
    for item in side_items:
        raw = (item.text or "").strip()
        if not raw:
            continue
        parts = [part.strip() for part in raw.splitlines() if part.strip()]
        if len(parts) >= 2:
            metadata[parts[0]] = parts[1]

    category = metadata.get("Secció del DOGC", "").strip()
    department = metadata.get("Organisme emissor", "").strip()
    extra_info = "\n".join(f"{key}: {value}" for key, value in metadata.items()).strip()

    return {
        "title": title,
        "category": category,
        "department": department,
        "content": content,
        "extra_info": extra_info,
        "url": context.driver.current_url,
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


def state_home(context: CrawlerContext) -> DogcState:
    context.logger.info("FSM state=%s opening URL=%s", DogcState.HOME.value, context.base_url)
    context.driver.get(context.base_url)
    wait_dom_ready(context.driver, context.timeout_seconds)
    created = context.artifacts.capture(context.driver, state=DogcState.HOME.value, note="homepage_opened")
    context.logger.info("Artifacts saved: %s", created)

    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.NAVIGATE_TO_START_MONTH)
    if cookie_interrupt is not None:
        return cookie_interrupt

    return DogcState.NAVIGATE_TO_START_MONTH


def state_navigate_to_start_month(context: CrawlerContext) -> DogcState:
    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.NAVIGATE_TO_START_MONTH)
    if cookie_interrupt is not None:
        return cookie_interrupt

    target_year = context.start_issue_date.year
    target_month = context.start_issue_date.month
    target_idx = month_index(target_year, target_month)

    current_year, current_month, month_text = get_calendar_year_month(
        context.driver, context.timeout_seconds
    )
    current_idx = month_index(current_year, current_month)

    context.logger.info(
        "FSM state=%s calendar visible month=%s parsed=(%04d-%02d) target=(%04d-%02d)",
        DogcState.NAVIGATE_TO_START_MONTH.value,
        month_text,
        current_year,
        current_month,
        target_year,
        target_month,
    )

    if current_idx == target_idx:
        created = context.artifacts.capture(
            context.driver,
            state=DogcState.NAVIGATE_TO_START_MONTH.value,
            note=f"start_month_reached_{target_year:04d}_{target_month:02d}",
        )
        context.logger.info("Start month reached. Artifacts saved: %s", created)
        return DogcState.PROCESS_MONTH

    if current_idx < target_idx:
        raise RuntimeError(
            "Current calendar month is older than target start_at. "
            f"current={current_year:04d}-{current_month:02d}, "
            f"target={target_year:04d}-{target_month:02d}. "
            "Cannot reach target by clicking previous month."
        )

    context.logger.info("Clicking calendar previous month control ('<') once.")
    click_previous_month(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(lambda _d: True)

    created = context.artifacts.capture(
        context.driver,
        state=DogcState.NAVIGATE_TO_START_MONTH.value,
        note=f"step_after_prev_click_target_{target_year:04d}_{target_month:02d}",
    )
    context.logger.info("Artifacts saved after previous-month click: %s", created)
    return DogcState.NAVIGATE_TO_START_MONTH


def state_process_month(context: CrawlerContext) -> DogcState:
    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.PROCESS_MONTH)
    if cookie_interrupt is not None:
        return cookie_interrupt

    current_year, current_month, month_text = get_calendar_year_month(context.driver, context.timeout_seconds)
    current_idx = month_index(current_year, current_month)
    end_idx = month_index(context.end_issue_date.year, context.end_issue_date.month)

    context.logger.info(
        "FSM state=%s processing month=%s (%04d-%02d) range=[%s -> %s]",
        DogcState.PROCESS_MONTH.value,
        month_text,
        current_year,
        current_month,
        context.start_issue_date.isoformat(),
        context.end_issue_date.isoformat(),
    )

    issues = extract_clickable_issues_for_visible_month(
        context=context,
        current_year=current_year,
        current_month=current_month,
    )
    context.logger.info("Month %04d-%02d: %d clickable issues in target range.", current_year, current_month, len(issues))

    for issue_date, issue_url, description in issues:
        upsert_daily_journal(context, issue_date, issue_url, description)
        context.logger.info(
            "Upserted daily_journal source_id=%s issue_date=%s url=%s description=%s",
            context.source_id,
            issue_date.isoformat(),
            issue_url,
            description,
        )

    created = context.artifacts.capture(
        context.driver,
        state=DogcState.PROCESS_MONTH.value,
        note=f"month_processed_{current_year:04d}_{current_month:02d}",
    )
    context.logger.info("Month processed artifacts saved: %s", created)

    if current_idx == end_idx:
        context.logger.info(
            "Reached end month (%04d-%02d). Switching to notice extraction flow.",
            context.end_issue_date.year,
            context.end_issue_date.month,
        )
        return DogcState.PICK_PENDING_DAILY_JOURNAL

    if current_idx > end_idx:
        raise RuntimeError(
            f"Visible calendar month {current_year:04d}-{current_month:02d} is after end month "
            f"{context.end_issue_date.year:04d}-{context.end_issue_date.month:02d}."
        )

    context.logger.info("Clicking calendar next month control ('>') once.")
    click_next_month(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(lambda _d: True)
    created = context.artifacts.capture(
        context.driver,
        state=DogcState.PROCESS_MONTH.value,
        note=f"step_after_next_click_{current_year:04d}_{current_month:02d}",
    )
    context.logger.info("Artifacts saved after next-month click: %s", created)
    return DogcState.PROCESS_MONTH


def state_pick_pending_daily_journal(context: CrawlerContext) -> DogcState:
    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.PICK_PENDING_DAILY_JOURNAL)
    if cookie_interrupt is not None:
        return cookie_interrupt

    if context.attempted_daily_journal_ids is None:
        context.attempted_daily_journal_ids = set()

    next_daily_journal = fetch_pending_daily_journal(context)
    if next_daily_journal is None:
        context.logger.info("No more pending daily journals (0 notices) for this run. FSM done.")
        return DogcState.DONE

    daily_journal_id = int(next_daily_journal["id"])
    context.attempted_daily_journal_ids.add(daily_journal_id)
    context.current_daily_journal = next_daily_journal
    context.pending_notice_candidates = []
    context.current_notice_candidate = None

    context.logger.info(
        "Selected pending daily_journal id=%s issue_date=%s url=%s",
        daily_journal_id,
        next_daily_journal.get("issue_date"),
        next_daily_journal.get("url"),
    )
    return DogcState.OPEN_DAILY_JOURNAL


def state_open_daily_journal(context: CrawlerContext) -> DogcState:
    if context.current_daily_journal is None:
        context.logger.warning("No current daily journal set. Returning to picker state.")
        return DogcState.PICK_PENDING_DAILY_JOURNAL

    daily_journal_id = int(context.current_daily_journal["id"])
    daily_journal_url = str(context.current_daily_journal.get("url") or "")
    context.logger.info(
        "FSM state=%s opening daily_journal id=%s url=%s",
        DogcState.OPEN_DAILY_JOURNAL.value,
        daily_journal_id,
        daily_journal_url,
    )
    context.driver.get(daily_journal_url)
    wait_dom_ready(context.driver, context.timeout_seconds)

    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.OPEN_DAILY_JOURNAL)
    if cookie_interrupt is not None:
        return cookie_interrupt

    candidates = extract_notice_candidates_from_daily_journal(context)
    context.pending_notice_candidates = candidates
    created = context.artifacts.capture(
        context.driver,
        state=DogcState.OPEN_DAILY_JOURNAL.value,
        note=f"daily_journal_loaded_{daily_journal_id}",
    )
    context.logger.info("Daily journal artifacts saved: %s", created)

    if not candidates:
        context.logger.warning(
            "No notice candidates extracted from daily_journal id=%s (%s). Moving on.",
            daily_journal_id,
            daily_journal_url,
        )
        context.current_daily_journal = None
        return DogcState.PICK_PENDING_DAILY_JOURNAL

    return DogcState.PICK_NOTICE_LINK


def state_pick_notice_link(context: CrawlerContext) -> DogcState:
    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.PICK_NOTICE_LINK)
    if cookie_interrupt is not None:
        return cookie_interrupt

    if context.current_daily_journal is None:
        return DogcState.PICK_PENDING_DAILY_JOURNAL

    if not context.pending_notice_candidates:
        context.logger.info(
            "Finished all notice links for daily_journal id=%s",
            context.current_daily_journal.get("id"),
        )
        context.current_daily_journal = None
        context.current_notice_candidate = None
        return DogcState.PICK_PENDING_DAILY_JOURNAL

    context.current_notice_candidate = context.pending_notice_candidates.pop(0)
    context.logger.info(
        "Selected notice candidate title=%s url=%s",
        context.current_notice_candidate.get("title"),
        context.current_notice_candidate.get("url"),
    )
    return DogcState.OPEN_NOTICE


def state_open_notice(context: CrawlerContext) -> DogcState:
    if context.current_daily_journal is None or context.current_notice_candidate is None:
        context.logger.warning("Missing notice context. Returning to notice picker.")
        return DogcState.PICK_NOTICE_LINK

    daily_journal_id = int(context.current_daily_journal["id"])
    notice_url = str(context.current_notice_candidate.get("url") or "")
    context.logger.info(
        "FSM state=%s opening notice URL=%s for daily_journal id=%s",
        DogcState.OPEN_NOTICE.value,
        notice_url,
        daily_journal_id,
    )
    context.driver.get(notice_url)
    wait_dom_ready(context.driver, context.timeout_seconds)
    WebDriverWait(context.driver, context.timeout_seconds).until(
        lambda d: len(d.find_elements(By.CSS_SELECTOR, "#fullText h1")) > 0
    )

    cookie_interrupt = maybe_route_to_cookie_state(context, current_state=DogcState.OPEN_NOTICE)
    if cookie_interrupt is not None:
        return cookie_interrupt

    detail = extract_notice_detail(context)
    title = detail["title"] or str(context.current_notice_candidate.get("title") or "").strip()
    category = str(detail.get("category") or context.current_notice_candidate.get("category") or "").strip()
    department = str(detail.get("department") or context.current_notice_candidate.get("department") or "").strip()
    final_url = detail["url"] or notice_url
    content = detail["content"]
    extra_info = detail["extra_info"]

    if not title:
        context.logger.warning("Skipping notice without title for URL=%s", final_url)
    else:
        upsert_notice(
            context,
            daily_journal_id=daily_journal_id,
            title=title,
            category=category,
            department=department,
            url=final_url,
            content=content,
            extra_info=extra_info,
        )
        context.logger.info(
            "Upserted notice daily_journal_id=%s title=%s category=%s department=%s url=%s content_len=%s",
            daily_journal_id,
            title,
            category,
            department,
            final_url,
            len(content),
        )

    created = context.artifacts.capture(
        context.driver,
        state=DogcState.OPEN_NOTICE.value,
        note=f"notice_processed_{daily_journal_id}",
    )
    context.logger.info("Notice page artifacts saved: %s", created)
    context.current_notice_candidate = None
    return DogcState.PICK_NOTICE_LINK


def find_cookie_accept_button(context: CrawlerContext):
    selectors = [
        (By.CSS_SELECTOR, "button#ppms_cm_agree-to-all"),
        (By.CSS_SELECTOR, "#ppms_cm_agree-to-all"),
        (By.XPATH, "//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜ', 'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïñòóôõöùúûü'), 'accept')]"),
        (By.XPATH, "//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜ', 'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïñòóôõöùúûü'), 'acceptar')]"),
        (By.XPATH, "//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜ', 'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïñòóôõöùúûü'), 'aceptar')]"),
        (By.XPATH, "//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜ', 'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïñòóôõöùúûü'), 'totes')]"),
        (By.XPATH, "//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜ', 'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïñòóôõöùúûü'), 'todo')]"),
    ]

    for by, selector in selectors:
        try:
            elements = context.driver.find_elements(by, selector)
            for element in elements:
                if element.is_displayed():
                    return element
        except Exception:
            continue

    return None


def maybe_route_to_cookie_state(context: CrawlerContext, *, current_state: DogcState) -> DogcState | None:
    button = find_cookie_accept_button(context)
    if button is None:
        return None

    context.logger.info(
        "Cookie consent detected while in %s. Redirecting FSM to %s.",
        current_state.value,
        DogcState.COOKIE_CONSENT.value,
    )
    context.resume_state = current_state
    return DogcState.COOKIE_CONSENT


def state_cookie_consent(context: CrawlerContext) -> DogcState:
    context.logger.info("FSM state=%s processing cookie modal.", DogcState.COOKIE_CONSENT.value)
    button = find_cookie_accept_button(context)
    if button is None:
        raise RuntimeError("Entered COOKIE_CONSENT state but no visible accept button was found.")

    try:
        context.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", button)
    except Exception:
        pass

    try:
        button.click()
    except Exception:
        context.driver.execute_script("arguments[0].click();", button)

    WebDriverWait(context.driver, context.timeout_seconds).until(lambda _d: True)
    created = context.artifacts.capture(
        context.driver,
        state=DogcState.COOKIE_CONSENT.value,
        note="accepted_all",
    )
    context.logger.info("Cookie accepted. Artifacts saved: %s", created)

    if context.resume_state is None:
        context.logger.warning("No resume state set after COOKIE_CONSENT; defaulting to NAVIGATE_TO_START_MONTH.")
        return DogcState.NAVIGATE_TO_START_MONTH

    next_state = context.resume_state
    context.resume_state = None
    context.logger.info("Resuming FSM at state=%s after cookie consent.", next_state.value)
    return next_state


def simulate_human_transition(context: CrawlerContext, from_state: DogcState, to_state: DogcState) -> None:
    if to_state == DogcState.DONE:
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

    logger = build_logger("crawler.dogc", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Mode: %s", "headless" if headless else "headed")
    logger.info("Run directory: %s", run_dir)
    source_id, start_issue_date = resolve_source_context(args, logger)
    end_issue_date = date.today()
    if start_issue_date > end_issue_date:
        raise RuntimeError(
            f"start_at ({start_issue_date.isoformat()}) cannot be after today ({end_issue_date.isoformat()})."
        )

    db_conn = None
    driver: WebDriver | None = None
    try:
        try:
            import psycopg
        except ImportError as exc:  # pragma: no cover
            raise RuntimeError("psycopg is required to write daily_journals.") from exc

        db_conn = psycopg.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_DATABASE", ""),
            user=os.getenv("DB_USERNAME", ""),
            password=os.getenv("DB_PASSWORD", ""),
        )

        driver = build_driver(headless=headless)
        artifacts = ArtifactWriter(run_dir=run_dir)
        context = CrawlerContext(
            logger=logger,
            driver=driver,
            artifacts=artifacts,
            slug=args.slug,
            source_id=source_id,
            base_url=args.base_url,
            daily_base_url=args.daily_base_url,
            timeout_seconds=args.timeout,
            start_issue_date=start_issue_date,
            end_issue_date=end_issue_date,
            db_conn=db_conn,
        )

        fsm = FSMRunner(
            initial_state=DogcState.HOME,
            terminal_state=DogcState.DONE,
            handlers={
                DogcState.HOME: state_home,
                DogcState.COOKIE_CONSENT: state_cookie_consent,
                DogcState.NAVIGATE_TO_START_MONTH: state_navigate_to_start_month,
                DogcState.PROCESS_MONTH: state_process_month,
                DogcState.PICK_PENDING_DAILY_JOURNAL: state_pick_pending_daily_journal,
                DogcState.OPEN_DAILY_JOURNAL: state_open_daily_journal,
                DogcState.PICK_NOTICE_LINK: state_pick_notice_link,
                DogcState.OPEN_NOTICE: state_open_notice,
            },
            on_transition=simulate_human_transition,
            config=FSMConfig(max_steps=10000),
        )
        final_state = fsm.run(context)
        logger.info("Crawler finished with final state=%s", final_state.value)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s", exc)
        if driver is not None:
            try:
                ArtifactWriter(run_dir=run_dir).capture(
                    driver,
                    state="ERROR",
                    note="unhandled_exception",
                )
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
