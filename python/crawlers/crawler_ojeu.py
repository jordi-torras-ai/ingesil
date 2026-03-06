#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
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
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

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


DEFAULT_SPARQL_ENDPOINT = "https://publications.europa.eu/webapi/rdf/sparql"


class OjeuState(Enum):
    FETCH_DAY = "FETCH_DAY"
    PROCESS_ITEM = "PROCESS_ITEM"
    OPEN_NOTICE = "OPEN_NOTICE"
    DONE = "DONE"


@dataclass
class NoticeItem:
    c_act: str | None
    act: str | None
    celex: str | None
    title: str | None
    pdf: str | None


@dataclass
class CrawlerContext:
    logger: logging.Logger
    driver: WebDriver
    artifacts: ArtifactWriter
    slug: str
    source_id: int
    base_url: str
    sparql_endpoint: str
    timeout_seconds: int
    limit: int
    from_date: date
    to_date: date
    db_conn: object
    pending_days: list[date]
    current_day: date | None = None
    daily_journal_id: int | None = None
    pending_items: list[NoticeItem] | None = None
    current_item: NoticeItem | None = None


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for OJEU (Official Journal of the European Union)")
    parser.add_argument("--slug", default="ojeu")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_OJEU_BASE_URL", "https://eur-lex.europa.eu"))
    parser.add_argument("--sparql-endpoint", default=os.getenv("CRAWLER_OJEU_SPARQL_ENDPOINT", DEFAULT_SPARQL_ENDPOINT))
    parser.add_argument("--limit", type=int, default=int(os.getenv("CRAWLER_OJEU_LIMIT", "500")))
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
    options.add_argument("--disable-extensions")
    options.add_argument("--remote-debugging-port=0")
    chrome_binary = os.getenv("CRAWLER_CHROME_BINARY", "/usr/bin/google-chrome")
    if os.path.isfile(chrome_binary):
        options.binary_location = chrome_binary
    user_data_dir = Path(os.getenv("CRAWLER_CHROME_USER_DATA_DIR", "/tmp/ingesil-chrome"))
    user_data_dir.mkdir(parents=True, exist_ok=True)
    options.add_argument(f"--user-data-dir={user_data_dir}")
    if headless:
        options.add_argument("--headless=new")
    return webdriver.Chrome(options=options)


def wait_dom_ready(driver: WebDriver, timeout_seconds: int) -> None:
    WebDriverWait(driver, timeout_seconds).until(
        lambda d: d.execute_script("return document.readyState") == "complete"
    )


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
        logger.info("No previous OJEU daily journals found. Starting from source.start_at=%s", from_date.isoformat())
    else:
        from_date = latest_issue_date + timedelta(days=1)
        logger.info(
            "Latest OJEU daily journal=%s. Starting next day=%s",
            latest_issue_date.isoformat(),
            from_date.isoformat(),
        )

    to_date = date.today()
    logger.info("Crawl date window resolved to [%s -> %s]", from_date.isoformat(), to_date.isoformat())
    return from_date, to_date


def build_notice_query(date_str: str, date_property: str, limit: int) -> str:
    return f"""
prefix cdm: <http://publications.europa.eu/ontology/cdm#>
prefix owl: <http://www.w3.org/2002/07/owl#>
prefix xsd: <http://www.w3.org/2001/XMLSchema#>
prefix dc: <http://purl.org/dc/elements/1.1/>

SELECT DISTINCT ?c_act ?act ?celex ?title ?pdf
WHERE {{
  ?c_act {date_property} "{date_str}"^^xsd:date .

  OPTIONAL {{ ?c_act owl:sameAs ?act . }}
  OPTIONAL {{ ?c_act cdm:resource_legal_id_celex ?celex . }}
  OPTIONAL {{
    ?c_exp cdm:expression_belongs_to_work ?c_act .
    ?c_exp cdm:expression_language <http://publications.europa.eu/resource/authority/language/ENG> .
    ?c_exp dc:title ?title .

    OPTIONAL {{
      ?c_manif cdm:manifestation_manifests_expression ?c_exp .
      ?c_manif owl:sameAs ?pdf .
      OPTIONAL {{ ?c_manif cdm:manifestation_type <http://publications.europa.eu/resource/authority/manifestation-type/PDF> . }}
    }}
  }}
}}
LIMIT {limit}
"""


