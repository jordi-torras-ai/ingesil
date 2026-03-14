#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import json
import logging
import os
import re
import sys
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

import requests

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover
    def load_dotenv(*args, **kwargs):  # type: ignore[no-redef]
        return None


PROJECT_ROOT = Path(__file__).resolve().parents[2]
PYTHON_SRC = PROJECT_ROOT / "python" / "src"
if str(PYTHON_SRC) not in sys.path:
    sys.path.insert(0, str(PYTHON_SRC))

from ingesil_crawlers.logging_utils import build_logger  # noqa: E402


DEFAULT_SPARQL_ENDPOINT = "https://publications.europa.eu/webapi/rdf/sparql"
DEFAULT_OJEU_BASE_URL = "https://eur-lex.europa.eu"
CORPORATE_BODY_LABELS = {
    "COM": "EUROPEAN COMMISSION",
    "EP": "EUROPEAN PARLIAMENT",
    "CONSIL": "COUNCIL OF THE EUROPEAN UNION",
    "ECB": "EUROPEAN CENTRAL BANK",
    "CJUE": "COURT OF JUSTICE OF THE EUROPEAN UNION",
}
RESOURCE_TYPE_LABELS = {
    "DIR": "Directive",
    "REG": "Regulation",
    "DEC": "Decision",
    "RECO": "Recommendation",
    "OPIN": "Opinion",
    "COMMUNIC": "Communication",
    "NOTICE": "Notice",
    "RES": "Resolution",
    "CONCL": "Conclusion",
    "CORRIGENDUM": "Corrigendum",
}


@dataclass(slots=True)
class NoticeItem:
    c_act: str | None
    act: str | None
    celex: str | None
    title: str | None
    pdf: str | None


@dataclass(slots=True)
class ParsedNotice:
    title: str
    category: str
    department: str
    content: str
    fetched_url: str
    extra_info: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for OJEU (Official Journal of the European Union)")
    parser.add_argument("--slug", default="ojeu")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_OJEU_BASE_URL", DEFAULT_OJEU_BASE_URL))
    parser.add_argument("--sparql-endpoint", default=os.getenv("CRAWLER_OJEU_SPARQL_ENDPOINT", DEFAULT_SPARQL_ENDPOINT))
    parser.add_argument("--limit", type=int, default=int(os.getenv("CRAWLER_OJEU_LIMIT", "500")))
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument("--day", default=None, help="Crawl only one day (YYYY-MM-DD).")
    parser.add_argument("--from-date", default=None, help="Crawl window start date (YYYY-MM-DD).")
    parser.add_argument("--to-date", default=None, help="Crawl window end date (YYYY-MM-DD).")
    parser.add_argument("--headless", action="store_true", help="Ignored for OJEU HTTP crawler; kept for CLI compatibility")
    parser.add_argument("--headed", action="store_true", help="Ignored for OJEU HTTP crawler; kept for CLI compatibility")
    return parser.parse_args()


def build_session() -> requests.Session:
    session = requests.Session()
    session.headers.update({"User-Agent": "ingesil-ojeu-crawler/1.0"})
    return session


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
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/HTML/?uri=CELEX:{celex_q}",
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
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/?uri=CELEX:{celex_q}",
                f"{base_url.rstrip('/')}/legal-content/EN/TXT/HTML/?uri=CELEX:{celex_q}",
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
        return f"{base_url.rstrip('/')}/legal-content/EN/TXT/?uri=CELEX:{celex_q}"

    for eli in extract_eli_uris(triples):
        eurlex = eli_to_eurlex(eli)
        if eurlex:
            return eurlex

    for url in (item.act, item.c_act, item.pdf):
        if isinstance(url, str) and url.strip().startswith("http"):
            return url.strip()

    return ""


def is_waf_challenge(html_text: str) -> bool:
    sample = (html_text or "")[:20000].lower()
    return (
        "awswafintegration" in sample
        or "challenge.js" in sample
        or "verify that you're not a robot" in sample
    )


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


def clean_html_text(value: str) -> str:
    text = re.sub(r"(?is)<script\b[^>]*>.*?</script>", " ", value)
    text = re.sub(r"(?is)<style\b[^>]*>.*?</style>", " ", text)
    text = re.sub(r"(?i)<br\s*/?>", "\n", text)
    text = re.sub(r"(?i)</(p|div|li|tr|h[1-6]|table|section|article)>", "\n", text)
    text = re.sub(r"(?i)<li[^>]*>", "- ", text)
    text = re.sub(r"(?i)</(td|th)>", " | ", text)
    text = re.sub(r"<[^>]+>", " ", text)
    text = html.unescape(text)
    text = text.replace("\xa0", " ")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r" *\n *", "\n", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return normalize_text(text)


