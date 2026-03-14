#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import logging
import os
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from urllib.parse import urljoin

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


BOE_SITE_BASE_URL = "https://www.boe.es"
BOE_SUMMARY_API_URL_TEMPLATE = "https://www.boe.es/datosabiertos/api/boe/sumario/{stamp}"


@dataclass(slots=True)
class SummaryNotice:
    identifier: str
    title: str
    issue_date: date
    daily_journal_description: str
    section: str
    department: str
    epigraph: str
    html_url: str
    pdf_url: str
    xml_url: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for BOE source")
    parser.add_argument("--slug", default="boe")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument(
        "--base-url",
        default=os.getenv("CRAWLER_BOE_SUMMARY_API_URL_TEMPLATE", BOE_SUMMARY_API_URL_TEMPLATE),
        help="BOE open-data summary API URL template. Use {stamp} for YYYYMMDD.",
    )
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument("--day", default=None, help="Crawl only one day (YYYY-MM-DD).")
    parser.add_argument("--from-date", default=None, help="Crawl window start date (YYYY-MM-DD).")
    parser.add_argument("--to-date", default=None, help="Crawl window end date (YYYY-MM-DD).")
    parser.add_argument("--headless", action="store_true", help="Ignored for BOE API crawler; kept for CLI compatibility")
    parser.add_argument("--headed", action="store_true", help="Ignored for BOE API crawler; kept for CLI compatibility")
    return parser.parse_args()


def parse_iso_date(value: str, *, flag: str) -> date:
    try:
        return datetime.strptime(value.strip(), "%Y-%m-%d").date()
    except ValueError as exc:
        raise RuntimeError(f"Invalid {flag} value {value!r}. Expected YYYY-MM-DD.") from exc


def read_source_data_from_db(slug: str) -> tuple[int, date]:
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
            cur.execute("SELECT id, start_at FROM sources WHERE slug = %s LIMIT 1", (slug,))
            row = cur.fetchone()

    if row is None:
        raise RuntimeError(f"No source found for slug={slug!r}")
    if row[1] is None:
        raise RuntimeError(f"Source slug={slug!r} has NULL start_at")

    source_id = int(row[0])
    start_at = row[1] if isinstance(row[1], date) else datetime.strptime(str(row[1]), "%Y-%m-%d").date()
    return source_id, start_at


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


def normalize_space(value: str | None) -> str:
    return " ".join((value or "").split()).strip()


def ensure_list(value: dict | list | None) -> list:
    if value is None:
        return []
    if isinstance(value, list):
        return value
    return [value]


def extract_text_value(value: str | dict | None) -> str:
    if isinstance(value, str):
        return value.strip()
    if isinstance(value, dict):
        for key in ("texto", "@texto"):
            candidate = value.get(key)
            if isinstance(candidate, str):
                return candidate.strip()
    return ""


def make_absolute_url(value: str) -> str:
    if not value:
        return ""
    return urljoin(BOE_SITE_BASE_URL, value)


def resolve_summary_api_url(url_template: str, target_date: date, logger: logging.Logger) -> str:
    if "buscar/boe.php" in url_template:
        logger.warning(
            "Ignoring legacy BOE search URL %s and using official summary API template instead.",
            url_template,
        )
        url_template = BOE_SUMMARY_API_URL_TEMPLATE

    stamp = target_date.strftime("%Y%m%d")
    if "{stamp}" in url_template:
        return url_template.format(stamp=stamp)

    return f"{url_template.rstrip('/')}/{stamp}"


def build_daily_journal_description(diario: dict, issue_date: date) -> str:
    issue_date_label = issue_date.strftime("%d/%m/%Y")
    number = normalize_space(str(diario.get("numero", "")))
    if number:
        return f"BOE {number} de {issue_date_label}"
    return f"BOE de {issue_date_label}"


def fetch_day_summary(
    session: requests.Session,
    url_template: str,
    target_date: date,
    timeout_seconds: int,
    logger: logging.Logger,
) -> dict | None:
    url = resolve_summary_api_url(url_template, target_date, logger)
    response = session.get(
        url,
        headers={"Accept": "application/json", "User-Agent": "ingesil-boe-crawler/1.0"},
        timeout=timeout_seconds,
    )
    if response.status_code == 404:
        logger.info("BOE summary not available for %s (404). Skipping day.", target_date.isoformat())
        return None

    response.raise_for_status()
    payload = response.json()
    status_code = str(payload.get("status", {}).get("code", ""))
    if status_code and status_code != "200":
        if status_code == "404":
            logger.info("BOE summary reports status 404 for %s. Skipping day.", target_date.isoformat())
            return None
        raise RuntimeError(f"BOE summary API returned unexpected status {status_code} for {target_date.isoformat()}")

    return payload