def run_sparql(endpoint: str, query: str) -> list[dict[str, Any]]:
    params = urlencode({"query": query, "format": "application/sparql-results+json"})
    req = Request(
        f"{endpoint}?{params}",
        headers={
            "Accept": "application/sparql-results+json",
            "User-Agent": "ingesil-ojeu-crawler/1.0",
        },
    )
    with urlopen(req, timeout=60) as response:
        payload = json.loads(response.read().decode("utf-8"))
    return payload.get("results", {}).get("bindings", [])


def normalize_sparql_rows(rows: list[dict[str, Any]]) -> list[NoticeItem]:
    items: list[NoticeItem] = []
    for row in rows:
        items.append(
            NoticeItem(
                c_act=row.get("c_act", {}).get("value"),
                act=row.get("act", {}).get("value"),
                celex=row.get("celex", {}).get("value"),
                title=row.get("title", {}).get("value"),
                pdf=row.get("pdf", {}).get("value"),
            )
        )
    return items


def fetch_items_for_date(
    *,
    logger: logging.Logger,
    endpoint: str,
    issue_date: date,
    limit: int,
) -> tuple[str, list[NoticeItem]]:
    date_str = issue_date.isoformat()
    date_properties = [
        "cdm:official-journal-act_date_publication",
        "cdm:work_date_document",
    ]

    last_error: Exception | None = None
    for prop in date_properties:
        try:
            rows = run_sparql(endpoint, build_notice_query(date_str, prop, limit))
        except (HTTPError, URLError) as exc:
            last_error = exc
            logger.warning("SPARQL query failed for date=%s using %s: %s", date_str, prop, exc)
            continue
        if rows:
            raw_items = normalize_sparql_rows(rows)
            unique: dict[str, NoticeItem] = {}
            for item in raw_items:
                key = item.c_act or item.act or item.celex or ""
                if not key:
                    continue
                if key not in unique:
                    unique[key] = item
            return prop, list(unique.values())

    if last_error is not None:
        raise RuntimeError(f"SPARQL endpoint errors while fetching date={date_str}") from last_error
    return date_properties[0], []


def build_detail_url_candidates(base_url: str, item: NoticeItem) -> list[str]:
    candidates: list[str] = []

    celex = (item.celex or "").strip()
    if celex:
        celex_q = quote(celex, safe="")
        candidates.extend(
            [
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/HTML/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/PDF/?uri=CELEX:{celex_q}",
            ]
        )

    for url in (item.act, item.c_act, item.pdf):
        if isinstance(url, str) and url.strip().startswith("http"):
            candidates.append(url.strip())

    deduped: list[str] = []
    seen = set()
    for url in candidates:
        if url in seen:
            continue
        seen.add(url)
        deduped.append(url)
    return deduped


def build_notice_triples_query(c_act_uri: str) -> str:
    return f"""
SELECT ?p ?o
WHERE {{
  <{c_act_uri}> ?p ?o .
}}
"""


def fetch_notice_triples(endpoint: str, c_act_uri: str) -> list[dict[str, str | None]]:
    rows = run_sparql(endpoint, build_notice_triples_query(c_act_uri))
    triples: list[dict[str, str | None]] = []
    for row in rows:
        obj = row.get("o", {}) or {}
        triples.append(
            {
                "p": (row.get("p", {}) or {}).get("value"),
                "o": obj.get("value"),
                "o_type": obj.get("type"),
                "o_lang": obj.get("xml:lang"),
                "o_datatype": obj.get("datatype"),
            }
        )
    return triples


def extract_celex_from_triples(triples: list[dict[str, str | None]]) -> str | None:
    for triple in triples or []:
        p = (triple.get("p") or "").lower()
        if "celex" not in p:
            continue
        value = (triple.get("o") or "").strip()
        if value:
            return value
    return None


def extract_eli_uris(triples: list[dict[str, str | None]]) -> list[str]:
    uris = set()
    for triple in triples or []:
        uri = triple.get("o")
        if isinstance(uri, str) and uri.startswith(("http://data.europa.eu/eli/", "https://data.europa.eu/eli/")):
            uris.add(uri)
    return sorted(uris)


