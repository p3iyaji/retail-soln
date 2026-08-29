#!/usr/bin/env bash
# Restore a gzip MySQL dump produced by backup-mysql.sh into the Compose stack.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <path-to-backup.sql.gz> [--force]" >&2
  exit 1
fi

DUMP="$1"
FORCE="${2:-}"

if [[ ! -f "$DUMP" ]]; then
  echo "Backup file not found: $DUMP" >&2
  exit 1
fi

DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-rootsecret}"

if [[ "$FORCE" != "--force" ]]; then
  echo "WARNING: This will overwrite the current MySQL database in docker compose."
  read -r -p "Type RESTORE to continue: " confirm
  if [[ "$confirm" != "RESTORE" ]]; then
    echo "Aborted."
    exit 1
  fi
fi

echo "Restoring from $DUMP ..."

gunzip -c "$DUMP" | docker compose exec -T mysql mysql \
  -uroot \
  -p"${DB_ROOT_PASSWORD}"

echo "Restore complete. Verify with: curl -sf http://localhost:8080/api/v1/health/ready"
