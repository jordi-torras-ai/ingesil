# User manuals

This project uses Playwright CLI to capture screenshots and Markdown manuals to document the flow.

## Structure

- Manuals: `docs/manuals/<slug>.md`
- Screenshots: `output/playwright/<slug>/`

## Start a new manual

Run:

```bash
bash scripts/manual-scaffold.sh smart-search "Smart Search"
```

This creates:

- `docs/manuals/smart-search.md`
- `output/playwright/smart-search/`

## Playwright setup

```bash
export CODEX_HOME="${CODEX_HOME:-$HOME/.codex}"
export PWCLI="$CODEX_HOME/skills/playwright/scripts/playwright_cli.sh"
```

## Typical capture flow

Open the app:

```bash
"$PWCLI" open http://127.0.0.1:8000/admin --headed
```

Take a snapshot before every interaction:

```bash
"$PWCLI" snapshot
```

Capture screenshots step by step:

```bash
"$PWCLI" screenshot output/playwright/smart-search/01-home.png
"$PWCLI" click e12
"$PWCLI" snapshot
"$PWCLI" screenshot output/playwright/smart-search/02-open-smart-search.png
```

Re-run `snapshot` after navigation, modal changes, or any substantial DOM update.

## Writing convention

For each manual:

- one task-oriented section per user flow
- one screenshot per meaningful UI state
- sequential filenames: `01-...png`, `02-...png`, `03-...png`
- concise step text, imperative voice

## Recommended loop

1. scaffold the manual
2. capture screenshots with Playwright
3. fill the Markdown template
4. review image order and wording