def eli_to_eurlex(eli_uri: str | None) -> str | None:
    if not eli_uri or not isinstance(eli_uri, str):
        return None
    if "data.europa.eu/eli/" in eli_uri:
        suffix = eli_uri.split("data.europa.eu/eli/", 1)[1]
        return f"https://eur-lex.europa.eu/eli/{suffix}"
    return None


def build_useful_urls(base_url: str, item: NoticeItem, triples: list[dict[str, str | None]]) -> list[str]:
    urls: list[str] = []

    celex = (item.celex or "").strip()
    if celex:
        celex_q = quote(celex, safe="")
        urls.extend(
            [
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/HTML/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/PDF/?uri=CELEX:{celex_q}",
            ]
        )

    for eli in extract_eli_uris(triples):
        eurlex = eli_to_eurlex(eli)
        if eurlex:
            urls.append(eurlex)

    deduped: list[str] = []
    seen = set()
    for url in urls:
        if url in seen:
            continue
        seen.add(url)
        deduped.append(url)
    return deduped


def canonical_notice_url(base_url: str, item: NoticeItem, triples: list[dict[str, str | None]]) -> str:
    celex = (item.celex or "").strip()
    if celex:
        celex_q = quote(celex, safe="")
        return f"{base_url.rstrip('/')}/legal-content/EN/TXT/HTML/?uri=CELEX:{celex_q}"

    for eli in extract_eli_uris(triples):
        eurlex = eli_to_eurlex(eli)
        if eurlex:
            return eurlex

    for url in (item.act, item.c_act, item.pdf):
        if isinstance(url, str) and url.strip().startswith("http"):
            return url.strip()

    return ""


def is_waf_challenge(html: str) -> bool:
    sample = (html or "")[:20000].lower()
    return (
        "awswafintegration" in sample
        or "challenge.js" in sample
        or "verify that you're not a robot" in sample
    )


def maybe_accept_cookies(driver: WebDriver, logger: logging.Logger) -> None:
    xpaths = [
        "//button[contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'accept')]",
        "//button[contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'agree')]",
        "//a[contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'accept')]",
    ]
    for xpath in xpaths:
        try:
            nodes = driver.find_elements(By.XPATH, xpath)
        except Exception:
            continue
        for node in nodes[:3]:
            try:
                text = (node.text or "").strip()
                if not text:
                    continue
                node.click()
                logger.info("Cookie consent clicked: %r", text)
                time.sleep(0.4)
                return
            except Exception:
                continue


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


def extract_title(driver: WebDriver, fallback: str = "") -> str:
    main_title_nodes = driver.find_elements(By.CSS_SELECTOR, ".eli-main-title .oj-doc-ti, #tit_1 .oj-doc-ti")
    if main_title_nodes:
        parts: list[str] = []
        for node in main_title_nodes[:8]:
            value = (node.text or "").strip()
            if value:
                parts.append(value)
        if parts:
            return " ".join(parts).strip()

    for selector in ["h1", "header h1", "h1.title", "h1.document-title"]:
        nodes = driver.find_elements(By.CSS_SELECTOR, selector)
        if nodes:
            value = (nodes[0].text or "").strip()
            if value:
                return value

    meta_nodes = driver.find_elements(By.CSS_SELECTOR, "meta[property='og:title']")
    if meta_nodes:
        value = (meta_nodes[0].get_attribute("content") or "").strip()
        if value:
            return value

    title = (driver.title or "").strip()
    if title and not re.search(r"\.(xml|fmx)$", title, flags=re.IGNORECASE):
        return title
    return fallback or title


def extract_metadata(driver: WebDriver) -> dict[str, str]:
    metadata: dict[str, str] = {}

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

    rows = driver.find_elements(By.XPATH, "//table//tr[th and td]")
    for row in rows:
        th_nodes = row.find_elements(By.XPATH, "./th[1]")
        td_nodes = row.find_elements(By.XPATH, "./td[1]")
        if not th_nodes or not td_nodes:
            continue
        label = (th_nodes[0].text or "").strip().rstrip(":")
        value = (td_nodes[0].text or "").strip()
        if label and value and label not in metadata:
            metadata[label] = value

    return metadata


