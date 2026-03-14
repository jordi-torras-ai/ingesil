#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import json
import logging
import os
import re
import ssl
import sys
import xml.etree.ElementTree as ET
from datetime import date, datetime, timedelta
from pathlib import Path
from urllib.parse import urljoin

import requests
from requests.adapters import HTTPAdapter

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


DOGC_DAILY_BASE_URL = "https://dogc.gencat.cat/ca/sumari-del-dogc/"
DOGC_DOCUMENT_BASE_URL = "https://dogc.gencat.cat/ca/document-del-dogc/"
DOGC_SEARCH_API = "https://portaldogc.gencat.cat/eadop-rest/api/dogc/searchDOGC"
DOGC_DOCUMENT_API = "https://portaldogc.gencat.cat/eadop-rest/api/dogc/documentDOGC"
DOGC_AKN_NAMESPACE = {"akn": "http://docs.oasis-open.org/legaldocml/ns/akn/3.0"}
DOGC_RESULTS_PER_PAGE = 500


class LegacyTLSAdapter(HTTPAdapter):
    def init_poolmanager(self, *args, **kwargs):
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname = True
        context.verify_mode = ssl.CERT_REQUIRED
        context.load_default_certs()
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.maximum_version = ssl.TLSVersion.TLSv1_2
        context.set_ciphers("AES256-SHA")
        kwargs["ssl_context"] = context
        return super().init_poolmanager(*args, **kwargs)

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for DOGC source")
    parser.add_argument("--slug", default="dogc")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument(
        "--base-url",
        default=os.getenv("CRAWLER_DOGC_BASE_URL", "https://dogc.gencat.cat/ca"),
        help="Legacy DOGC browser URL. Ignored by the API crawler; kept for CLI compatibility.",
    )
    parser.add_argument(
        "--daily-base-url",
        default=os.getenv("CRAWLER_DOGC_DAILY_BASE_URL", DOGC_DAILY_BASE_URL),
        help="Base URL used to build daily journal links.",
    )
    parser.add_argument(
        "--search-api-url",
        default=os.getenv("CRAWLER_DOGC_SEARCH_API_URL", DOGC_SEARCH_API),
        help="DOGC search API endpoint.",
    )
    parser.add_argument(
        "--document-api-url",
        default=os.getenv("CRAWLER_DOGC_DOCUMENT_API_URL", DOGC_DOCUMENT_API),
        help="DOGC document metadata API endpoint.",
    )
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument(
        "--start-at",
        default=None,
        help="Optional override for source start date (YYYY-MM-DD). If omitted, read from DB sources.start_at.",
    )
    parser.add_argument("--day", default=None, help="Crawl only one day (YYYY-MM-DD).")
    parser.add_argument("--from-date", default=None, help="Crawl window start date (YYYY-MM-DD).")
    parser.add_argument("--to-date", default=None, help="Crawl window end date (YYYY-MM-DD).")
    parser.add_argument("--headless", action="store_true", help="Ignored for DOGC API crawler; kept for CLI compatibility")
    parser.add_argument("--headed", action="store_true", help="Ignored for DOGC API crawler; kept for CLI compatibility")
    return parser.parse_args()


def build_session() -> requests.Session:
    session = requests.Session()
    session.headers.update({"User-Agent": "ingesil-dogc-crawler/1.0"})
    session.mount("https://portaldogc.gencat.cat/", LegacyTLSAdapter())
    return session


def parse_iso_date(value: str, *, flag: str) -> date:
    try:
        return datetime.strptime(value.strip(), "%Y-%m-%d").date()
    except ValueError as exc:
        raise RuntimeError(f"Invalid {flag} value {value!r}. Expected YYYY-MM-DD.") from exc


def read_source_data_from_db(slug: str) -> tuple[int, date]:
    try:
        import psycopg
    except ImportError as exc:  # pragma: no cover
        raise RuntimeError("psycopg is required to read source start_at from DB") from exc

    with psycopg.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "5432")),
        dbname=os.getenv("DB_DATABASE", ""),
        user=os.getenv("DB_USERNAME", ""),
        password=os.getenv("DB_PASSWORD", ""),
    ) as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT id, start_at FROM sources WHERE slug = %s LIMIT 1", (slug,))
            row = cur.fetchone()

    if row is None:
        raise RuntimeError(f"No source found for slug={slug!r}")
    if row[1] is None:
        raise RuntimeError(f"Source slug={slug!r} has NULL start_at")

    source_id = int(row[0])
    start_value = row[1]
    if isinstance(start_value, date):
        return source_id, start_value

    return source_id, datetime.strptime(str(start_value), "%Y-%m-%d").date()


