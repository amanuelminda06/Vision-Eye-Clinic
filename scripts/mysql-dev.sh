#!/usr/bin/env bash
#
# Start a local, unprivileged MySQL 8.0 server for the test suite.
#
#   scripts/mysql-dev.sh start|stop|status|cli
#
# The server binaries live outside the repository (they are not part of the
# app); MYSQL_HOME points at that prefix. Nothing is installed system-wide
# and the data directory stays inside the prefix.
set -uo pipefail

ROOT="${MYSQL_HOME:-/tmp/opencode/mysql}"
DATA="${MYSQL_DATA:-$ROOT/data}"
RUN="${MYSQL_RUN:-$ROOT/run}"
LOG="${MYSQL_LOG:-$ROOT/mysqld.log}"
SOCKET="$RUN/mysqld.sock"
PORT="${MYSQL_PORT:-3399}"
LIB="$ROOT/usr/lib/x86_64-linux-gnu"

export LD_LIBRARY_PATH="$LIB${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

export PATH="$ROOT/usr/sbin:$ROOT/usr/bin:$PATH"

start() {
  if [ -S "$SOCKET" ] && "$ROOT/usr/bin/mysqladmin" --socket="$SOCKET" -u root ping >/dev/null 2>&1; then
    echo "• already running on $SOCKET"
    return 0
  fi

  mkdir -p "$DATA" "$RUN"

  if [ ! -d "$DATA/mysql" ]; then
    echo "• initialising the data directory"
    "$ROOT/usr/sbin/mysqld" --initialize-insecure \
      --user="$(id -un)" \
      --datadir="$DATA" \
      --basedir="$ROOT/usr" \
      --log-error="$LOG" >/dev/null 2>&1 \
      || { echo "✗ initialisation failed — see $LOG"; tail -20 "$LOG"; return 1; }
  fi

  echo "• starting mysqld on $SOCKET (port $PORT)"
  nohup "$ROOT/usr/sbin/mysqld" \
    --user="$(id -un)" \
    --datadir="$DATA" \
    --basedir="$ROOT/usr" \
    --socket="$SOCKET" \
    --port="$PORT" \
    --pid-file="$RUN/mysqld.pid" \
    --log-error="$LOG" \
    --mysqlx=0 \
    --skip-name-resolve \
    --innodb-buffer-pool-size=128M \
    --innodb-use-native-aio=0 \
    --max-allowed-packet=64M \
    --secure-file-priv="$DATA/files" \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_0900_ai_ci \
    --default-time-zone=+00:00 \
    >/dev/null 2>&1 &

  for _ in $(seq 1 60); do
    if "$ROOT/usr/bin/mysqladmin" --socket="$SOCKET" -u root ping >/dev/null 2>&1; then
      echo "✓ up: $("$ROOT/usr/bin/mysql" --socket="$SOCKET" -u root -N -B -e 'SELECT VERSION();')"
      return 0
    fi
    sleep 1
  done

  echo "✗ the server did not come up — see $LOG"
  tail -20 "$LOG"
  return 1
}

stop() {
  if [ -S "$SOCKET" ]; then
    "$ROOT/usr/bin/mysqladmin" --socket="$SOCKET" -u root shutdown >/dev/null 2>&1
    echo "• stopped"
  else
    echo "• not running"
  fi
}

status() {
  if "$ROOT/usr/bin/mysqladmin" --socket="$SOCKET" -u root ping >/dev/null 2>&1; then
    echo "• running: $("$ROOT/usr/bin/mysql" --socket="$SOCKET" -u root -N -B -e 'SELECT VERSION();')"
  else
    echo "• stopped"
  fi
}

cli() {
  "$ROOT/usr/bin/mysql" --socket="$SOCKET" -u root "$@"
}

case "${1:-status}" in
  start) start ;;
  stop) stop ;;
  status) status ;;
  cli) shift; cli "$@" ;;
  *) echo "usage: $0 start|stop|status|cli"; exit 1 ;;
esac