def pick_metadata_value(metadata: dict[str, str], *, patterns: list[str]) -> str:
    normalized: dict[str, str] = {}
    for key, value in metadata.items():
        norm_key = " ".join(key.strip().lower().split())
        normalized[norm_key] = value

    for pattern in patterns:
        regex = re.compile(pattern, flags=re.IGNORECASE)
        for key, value in normalized.items():
            if regex.search(key) and value.strip():
                return value.strip()

    return ""


ACT_TYPE_PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    ("Directive", re.compile(r"\bDIRECTIVE\b", flags=re.IGNORECASE)),
    ("Regulation", re.compile(r"\bREGULATION\b", flags=re.IGNORECASE)),
    ("Decision", re.compile(r"\bDECISION\b", flags=re.IGNORECASE)),
    ("Recommendation", re.compile(r"\bRECOMMENDATION\b", flags=re.IGNORECASE)),
    ("Opinion", re.compile(r"\bOPINION\b", flags=re.IGNORECASE)),
    ("Communication", re.compile(r"\bCOMMUNICATION\b", flags=re.IGNORECASE)),
    ("Notice", re.compile(r"\bNOTICE\b", flags=re.IGNORECASE)),
    ("Resolution", re.compile(r"\bRESOLUTION\b", flags=re.IGNORECASE)),
    ("Conclusion", re.compile(r"\bCONCLUSIONS?\b", flags=re.IGNORECASE)),
]


def extract_act_type(text: str) -> str:
    candidate = " ".join((text or "").split())
    if not candidate:
        return ""
    best: tuple[int, int, str] | None = None
    for priority, (act_type, pattern) in enumerate(ACT_TYPE_PATTERNS):
        match = pattern.search(candidate)
        if not match:
            continue
        key = (match.start(), priority, act_type)
        if best is None or key < best:
            best = key
    return best[2] if best else ""


def extract_act_header(driver: WebDriver) -> str:
    nodes = driver.find_elements(By.CSS_SELECTOR, ".eli-main-title .oj-doc-ti, #tit_1 .oj-doc-ti")
    if nodes:
        return (nodes[0].text or "").strip()
    return ""


def extract_ojeu_department(driver: WebDriver) -> str:
    nodes = driver.find_elements(By.CSS_SELECTOR, "#docHtml p.oj-normal, .eli-container p.oj-normal")
    for node in nodes[:8]:
        text = (node.text or "").strip()
        if not text:
            continue
        text = text.strip().rstrip(",").strip()
        if len(text) < 4:
            continue
        if any(token in text.upper() for token in ["COMMISSION", "PARLIAMENT", "COUNCIL", "CENTRAL BANK", "BOARD"]):
            return text
    return ""


def extract_department_from_title(title: str) -> str:
    candidate = " ".join((title or "").split()).upper()
    if not candidate:
        return ""
    if "EUROPEAN COMMISSION" in candidate or re.search(r"\bCOMMISSION\b", candidate):
        return "THE EUROPEAN COMMISSION"
    if "EUROPEAN PARLIAMENT" in candidate:
        return "EUROPEAN PARLIAMENT"
    if re.search(r"\bCOUNCIL\b", candidate):
        return "COUNCIL OF THE EUROPEAN UNION"
    if "EUROPEAN CENTRAL BANK" in candidate:
        return "EUROPEAN CENTRAL BANK"
    return ""