def extract_first_match(patterns: list[str], html_text: str) -> str:
    for pattern in patterns:
        match = re.search(pattern, html_text, flags=re.IGNORECASE | re.DOTALL)
        if match:
            value = clean_html_text(match.group(1))
            if value:
                return value
    return ""


def extract_title(html_text: str, fallback: str = "") -> str:
    title = extract_first_match(
        [
            r'<div[^>]+class="[^"]*eli-main-title[^"]*"[^>]*>(.*?)</div>',
            r'<meta[^>]+name="WT\.z_docTitle"[^>]+content="([^"]+)"',
            r'<title>(.*?)</title>',
        ],
        html_text,
    )
    if title and not re.search(r"\.(xml|fmx)$", title, flags=re.IGNORECASE):
        return title
    return fallback or title


def extract_meta_values(html_text: str, *, attribute: str, key: str) -> list[str]:
    pattern = rf'<meta[^>]+{attribute}="{re.escape(key)}"[^>]+(?:content|resource)="([^"]+)"'
    return [html.unescape(value).strip() for value in re.findall(pattern, html_text, flags=re.IGNORECASE)]


def extract_act_type(text: str) -> str:
    candidate = " ".join((text or "").split())
    if not candidate:
        return ""

    act_type_patterns: list[tuple[str, re.Pattern[str]]] = [
        ("Directive", re.compile(r"\bDIRECTIVE\b", flags=re.IGNORECASE)),
        ("Regulation", re.compile(r"\bREGULATION\b", flags=re.IGNORECASE)),
        ("Decision", re.compile(r"\bDECISION\b", flags=re.IGNORECASE)),
        ("Recommendation", re.compile(r"\bRECOMMENDATION\b", flags=re.IGNORECASE)),
        ("Opinion", re.compile(r"\bOPINION\b", flags=re.IGNORECASE)),
        ("Communication", re.compile(r"\bCOMMUNICATION\b", flags=re.IGNORECASE)),
        ("Notice", re.compile(r"\bNOTICE\b", flags=re.IGNORECASE)),
        ("Resolution", re.compile(r"\bRESOLUTION\b", flags=re.IGNORECASE)),
        ("Conclusion", re.compile(r"\bCONCLUSIONS?\b", flags=re.IGNORECASE)),
        ("Corrigendum", re.compile(r"\bCORRIG(?:ENDUM|É|E)\b", flags=re.IGNORECASE)),
    ]
    best: tuple[int, int, str] | None = None
    for priority, (act_type, pattern) in enumerate(act_type_patterns):
        match = pattern.search(candidate)
        if not match:
            continue
        key = (match.start(), priority, act_type)
        if best is None or key < best:
            best = key
    return best[2] if best else ""


def extract_department_from_title(title: str) -> str:
    candidate = " ".join((title or "").split()).upper()
    if not candidate:
        return ""
    if "EUROPEAN COMMISSION" in candidate or re.search(r"\bCOMMISSION\b", candidate):
        return "EUROPEAN COMMISSION"
    if "EUROPEAN PARLIAMENT" in candidate:
        return "EUROPEAN PARLIAMENT"
    if re.search(r"\bCOUNCIL\b", candidate):
        return "COUNCIL OF THE EUROPEAN UNION"
    if "EUROPEAN CENTRAL BANK" in candidate:
        return "EUROPEAN CENTRAL BANK"
    return ""


def extract_department_from_meta(html_text: str) -> str:
    resources = extract_meta_values(html_text, attribute="property", key="eli:passed_by")
    labels = [CORPORATE_BODY_LABELS.get(resource.rsplit("/", 1)[-1], "") for resource in resources]
    labels = [label for label in labels if label]
    return "; ".join(dict.fromkeys(labels))


def extract_category_from_meta(html_text: str) -> str:
    resources = extract_meta_values(html_text, attribute="property", key="eli:type_document")
    labels = [RESOURCE_TYPE_LABELS.get(resource.rsplit("/", 1)[-1], "") for resource in resources]
    labels = [label for label in labels if label]
    return "; ".join(dict.fromkeys(labels))