def resolve_source_context(args: argparse.Namespace, logger: logging.Logger) -> tuple[int, date, date]:
    source_id, db_start_date = read_source_data_from_db(args.slug)
    logger.info("Loaded source id=%s and start_at=%s from DB for slug=%s", source_id, db_start_date.isoformat(), args.slug)

    if args.day:
        if args.from_date or args.to_date or args.start_at:
            raise RuntimeError("Use either --day, --from-date/--to-date, or --start-at. Do not combine them.")
        one_day = parse_iso_date(args.day, flag="--day")
        logger.info("Using explicit single-day crawl window from --day: [%s -> %s]", one_day, one_day)
        return source_id, one_day, one_day

    if args.from_date or args.to_date:
        if args.start_at:
            raise RuntimeError("Use either --start-at or --from-date/--to-date, not both.")
        if not args.from_date or not args.to_date:
            raise RuntimeError("Both --from-date and --to-date are required when using a date range override.")
        start_date = parse_iso_date(args.from_date, flag="--from-date")
        end_date = parse_iso_date(args.to_date, flag="--to-date")
        if start_date > end_date:
            raise RuntimeError(
                f"Invalid date range: --from-date ({start_date.isoformat()}) is after --to-date ({end_date.isoformat()})."
            )
        logger.info("Using explicit crawl window from CLI: [%s -> %s]", start_date.isoformat(), end_date.isoformat())
        return source_id, start_date, end_date

    if args.start_at:
        override_start = parse_iso_date(args.start_at, flag="--start-at")
        logger.info("Using --start-at override: %s", override_start.isoformat())
        return source_id, override_start, date.today()

    return source_id, db_start_date, date.today()


def format_search_date(value: date) -> str:
    return value.strftime("%d/%m/%Y")


def build_search_payload(target_date: date, page: int) -> dict[str, object]:
    formatted_date = format_search_date(target_date)
    return {
        "typeSearch": "1",
        "value": "",
        "title": True,
        "current": False,
        "range": [],
        "issuingAuthority": [],
        "publicationDateInitial": formatted_date,
        "publicationDateFinal": formatted_date,
        "dispositionDateInitial": "",
        "dispositionDateFinal": "",
        "sectionDOGC": [],
        "thematicDescriptor": [],
        "organizationDescriptor": [],
        "geographicDescriptor": [],
        "aranese": False,
        "expandSearchFullText": False,
        "noCurrent": False,
        "orderBy": "3",
        "page": str(page),
        "numResultsByPage": str(DOGC_RESULTS_PER_PAGE),
        "advanced": True,
        "language": "ca",
    }


def raise_api_error(data: dict) -> None:
    if "errorCode" in data:
        message = data.get("errorDescription") or "Unknown DOGC API error"
        raise RuntimeError(str(message))


def normalize_space(value: str | None) -> str:
    return " ".join((value or "").split()).strip()


def build_daily_journal_url(daily_base_url: str, issue_date: date, num_dogc: str | None) -> str:
    if not num_dogc:
        return daily_base_url.rstrip("/") + "/"
    return (
        f"{daily_base_url.rstrip('/')}/?anexos=1&selectedYear={issue_date.year}"
        f"&selectedMonth={issue_date.month}&numDOGC={num_dogc}&language=ca_ES"
    )


def build_notice_url(document_id: str, link_title: str | None) -> str:
    if link_title:
        link_title = link_title.strip()
        if link_title.startswith("http://") or link_title.startswith("https://"):
            return link_title
        if link_title.startswith("?"):
            return f"{DOGC_DOCUMENT_BASE_URL}?{link_title.lstrip('?')}"
        if "document-del-dogc" in link_title:
            return urljoin(DOGC_DOCUMENT_BASE_URL, link_title)
    return f"{DOGC_DOCUMENT_BASE_URL}?documentId={document_id}"