def extract_main_content(driver: WebDriver) -> str:
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

                    // Treat as a generic 2-column table.
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
                  document.querySelector("#docHtml") ||
                  document.querySelector("main") ||
                  document.querySelector("article") ||
                  document.body;

                if (!root) return "";

                const candidates = Array.from(root.querySelectorAll("p, table, h1, h2, h3, h4, h5, h6, ul, ol"));
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

                const joined = blocks.join("\n\n").replace(/\n{3,}/g, "\n\n").trim();
                return joined;
                """
            )
        )
    except Exception:
        markdown = ""

    if not markdown:
        try:
            markdown = str(
                driver.execute_script("return document.body && document.body.innerText ? document.body.innerText : ''")
            )
        except Exception:
            markdown = ""

    markdown = normalize_text(markdown)
    if len(markdown) > 200_000:
        markdown = markdown[:200_000].rstrip() + "\n\n[TRUNCATED]"
    return markdown


def upsert_daily_journal(context: CrawlerContext, *, issue_date: date, description: str) -> int:
    daily_journal_url = f"{context.base_url.rstrip('/')}/oj/direct-access.html?locale=en"
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


def simulate_human_transition(context: CrawlerContext, from_state: OjeuState, to_state: OjeuState) -> None:
    delay = random.uniform(0.3, 0.9)
    time.sleep(delay)
    context.logger.info("Human pacing: transition %s -> %s (pause %.2fs)", from_state.value, to_state.value, delay)


def state_fetch_day(context: CrawlerContext) -> OjeuState:
    if not context.pending_days:
        return OjeuState.DONE

    issue_date = context.pending_days.pop(0)
    context.current_day = issue_date
    context.logger.info("FSM state=%s fetching SPARQL items for day=%s", OjeuState.FETCH_DAY.value, issue_date.isoformat())

    prop_used, items = fetch_items_for_date(
        logger=context.logger,
        endpoint=context.sparql_endpoint,
        issue_date=issue_date,
        limit=context.limit,
    )
    context.logger.info("SPARQL property used: %s items=%d", prop_used, len(items))

    description = f"OJEU crawl {issue_date.isoformat()} - {len(items)} notices"
    context.daily_journal_id = upsert_daily_journal(context, issue_date=issue_date, description=description)
    context.pending_items = items
    context.current_item = None

    return OjeuState.PROCESS_ITEM


def state_process_item(context: CrawlerContext) -> OjeuState:
    if not context.pending_items:
        return OjeuState.FETCH_DAY

    context.current_item = context.pending_items.pop(0)
    item = context.current_item
    context.logger.info(
        "FSM state=%s picked item: day=%s celex=%s title=%s",
        OjeuState.PROCESS_ITEM.value,
        (context.current_day.isoformat() if context.current_day else "<none>"),
        (item.celex or "").strip() or "<none>",
        (item.title or "").strip()[:180] or "<none>",
    )
    return OjeuState.OPEN_NOTICE


def state_open_notice(context: CrawlerContext) -> OjeuState:
    if context.current_day is None or context.daily_journal_id is None or context.current_item is None:
        raise RuntimeError("Invalid crawler context: missing current day/journal/item")

    item = context.current_item
    triples: list[dict[str, str | None]] = []
    if item.c_act:
        try:
            triples = fetch_notice_triples(context.sparql_endpoint, item.c_act)
            if not item.celex:
                item.celex = extract_celex_from_triples(triples)
        except Exception as exc:
            context.logger.warning("Failed fetching notice triples for c_act=%s: %s", item.c_act, exc)

    candidates_raw = [
        *build_useful_urls(context.base_url, item, triples),
        *build_detail_url_candidates(context.base_url, item),
    ]
    candidates: list[str] = []
    seen = set()
    for url in candidates_raw:
        if url in seen:
            continue
        seen.add(url)
        candidates.append(url)
    if not candidates:
        context.logger.warning("Skipping item: no URL candidates (celex=%s act=%s c_act=%s)", item.celex, item.act, item.c_act)
        return OjeuState.PROCESS_ITEM

    last_error: str | None = None
    for idx, url in enumerate(candidates, start=1):
        try:
            context.logger.info("Opening notice (%d/%d): %s", idx, len(candidates), url)
            context.driver.get(url)
            wait_dom_ready(context.driver, context.timeout_seconds)
            maybe_accept_cookies(context.driver, context.logger)

            if is_waf_challenge(context.driver.page_source):
                last_error = "Blocked by WAF challenge."
                context.logger.warning("WAF challenge detected, trying next candidate: %s", url)
                continue

            created = context.artifacts.capture(context.driver, state=OjeuState.OPEN_NOTICE.value, note="opened")
            context.logger.info("Artifacts saved: %s", created)

            fallback_title = (item.title or "").strip()
            title = extract_title(context.driver, fallback=fallback_title)
            act_header = extract_act_header(context.driver) or title
            metadata = extract_metadata(context.driver)
            category = extract_act_type(act_header) or extract_act_type(title)
            if not category:
                category = pick_metadata_value(
                    metadata,
                    patterns=[
                        r"\btype of act\b",
                        r"\bdocument type\b",
                        r"\bform\b",
                        r"\bclassification\b",
                    ],
                )

            department = extract_department_from_title(act_header) or extract_department_from_title(title)
            if not department:
                department = pick_metadata_value(
                    metadata,
                    patterns=[
                        r"\bauthor\b",
                        r"\bcorporate author\b",
                        r"\bresponsible\b",
                        r"\binstitution\b",
                    ],
                )
            if not department:
                department = extract_ojeu_department(context.driver)
            content = extract_main_content(context.driver)

            stable_url = canonical_notice_url(context.base_url, item, triples) or context.driver.current_url
            extra_info = {
                "celex": item.celex,
                "act": item.act,
                "c_act": item.c_act,
                "source_pdf": item.pdf,
                "canonical_url": stable_url,
                "fetched_url": context.driver.current_url,
                "url_candidates": candidates[:6],
                "metadata": {k: metadata[k] for k in list(metadata)[:40]},
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
            return OjeuState.PROCESS_ITEM
        except Exception as exc:
            last_error = f"{type(exc).__name__}: {exc!s}"
            context.logger.warning("Failed to open/parse notice candidate url=%s error=%s", url, last_error)
            continue

    context.logger.warning("All URL candidates failed for celex=%s. Inserting placeholder notice.", item.celex)
    placeholder_title = (item.title or "").strip() or (item.celex or "").strip() or "OJEU notice"
    stable_url = canonical_notice_url(context.base_url, item, triples) or candidates[0]
    extra_info = json.dumps(
        {
            "celex": item.celex,
            "act": item.act,
            "c_act": item.c_act,
            "source_pdf": item.pdf,
            "canonical_url": stable_url,
            "url_candidates": candidates,
            "error": last_error,
        },
        ensure_ascii=False,
    )
    upsert_notice(
        context,
        daily_journal_id=context.daily_journal_id,
        title=placeholder_title,
        category="",
        department="",
        url=stable_url,
        content="",
        extra_info=extra_info,
    )
    return OjeuState.PROCESS_ITEM


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()
    headless = resolve_headless(args)

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.ojeu", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Mode: %s", "headless" if headless else "headed")
    logger.info("Run directory: %s", run_dir)

    db_conn = None
    driver: WebDriver | None = None
    try:
        try:
            import psycopg
        except ImportError as exc:  # pragma: no cover
            raise RuntimeError("psycopg is required to crawl OJEU and write DB records.") from exc

        db_conn = psycopg.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_DATABASE", ""),
            user=os.getenv("DB_USERNAME", ""),
            password=os.getenv("DB_PASSWORD", ""),
        )

        source_id, source_start_at, source_base_url = read_source_data_from_db(args.slug)
        base_url = (args.base_url or source_base_url or "https://eur-lex.europa.eu").strip()

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
            logger.info("Nothing to crawl for OJEU: from_date=%s is after today=%s", from_date.isoformat(), to_date.isoformat())
            return 0

        pending_days: list[date] = []
        cur = from_date
        while cur <= to_date:
            pending_days.append(cur)
            cur += timedelta(days=1)

        driver = build_driver(headless=headless)
        artifacts = ArtifactWriter(run_dir=run_dir)
        context = CrawlerContext(
            logger=logger,
            driver=driver,
            artifacts=artifacts,
            slug=args.slug,
            source_id=source_id,
            base_url=base_url,
            sparql_endpoint=(args.sparql_endpoint or DEFAULT_SPARQL_ENDPOINT).strip(),
            timeout_seconds=args.timeout,
            limit=args.limit,
            from_date=from_date,
            to_date=to_date,
            db_conn=db_conn,
            pending_days=pending_days,
        )

        fsm = FSMRunner(
            initial_state=OjeuState.FETCH_DAY,
            terminal_state=OjeuState.DONE,
            handlers={
                OjeuState.FETCH_DAY: state_fetch_day,
                OjeuState.PROCESS_ITEM: state_process_item,
                OjeuState.OPEN_NOTICE: state_open_notice,
            },
            on_transition=simulate_human_transition,
            config=FSMConfig(max_steps=200000),
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
