#!/usr/bin/env bash

if [ -z "${BASH_VERSION:-}" ]; then
  exec bash "$0" "$@"
fi

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [[ ! -f artisan ]]; then
  echo "artisan not found in $ROOT_DIR"
  exit 1
fi

QUEUE_WORKERS="${QUEUE_WORKERS:-2}"
QUEUE_NAMES="${QUEUE_NAMES:-default}"
APP_HOST="${APP_HOST:-127.0.0.1}"
APP_PORT="${APP_PORT:-8000}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"
RUN_SEEDERS="${RUN_SEEDERS:-1}"
# Keep existing data by default; set MIGRATE_FRESH=1 when you explicitly want a full reset.
MIGRATE_FRESH="${MIGRATE_FRESH:-0}"
CLEAR_FAILED_JOBS="${CLEAR_FAILED_JOBS:-0}"

QUEUE_PIDS=()
WEB_PID=""
IS_CLEANED_UP=0

cleanup() {
  if [[ "$IS_CLEANED_UP" -eq 1 ]]; then
    return
  fi
  IS_CLEANED_UP=1

  echo
  echo "Stopping background processes..."

  if [[ -n "$WEB_PID" ]] && kill -0 "$WEB_PID" 2>/dev/null; then
    kill -TERM "$WEB_PID" 2>/dev/null || true
  fi

  if ((${#QUEUE_PIDS[@]})); then
    for pid in "${QUEUE_PIDS[@]}"; do
      if kill -0 "$pid" 2>/dev/null; then
        kill -TERM "$pid" 2>/dev/null || true
      fi
    done
  fi

  wait 2>/dev/null || true
}

trap cleanup INT TERM EXIT

echo "Preparing app..."

if [[ "$RUN_MIGRATIONS" -eq 1 ]]; then
  if [[ "$MIGRATE_FRESH" -eq 1 ]]; then
    if [[ "$RUN_SEEDERS" -eq 1 ]]; then
      php artisan migrate:fresh --seed --force
    else
      php artisan migrate:fresh --force
    fi
  else
    php artisan migrate --force
    if [[ "$RUN_SEEDERS" -eq 1 ]]; then
      php artisan db:seed --force
    fi
  fi
elif [[ "$RUN_SEEDERS" -eq 1 ]]; then
  php artisan db:seed --force
fi

echo "Resetting queue state..."
php artisan queue:restart || true
php artisan queue:clear --force || true

if [[ "$CLEAR_FAILED_JOBS" -eq 1 ]]; then
  php artisan queue:flush --force || true
fi

echo "Starting $QUEUE_WORKERS queue worker(s) for queue(s): $QUEUE_NAMES"
for _ in $(seq 1 "$QUEUE_WORKERS"); do
  php artisan queue:work --queue="$QUEUE_NAMES" --sleep=1 --tries=3 --timeout=90 &
  QUEUE_PIDS+=("$!")
done

echo "Starting web server on http://$APP_HOST:$APP_PORT"
php artisan serve --host="$APP_HOST" --port="$APP_PORT" &
WEB_PID="$!"

wait "$WEB_PID"
