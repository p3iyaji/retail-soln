#!/usr/bin/env bash
# Create a gzip-compressed MySQL logical backup from the Docker Compose stack.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups/mysql}"
DB_DATABASE="${DB_DATABASE:-retail_soln}"
DB_USERNAME="${DB_USERNAME:-retail}"
DB_PASSWORD="${DB_PASSWORD:-secret}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-rootsecret}"

mkdir -p "$BACKUP_DIR"

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUTPUT="$BACKUP_DIR/${DB_DATABASE}_${TIMESTAMP}.sql.gz"

echo "Writing backup to $OUTPUT"

docker compose exec -T mysql mysqldump \
  -uroot \
  -p"${DB_ROOT_PASSWORD}" \
  --single-transaction \
  --routines \
  --triggers \
  --databases "${DB_DATABASE}" \
  | gzip -c > "$OUTPUT"

echo "Backup complete ($(du -h "$OUTPUT" | awk '{print $1}'))"
