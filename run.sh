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

QUEUE_PIDS=()
WEB_PID=""
IS_CLEANED_UP=0

print_queue_snapshot() {
  local label="$1"
  echo "$label"
  php artisan tinker --execute="
\$pending = DB::table('jobs')
  ->selectRaw('queue, count(*) as total')
  ->groupBy('queue')
  ->orderBy('queue')
  ->get()
  ->map(fn (\$row) => ['queue' => \$row->queue, 'total' => (int) \$row->total])
  ->all();

\$failed = DB::table('failed_jobs')
  ->selectRaw('count(*) as total')
  ->first();

\$pendingNames = DB::table('jobs')
  ->orderBy('id')
  ->limit(10)
  ->get(['id', 'queue', 'payload'])
  ->map(fn (\$row) => [
    'id' => \$row->id,
    'queue' => \$row->queue,
    'job' => data_get(json_decode(\$row->payload, true), 'displayName'),
  ])
  ->all();

\$failedNames = DB::table('failed_jobs')
  ->orderByDesc('id')
  ->limit(10)
  ->get(['id', 'failed_at', 'payload'])
  ->map(fn (\$row) => [
    'id' => \$row->id,
    'failed_at' => \$row->failed_at,
    'job' => data_get(json_decode(\$row->payload, true), 'displayName'),
  ])
  ->all();

dump([
  'pending_by_queue' => \$pending,
  'pending_examples' => \$pendingNames,
  'failed_total' => (int) (\$failed->total ?? 0),
  'failed_examples' => \$failedNames,
]);
"
}

flush_queue_state() {
  local phase="$1"
  echo
  echo "Queue cleanup ($phase)..."
  print_queue_snapshot "Queue state before cleanup:"
  php artisan queue:restart || true
  php artisan queue:clear --force || true
  php artisan queue:flush || true
  print_queue_snapshot "Queue state after cleanup:"
}

cleanup() {
  if [[ "$IS_CLEANED_UP" -eq 1 ]]; then
    return
  fi
  IS_CLEANED_UP=1

  echo
  echo "Stopping background processes..."

  if [[ -n "$WEB_PID" ]] && kill -0 "$WEB_PID" 2>/dev/null; then
    echo "Stopping web server PID $WEB_PID"
    kill -TERM "$WEB_PID" 2>/dev/null || true
  fi

  if ((${#QUEUE_PIDS[@]})); then
    for pid in "${QUEUE_PIDS[@]}"; do
      if kill -0 "$pid" 2>/dev/null; then
        echo "Stopping queue worker PID $pid"
        kill -TERM "$pid" 2>/dev/null || true
      fi
    done
  fi

  wait 2>/dev/null || true

  flush_queue_state "shutdown"
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

flush_queue_state "startup"

echo "Starting $QUEUE_WORKERS queue worker(s) for queue(s): $QUEUE_NAMES"
for _ in $(seq 1 "$QUEUE_WORKERS"); do
  php artisan queue:work --queue="$QUEUE_NAMES" --sleep=1 --tries=3 --timeout=90 &
  QUEUE_PIDS+=("$!")
done

echo "Starting web server on http://$APP_HOST:$APP_PORT"
php artisan serve --host="$APP_HOST" --port="$APP_PORT" &
WEB_PID="$!"

wait "$WEB_PID"
