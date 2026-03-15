#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import logging
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Iterable
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup

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


DEFAULT_BASE_URL = "https://bop.diba.cat"
DEFAULT_SUMMARY_BASE_URL = f"{DEFAULT_BASE_URL}/sumario-del-dia"
DEFAULT_FEED_URL = f"{DEFAULT_BASE_URL}/datos-abiertos/boletin-del-dia/feed"
REQUEST_HEADERS = {
    "User-Agent": "ingesil-bopb-crawler/2.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}
PDF_HEADERS = {
    "User-Agent": "ingesil-bopb-crawler/2.0",
    "Accept": "application/pdf,*/*;q=0.8",
}


@dataclass(slots=True)
class NoticeRef:
    notice_id: str
    label: str
    notice_url: str
    pdf_url: str


class NoIssuePublished(RuntimeError):
    pass


@dataclass
class CrawlerContext:
    logger: logging.Logger
    slug: str
    source_id: int
    base_url: str
    summary_base_url: str
    feed_url: str
    timeout_seconds: int
    max_notices: int
    from_date: date
    to_date: date
    db_conn: object
    no_pdf_text: bool
    processed_notices: int = 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingesil crawler for BOPB (bop.diba.cat)")
    parser.add_argument("--slug", default="bopb")
    parser.add_argument("--run-id", default=datetime.now().strftime("%Y%m%d_%H%M%S"))
    parser.add_argument("--base-url", default=os.getenv("CRAWLER_BOPB_BASE_URL", DEFAULT_BASE_URL))
    parser.add_argument(
        "--summary-base-url",
        default=os.getenv("CRAWLER_BOPB_SUMMARY_BASE_URL", DEFAULT_SUMMARY_BASE_URL),
    )
    parser.add_argument("--feed-url", default=os.getenv("CRAWLER_BOPB_FEED_URL", DEFAULT_FEED_URL))
    parser.add_argument("--timeout", type=int, default=int(os.getenv("CRAWLER_TIMEOUT_SECONDS", "20")))
    parser.add_argument("--max-notices", type=int, default=int(os.getenv("CRAWLER_BOPB_MAX_NOTICES", "0")))
    parser.add_argument("--no-pdf-text", action="store_true", help="Do not download PDFs and extract text content.")
    parser.add_argument("--day", default=None, help="Crawl only one day (YYYY-MM-DD).")
    parser.add_argument("--from-date", default=None, help="Crawl window start date (YYYY-MM-DD).")
    parser.add_argument("--to-date", default=None, help="Crawl window end date (YYYY-MM-DD).")
    parser.add_argument("--headless", action="store_true", help="Accepted for CLI compatibility, ignored.")
    parser.add_argument("--headed", action="store_true", help="Accepted for CLI compatibility, ignored.")
    return parser.parse_args()


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


def build_summary_url(summary_base_url: str, issue_date: date) -> str:
    return f"{summary_base_url.rstrip('/')}/{issue_date:%Y-%m-%d}"


def upsert_daily_journal(context: CrawlerContext, *, issue_date: date, notice_count: int) -> int:
    daily_journal_url = build_summary_url(context.summary_base_url, issue_date)
    description = f"BOPB {issue_date.isoformat()} - {notice_count} notices"

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


def request_bytes(url: str, *, timeout_seconds: int, headers: dict[str, str] | None = None) -> bytes:
    response = requests.get(url, timeout=max(30, timeout_seconds * 3), headers=headers or REQUEST_HEADERS)
    response.raise_for_status()
    return response.content


def request_text(url: str, *, timeout_seconds: int) -> str:
    response = requests.get(url, timeout=max(30, timeout_seconds * 3), headers=REQUEST_HEADERS)
    response.raise_for_status()
    response.encoding = response.encoding or "utf-8"
    return response.text


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
        pdf_path.unlink(missing_ok=True)
        txt_path.unlink(missing_ok=True)

    raw = raw.replace("\r\n", "\n").replace("\r", "\n")
    raw = re.sub(r"[ \t]+\n", "\n", raw)
    raw = re.sub(r"\n{4,}", "\n\n\n", raw).strip()

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


def parse_summary_pdf_notice_refs(pdf_bytes: bytes, *, base_url: str, timeout_seconds: int) -> list[NoticeRef]:
    with tempfile.TemporaryDirectory(prefix="ingesil_bopb_summary_") as temp_dir:
        temp_path = Path(temp_dir)
        pdf_path = temp_path / "summary.pdf"
        xml_path = temp_path / "summary.xml"
        pdf_path.write_bytes(pdf_bytes)
        subprocess.run(
            ["pdftohtml", "-xml", str(pdf_path), str(xml_path)],
            check=True,
            timeout=max(30, timeout_seconds * 3),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        xml_text = xml_path.read_text(encoding="utf-8", errors="ignore")

    candidates: dict[str, NoticeRef] = {}
    for notice_id, raw_label in re.findall(r'https://bop\.diba\.cat/anunci/(\d+)">([^<]+)', xml_text):
        label = normalize_text(raw_label)
        if not label or label.startswith("CVE-Núm."):
            continue
        if notice_id in candidates:
            continue
        candidates[notice_id] = NoticeRef(
            notice_id=notice_id,
            label=label,
            notice_url=f"{base_url.rstrip('/')}/anunci/{notice_id}",
            pdf_url=f"{base_url.rstrip('/')}/anunci/descarrega-pdf/{notice_id}",
        )

    return list(candidates.values())


def extract_notice_refs_from_feed(feed_xml: bytes, *, base_url: str) -> list[NoticeRef]:
    soup = BeautifulSoup(feed_xml, "xml")
    refs: list[NoticeRef] = []
    seen_ids: set[str] = set()
    for item in soup.find_all("item"):
        link_node = item.find("link")
        if link_node is None or not link_node.text.strip():
            continue
        href = link_node.text.strip()
        match = re.search(r"/anunci[o]?/(\d+)", href)
        if not match:
            continue
        notice_id = match.group(1)
        if notice_id in seen_ids:
            continue
        title_node = item.find("title")
        label = normalize_text(title_node.text) if title_node and title_node.text else ""
        refs.append(
            NoticeRef(
                notice_id=notice_id,
                label=label,
                notice_url=f"{base_url.rstrip('/')}/anunci/{notice_id}",
                pdf_url=f"{base_url.rstrip('/')}/anunci/descarrega-pdf/{notice_id}",
            )
        )
        seen_ids.add(notice_id)
    return refs


def extract_notice_refs_for_day(context: CrawlerContext, issue_date: date) -> list[NoticeRef]:
    summary_url = build_summary_url(context.summary_base_url, issue_date)
    context.logger.info("Fetching BOPB dated summary PDF: %s", summary_url)
    try:
        summary_pdf = request_bytes(summary_url, timeout_seconds=context.timeout_seconds, headers=PDF_HEADERS)
    except requests.HTTPError as exc:
        status_code = exc.response.status_code if exc.response is not None else None
        if issue_date.weekday() >= 5 and status_code in {404, 500}:
            raise NoIssuePublished(
                f"BOPB returned HTTP {status_code} for weekend date {issue_date.isoformat()}, treating as no publication day."
            ) from exc
        raise

    refs = parse_summary_pdf_notice_refs(summary_pdf, base_url=context.base_url, timeout_seconds=context.timeout_seconds)
    if refs:
        return refs

    if issue_date == date.today():
        context.logger.warning("Summary PDF yielded no notice refs for %s, trying current-day feed fallback.", issue_date)
        feed_xml = request_bytes(context.feed_url, timeout_seconds=context.timeout_seconds)
        refs = extract_notice_refs_from_feed(feed_xml, base_url=context.base_url)
        if refs:
            return refs

    raise RuntimeError(f"Could not extract BOPB notice references for {issue_date.isoformat()}.")


def extract_notice_title(soup: BeautifulSoup) -> str:
    for selector in ("h1", "h1.page-title", "header h1"):
        node = soup.select_one(selector)
        if node:
            text = normalize_text(node.get_text(" ", strip=True))
            if text:
                return text
    title_node = soup.find("title")
    return normalize_text(title_node.get_text(" ", strip=True) if title_node else "")


def extract_notice_metadata(soup: BeautifulSoup) -> dict[str, str]:
    metadata: dict[str, str] = {}

    for dt in soup.select("dl dt"):
        label = normalize_text(dt.get_text(" ", strip=True)).rstrip(":")
        if not label:
            continue
        dd = dt.find_next_sibling("dd")
        if dd is None:
            continue
        value = normalize_text(dd.get_text(" ", strip=True))
        if value:
            metadata[label] = value

    for node in soup.select("li, p, div"):
        text = normalize_text(node.get_text(" ", strip=True))
        if ":" not in text or len(text) > 300:
            continue
        left, right = text.split(":", 1)
        left = left.strip()
        right = right.strip()
        if left and right and left not in metadata:
            metadata[left] = right

    return metadata


def pick_metadata(metadata: dict[str, str], *keys: str) -> str:
    normalized = {k.strip().lower(): v for k, v in metadata.items()}
    for key in keys:
        value = normalized.get(key.strip().lower(), "")
        if value:
            return value.strip()
    return ""


def extract_pdf_url_from_soup(soup: BeautifulSoup, *, base_url: str, notice_id: str) -> str:
    for anchor in soup.select("a[href]"):
        href = (anchor.get("href") or "").strip()
        if not href:
            continue
        if "/anunci/descarrega-pdf/" in href or "/anuncio/descargar-pdf/" in href:
            return urljoin(base_url.rstrip("/") + "/", href.lstrip("/"))

    return f"{base_url.rstrip('/')}/anunci/descarrega-pdf/{notice_id}"


def iter_content_blocks(container: BeautifulSoup) -> Iterable[str]:
    for node in container.select("h1, h2, h3, h4, h5, h6, p, ul, ol"):
        if node.find_parent(["aside", "nav", "footer", "header"]):
            continue
        if node.name in {"ul", "ol"}:
            items = [normalize_text(item.get_text(" ", strip=True)) for item in node.find_all("li", recursive=False)]
            items = [item for item in items if item]
            if not items:
                continue
            prefix = "1." if node.name == "ol" else "-"
            yield "\n".join(f"{prefix} {item}" for item in items)
            continue
        text = normalize_text(node.get_text(" ", strip=True))
        if text:
            yield text


def extract_markdown_content_from_html(soup: BeautifulSoup) -> str:
    root = soup.select_one("main") or soup.select_one("article") or soup.select_one("#content") or soup.body
    if root is None:
        return ""
    blocks = list(iter_content_blocks(root))
    markdown = "\n\n".join(blocks).strip()
    if len(markdown) > 200_000:
        markdown = markdown[:200_000].rstrip() + "\n\n[TRUNCATED]"
    return markdown


def fetch_notice_payload(context: CrawlerContext, notice_ref: NoticeRef) -> tuple[str, str, str, str, dict[str, object]]:
    html = request_text(notice_ref.notice_url, timeout_seconds=context.timeout_seconds)
    soup = BeautifulSoup(html, "html.parser")

    title = extract_notice_title(soup) or notice_ref.label or notice_ref.notice_url
    metadata = extract_notice_metadata(soup)
    department = pick_metadata(metadata, "Anunciante", "Anunciant")
    category = pick_metadata(metadata, "Tipo de anuncio", "Tipus d'anunci")
    publish_date_raw = pick_metadata(metadata, "Fecha de publicación", "Data de publicació")
    registration = pick_metadata(metadata, "Registro", "Registre")
    eli = pick_metadata(metadata, "Enlace ELI", "Enllaç ELI")
    pdf_url = extract_pdf_url_from_soup(soup, base_url=context.base_url, notice_id=notice_ref.notice_id)

    issue_date_parsed: str | None = None
    if publish_date_raw:
        try:
            issue_date_parsed = parse_es_date(publish_date_raw).isoformat()
        except Exception:
            issue_date_parsed = None

    pdf_text_markdown = ""
    pdf_text_error: str | None = None
    if pdf_url and not context.no_pdf_text:
        try:
            pdf_bytes = request_bytes(pdf_url, timeout_seconds=context.timeout_seconds, headers=PDF_HEADERS)
            pdf_text_markdown = extract_pdf_text_markdown(pdf_bytes, timeout_seconds=30)
        except (requests.RequestException, subprocess.TimeoutExpired, subprocess.CalledProcessError, OSError) as exc:
            pdf_text_error = f"{type(exc).__name__}: {exc!s}"

    if pdf_text_markdown:
        parts: list[str] = []
        parts.append(f"- PDF: {pdf_url}")
        if eli:
            parts.append(f"- ELI: {eli}")
        if registration:
            parts.append(f"- Registro: {registration}")
        parts.append("")
        parts.append(pdf_text_markdown)
        content = "\n".join(parts).strip()
        content_format = "pdf_markdown"
    else:
        content = extract_markdown_content_from_html(soup)
        if pdf_url:
            content = (f"- PDF: {pdf_url}\n\n{content}" if content else f"- PDF: {pdf_url}").strip()
        if pdf_text_error:
            content = (content + f"\n\n[PDF text extraction failed: {pdf_text_error}]").strip()
        content_format = "html_markdown"

    parsed = urlparse(notice_ref.notice_url)
    stable_url = f"{parsed.scheme}://{parsed.netloc}{parsed.path}".rstrip("/") or notice_ref.notice_url
    extra_info = {
        "registration": registration,
        "eli": eli,
        "publication_date": publish_date_raw,
        "publication_date_parsed": issue_date_parsed,
        "pdf_url": pdf_url,
        "pdf_text_extracted": bool(pdf_text_markdown),
        "pdf_text_error": pdf_text_error,
        "notice_id": notice_ref.notice_id,
        "metadata": {k: metadata[k] for k in list(metadata)[:60]},
        "content_format": content_format,
    }
    return title, category, department, stable_url, content, extra_info


def process_day(context: CrawlerContext, issue_date: date) -> None:
    try:
        notice_refs = extract_notice_refs_for_day(context, issue_date)
    except NoIssuePublished as exc:
        context.logger.info("%s", exc)
        return

    context.logger.info("Processing BOPB day %s with %d notice refs.", issue_date.isoformat(), len(notice_refs))
    daily_journal_id = upsert_daily_journal(context, issue_date=issue_date, notice_count=len(notice_refs))

    for index, notice_ref in enumerate(notice_refs, start=1):
        if context.max_notices > 0 and context.processed_notices >= context.max_notices:
            context.logger.info("Reached --max-notices=%d, stopping crawl.", context.max_notices)
            return

        context.logger.info(
            "Fetching BOPB notice (%d/%d): id=%s url=%s",
            index,
            len(notice_refs),
            notice_ref.notice_id,
            notice_ref.notice_url,
        )
        title, category, department, url, content, extra_info = fetch_notice_payload(context, notice_ref)
        upsert_notice(
            context,
            daily_journal_id=daily_journal_id,
            title=title,
            category=category,
            department=department,
            url=url,
            content=content,
            extra_info=json.dumps(extra_info, ensure_ascii=False),
        )
        context.processed_notices += 1


def main() -> int:
    load_dotenv(PROJECT_ROOT / ".env", override=False)
    args = parse_args()

    run_dir = PROJECT_ROOT / "storage" / "crawlers" / args.slug / args.run_id
    run_dir.mkdir(parents=True, exist_ok=True)

    logger = build_logger("crawler.bopb", run_dir / "crawler.log")
    logger.info("Starting BOPB crawler for slug=%s", args.slug)
    logger.info("Run directory: %s", run_dir)
    if args.headless or args.headed:
        logger.info("Ignoring --headless/--headed: BOPB crawler is now browserless.")

    db_conn = None
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
        summary_base_url = (args.summary_base_url or f"{base_url.rstrip('/')}/sumario-del-dia").strip()
        feed_url = (args.feed_url or DEFAULT_FEED_URL).strip()

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
            logger.info(
                "Nothing to crawl for BOPB: from_date=%s is after today=%s",
                from_date.isoformat(),
                to_date.isoformat(),
            )
            return 0

        context = CrawlerContext(
            logger=logger,
            slug=args.slug,
            source_id=source_id,
            base_url=base_url,
            summary_base_url=summary_base_url,
            feed_url=feed_url,
            timeout_seconds=args.timeout,
            max_notices=max(0, int(args.max_notices)),
            from_date=from_date,
            to_date=to_date,
            db_conn=db_conn,
            no_pdf_text=bool(args.no_pdf_text),
        )

        current_day = from_date
        while current_day <= to_date:
            process_day(context, current_day)
            if context.max_notices > 0 and context.processed_notices >= context.max_notices:
                break
            current_day += timedelta(days=1)

        logger.info("BOPB crawler finished. Total processed notices=%d", context.processed_notices)
        return 0
    except Exception as exc:  # pragma: no cover
        logger.exception("Crawler failed: %s: %r", type(exc).__name__, exc)
        return 1
    finally:
        if db_conn is not None:
            db_conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
