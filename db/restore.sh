#!/usr/bin/env bash
# MIS database restore. Loads a backup .sql or .sql.gz produced by backup.sh.
# Usage:
#   ./db/restore.sh path/to/dump.sql.gz             # into 'mis'
#   ./db/restore.sh path/to/dump.sql.gz --db mis_test
#
# WARNING: this DROPS and re-creates the target database before loading.
# Pass --no-drop to load into the existing database without dropping.

set -euo pipefail

DUMP=""
DB="mis"
DROP=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --db)      DB="$2"; shift 2 ;;
    --no-drop) DROP=0; shift ;;
    -h|--help)
      grep '^#' "$0" | sed 's/^# *//'; exit 0 ;;
    *)
      if [[ -z "$DUMP" ]]; then DUMP="$1"; shift
      else echo "Unknown arg: $1" >&2; exit 2; fi ;;
  esac
done

if [[ -z "$DUMP" || ! -f "$DUMP" ]]; then
  echo "Pass a path to a backup file (.sql or .sql.gz)." >&2
  exit 2
fi

HOST="${MIS_DB_HOST:-127.0.0.1}"
PORT="${MIS_DB_PORT:-8889}"
USER="${MIS_DB_USER:-root}"
PASS="${MIS_DB_PASS:-root}"
SOCK="${MIS_DB_SOCKET:-/Applications/MAMP/tmp/mysql/mysql.sock}"

if [[ -z "${MYSQL:-}" ]]; then
  if [[ -x /Applications/MAMP/Library/bin/mysql80/bin/mysql ]]; then
    MYSQL="/Applications/MAMP/Library/bin/mysql80/bin/mysql"
  elif [[ -x /Applications/MAMP/Library/bin/mysql57/bin/mysql ]]; then
    MYSQL="/Applications/MAMP/Library/bin/mysql57/bin/mysql"
  else
    MYSQL="$(command -v mysql || true)"
  fi
fi
if [[ -z "${MYSQL}" ]]; then echo "mysql not found." >&2; exit 3; fi

CONN=(-u "$USER" "-p${PASS}")
if [[ -n "${SOCK}" && -S "${SOCK}" ]]; then
  CONN+=(--socket="$SOCK")
else
  CONN+=(-h "$HOST" -P "$PORT")
fi

if [[ "$DROP" -eq 1 ]]; then
  echo "[mis-restore] dropping and re-creating $DB"
  "$MYSQL" "${CONN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    2> >(grep -v 'Using a password on the command line interface' >&2 || true)
fi

echo "[mis-restore] loading $DUMP into $DB"
case "$DUMP" in
  *.gz) gunzip -c "$DUMP" | "$MYSQL" "${CONN[@]}" "$DB" 2> >(grep -v 'Using a password on the command line interface' >&2 || true) ;;
  *)    "$MYSQL" "${CONN[@]}" "$DB" < "$DUMP"           2> >(grep -v 'Using a password on the command line interface' >&2 || true) ;;
esac

echo "[mis-restore] OK"