def extract_main_content(html_text: str) -> str:
    fragment_patterns = [
        r'<div[^>]+id="PP4Contents"[^>]*>(.*?)(?:<a[^>]+href="#document1"|</div>\s*</div>\s*</div>\s*</div>)',
        r'<div[^>]+class="[^"]*eli-container[^"]*"[^>]*>(.*?)(?:<hr[^>]+class="[^"]*oj-doc-end[^"]*"|</footer>)',
        r'<div[^>]+id="docHtml"[^>]*>(.*?)(?:</footer>|</body>)',
    ]
    fragment = extract_first_match(fragment_patterns, html_text)
    if not fragment:
        fragment = clean_html_text(html_text)

    content = fragment
    content = re.sub(r"(?im)^Top$", "", content)
    content = re.sub(r"(?im)^ELI:\s+.*$", "", content)
    content = re.sub(r"(?im)^ISSN\s+.*$", "", content)
    return normalize_text(content)


def build_html_metadata(html_text: str) -> dict[str, str]:
    metadata: dict[str, str] = {}

    wt_title = extract_first_match([r'<meta[^>]+name="WT\.z_docTitle"[^>]+content="([^"]+)"'], html_text)
    if wt_title:
        metadata["WT title"] = wt_title

    wt_id = extract_first_match([r'<meta[^>]+name="WT\.z_docID"[^>]+content="([^"]+)"'], html_text)
    if wt_id:
        metadata["WT doc id"] = wt_id

    canonical = extract_first_match([r'<link[^>]+rel="canonical"[^>]+href="([^"]+)"'], html_text)
    if canonical:
        metadata["Canonical"] = canonical

    eli = extract_first_match([r'<p[^>]*>ELI:\s*(.*?)</p>'], html_text)
    if eli:
        metadata["ELI"] = eli

    return metadata


def parse_notice_html(html_text: str, fetched_url: str, item: NoticeItem, triples: list[dict[str, str | None]]) -> ParsedNotice:
    fallback_title = (item.title or "").strip()
    title = extract_title(html_text, fallback=fallback_title)
    category = extract_category_from_meta(html_text) or extract_act_type(title)
    department = extract_department_from_meta(html_text) or extract_department_from_title(title)
    content = extract_main_content(html_text)

    metadata = build_html_metadata(html_text)
    extra_info_payload = {
        "celex": item.celex,
        "act": item.act,
        "c_act": item.c_act,
        "source_pdf": item.pdf,
        "fetched_url": fetched_url,
        "canonical_url": canonical_notice_url(DEFAULT_OJEU_BASE_URL, item, triples),
        "metadata": metadata,
        "content_format": "text",
    }

    return ParsedNotice(
        title=title,
        category=category,
        department=department,
        content=content,
        fetched_url=fetched_url,
        extra_info=json.dumps(extra_info_payload, ensure_ascii=False),
    )


def fetch_notice_html(session: requests.Session, url: str, timeout_seconds: int) -> tuple[str, str]:
    response = session.get(
        url,
        headers={
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Language": "en",
            "User-Agent": "ingesil-ojeu-crawler/1.0",
        },
        timeout=timeout_seconds,
        allow_redirects=True,
    )
    response.raise_for_status()
    return response.text, response.url


def write_day_payload(run_dir: Path, issue_date: date, items: list[NoticeItem]) -> None:
    output_path = run_dir / f"items_{issue_date.isoformat()}.json"
    serialized = [
        {
            "c_act": item.c_act,
            "act": item.act,
            "celex": item.celex,
            "title": item.title,
            "pdf": item.pdf,
        }
        for item in items
    ]
    output_path.write_text(json.dumps(serialized, ensure_ascii=False, indent=2), encoding="utf-8")


def upsert_daily_journal(db_conn: object, *, source_id: int, base_url: str, issue_date: date, description: str) -> int:
    daily_journal_url = f"{base_url.rstrip('/')}/oj/direct-access.html?locale=en"
    cur = db_conn.cursor()
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
            (source_id, issue_date, daily_journal_url, description),
        )
        row = cur.fetchone()
    finally:
        cur.close()

    db_conn.commit()
    if row is None:
        raise RuntimeError("Failed to upsert daily_journal and return id")
    return int(row[0])


def upsert_notice(
    db_conn: object,
    *,
    daily_journal_id: int,
    title: str,
    category: str,
    department: str,
    url: str,
    content: str,
    extra_info: str,
) -> None:
    cur = db_conn.cursor()
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

    db_conn.commit()


