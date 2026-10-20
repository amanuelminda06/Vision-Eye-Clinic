#!/usr/bin/env bash
#
# Restore the clinic database from a mysqldump SQL file.
#
#   scripts/restore.sh <dump-file>        # restores into DB_DATABASE
#   FORCE=1 scripts/restore.sh <dump>     # drops the existing tables first
#
# The target database must already exist. Requires `mysql` (see backup.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

: "${DB_DATABASE:?DB_DATABASE is not set (add it to .env or export it)}"
: "${DB_USERNAME:?DB_USERNAME is not set (add it to .env or export it)}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_PASSWORD="${DB_PASSWORD:-}"

DUMP="${1:?usage: scripts/restore.sh <dump-file>}"
[ -f "$DUMP" ] || { echo "✗ dump not found: $DUMP"; exit 1; }

if ! command -v mysql >/dev/null 2>&1; then
  echo "✗ mysql not found on PATH (install mysql-client)."
  exit 1
fi

if [ "${FORCE:-0}" = "1" ]; then
  echo "• FORCE=1: dropping the existing tables"
  TABLES="$(MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
    --batch --skip-column-names -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_DATABASE';" || true)"
  for t in $TABLES; do
    MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
      "$DB_DATABASE" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`$t\`; SET FOREIGN_KEY_CHECKS=1;"
  done
fi

echo "• restoring $DUMP → $DB_DATABASE"
gzip -dc "$DUMP" | MYSQL_PWD="$DB_PASSWORD" mysql \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE"

echo "✓ restore complete. Verify with: php artisan migrate:status"
