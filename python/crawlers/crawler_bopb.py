#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import logging
import os
import random
import re
import subprocess
import sys
import time
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from enum import Enum
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

from selenium import webdriver
from selenium.webdriver.chrome.options import Options as ChromeOptions
from selenium.webdriver.common.by import By
from selenium.common.exceptions import TimeoutException
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


DEFAULT_BASE_URL = "https://bop.diba.cat"


class BopbState(Enum):
    PICK_DAY = "PICK_DAY"
    OPEN_LISTING = "OPEN_LISTING"
    PARSE_LISTING_PAGE = "PARSE_LISTING_PAGE"
    PICK_NOTICE = "PICK_NOTICE"
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
    listing_base_url: str
    timeout_seconds: int
    max_notices: int
    from_date: date
    to_date: date
    db_conn: object
    pending_days: list[date]
    current_day: date | None = None
    daily_journal_id: int | None = None
    pending_notice_urls: list[str] | None = None
    next_page_url: str | None = None
    current_notice_url: str | None = None
    processed_notices: int = 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for BOPB (bop.diba.cat)")
    parser.add_argument("--slug", default="bopb")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_BOPB_BASE_URL", DEFAULT_BASE_URL))
    parser.add_argument("--listing-base-url", default=os.getenv("CRAWLER_BOPB_LISTING_BASE_URL", f"{DEFAULT_BASE_URL}/anteriores"))
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument("--max-notices", type=int, default=int(os.getenv("CRAWLER_BOPB_MAX_NOTICES", "0")))
    parser.add_argument("--no-pdf-text", action="store_true", help="Do not download PDFs and extract text content.")
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
    options.page_load_strategy = "eager"
    options.add_argument("--window-size=1600,1200")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-extensions")
    options.add_argument("--remote-debugging-port=0")
    chrome_binary = os.getenv("CRAWLER_CHROME_BINARY", "/usr/bin/google-chrome")
    if os.path.isfile(chrome_binary):
        options.binary_location = chrome_binary
    user_data_dir = Path(os.getenv("CRAWLER_CHROME_USER_DATA_DIR", "/tmp/ingesil-chrome"))
    user_data_dir.mkdir(parents=True, exist_ok=True)
    options.add_argument(f"--user-data-dir={user_data_dir}")
    options.add_experimental_option(
        "prefs",
        {
            "profile.managed_default_content_settings.images": 2,
            "profile.default_content_setting_values.notifications": 2,
        },
    )
    if headless:
        options.add_argument("--headless=new")
    return webdriver.Chrome(options=options)


def configure_driver(driver: WebDriver, *, timeout_seconds: int) -> None:
    budget = max(30, int(timeout_seconds) * 3)
    driver.set_page_load_timeout(budget)
    driver.set_script_timeout(budget)


def wait_dom_ready(driver: WebDriver, timeout_seconds: int) -> None:
    WebDriverWait(driver, timeout_seconds).until(
        lambda d: d.execute_script("return document.readyState") in {"interactive", "complete"}
    )


def parse_iso_date(value: str, *, flag: str) -> date:
    try:
        return datetime.strptime(value.strip(), "%Y-%m-%d").date()
    except ValueError as exc:
        raise RuntimeError(f"Invalid {flag} value {value!r}. Expected YYYY-MM-DD.") from exc


def parse_es_date(value: str) -> date:
    raw = value.strip()
    for fmt in ("%d/%m/%Y", "%d-%m-%Y", "%d.%m.%Y"):
        try:
            return datetime.strptime(raw, fmt).date()
        except ValueError:
            continue
    raise RuntimeError(f"Invalid date value {value!r}. Expected dd/mm/YYYY.")


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
        logger.info("No previous BOPB daily journals found. Starting from source.start_at=%s", from_date.isoformat())
    else:
        from_date = latest_issue_date + timedelta(days=1)
        logger.info(
            "Latest BOPB daily journal=%s. Starting next day=%s",
            latest_issue_date.isoformat(),
            from_date.isoformat(),
        )

    to_date = date.today()
    logger.info("Crawl date window resolved to [%s -> %s]", from_date.isoformat(), to_date.isoformat())
    return from_date, to_date


