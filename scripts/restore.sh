#!/usr/bin/env bash
#
# Restore the ERP database from a pg_dump custom-format file.
#
#   scripts/restore.sh <dump-file>        # restores into DATABASE_URL
#   FORCE=1 scripts/restore.sh <dump>
#
# The target database must already exist and be empty (or use FORCE=1 to drop
# and recreate the public schema first). Requires `pg_restore` (see backup.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

: "${DATABASE_URL:?DATABASE_URL is not set (add it to .env or export it)}"
DUMP="${1:?usage: scripts/restore.sh <dump-file>}"
[ -f "$DUMP" ] || { echo "✗ dump not found: $DUMP"; exit 1; }

if ! command -v pg_restore >/dev/null 2>&1; then
  echo "✗ pg_restore not found on PATH (install postgresql-client)."
  exit 1
fi

if [ "${FORCE:-0}" = "1" ]; then
  if ! command -v psql >/dev/null 2>&1; then
    echo "✗ FORCE=1 needs psql to drop the schema."
    exit 1
  fi
  echo "• FORCE=1: dropping and recreating the public schema"
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -c 'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;'
fi

echo "• restoring $DUMP → $DATABASE_URL"
pg_restore --no-owner --no-privileges --clean --if-exists --dbname="$DATABASE_URL" "$DUMP"
echo "✓ restore complete. Run migrations to confirm: npm run db:deploy"