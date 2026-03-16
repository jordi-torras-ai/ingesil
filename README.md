# Ingesil

Ingesil is a Laravel + Filament application for regulatory intelligence and company-specific compliance workflows.

At a high level, it does four things:

1. Crawls official publication sources and stores notices.
2. Runs an English-only general AI analysis per scope and issue date.
3. Runs company-specific AI analysis per company subscription (`scope + language`).
4. Lets company users review applicable notices, track compliance work, search the notice corpus, and receive digest emails.

## Current status

The application currently supports:

- Official source crawling for:
  - `dogc`
  - `boe`
  - `ojeu`
  - `bopb`
- AI-based notice screening by regulatory scope
- AI-based company applicability analysis
- Company subscriptions by `scope + language`
- Company feature-answer profiles used in AI prompts
- Semantic smart search over embedded notices
- Email digests with per-user notification preferences
- Admin-only global activity log

---

## Product model

### Main entities

- `sources`
  - Official publication sources such as BOE, DOGC, OJEU, BOPB.
- `daily_journals`
  - One issue per source and date.
- `notices`
  - Individual legal/publication records within a daily journal.
- `scopes`
  - Regulatory domains, for example `environment_industrial_safety`.
- `features`
  - Structured company profile questions attached to a scope.
- `companies`
  - Customer companies.
- `company_scope_subscriptions`
  - Commercial subscription units. Each row is one `company + scope + locale`.
- `company_feature_answers`
  - Company answers to scope features.

### AI workflow

#### Stage 1: general notice analysis

- Table roots:
  - `notice_analysis_runs`
  - `notice_analyses`
- Runs once per:
  - `scope + issue_date`
- Output locale is always English.
- This stage decides whether a notice should be:
  - `send`
  - `ignore`

#### Stage 2: company notice analysis

- Table roots:
  - `company_notice_analysis_runs`
  - `company_notice_analyses`
- Runs once per:
  - `company_scope_subscription + notice_analysis_run`
- Only Stage 1 notices with decision `send` are evaluated.
- Output locale is the subscription language.
- This stage decides whether a notice is:
  - `relevant`
  - `not_relevant`

### Human workflow

For company users:

- AI marks a company notice as `relevant` or `not_relevant`.
- If AI says `relevant`, users review it.
- User review state is stored in `confirmed_relevant`:
  - `null` = pending review
  - `true` = confirmed relevant
  - `false` = confirmed not relevant
- Once confirmed relevant, users can complete compliance fields such as:
  - compliance
  - compliance evaluation
  - compliance date
  - action plan

### Notification workflow

Each user has digest preferences:

- `daily`
- `weekly`
- `monthly`
- `never`

Digests are:

- one email per user
- grouped across all companies the user can access
- sent in the user’s preferred locale
- sent only when there is something to report

Current trigger logic:

- pending tasks:
  - `decision = relevant AND confirmed_relevant IS NULL`
- new relevant notices:
  - AI-relevant notices added since the digest window
- completed items:
  - notices marked compliant
  - notices marked not relevant by a user

Completed items are included in the digest, but do not trigger a digest by themselves.

---

## Roles

### Platform admin

Internal operator role.

Can manage:

- companies
- subscriptions
- users
- scopes
- features
- sources
- crawlers
- notice analyses
- company analyses
- activity log
- digest run history

### Company admin

Customer-side admin for one or more companies.

Can manage:

- users in their own companies
- notification preferences for those users
- company profile / feature answers
- compliance review data

Cannot manage:

- subscriptions
- sources
- scopes catalog
- global analysis operations

### Regular user

Customer-side operational user.

Can access:

- their own companies
- compliance notices
- smart search
- personal notification settings

Cannot manage:

- other users
- subscriptions
- catalog / source administration

---

## Tech stack

- PHP `^8.2`
- Laravel `^12`
- Filament `^3`
- Filament Breezy for profile / 2FA
- PostgreSQL
- OpenAI API for:
  - notice analysis
  - company analysis
  - embeddings
  - smart search answering
- Python crawlers
- Vite / Tailwind frontend assets

### PostgreSQL notes

The app assumes PostgreSQL. Smart search uses vector similarity on notice embeddings, so the database must support vector storage and search accordingly.