def build_listing_url(listing_base_url: str, issue_date: date, page: int | None = None) -> str:
    base = listing_base_url.rstrip("/")
    if page and page > 1:
        return f"{base}/{issue_date:%Y-%m-%d}/{page}"
    return f"{base}/{issue_date:%Y-%m-%d}"


def upsert_daily_journal(context: CrawlerContext, *, issue_date: date) -> int:
    daily_journal_url = build_listing_url(context.listing_base_url, issue_date)
    description = f"BOPB {issue_date.isoformat()}"

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


def simulate_human_transition(context: CrawlerContext, from_state: BopbState, to_state: BopbState) -> None:
    delay = random.uniform(0.25, 0.8)
    time.sleep(delay)
    context.logger.info("Human pacing: transition %s -> %s (pause %.2fs)", from_state.value, to_state.value, delay)


def extract_notice_urls_from_listing(driver: WebDriver, base_url: str) -> list[str]:
    urls: list[str] = []
    anchors = driver.find_elements(By.XPATH, "//a[contains(@href, '/anuncio/')]")
    for a in anchors:
        href = (a.get_attribute("href") or "").strip()
        if not href:
            continue
        if "/anuncio/" not in href:
            continue
        if href.startswith("/"):
            href = urljoin(base_url.rstrip("/") + "/", href)
        urls.append(href)

    # Some pages include hidden links in data attributes.
    try:
        data_urls = driver.execute_script(
            r"""
            const out = [];
            document.querySelectorAll('[data-href]').forEach((el) => {
              const v = (el.getAttribute('data-href') || '').trim();
              if (v && v.includes('/anuncio/')) out.push(v);
            });
            return out;
            """
        )
        if isinstance(data_urls, list):
            for u in data_urls:
                if isinstance(u, str) and "/anuncio/" in u:
                    urls.append(u.strip())
    except Exception:
        pass

    deduped: list[str] = []
    seen = set()
    for url in urls:
        if url in seen:
            continue
        seen.add(url)
        deduped.append(url)
    return deduped


def extract_next_page_url(driver: WebDriver, base_url: str) -> str | None:
    candidates = []
    # Common Drupal pager classnames.
    candidates.extend(driver.find_elements(By.CSS_SELECTOR, "li.pager__item--next a"))
    candidates.extend(driver.find_elements(By.CSS_SELECTOR, "a[rel='next']"))
    if not candidates:
        candidates = driver.find_elements(By.XPATH, "//a[contains(., 'Siguiente') or contains(., 'Següent') or contains(., 'Next') or contains(., '›')]")

    for node in candidates[:5]:
        href = (node.get_attribute("href") or "").strip()
        if not href:
            continue
        if href.startswith("/"):
            href = urljoin(base_url.rstrip("/") + "/", href)
        return href
    return None


def normalize_text(text: str) -> str:
    lines = [line.strip() for line in (text or "").splitlines()]
    out: list[str] = []
    last_blank = True
    for line in lines:
        line = re.sub(r"\s+", " ", line).strip()
        if not line:
            if not last_blank:
                out.append("")
            last_blank = True
            continue
        out.append(line)
        last_blank = False
    return "\n".join(out).strip()


def download_pdf(url: str) -> bytes:
    req = Request(
        url,
        headers={
            "User-Agent": "ingesil-bopb-crawler/1.0",
            "Accept": "application/pdf,*/*;q=0.8",
        },
    )
    with urlopen(req, timeout=60) as response:
        return response.read()


