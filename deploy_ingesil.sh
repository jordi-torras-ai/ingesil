#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- Config (override via env if needed) ---
APP_DIR="${APP_DIR:-$SCRIPT_DIR/ingesil}"
APP_USER="${APP_USER:-www-data}"
BRANCH="${BRANCH:-main}"
SERVICE_NAME="${SERVICE_NAME:-apache2}"
DB_SUPERUSER="${DB_SUPERUSER:-postgres}"

# --- Helpers ---
require_root() {
  if [[ "$(id -u)" -ne 0 ]]; then
    echo "This script must be run as root (use sudo)."
    exit 1
  fi
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1"
    exit 1
  }
}

as_app() {
  sudo -u "$APP_USER" -H bash -lc "$*"
}

db_query() {
  sudo -u "$DB_SUPERUSER" psql -v ON_ERROR_STOP=1 -tAc "$1"
}

ensure_app_dir() {
  if [[ ! -d "$APP_DIR" ]]; then
    echo "App directory not found: $APP_DIR"
    exit 1
  fi
}

load_env() {
  if [[ ! -f ".env" ]]; then
    echo ".env missing in $APP_DIR. Create it first (cp .env.example .env)."
    exit 1
  fi

  DB_CONNECTION="$(grep -E '^DB_CONNECTION=' .env | cut -d '=' -f2- || true)"
  DB_DATABASE="$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2- || true)"
  DB_USERNAME="$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2- || true)"
  DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2- || true)"

  if [[ "$DB_CONNECTION" != "pgsql" ]]; then
    echo "This deploy script expects DB_CONNECTION=pgsql. Found: ${DB_CONNECTION:-<empty>}"
    exit 1
  fi

  if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" || -z "$DB_PASSWORD" ]]; then
    echo "DB_DATABASE, DB_USERNAME, and DB_PASSWORD must be set in .env."
    exit 1
  fi
}

ensure_db() {
  local db_name="$1"
  local db_user="$2"
  local db_pass="$3"

  echo "Ensuring PostgreSQL role/database exist..."

  local user_exists
  user_exists="$(db_query "SELECT 1 FROM pg_roles WHERE rolname='${db_user}' LIMIT 1;" || true)"
  if [[ "$user_exists" != "1" ]]; then
    db_query "CREATE ROLE \"${db_user}\" LOGIN PASSWORD '${db_pass//\'/\'\'}';" >/dev/null
    echo "Created role: ${db_user}"
  else
    db_query "ALTER ROLE \"${db_user}\" WITH LOGIN PASSWORD '${db_pass//\'/\'\'}';" >/dev/null
    echo "Role already existed. Password refreshed: ${db_user}"
  fi

  local db_exists
  db_exists="$(db_query "SELECT 1 FROM pg_database WHERE datname='${db_name}' LIMIT 1;" || true)"
  if [[ "$db_exists" != "1" ]]; then
    sudo -u "$DB_SUPERUSER" createdb -O "$db_user" "$db_name"
    echo "Created database: ${db_name}"
  else
    db_query "ALTER DATABASE \"${db_name}\" OWNER TO \"${db_user}\";" >/dev/null
    echo "Database already existed: ${db_name}"
  fi
}

prepare_known_hosts() {
  if [[ ! -d /var/www/.ssh ]]; then
    mkdir -p /var/www/.ssh
    chown "$APP_USER:$APP_USER" /var/www/.ssh
    chmod 700 /var/www/.ssh
  fi

  if [[ ! -f /var/www/.ssh/known_hosts ]] || ! grep -q 'github.com' /var/www/.ssh/known_hosts; then
    as_app "ssh-keyscan -H github.com >> /var/www/.ssh/known_hosts"
    chmod 600 /var/www/.ssh/known_hosts
    chown "$APP_USER:$APP_USER" /var/www/.ssh/known_hosts
  fi
}

# --- Start ---
require_root
need_cmd git
need_cmd composer
need_cmd php
need_cmd sudo
need_cmd psql
need_cmd systemctl

ensure_app_dir
cd "$APP_DIR"
load_env

echo "--------------------------------------------"
echo "Deploying Ingesil (branch: $BRANCH)"
echo "App dir: $APP_DIR"
echo "App user: $APP_USER"
echo "DB: $DB_DATABASE (user: $DB_USERNAME)"

# Ensure ownership for deploy-critical paths
chown -R "$APP_USER:$APP_USER" "$APP_DIR/.git" || true
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true
chown "$APP_USER:$APP_USER" "$APP_DIR/.env" || true

prepare_known_hosts
ensure_db "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD"

echo "--------------------------------------------"
echo "Pulling latest code..."
as_app "git config --local safe.directory '$APP_DIR' || true"
as_app "git fetch --all"
as_app "git checkout '$BRANCH'"
as_app "git pull origin '$BRANCH'"

echo "--------------------------------------------"
echo "Installing composer dependencies..."
as_app "composer install --no-dev --optimize-autoloader --no-interaction"

if [[ -f "package.json" ]]; then
  echo "Installing/building frontend assets..."
  if command -v npm >/dev/null 2>&1; then
    as_app "npm ci"
    as_app "npm run build"
  else
    echo "npm not found, skipping asset build."
  fi
fi

echo "--------------------------------------------"
echo "Running Laravel tasks..."
as_app "php artisan key:generate --force || true"
as_app "php artisan storage:link || true"
as_app "php artisan migrate --force"
as_app "php artisan db:seed --force || true"
as_app "php artisan optimize:clear"
as_app "php artisan optimize"
as_app "php artisan queue:restart || true"
as_app "php artisan schedule:run || true"

echo "--------------------------------------------"
echo "Reloading service: $SERVICE_NAME"
systemctl reload "$SERVICE_NAME" || systemctl restart "$SERVICE_NAME"

echo "--------------------------------------------"
echo "Ingesil deployment complete."