def extract_summary_notices(payload: dict, target_date: date) -> list[SummaryNotice]:
    sumario = payload.get("data", {}).get("sumario", {})
    notices: list[SummaryNotice] = []

    for diario in ensure_list(sumario.get("diario")):
        description = build_daily_journal_description(diario, target_date)
        for section in ensure_list(diario.get("seccion")):
            section_name = normalize_space(section.get("nombre"))
            notices.extend(
                collect_summary_notices(
                    node=section,
                    issue_date=target_date,
                    daily_journal_description=description,
                    section_name=section_name,
                    department_name="",
                    epigraph_name="",
                )
            )

    deduped: dict[str, SummaryNotice] = {}
    for notice in notices:
        deduped.setdefault(notice.identifier, notice)
    return list(deduped.values())


def collect_summary_notices(
    *,
    node: dict | list | None,
    issue_date: date,
    daily_journal_description: str,
    section_name: str,
    department_name: str,
    epigraph_name: str,
) -> list[SummaryNotice]:
    notices: list[SummaryNotice] = []

    if isinstance(node, list):
        for item in node:
            notices.extend(
                collect_summary_notices(
                    node=item,
                    issue_date=issue_date,
                    daily_journal_description=daily_journal_description,
                    section_name=section_name,
                    department_name=department_name,
                    epigraph_name=epigraph_name,
                )
            )
        return notices

    if not isinstance(node, dict):
        return notices

    for item in ensure_list(node.get("item")):
        summary_notice = summary_notice_from_item(
            item=item,
            issue_date=issue_date,
            daily_journal_description=daily_journal_description,
            section_name=section_name,
            department_name=department_name,
            epigraph_name=epigraph_name,
        )
        if summary_notice is not None:
            notices.append(summary_notice)

    for department in ensure_list(node.get("departamento")):
        notices.extend(
            collect_summary_notices(
                node=department,
                issue_date=issue_date,
                daily_journal_description=daily_journal_description,
                section_name=section_name,
                department_name=normalize_space(department.get("nombre")) or department_name,
                epigraph_name="",
            )
        )

    for epigraph in ensure_list(node.get("epigrafe")):
        notices.extend(
            collect_summary_notices(
                node=epigraph,
                issue_date=issue_date,
                daily_journal_description=daily_journal_description,
                section_name=section_name,
                department_name=department_name,
                epigraph_name=normalize_space(epigraph.get("nombre")) or epigraph_name,
            )
        )

    for apartado in ensure_list(node.get("apartado")):
        notices.extend(
            collect_summary_notices(
                node=apartado,
                issue_date=issue_date,
                daily_journal_description=daily_journal_description,
                section_name=section_name,
                department_name=department_name,
                epigraph_name=normalize_space(apartado.get("nombre")) or epigraph_name,
            )
        )

    return notices


def summary_notice_from_item(
    *,
    item: dict,
    issue_date: date,
    daily_journal_description: str,
    section_name: str,
    department_name: str,
    epigraph_name: str,
) -> SummaryNotice | None:
    title = normalize_space(item.get("titulo"))
    identifier = normalize_space(item.get("identificador"))
    html_url = make_absolute_url(extract_text_value(item.get("url_html")))
    pdf_url = make_absolute_url(extract_text_value(item.get("url_pdf")))
    xml_url = make_absolute_url(extract_text_value(item.get("url_xml")))

    if not title or not identifier:
        return None

    return SummaryNotice(
        identifier=identifier,
        title=title,
        issue_date=issue_date,
        daily_journal_description=daily_journal_description,
        section=section_name,
        department=department_name,
        epigraph=epigraph_name,
        html_url=html_url,
        pdf_url=pdf_url,
        xml_url=xml_url,
    )


def extract_text_values(root: ET.Element, tag_name: str) -> list[str]:
    values: list[str] = []
    for element in root.iter():
        if element.tag.lower() != tag_name.lower():
            continue
        text = normalize_space(" ".join(part for part in element.itertext()))
        if text:
            values.append(text)
    return values