def extract_pdf_text_markdown(pdf_bytes: bytes, *, timeout_seconds: int = 30) -> str:
    downloads_dir = Path(os.getenv("TMPDIR", "/tmp")) / "ingesil_bopb"
    downloads_dir.mkdir(parents=True, exist_ok=True)
    pdf_path = downloads_dir / f"bopb_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S_%f')}.pdf"
    txt_path = downloads_dir / f"{pdf_path.stem}.txt"

    try:
        pdf_path.write_bytes(pdf_bytes)
        subprocess.run(
            [
                "pdftotext",
                "-layout",
                "-nopgbrk",
                "-enc",
                "UTF-8",
                str(pdf_path),
                str(txt_path),
            ],
            check=True,
            timeout=timeout_seconds,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        raw = txt_path.read_text(encoding="utf-8", errors="replace")
    finally:
        try:
            pdf_path.unlink(missing_ok=True)
        except Exception:
            pass
        try:
            txt_path.unlink(missing_ok=True)
        except Exception:
            pass

    raw = raw.replace("\r\n", "\n").replace("\r", "\n")
    raw = re.sub(r"[ \t]+\n", "\n", raw)
    raw = re.sub(r"\n{4,}", "\n\n\n", raw).strip()

    # Wrap table-ish blocks in code fences to preserve alignment.
    blocks = re.split(r"\n\s*\n", raw)
    rendered: list[str] = []
    for block in blocks:
        lines = [line.rstrip() for line in block.split("\n") if line.strip()]
        if not lines:
            continue
        spacey = sum(1 for line in lines if re.search(r"\S[ \t]{2,}\S", line))
        if len(lines) >= 3 and (spacey / max(1, len(lines))) >= 0.6:
            rendered.append("```text\n" + "\n".join(lines) + "\n```")
        else:
            rendered.append("\n".join(lines))

    text = "\n\n".join(rendered).strip()
    if len(text) > 180_000:
        text = text[:180_000].rstrip() + "\n\n[TRUNCATED]"
    return text


def extract_markdown_content(driver: WebDriver) -> str:
    try:
        markdown = str(
            driver.execute_script(
                r"""
                function normalizeBlock(text) {
                  if (!text) return "";
                  return text.replace(/\r\n/g, "\n").replace(/\u00A0/g, " ").replace(/[ \t]+\n/g, "\n").trim();
                }

                function escapePipes(text) {
                  return (text || "").replace(/\|/g, "\\|").trim();
                }

                function isLayoutTable(table) {
                  if (!table) return true;
                  if (table.querySelector("img")) return true;
                  const rows = Array.from(table.querySelectorAll("tr"));
                  if (rows.length <= 1) return true;
                  const cells = Array.from(table.querySelectorAll("td,th"));
                  if (cells.length === 0) return true;
                  const text = normalizeBlock(table.innerText);
                  if ((text || "").length < 30 && cells.length >= 6) return true;
                  return false;
                }

                function tableToMarkdown(table) {
                  if (isLayoutTable(table)) return "";
                  const rows = Array.from(table.querySelectorAll("tr"));
                  const parsed = rows
                    .map((tr) => Array.from(tr.children).filter((c) => ["TD", "TH"].includes(c.tagName)).map((c) => normalizeBlock(c.innerText)))
                    .filter((cells) => cells.some((c) => c && c.trim()));
                  if (parsed.length === 0) return "";

                  const colCount = Math.max(...parsed.map((r) => r.length));
                  const hasTh = table.querySelector("th") !== null;

                  if (!hasTh && colCount === 2) {
                    let numberish = 0;
                    for (const row of parsed) {
                      const a = (row[0] || "").trim();
                      const looksLikeNumber = /^\(?\d+[.)]?\)?$/.test(a) || /^\([a-z]\)$/.test(a.toLowerCase());
                      if (looksLikeNumber) numberish += 1;
                    }
                    const numberRatio = parsed.length ? (numberish / parsed.length) : 0;

                    if (numberRatio >= 0.6) {
                      const lines = [];
                      for (const row of parsed) {
                        const a = (row[0] || "").trim();
                        const b = (row[1] || "").trim();
                        if (!a && !b) continue;
                        lines.push(`${a} ${b}`.trim());
                      }
                      return lines.join("\n");
                    }

                    const out = [];
                    out.push("| Col 1 | Col 2 |");
                    out.push("| --- | --- |");
                    for (const row of parsed) {
                      const a = escapePipes((row[0] || "").trim());
                      const b = escapePipes((row[1] || "").trim());
                      out.push(`| ${a} | ${b} |`);
                    }
                    return out.join("\n");
                  }

                  if (colCount >= 2) {
                    const header = hasTh ? parsed[0] : Array.from({ length: colCount }, (_, i) => `Col ${i + 1}`);
                    const dataRows = hasTh ? parsed.slice(1) : parsed;
                    const headerCells = header.map(escapePipes);
                    const sepCells = headerCells.map(() => "---");
                    const out = [];
                    out.push(`| ${headerCells.join(" | ")} |`);
                    out.push(`| ${sepCells.join(" | ")} |`);
                    for (const row of dataRows) {
                      const padded = [...row];
                      while (padded.length < colCount) padded.push("");
                      out.push(`| ${padded.map(escapePipes).join(" | ")} |`);
                    }
                    return out.join("\n");
                  }

                  return parsed.map((r) => r.join(" ").trim()).filter(Boolean).join("\n");
                }

                const root =
                  document.querySelector("main") ||
                  document.querySelector("article") ||
                  document.querySelector("#content") ||
                  document.body;
                if (!root) return "";

                const candidates = Array.from(root.querySelectorAll("h1, h2, h3, h4, h5, h6, p, table, ul, ol"));
                const blocks = [];

                function insideTable(node) {
                  let cur = node && node.parentElement;
                  while (cur) {
                    if (cur.tagName === "TABLE") return true;
                    cur = cur.parentElement;
                  }
                  return false;
                }

                for (const el of candidates) {
                  if (el.tagName !== "TABLE" && insideTable(el)) continue;

                  if (el.tagName === "TABLE") {
                    const md = tableToMarkdown(el);
                    if (md) blocks.push(md);
                    continue;
                  }

                  if (el.tagName === "UL" || el.tagName === "OL") {
                    const items = Array.from(el.querySelectorAll(":scope > li")).map((li) => normalizeBlock(li.innerText)).filter(Boolean);
                    if (items.length) {
                      const prefix = el.tagName === "OL" ? "1." : "-";
                      blocks.push(items.map((t) => `${prefix} ${t}`).join("\n"));
                    }
                    continue;
                  }

                  const text = normalizeBlock(el.innerText);
                  if (!text) continue;
                  blocks.push(text);
                }

                return blocks.join("\n\n").replace(/\n{3,}/g, "\n\n").trim();
                """
            )
        )
    except Exception:
        markdown = ""

    markdown = normalize_text(markdown)
    if len(markdown) > 200_000:
        markdown = markdown[:200_000].rstrip() + "\n\n[TRUNCATED]"
    return markdown


def extract_notice_title(driver: WebDriver) -> str:
    for selector in ["h1", "h1.page-title", "header h1"]:
        nodes = driver.find_elements(By.CSS_SELECTOR, selector)
        if nodes:
            text = (nodes[0].text or "").strip()
            if text:
                return text
    return (driver.title or "").strip()


def extract_notice_metadata(driver: WebDriver) -> dict[str, str]:
    metadata: dict[str, str] = {}

    # Try dl/dt pairs.
    dt_elements = driver.find_elements(By.XPATH, "//dl//dt[normalize-space()]")
    for dt in dt_elements:
        label = (dt.text or "").strip().rstrip(":")
        if not label:
            continue
        dd_candidates = dt.find_elements(By.XPATH, "following-sibling::dd[1]")
        if not dd_candidates:
            continue
        value = (dd_candidates[0].text or "").strip()
        if value:
            metadata[label] = value

    # Try list items like "Registro: 2025..."
    li_nodes = driver.find_elements(By.XPATH, "//li[normalize-space()]")
    for li in li_nodes[:300]:
        text = (li.text or "").strip()
        if ":" not in text:
            continue
        left, right = text.split(":", 1)
        left = left.strip()
        right = right.strip()
        if not left or not right:
            continue
        if left not in metadata:
            metadata[left] = right

    return metadata


def pick_metadata(metadata: dict[str, str], *keys: str) -> str:
    normalized = {k.strip().lower(): v for k, v in metadata.items()}
    for key in keys:
        value = normalized.get(key.strip().lower(), "")
        if value:
            return value.strip()
    return ""


def extract_pdf_url(driver: WebDriver, base_url: str) -> str:
    anchors = driver.find_elements(By.XPATH, "//a[contains(@href, '/anuncio/descargar-pdf/')]")
    for a in anchors[:10]:
        href = (a.get_attribute("href") or "").strip()
        if not href:
            continue
        if href.startswith("/"):
            href = urljoin(base_url.rstrip("/") + "/", href)
        return href

    anchors = driver.find_elements(By.XPATH, "//a[contains(translate(@href,'PDF','pdf'), '.pdf')]")
    for a in anchors[:10]:
        href = (a.get_attribute("href") or "").strip()
        if not href:
            continue
        if href.startswith("/"):
            href = urljoin(base_url.rstrip("/") + "/", href)
        return href
    return ""


def extract_pdf_iframe_url(driver: WebDriver, base_url: str) -> str:
    nodes = driver.find_elements(By.CSS_SELECTOR, "iframe[src*='/anuncio/ver-pdf/']")
    if not nodes:
        return ""
    src = (nodes[0].get_attribute("src") or "").strip()
    if not src:
        return ""
    if src.startswith("/"):
        src = urljoin(base_url.rstrip("/") + "/", src)
    return src


def state_pick_day(context: CrawlerContext) -> BopbState:
    if not context.pending_days:
        return BopbState.DONE

    issue_date = context.pending_days.pop(0)
    context.current_day = issue_date
    context.logger.info("FSM state=%s picked day=%s", BopbState.PICK_DAY.value, issue_date.isoformat())
    context.daily_journal_id = upsert_daily_journal(context, issue_date=issue_date)
    context.pending_notice_urls = []
    context.next_page_url = None
    context.current_notice_url = None
    return BopbState.OPEN_LISTING


def state_open_listing(context: CrawlerContext) -> BopbState:
    if context.current_day is None:
        raise RuntimeError("Missing current_day")
    url = build_listing_url(context.listing_base_url, context.current_day, page=1)
    context.logger.info("FSM state=%s opening listing URL=%s", BopbState.OPEN_LISTING.value, url)
    try:
        context.driver.get(url)
        wait_dom_ready(context.driver, context.timeout_seconds)
    except TimeoutException:
        context.logger.warning("Timeout loading listing URL=%s; continuing with partial DOM.", url)
        try:
            context.driver.execute_script("window.stop();")
        except Exception:
            pass
    created = context.artifacts.capture(context.driver, state=BopbState.OPEN_LISTING.value, note="listing_opened")
    context.logger.info("Artifacts saved: %s", created)
    return BopbState.PARSE_LISTING_PAGE


def state_parse_listing_page(context: CrawlerContext) -> BopbState:
    context.logger.info(
        "FSM state=%s parsing listing URL=%s",
        BopbState.PARSE_LISTING_PAGE.value,
        context.driver.current_url,
    )
    found = extract_notice_urls_from_listing(context.driver, context.base_url)
    context.logger.info("Notice URLs found on page: %d", len(found))
    if context.pending_notice_urls is None:
        context.pending_notice_urls = []
    context.pending_notice_urls.extend(found)
    context.next_page_url = extract_next_page_url(context.driver, context.base_url)
    created = context.artifacts.capture(context.driver, state=BopbState.PARSE_LISTING_PAGE.value, note="listing_parsed")
    context.logger.info("Artifacts saved: %s", created)
    return BopbState.PICK_NOTICE


def state_pick_notice(context: CrawlerContext) -> BopbState:
    if context.pending_notice_urls and len(context.pending_notice_urls) > 0:
        context.current_notice_url = context.pending_notice_urls.pop(0)
        context.logger.info("FSM state=%s picked notice URL=%s", BopbState.PICK_NOTICE.value, context.current_notice_url)
        return BopbState.OPEN_NOTICE

    if context.next_page_url:
        context.logger.info("FSM state=%s moving to next listing page=%s", BopbState.PICK_NOTICE.value, context.next_page_url)
        return BopbState.OPEN_NEXT_PAGE

    context.logger.info("FSM state=%s finished day=%s", BopbState.PICK_NOTICE.value, context.current_day.isoformat() if context.current_day else "<none>")
    return BopbState.PICK_DAY


def state_open_next_page(context: CrawlerContext) -> BopbState:
    if not context.next_page_url:
        return BopbState.PICK_DAY
    url = context.next_page_url
    context.next_page_url = None
    context.logger.info("FSM state=%s opening next listing page URL=%s", BopbState.OPEN_NEXT_PAGE.value, url)
    try:
        context.driver.get(url)
        wait_dom_ready(context.driver, context.timeout_seconds)
    except TimeoutException:
        context.logger.warning("Timeout loading next listing URL=%s; continuing with partial DOM.", url)
        try:
            context.driver.execute_script("window.stop();")
        except Exception:
            pass
    created = context.artifacts.capture(context.driver, state=BopbState.OPEN_NEXT_PAGE.value, note="listing_next_opened")
    context.logger.info("Artifacts saved: %s", created)
    return BopbState.PARSE_LISTING_PAGE


def state_open_notice(context: CrawlerContext) -> BopbState:
    if context.daily_journal_id is None or context.current_notice_url is None:
        raise RuntimeError("Missing daily_journal_id/current_notice_url")

    url = context.current_notice_url
    context.logger.info("FSM state=%s opening notice URL=%s", BopbState.OPEN_NOTICE.value, url)
    try:
        context.driver.get(url)
        wait_dom_ready(context.driver, context.timeout_seconds)
    except TimeoutException:
        context.logger.warning("Timeout loading notice URL=%s; continuing with partial DOM.", url)
        try:
            context.driver.execute_script("window.stop();")
        except Exception:
            pass
    created = context.artifacts.capture(context.driver, state=BopbState.OPEN_NOTICE.value, note="notice_opened")
    context.logger.info("Artifacts saved: %s", created)

    title = extract_notice_title(context.driver)
    metadata = extract_notice_metadata(context.driver)
    department = pick_metadata(metadata, "Anunciante", "Anunciant")
    category = pick_metadata(metadata, "Tipo de anuncio", "Tipus d'anunci")
    publish_date_raw = pick_metadata(metadata, "Fecha de publicación", "Data de publicació")
    registration = pick_metadata(metadata, "Registro", "Registre")
    eli = pick_metadata(metadata, "Enlace ELI", "Enllaç ELI")
    pdf_url = extract_pdf_url(context.driver, context.base_url)
    pdf_iframe_url = extract_pdf_iframe_url(context.driver, context.base_url)

    issue_date: date | None = None
    if publish_date_raw:
        try:
            issue_date = parse_es_date(publish_date_raw)
        except Exception:
            issue_date = None

    pdf_text_error: str | None = None
    pdf_text_markdown = ""

    # Many BOPB notices are PDF-only (embedded iframe). Prefer extracting the PDF text.
    if (pdf_url or pdf_iframe_url) and not getattr(context, "no_pdf_text", False):
        try:
            pdf_source_url = pdf_url or pdf_iframe_url
            if pdf_source_url:
                pdf_bytes = download_pdf(pdf_source_url)
                pdf_text_markdown = extract_pdf_text_markdown(pdf_bytes, timeout_seconds=30)
        except (HTTPError, URLError, subprocess.TimeoutExpired, subprocess.CalledProcessError, OSError) as exc:
            pdf_text_error = f"{type(exc).__name__}: {exc!s}"

    if pdf_text_markdown:
        parts: list[str] = []
        if pdf_url:
            parts.append(f"- PDF: {pdf_url}")
        if pdf_iframe_url:
            parts.append(f"- PDF viewer: {pdf_iframe_url}")
        if eli:
            parts.append(f"- ELI: {eli}")
        if registration:
            parts.append(f"- Registro: {registration}")
        parts.append("")
        parts.append(pdf_text_markdown)
        content = "\n".join(parts).strip()
    elif pdf_iframe_url or pdf_url:
        parts = []
        if pdf_url:
            parts.append(f"- PDF: {pdf_url}")
        if pdf_iframe_url:
            parts.append(f"- PDF viewer: {pdf_iframe_url}")
        if eli:
            parts.append(f"- ELI: {eli}")
        if registration:
            parts.append(f"- Registro: {registration}")
        if pdf_text_error:
            parts.append(f"\n[PDF text extraction failed: {pdf_text_error}]")
        content = "\n".join(parts).strip()
    else:
        content = extract_markdown_content(context.driver)
    parsed = urlparse(context.driver.current_url)
    stable_url = f"{parsed.scheme}://{parsed.netloc}{parsed.path}".rstrip("/") or context.driver.current_url

    extra_info = {
        "registration": registration,
        "eli": eli,
        "publication_date": publish_date_raw,
        "publication_date_parsed": issue_date.isoformat() if issue_date else None,
        "pdf_url": pdf_url,
        "pdf_iframe_url": pdf_iframe_url,
        "pdf_text_extracted": bool(pdf_text_markdown),
        "pdf_text_error": pdf_text_error,
        "metadata": {k: metadata[k] for k in list(metadata)[:60]},
        "content_format": "markdown",
    }

    upsert_notice(
        context,
        daily_journal_id=context.daily_journal_id,
        title=title,
        category=category,
        department=department,
        url=stable_url,
        content=content,
        extra_info=json.dumps(extra_info, ensure_ascii=False),
    )

    context.processed_notices += 1
    if context.max_notices > 0 and context.processed_notices >= context.max_notices:
        context.logger.info("Reached --max-notices=%d, stopping crawl.", context.max_notices)
        context.pending_notice_urls = []
        context.next_page_url = None
        context.pending_days = []
        return BopbState.PICK_DAY

    return BopbState.PICK_NOTICE


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()
    headless = resolve_headless(args)

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.bopb", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Mode: %s", "headless" if headless else "headed")
    logger.info("Run directory: %s", run_dir)

    db_conn = None
    driver: WebDriver | None = None
    try:
        try:
            import psycopg
        except ImportError as exc:  # pragma: no cover
            raise RuntimeError("psycopg is required to crawl BOPB and write DB records.") from exc

        db_conn = psycopg.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_DATABASE", ""),
            user=os.getenv("DB_USERNAME", ""),
            password=os.getenv("DB_PASSWORD", ""),
        )

        source_id, source_start_at, source_base_url = read_source_data_from_db(args.slug)
        base_url = (args.base_url or source_base_url or DEFAULT_BASE_URL).strip()
        listing_base_url = (args.listing_base_url or f"{base_url.rstrip('/')}/anteriores").strip()

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
            logger.info("Nothing to crawl for BOPB: from_date=%s is after today=%s", from_date.isoformat(), to_date.isoformat())
            return 0

        pending_days: list[date] = []
        cur = from_date
        while cur <= to_date:
            pending_days.append(cur)
            cur += timedelta(days=1)

        driver = build_driver(headless=headless)
        configure_driver(driver, timeout_seconds=args.timeout)
        artifacts = ArtifactWriter(run_dir=run_dir)
        context = CrawlerContext(
            logger=logger,
            driver=driver,
            artifacts=artifacts,
            slug=args.slug,
            source_id=source_id,
            base_url=base_url,
            listing_base_url=listing_base_url,
            timeout_seconds=args.timeout,
            max_notices=max(0, int(args.max_notices)),
            from_date=from_date,
            to_date=to_date,
            db_conn=db_conn,
            pending_days=pending_days,
        )
        # Keep as a plain attribute: avoids changing the dataclass signature everywhere.
        context.no_pdf_text = bool(args.no_pdf_text)  # type: ignore[attr-defined]

        fsm = FSMRunner(
            initial_state=BopbState.PICK_DAY,
            terminal_state=BopbState.DONE,
            handlers={
                BopbState.PICK_DAY: state_pick_day,
                BopbState.OPEN_LISTING: state_open_listing,
                BopbState.PARSE_LISTING_PAGE: state_parse_listing_page,
                BopbState.PICK_NOTICE: state_pick_notice,
                BopbState.OPEN_NOTICE: state_open_notice,
                BopbState.OPEN_NEXT_PAGE: state_open_next_page,
            },
            on_transition=simulate_human_transition,
            config=FSMConfig(max_steps=300000),
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