If migrations fail on managed PostgreSQL with ownership errors such as:

- `must be owner of table ...`

make sure the Laravel DB user owns the application tables. On DigitalOcean-managed PostgreSQL, this may require reconnecting as the admin role and reassigning ownership.

---

## Repository structure

### Laravel application

- `app/`
- `config/`
- `database/`
- `resources/`
- `routes/`

### Python crawler layer

- `python/run_crawler.py`
- `python/crawlers/`
- `python/src/ingesil_crawlers/`

### Prompt files

- General notice analysis:
  - `resources/ai-prompts/notice-analysis/<scope-code>/system.md`
  - `resources/ai-prompts/notice-analysis/<scope-code>/user.md`
- Company notice analysis:
  - `resources/ai-prompts/company-notice-analysis/<scope-code>/system.md`
  - `resources/ai-prompts/company-notice-analysis/<scope-code>/user.md`

Legacy fallback still exists for the general `environment_industrial_safety` scope.

---

## Local setup

### Requirements

- PHP 8.2+
- Composer
- Node.js + npm
- PostgreSQL
- Python 3 with `venv`
- `pdftotext`
- `pdftohtml`

### Install PHP and JS dependencies

From project root:

```bash
composer install
npm install
```

### Environment file

Create `.env` if needed:

```bash
cp .env.example .env
php artisan key:generate
```

### Database setup

```bash
php artisan migrate --force
php artisan db:seed --force
```

### Python environment

For local development, use the Python virtualenv required by this repository:

```bash
source ~/myenv/bin/activate
pip install -r python/requirements.txt
```

For production pipelines, the app expects:

- `.venv/bin/python`

and installs crawler dependencies there.

### Frontend assets

```bash
npm run build
```

---

## Local development workflow

### Start the app

Use the provided wrapper:

```bash
./run.sh
```

`run.sh` does the following:

- runs migrations by default
- runs seeders by default
- flushes queued and failed jobs on startup
- starts queue workers
- starts `php artisan serve`
- flushes queued and failed jobs again on shutdown

Important environment knobs:

- `QUEUE_WORKERS`
- `QUEUE_NAMES`
- `APP_HOST`
- `APP_PORT`
- `RUN_MIGRATIONS`
- `RUN_SEEDERS`
- `MIGRATE_FRESH`

### Tests

```bash
php artisan test
```

### Syntax / lint

```bash
php -l path/to/file.php
```

---

## Configuration

### Key Laravel config

#### Pipeline

Defined in `config/app.php`.

- `PIPELINE_DAILY_ENABLED`
- `PIPELINE_DAILY_TIME`
- `PIPELINE_TIMEZONE`
- `CRAWLER_COMMAND_TIMEOUT_SECONDS`

#### Notifications

Also in `config/app.php`.

- `NOTICE_DIGEST_TIME`
- `NOTICE_DIGEST_TIMEZONE`

#### OpenAI

Defined in `config/services.php`.

Important variables:

- `OPENAI_API_KEY`
- `OPENAI_BASE_URL`
- `OPENAI_API_MODEL`
- `OPENAI_HTTP_TIMEOUT`
- `OPENAI_MAX_COMPLETION_TOKENS`
- `OPENAI_NOTICE_ANALYSIS_QUEUE`
- `OPENAI_COMPANY_NOTICE_ANALYSIS_QUEUE`
- `OPENAI_EMBEDDING_MODEL`
- `OPENAI_EMBEDDING_QUEUE`

---

## Crawlers

### Source slugs

The daily pipeline discovers sources by `sources.slug`.

Current supported crawlers:

- `dogc`
- `boe`
- `ojeu`
- `bopb`

### General notes

- `python/run_crawler.py` dispatches to `python/crawlers/crawler_<slug>.py`.
- After a successful run, it can dispatch embedding jobs automatically.
- Browser compatibility flags are still accepted by some crawlers, but the current main crawlers are browserless.

### Run one crawler manually

Example:

```bash
source ~/myenv/bin/activate
python python/run_crawler.py boe --day 2026-03-13
```

Skip embeddings:

