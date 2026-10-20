#!/usr/bin/env bash
#
# Back up the clinic database with mysqldump (gzip-compressed SQL).
#
#   scripts/backup.sh [output-dir]
#
# Requires the MySQL client tools (`mysqldump`). Retention keeps the newest
# dumps and prunes the older ones.
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

OUT_DIR="${1:-$ROOT/backups}"
RETENTION="${BACKUP_RETENTION:-14}"
mkdir -p "$OUT_DIR"

if ! command -v mysqldump >/dev/null 2>&1; then
  echo "✗ mysqldump not found on PATH."
  echo "  Install the MySQL client tools (e.g. 'apt-get install mysql-client')."
  exit 1
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/vision_eye_clinic-$STAMP.sql.gz"

echo "• dumping $DB_DATABASE to $FILE"

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  "$DB_DATABASE" | gzip -9 > "$FILE"

echo "✓ backup written: $FILE ($(du -h "$FILE" | cut -f1))"

# Retention: newest N dumps kept.
if [ "$RETENTION" -gt 0 ]; then
  mapfile -t OLD < <(ls -1t "$OUT_DIR"/vision_eye_clinic-*.sql.gz 2>/dev/null | tail -n +$((RETENTION + 1)))
  for f in "${OLD[@]:-}"; do
    [ -n "$f" ] && rm -f "$f" && echo "  pruned old backup: $f"
  done
fi

echo "Restore with: scripts/restore.sh $FILE"
