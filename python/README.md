# Python Crawlers

This folder contains source crawlers that map to `sources.slug`.

## Layout

- `python/run_crawler.py`: slug-based dispatcher (`crawler_<slug>.py`)
- `python/crawlers/crawler_dogc.py`: first crawler implementation (DOGC)
- `python/src/ingesil_crawlers/fsm.py`: shared FSM runner
- `python/src/ingesil_crawlers/logging_utils.py`: colored + file logging
- `python/src/ingesil_crawlers/artifacts.py`: PNG/HTML/JSON snapshot writer

## Conventions

- One crawler script per source slug:
  - `sources.slug = dogc` -> `python/crawlers/crawler_dogc.py`
- Artifact outputs for each run:
  - `storage/crawlers/<slug>/<run_id>/steps/*.png`
  - `storage/crawlers/<slug>/<run_id>/steps/*.html`
  - `storage/crawlers/<slug>/<run_id>/steps/*.json`
  - `storage/crawlers/<slug>/<run_id>/crawler.log`

## Setup

From project root:

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r python/requirements.txt
```

## Run

Headed (default, for browser-based crawlers only):

```bash
python python/run_crawler.py dogc
```

Headless:

```bash
python python/run_crawler.py dogc --headless
```

Force headed (overrides env):

```bash
python python/run_crawler.py dogc --headed
```

Override source start date (skip DB lookup):

```bash
python python/run_crawler.py dogc --headless --start-at 2026-01-01
```

Optional env knobs (read from `.env`):

- `CRAWLER_HEADLESS=1` to default to headless mode
- `CRAWLER_TIMEOUT_SECONDS=20`
- `CRAWLER_DOGC_BASE_URL=https://dogc.gencat.cat/ca` (legacy browser URL, ignored by the API crawler)
- `CRAWLER_DOGC_DAILY_BASE_URL=https://dogc.gencat.cat/ca/sumari-del-dogc/`
- `CRAWLER_DOGC_SEARCH_API_URL=https://portaldogc.gencat.cat/eadop-rest/api/dogc/searchDOGC`
- `CRAWLER_DOGC_DOCUMENT_API_URL=https://portaldogc.gencat.cat/eadop-rest/api/dogc/documentDOGC`
- `CRAWLER_BOE_SUMMARY_API_URL_TEMPLATE=https://www.boe.es/datosabiertos/api/boe/sumario/{stamp}`
- `CRAWLER_OJEU_BASE_URL=https://eur-lex.europa.eu`
- `CRAWLER_OJEU_SPARQL_ENDPOINT=https://publications.europa.eu/webapi/rdf/sparql`
- `CRAWLER_OJEU_LIMIT=500`
- `CRAWLER_BOPB_BASE_URL=https://bop.diba.cat`
- `CRAWLER_BOPB_SUMMARY_BASE_URL=https://bop.diba.cat/sumario-del-dia`
- `CRAWLER_BOPB_FEED_URL=https://bop.diba.cat/datos-abiertos/boletin-del-dia/feed`
- `CRAWLER_BOPB_MAX_NOTICES=0` (0 = unlimited, useful for debug)

## Embeddings

After a successful `python/run_crawler.py ...` run, the wrapper dispatches Laravel notice embedding jobs asynchronously for the crawled source. If `--day YYYY-MM-DD` is provided, dispatch is restricted to that source and date.

Skip this automatic dispatch when needed:

```bash
python python/run_crawler.py dogc --skip-embeddings --headless --day 2026-03-05
```

Manual backfill / requeue:

```bash
php artisan notices:embed --stale
php artisan notices:embed --stale --source-slug=dogc --issue-date=2026-03-05
```

## Browser FSM note

`python/src/ingesil_crawlers/fsm.py` remains in the project for browser-based crawlers, but DOGC, BOE, OJEU, and BOPB no longer use it.

## DOGC crawler

`python/crawlers/crawler_dogc.py` now uses the DOGC APIs instead of browser automation.

- Search endpoint:
  - `https://portaldogc.gencat.cat/eadop-rest/api/dogc/searchDOGC`
- Document endpoint:
  - `https://portaldogc.gencat.cat/eadop-rest/api/dogc/documentDOGC`
- `--headless` / `--headed` are still accepted for CLI compatibility, but ignored.

## BOE crawler

`python/crawlers/crawler_boe.py` uses the official BOE open-data summary API instead of browser automation.

- Summary endpoint template:
  - `https://www.boe.es/datosabiertos/api/boe/sumario/{stamp}`
- `stamp` format:
  - `YYYYMMDD`
- The crawler still accepts `--headless` / `--headed` for CLI compatibility, but ignores them.

## OJEU crawler

`python/crawlers/crawler_ojeu.py` now uses the Publications Office SPARQL endpoint plus direct HTTP fetches to EUR-Lex instead of browser automation.

- SPARQL endpoint:
  - `https://publications.europa.eu/webapi/rdf/sparql`
- Detail fetches:
  - `https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:...`
- `--headless` / `--headed` are still accepted for CLI compatibility, but ignored.

## BOPB crawler

`python/crawlers/crawler_bopb.py` now uses dated BOPB summary PDFs plus direct HTTP fetches instead of browser automation.

- Dated summary PDFs:
  - `https://bop.diba.cat/sumario-del-dia/YYYY-MM-DD`
- Current-day feed fallback:
  - `https://bop.diba.cat/datos-abiertos/boletin-del-dia/feed`
- Notice pages:
  - `https://bop.diba.cat/anunci/{id}`
- Notice PDFs:
  - `https://bop.diba.cat/anunci/descarrega-pdf/{id}`
- `--headless` / `--headed` are still accepted for CLI compatibility, but ignored.