def process_notice(
    *,
    session: requests.Session,
    logger: logging.Logger,
    db_conn: object,
    daily_journal_id: int,
    base_url: str,
    sparql_endpoint: str,
    item: NoticeItem,
    timeout_seconds: int,
) -> None:
    triples: list[dict[str, str | None]] = []
    if item.c_act:
        try:
            triples = fetch_notice_triples(sparql_endpoint, item.c_act)
            if not item.celex:
                item.celex = extract_celex_from_triples(triples)
        except Exception as exc:
            logger.warning("Failed fetching notice triples for c_act=%s: %s", item.c_act, exc)

    candidates_raw = [
        *build_useful_urls(base_url, item, triples),
        *build_detail_url_candidates(base_url, item),
    ]
    candidates: list[str] = []
    seen = set()
    for url in candidates_raw:
        if url in seen:
            continue
        seen.add(url)
        candidates.append(url)

    if not candidates:
        logger.warning("Skipping item: no URL candidates (celex=%s act=%s c_act=%s)", item.celex, item.act, item.c_act)
        return

    last_error: str | None = None
    for index, url in enumerate(candidates, start=1):
        try:
            logger.info(
                "Fetching OJEU candidate (%d/%d) for celex=%s url=%s",
                index,
                len(candidates),
                (item.celex or "").strip() or "<none>",
                url,
            )
            html_text, fetched_url = fetch_notice_html(session, url, timeout_seconds)
            if is_waf_challenge(html_text):
                last_error = "Blocked by WAF challenge."
                logger.warning("WAF challenge detected for candidate: %s", url)
                continue

            parsed = parse_notice_html(html_text, fetched_url, item, triples)
            title = parsed.title or (item.title or "").strip() or (item.celex or "").strip() or "OJEU notice"
            stable_url = canonical_notice_url(base_url, item, triples) or fetched_url or url
            upsert_notice(
                db_conn,
                daily_journal_id=daily_journal_id,
                title=title,
                category=parsed.category,
                department=parsed.department,
                url=stable_url,
                content=parsed.content,
                extra_info=parsed.extra_info,
            )
            return
        except Exception as exc:
            last_error = f"{type(exc).__name__}: {exc!s}"
            logger.warning("Failed to fetch/parse OJEU candidate url=%s error=%s", url, last_error)
            continue

    logger.warning("All URL candidates failed for celex=%s. Inserting placeholder notice.", item.celex)
    placeholder_title = (item.title or "").strip() or (item.celex or "").strip() or "OJEU notice"
    stable_url = canonical_notice_url(base_url, item, triples) or candidates[0]
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
        db_conn,
        daily_journal_id=daily_journal_id,
        title=placeholder_title,
        category="",
        department="",
        url=stable_url,
        content="",
        extra_info=extra_info,
    )


def process_day(
    *,
    session: requests.Session,
    logger: logging.Logger,
    db_conn: object,
    run_dir: Path,
    source_id: int,
    issue_date: date,
    base_url: str,
    sparql_endpoint: str,
    limit: int,
    timeout_seconds: int,
) -> int:
    logger.info("Processing OJEU day %s using SPARQL + HTTP fetch.", issue_date.isoformat())
    prop_used, items = fetch_items_for_date(logger=logger, endpoint=sparql_endpoint, issue_date=issue_date, limit=limit)
    logger.info("SPARQL property used: %s items=%d", prop_used, len(items))
    write_day_payload(run_dir, issue_date, items)

    description = f"OJEU crawl {issue_date.isoformat()} - {len(items)} notices"
    daily_journal_id = upsert_daily_journal(
        db_conn,
        source_id=source_id,
        base_url=base_url,
        issue_date=issue_date,
        description=description,
    )

    processed_count = 0
    for item in items:
        process_notice(
            session=session,
            logger=logger,
            db_conn=db_conn,
            daily_journal_id=daily_journal_id,
            base_url=base_url,
            sparql_endpoint=sparql_endpoint,
            item=item,
            timeout_seconds=timeout_seconds,
        )
        processed_count += 1

    return processed_count


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.ojeu", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Run directory: %s", run_dir)
    if args.headless or args.headed:
        logger.info("Browser mode flags were provided, but OJEU now uses HTTP fetches and ignores them.")

    db_conn = None
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
        base_url = (args.base_url or source_base_url or DEFAULT_OJEU_BASE_URL).strip()

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

        session = build_session()
        total_processed = 0
        current_day = from_date
        while current_day <= to_date:
            total_processed += process_day(
                session=session,
                logger=logger,
                db_conn=db_conn,
                run_dir=run_dir,
                source_id=source_id,
                issue_date=current_day,
                base_url=base_url,
                sparql_endpoint=(args.sparql_endpoint or DEFAULT_SPARQL_ENDPOINT).strip(),
                limit=args.limit,
                timeout_seconds=args.timeout,
            )
            current_day += timedelta(days=1)

        logger.info("OJEU crawler finished. Total processed notices=%d", total_processed)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s: %r", type(exc).__name__, exc)
        return 1
    finally:
        if db_conn is not None:
            db_conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