def fetch_day_documents(
    session: requests.Session,
    search_api_url: str,
    target_date: date,
    timeout_seconds: int,
) -> tuple[list[dict], list[dict]]:
    page = 1
    total: int | None = None
    seen_ids: set[str] = set()
    documents: list[dict] = []
    responses: list[dict] = []

    while True:
        payload = build_search_payload(target_date, page)
        response = session.post(search_api_url, json=payload, timeout=timeout_seconds)
        response.raise_for_status()

        data = response.json()
        raise_api_error(data)
        responses.append(data)

        results = data.get("resultSearch", []) or []
        for item in results:
            document_id = normalize_space(str(item.get("idDocument") or ""))
            if not document_id or document_id in seen_ids:
                continue
            seen_ids.add(document_id)
            documents.append(item)

        if total is None:
            total_value = data.get("numResultSearch")
            total = int(total_value) if total_value is not None else len(documents)

        if not results or len(documents) >= total:
            break

        page += 1

    return documents, responses


def fetch_document_metadata(
    session: requests.Session,
    document_api_url: str,
    document_id: str,
    timeout_seconds: int,
) -> dict:
    response = session.post(
        document_api_url,
        data={"documentId": document_id, "language": "ca"},
        timeout=timeout_seconds,
    )
    response.raise_for_status()

    data = response.json()
    raise_api_error(data)
    return data


def fetch_document_xml(session: requests.Session, xml_url: str, timeout_seconds: int) -> str | None:
    if not xml_url:
        return None

    response = session.get(xml_url, timeout=timeout_seconds)
    if response.status_code != 200:
        return None

    return response.text


def html_to_text(fragment: str | None) -> str:
    if not fragment:
        return ""

    fragment = html.unescape(fragment)
    fragment = fragment.replace("\xa0", " ")
    fragment = re.sub(r"<!--.*?-->", " ", fragment, flags=re.DOTALL)
    fragment = re.sub(r"(?i)<br\s*/?>", "\n", fragment)
    fragment = re.sub(r"(?i)</(p|div|li|tr|h[1-6])>", "\n", fragment)
    fragment = re.sub(r"(?i)<li[^>]*>", "- ", fragment)
    fragment = re.sub(r"<[^>]+>", "", fragment)
    fragment = html.unescape(fragment)

    lines: list[str] = []
    for line in fragment.splitlines():
        cleaned = re.sub(r"\s+", " ", line).strip()
        if cleaned:
            lines.append(cleaned)

    return "\n".join(lines)


def extract_text_from_xml(xml_text: str) -> str:
    root = ET.fromstring(xml_text)

    paragraphs: list[str] = []
    for paragraph in root.findall(".//akn:p", DOGC_AKN_NAMESPACE):
        text = normalize_space("".join(paragraph.itertext()))
        if text:
            paragraphs.append(text)

    if paragraphs:
        return "\n\n".join(paragraphs)

    for content in root.findall(".//akn:content", DOGC_AKN_NAMESPACE):
        rich_text = content.get("period") or "".join(content.itertext())
        text = html_to_text(rich_text)
        if text:
            return text

    return ""


def extract_notice_content(xml_text: str | None, html_fallback: str | None) -> str:
    if xml_text:
        try:
            content = extract_text_from_xml(xml_text)
            if content:
                return content
        except ET.ParseError:
            pass

    return html_to_text(html_fallback)


def build_extra_info(metadata: dict) -> str:
    document_data = metadata.get("documentData") or {}
    uri_eli = metadata.get("uriELI") or {}

    rows: list[tuple[str, str]] = [
        ("Tipus document", normalize_space(document_data.get("typeDocument"))),
        ("Data document", normalize_space(document_data.get("dateDocument"))),
        ("Número document", normalize_space(document_data.get("numDocument"))),
        ("Número control", normalize_space(document_data.get("numControl"))),
        ("Organisme emissor", normalize_space(document_data.get("issuingAuthority"))),
        ("Número DOGC", normalize_space(document_data.get("numDOGC"))),
        ("Data DOGC", normalize_space(document_data.get("dateDOGC"))),
        ("Secció DOGC", normalize_space(document_data.get("sectionDOGC"))),
        ("CVE", normalize_space(document_data.get("CVE"))),
        ("ELI", normalize_space(uri_eli.get("link"))),
        ("Text consolidat", "Sí" if metadata.get("consolidatedText") else "No"),
        ("Text vigent", "Sí" if metadata.get("current") else "No"),
    ]

    extra_parts = [f"{label}: {value}" for label, value in rows if value]
    return "\n".join(extra_parts).strip()


def write_search_payload(run_dir: Path, target_date: date, payloads: list[dict]) -> None:
    output_path = run_dir / f"search_{target_date.isoformat()}.json"
    output_path.write_text(json.dumps(payloads, ensure_ascii=False, indent=2), encoding="utf-8")