```bash
source ~/myenv/bin/activate
python python/run_crawler.py boe --day 2026-03-13 --skip-embeddings
```

### Crawler behavior by source

#### DOGC

- Uses DOGC APIs
- No browser required

#### BOE

- Uses BOE open-data summary API
- No browser required

#### OJEU

- Uses Publications Office SPARQL + direct HTTP fetches
- No browser required

#### BOPB

- Uses dated summary PDFs plus direct notice/PDF fetches
- No browser required
- Weekend/no-publication days may return `500` from the remote site; the crawler now treats weekend `404/500` as “no issue published”

### Crawler artifacts

Stored under:

- `storage/crawlers/<slug>/<run_id>/`

---

## Embeddings and smart search

### Embeddings

Compute missing or stale notice embeddings:

```bash
php artisan notices:embed --stale
```

Restrict by source and date:

```bash
php artisan notices:embed --stale --source-slug=dogc --issue-date=2026-03-05
```

Run synchronously:

```bash
php artisan notices:embed --stale --sync
```

### Smart search

Smart search is available in the Filament UI and is backed by:

- notice embeddings
- semantic vector search
- optional answer generation over the retrieved notice set

---

## Notice analysis pipeline

### Full daily pipeline

Run crawlers for one date, then dispatch English general notice analyses:

```bash
php artisan pipeline:daily-notices --date=2026-03-13 --continue-on-crawler-error
```

What it does:

1. Runs all crawlers for the target date
2. Creates one English Stage 1 analysis run per active, prompt-ready scope
3. Queues notice analysis jobs
4. Automatically dispatches Stage 2 company analysis when Stage 1 completes

### Analysis only

If crawling is already done and you only want Stage 1 + Stage 2:

```bash
php artisan notice-analyses:run --date=2026-03-13
```

Restrict to one scope:

```bash
php artisan notice-analyses:run --date=2026-03-13 --scopes=environment_industrial_safety
```

### Queue behavior

Dispatch is asynchronous.

The command returning quickly means:

- runs were created
- jobs were queued
- queue workers now need to process them

### Stage 1

- Input: crawled notices for one issue date
- Output: `send` / `ignore`
- Scope-specific prompt files required
- Locale forced to English

### Stage 2

- Triggered from completed Stage 1 runs
- One run per company subscription (`company + scope + locale`)
- Only Stage 1 `send` notices are evaluated
- Output: `relevant` / `not_relevant`

---

## Company subscriptions and feature answers

### Subscription model

Subscriptions are stored as:

- `company_scope_subscriptions`

Each subscription represents:

- one company
- one scope
- one locale

This is the commercial unit the customer pays for.

### Feature answers

Feature answers store company-specific profile data used in Stage 2 prompts.

### Import company feature answers

Import from TSV:

```bash
php artisan companies:import-feature-answers 3 environment_industrial_safety database/data/company-feature-imports/ingecal-environment-industry-safety.tsv --locale=es
```

Dry run:

```bash
php artisan companies:import-feature-answers 3 environment_industrial_safety database/data/company-feature-imports/ingecal-environment-industry-safety.tsv --locale=es --dry-run
```

---

## Email digests

### User preferences

Each user can configure:

- frequency
  - `daily`
  - `weekly`
  - `monthly`
  - `never`
- send when pending tasks exist
- send when new relevant notices exist

Defaults:

- frequency = `weekly`
- weekly digests run Monday morning
- monthly digests run on the first day of the month

Company admins can configure other users in their own companies. Users can also configure their own settings from the UI.

### Send digests manually

Dry run:

```bash
php artisan notice-digests:send --pretend --force
```

Send now regardless of schedule:

```bash
php artisan notice-digests:send --force
```

Restrict to one user:

```bash
php artisan notice-digests:send --user=1 --force
```

### Digest run log

Admins can review digest executions via the `NotificationDigestRun` resource in Filament.

---

## Prompt management

Each active scope must have prompt files present before it can be activated.

### General analysis prompts

Expected path pattern:

- `resources/ai-prompts/notice-analysis/<scope-code>/system.md`
- `resources/ai-prompts/notice-analysis/<scope-code>/user.md`

### Company analysis prompts

Expected path pattern:

