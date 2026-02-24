#!/usr/bin/env bash

if [ -z "${BASH_VERSION:-}" ]; then
  exec bash "$0" "$@"
fi

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

PYTHON_BIN="${ROOT_DIR}/.venv/bin/python"
RUNNER="${ROOT_DIR}/python/run_crawler.py"
CRAWLERS_DIR="${ROOT_DIR}/python/crawlers"
CRAWLER_RUNS_DIR="${ROOT_DIR}/storage/crawlers"

if [[ ! -x "$PYTHON_BIN" ]]; then
  echo "Python venv not found or not executable: $PYTHON_BIN"
  exit 1
fi

if [[ ! -f "$RUNNER" ]]; then
  echo "Crawler runner not found: $RUNNER"
  exit 1
fi

if [[ ! -d "$CRAWLERS_DIR" ]]; then
  echo "Crawlers directory not found: $CRAWLERS_DIR"
  exit 1
fi

echo "Pruning crawler logs older than 48 hours..."
if [[ -d "$CRAWLER_RUNS_DIR" ]]; then
  OLD_RUN_DIRS=()
  while IFS= read -r line; do
    OLD_RUN_DIRS+=("$line")
  done < <(find "$CRAWLER_RUNS_DIR" -mindepth 2 -maxdepth 2 -type d -mmin +2880 | sort)
  if [[ "${#OLD_RUN_DIRS[@]}" -eq 0 ]]; then
    echo "No old crawler logs to delete."
  else
    for old_dir in "${OLD_RUN_DIRS[@]}"; do
      echo "Deleting old crawler run: $old_dir"
      rm -rf "$old_dir"
    done
  fi
else
  echo "Crawler runs directory not found, skipping prune: $CRAWLER_RUNS_DIR"
fi

CRAWLER_SCRIPTS=()
while IFS= read -r line; do
  CRAWLER_SCRIPTS+=("$line")
done < <(find "$CRAWLERS_DIR" -maxdepth 1 -type f -name 'crawler_*.py' | sort)

if [[ "${#CRAWLER_SCRIPTS[@]}" -eq 0 ]]; then
  echo "No crawler scripts found in $CRAWLERS_DIR"
  exit 0
fi

echo "Running crawlers in headless mode..."

FAILED=0
for script in "${CRAWLER_SCRIPTS[@]}"; do
  slug="$(basename "$script")"
  slug="${slug#crawler_}"
  slug="${slug%.py}"

  echo
  echo "=== Running crawler: $slug (headless) ==="
  if ! "$PYTHON_BIN" "$RUNNER" "$slug" --headless; then
    echo "Crawler failed: $slug"
    FAILED=1
  else
    echo "Crawler finished: $slug"
  fi
done

if [[ "$FAILED" -ne 0 ]]; then
  echo
  echo "One or more crawlers failed."
  exit 1
fi

echo
echo "All crawlers completed successfully."