def upsert_daily_journal(
    db_conn: object,
    source_id: int,
    *,
    issue_date: date,
    issue_url: str,
    description: str,
) -> int:
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
            (source_id, issue_date, issue_url, description),
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


def process_day(
    *,
    session: requests.Session,
    db_conn: object,
    logger: logging.Logger,
    run_dir: Path,
    source_id: int,
    target_date: date,
    search_api_url: str,
    document_api_url: str,
    daily_base_url: str,
    timeout_seconds: int,
) -> int:
    logger.info("Processing DOGC day %s via API.", target_date.isoformat())
    documents, search_payloads = fetch_day_documents(session, search_api_url, target_date, timeout_seconds)
    write_search_payload(run_dir, target_date, search_payloads)
    logger.info("DOGC search API returned %d documents for %s.", len(documents), target_date.isoformat())

    processed_count = 0
    daily_journal_id: int | None = None

    for document in documents:
        document_id = normalize_space(str(document.get("idDocument") or ""))
        if not document_id:
            continue

        metadata = fetch_document_metadata(session, document_api_url, document_id, timeout_seconds)
        document_data = metadata.get("documentData") or {}
        num_dogc = normalize_space(document_data.get("numDOGC"))
        description = f"DOGC núm. {num_dogc}" if num_dogc else f"DOGC {target_date.isoformat()}"
        daily_journal_url = build_daily_journal_url(daily_base_url, target_date, num_dogc)
        daily_journal_id = upsert_daily_journal(
            db_conn,
            source_id,
            issue_date=target_date,
            issue_url=daily_journal_url,
            description=description,
        )

        link_download = metadata.get("linkDownload") or {}
        xml_text = fetch_document_xml(session, normalize_space(link_download.get("linkDownloadXML")), timeout_seconds)
        content = extract_notice_content(xml_text, metadata.get("textDocument"))
        title = normalize_space(metadata.get("titleDocument")) or normalize_space(document.get("title"))
        category = normalize_space(document_data.get("sectionDOGC"))
        department = normalize_space(document_data.get("issuingAuthority"))
        url = build_notice_url(document_id, document.get("linkTitle"))
        extra_info = build_extra_info(metadata)

        if not title:
            logger.warning("Skipping DOGC document without title: document_id=%s", document_id)
            continue

        upsert_notice(
            db_conn,
            daily_journal_id=daily_journal_id,
            title=title,
            category=category,
            department=department,
            url=url,
            content=content,
            extra_info=extra_info,
        )
        processed_count += 1
        logger.info(
            "Upserted DOGC notice document_id=%s journal_id=%s title=%s content_len=%d",
            document_id,
            daily_journal_id,
            title,
            len(content),
        )

    return processed_count


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.dogc", run_dir / "crawler.log")
    logger.info("Starting crawler for slug=%s", args.slug)
    logger.info("Run directory: %s", run_dir)
    if args.headless or args.headed:
        logger.info("Browser mode flags were provided, but DOGC now uses the API and ignores them.")
    if args.base_url:
        logger.info("Legacy DOGC browser base URL retained for CLI compatibility: %s", args.base_url)

    db_conn = None
    try:
        try:
            import psycopg
        except ImportError as exc:  # pragma: no cover
            raise RuntimeError("psycopg is required to crawl DOGC and write DB records.") from exc

        db_conn = psycopg.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=int(os.getenv("DB_PORT", "5432")),
            dbname=os.getenv("DB_DATABASE", ""),
            user=os.getenv("DB_USERNAME", ""),
            password=os.getenv("DB_PASSWORD", ""),
        )

        source_id, start_issue_date, end_issue_date = resolve_source_context(args, logger)
        if start_issue_date > end_issue_date:
            raise RuntimeError(
                f"start_at ({start_issue_date.isoformat()}) cannot be after today ({end_issue_date.isoformat()})."
            )

        session = build_session()
        total_processed = 0
        current_day = start_issue_date
        while current_day <= end_issue_date:
            total_processed += process_day(
                session=session,
                db_conn=db_conn,
                logger=logger,
                run_dir=run_dir,
                source_id=source_id,
                target_date=current_day,
                search_api_url=args.search_api_url,
                document_api_url=args.document_api_url,
                daily_base_url=args.daily_base_url,
                timeout_seconds=args.timeout,
            )
            current_day += timedelta(days=1)

        logger.info("DOGC crawler finished. Total processed notices=%d", total_processed)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s: %r", type(exc).__name__, exc)
        return 1
    finally:
        if db_conn is not None:
            db_conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