def fetch_notice_detail(
    session: requests.Session,
    notice: SummaryNotice,
    timeout_seconds: int,
) -> dict[str, str]:
    if not notice.xml_url:
        return {
            "title": notice.title,
            "category": notice.section,
            "department": notice.department,
            "url": notice.html_url or notice.pdf_url,
            "content": "",
            "extra_info": build_extra_info(
                identifier=notice.identifier,
                range_label="",
                epigraph=notice.epigraph,
                materias=[],
                alerts=[],
            ),
        }

    response = session.get(
        notice.xml_url,
        headers={"User-Agent": "ingesil-boe-crawler/1.0"},
        timeout=timeout_seconds,
    )
    response.raise_for_status()
    root = ET.fromstring(response.text)

    title = normalize_space(root.findtext("./metadatos/titulo")) or notice.title
    department = normalize_space(root.findtext("./metadatos/departamento")) or notice.department
    html_url = make_absolute_url(normalize_space(root.findtext("./metadatos/url_html"))) or notice.html_url
    identifier = normalize_space(root.findtext("./metadatos/identificador")) or notice.identifier
    range_label = normalize_space(root.findtext("./metadatos/rango"))
    materias = extract_text_values(root, "materia")
    alerts = extract_text_values(root, "alerta")

    paragraphs: list[str] = []
    for paragraph in root.findall(".//texto//p"):
        text = normalize_space(" ".join(paragraph.itertext()))
        if text:
            paragraphs.append(text)

    content = "\n\n".join(paragraphs).strip()
    extra_info = build_extra_info(
        identifier=identifier,
        range_label=range_label,
        epigraph=notice.epigraph,
        materias=materias,
        alerts=alerts,
    )

    return {
        "title": title,
        "category": notice.section,
        "department": department,
        "url": html_url or notice.pdf_url or notice.xml_url,
        "content": content,
        "extra_info": extra_info,
    }


def build_extra_info(
    *,
    identifier: str,
    range_label: str,
    epigraph: str,
    materias: list[str],
    alerts: list[str],
) -> str:
    parts: list[str] = []
    if identifier:
        parts.append(f"Identificador: {identifier}")
    if range_label:
        parts.append(f"Rango: {range_label}")
    if epigraph:
        parts.append(f"Epígrafe: {epigraph}")
    if materias:
        parts.append("Materias: " + "; ".join(materias))
    if alerts:
        parts.append("Alertas: " + "; ".join(alerts))
    return "\n".join(parts).strip()


def write_summary_payload(run_dir: Path, target_date: date, payload: dict) -> None:
    output_path = run_dir / f"summary_{target_date.isoformat()}.json"
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def upsert_daily_journal(
    db_conn: object,
    source_id: int,
    *,
    issue_date: date,
    description: str,
) -> int:
    daily_journal_url = f"https://www.boe.es/boe/dias/{issue_date:%Y/%m/%d}/"
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


def process_day(
    *,
    session: requests.Session,
    db_conn: object,
    logger: logging.Logger,
    run_dir: Path,
    source_id: int,
    target_date: date,
    url_template: str,
    timeout_seconds: int,
) -> int:
    logger.info("Processing BOE day %s via open-data API.", target_date.isoformat())
    payload = fetch_day_summary(session, url_template, target_date, timeout_seconds, logger)
    if payload is None:
        return 0

    write_summary_payload(run_dir, target_date, payload)
    notices = extract_summary_notices(payload, target_date)
    logger.info("Summary API returned %d BOE notices for %s.", len(notices), target_date.isoformat())

    processed_count = 0
    for notice in notices:
        daily_journal_id = upsert_daily_journal(
            db_conn,
            source_id,
            issue_date=notice.issue_date,
            description=notice.daily_journal_description,
        )
        detail = fetch_notice_detail(session, notice, timeout_seconds)
        upsert_notice(
            db_conn,
            daily_journal_id=daily_journal_id,
            title=detail["title"],
            category=detail["category"],
            department=detail["department"],
            url=detail["url"],
            content=detail["content"],
            extra_info=detail["extra_info"],
        )
        processed_count += 1
        logger.info(
            "Upserted BOE notice identifier=%s journal_id=%s title=%s content_len=%d",
            notice.identifier,
            daily_journal_id,
            detail["title"],
            len(detail["content"]),
        )

    return processed_count


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.boe", run_dir / "crawler.log")
    logger.info("Starting BOE crawler for slug=%s", args.slug)
    logger.info("Run directory: %s", run_dir)
    if args.headless or args.headed:
        logger.info("Browser mode flags were provided, but BOE now uses the official open-data API and ignores them.")

    db_conn = None
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

        source_id, source_start_at = read_source_data_from_db(args.slug)
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
            logger.info("Nothing to crawl for BOE: from_date=%s is after to_date=%s", from_date.isoformat(), to_date.isoformat())
            return 0

        session = requests.Session()
        total_processed = 0
        current_day = from_date
        while current_day <= to_date:
            total_processed += process_day(
                session=session,
                db_conn=db_conn,
                logger=logger,
                run_dir=run_dir,
                source_id=source_id,
                target_date=current_day,
                url_template=args.base_url,
                timeout_seconds=args.timeout,
            )
            current_day += timedelta(days=1)

        logger.info("BOE crawler finished. Total processed notices=%d", total_processed)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s: %r", type(exc).__name__, exc)
        return 1
    finally:
        if db_conn is not None:
            db_conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