- `resources/ai-prompts/company-notice-analysis/<scope-code>/system.md`
- `resources/ai-prompts/company-notice-analysis/<scope-code>/user.md`

The admin UI exposes prompt readiness for both prompt layers.

---

## Filament UI overview

### Key admin resources

- Companies
- Users
- Scopes
- Features
- Sources
- Daily Journals
- Notices
- Notice Analysis Runs
- Notice Analyses
- Company Analysis Runs
- Company Analyses
- Activity Log
- Email Digest Runs

### Key pages

- Dashboard
- Smart Search
- Language
- Email Notifications
- Profile / 2FA

### Dashboard behavior

- Platform admins see a broad operational dashboard.
- Company admins and regular users see subscription/compliance KPIs for their accessible companies.
- Pending notices are first-class in the UI through:
  - dashboard KPIs
  - list filters
  - navigation badges

---

## Activity logging

There are two audit layers:

### Record-level business history

For company notice analyses:

- `company_notice_analysis_events`
- visible from the record

### Global admin audit trail

Provided through:

- `spatie/laravel-activitylog`
- admin-only `Activity Log` resource

This tracks important write operations across models such as:

- users
- companies
- subscriptions
- feature answers
- analyses
- catalog records

---

## Scheduled tasks

Defined in `routes/console.php`.

### Daily pipeline

Scheduled command:

```bash
php artisan pipeline:daily-notices --headless --continue-on-crawler-error
```

### Email digests

Scheduled command:

```bash
php artisan notice-digests:send
```

The scheduler runs the digest command daily, and the command itself decides whether each user is due.

---

## Production deployment notes

### Standard deploy checklist

```bash
php artisan migrate --force
php artisan optimize:clear
```

Make sure:

- queue workers are running
- the Laravel scheduler is running
- the Python crawler virtualenv exists at `.venv`
- crawler dependencies are installed:

```bash
.venv/bin/python -m pip install -r python/requirements.txt
```

### PostgreSQL ownership

If migrations fail with:

- `must be owner of table ...`

the Laravel DB user does not own the table. Reassign ownership from the admin role to the app role before rerunning migrations.

### Production smoke checks

- `php artisan notice-digests:send --pretend --force`
- verify dashboard loads
- verify activity log loads
- verify email notification page loads
- verify one Stage 1 and one Stage 2 run can process successfully

---

## Troubleshooting

### Pipeline runs return immediately

That is normal. Dispatch commands are asynchronous. Check queue workers and run progress tables.

### Stage 1 runs are stuck in `queued` or `processing`

Check:

- queue workers
- `jobs` table
- `failed_jobs`

### Company analyses have `decision = null`

Usually means:

- jobs are still running
- or the queue is backed up

Check:

- `company_notice_analyses.status`
- `jobs`
- `failed_jobs`

### BOPB weekend crawl fails with `500`

The remote site returns `500` for some weekend/no-publication days. The crawler now treats weekend `404/500` as “no issue published”.

### Production crawlers fail with missing Python modules

Install requirements into the same Python interpreter the pipeline uses:

```bash
.venv/bin/python -m pip install -r python/requirements.txt
```

### Filtered work queue behavior

Company notice review is based on:

- AI relevant
- human confirmation pending

Pending review means:

- `decision = relevant`
- `confirmed_relevant IS NULL`

It does not include notices AI already marked `not_relevant`.

---

## Useful commands

### Full pipeline

```bash
php artisan pipeline:daily-notices --date=2026-03-13 --continue-on-crawler-error
```

### Analysis only

```bash
php artisan notice-analyses:run --date=2026-03-13
```

### Embed notices

```bash
php artisan notices:embed --stale
```

### Import company answers

```bash
php artisan companies:import-feature-answers 3 environment_industrial_safety database/data/company-feature-imports/ingecal-environment-industry-safety.tsv --locale=es
```

### Send notice digests

```bash
php artisan notice-digests:send --pretend --force
```

### Run one crawler

```bash
source ~/myenv/bin/activate
python python/run_crawler.py boe --day 2026-03-13
```

---

## License

This repository is private application code. See internal project ownership and deployment practices rather than the default Laravel upstream license text.
