# Python Crawlers

This folder contains Selenium crawlers that map to `sources.slug`.

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

Headed (default):

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
- `CRAWLER_DOGC_BASE_URL=https://dogc.gencat.cat/ca`
- `CRAWLER_DOGC_DAILY_BASE_URL=https://dogc.gencat.cat/ca/sumari-del-dogc/`

## DOGC calendar FSM

Current flow:

1. `HOME`: open source base URL.
2. `NAVIGATE_TO_START_MONTH`: click `<` one month at a time until visible month equals `sources.start_at`.
3. `PROCESS_MONTH`: collect clickable day entries for visible month, upsert `daily_journals`, click `>` once.
4. Repeat `PROCESS_MONTH` until current month equals today's month, then finish.

No multi-click month loop inside a single state handler: month navigation progresses one FSM transition at a time.
