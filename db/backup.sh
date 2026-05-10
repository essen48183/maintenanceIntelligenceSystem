#!/usr/bin/env bash
# MIS database backup. Writes a gzipped mysqldump to db/backups/.
# Usage:
#   ./db/backup.sh                # backs up the 'mis' DB using MAMP defaults
#   ./db/backup.sh --db mis_test  # backs up another DB
#   ./db/backup.sh --keep 14      # keep only the most-recent 14 dumps
#
# Designed to work both on MAMP (default) and a remote host (override env).
# Override these via env vars when migrating to a host:
#   MIS_DB_HOST   (default 127.0.0.1)
#   MIS_DB_PORT   (default 8889 — MAMP)
#   MIS_DB_USER   (default root)
#   MIS_DB_PASS   (default root)
#   MIS_DB_SOCKET (default /Applications/MAMP/tmp/mysql/mysql.sock)
#   MYSQLDUMP     (default looks under /Applications/MAMP, then PATH)

set -euo pipefail

DB="mis"
KEEP=30

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db)   DB="$2"; shift 2 ;;
    --keep) KEEP="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

HOST="${MIS_DB_HOST:-127.0.0.1}"
PORT="${MIS_DB_PORT:-8889}"
USER="${MIS_DB_USER:-root}"
PASS="${MIS_DB_PASS:-root}"
SOCK="${MIS_DB_SOCKET:-/Applications/MAMP/tmp/mysql/mysql.sock}"

if [[ -z "${MYSQLDUMP:-}" ]]; then
  if [[ -x /Applications/MAMP/Library/bin/mysql80/bin/mysqldump ]]; then
    MYSQLDUMP="/Applications/MAMP/Library/bin/mysql80/bin/mysqldump"
  elif [[ -x /Applications/MAMP/Library/bin/mysql57/bin/mysqldump ]]; then
    MYSQLDUMP="/Applications/MAMP/Library/bin/mysql57/bin/mysqldump"
  else
    MYSQLDUMP="$(command -v mysqldump || true)"
  fi
fi
if [[ -z "${MYSQLDUMP}" ]]; then
  echo "mysqldump not found. Set the MYSQLDUMP env var." >&2
  exit 3
fi

DIR="$(cd "$(dirname "$0")" && pwd)/backups"
mkdir -p "$DIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$DIR/${DB}_${STAMP}.sql.gz"

CONN_ARGS=(-u "$USER" "-p${PASS}")
if [[ -n "${SOCK}" && -S "${SOCK}" ]]; then
  CONN_ARGS+=(--socket="$SOCK")
else
  CONN_ARGS+=(-h "$HOST" -P "$PORT")
fi

echo "[mis-backup] dumping $DB → $OUT"
"$MYSQLDUMP" \
  "${CONN_ARGS[@]}" \
  --single-transaction --quick --hex-blob \
  --set-gtid-purged=OFF --no-tablespaces \
  --default-character-set=utf8mb4 \
  --routines --events --triggers \
  "$DB" 2> >(grep -v 'Using a password on the command line interface' >&2 || true) \
  | gzip -9 > "$OUT"

# Retention
if [[ "$KEEP" -gt 0 ]]; then
  ls -1t "$DIR"/${DB}_*.sql.gz 2>/dev/null | tail -n +"$((KEEP+1))" | xargs -I{} rm -f {}
fi

SIZE=$(du -h "$OUT" | cut -f1)
echo "[mis-backup] OK: $OUT ($SIZE). Keeping latest $KEEP backups."
