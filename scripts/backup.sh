#!/usr/bin/env bash
#
# Back up the ERP database with pg_dump (custom format).
#
#   scripts/backup.sh [output-dir]
#
# Requires the PostgreSQL client tools (`pg_dump`) matching the server major
# version. The embedded dev database does NOT ship pg_dump — use a real
# PostgreSQL install (or `docker run postgres:18 pg_dump`) for backups.
# Retention: keeps the 14 newest dumps and deletes older ones.
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
OUT_DIR="${1:-$ROOT/backups}"
RETENTION="${BACKUP_RETENTION:-14}"
mkdir -p "$OUT_DIR"

if ! command -v pg_dump >/dev/null 2>&1; then
  echo "✗ pg_dump not found on PATH."
  echo "  Install the PostgreSQL client tools (e.g. 'apt-get install postgresql-client')"
  echo "  or run the dump inside a container that has them."
  exit 1
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/vision_erp-$STAMP.dump"

echo "• dumping to $FILE"
pg_dump --format=custom --no-owner --no-privileges --dbname="$DATABASE_URL" --file="$FILE"
echo "✓ backup written: $FILE ($(du -h "$FILE" | cut -f1))"

# Retention: newest N dumps kept.
if [ "$RETENTION" -gt 0 ]; then
  mapfile -t OLD < <(ls -1t "$OUT_DIR"/vision_erp-*.dump 2>/dev/null | tail -n +$((RETENTION + 1)))
  for f in "${OLD[@]:-}"; do
    [ -n "$f" ] && rm -f "$f" && echo "  pruned old backup: $f"
  done
fi

echo "Restore with: scripts/restore.sh $FILE"