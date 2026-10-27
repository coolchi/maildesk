#!/usr/bin/env bash
set -euo pipefail
ROOT="${STALWART_HOME:-/home/forge/mail/stalwart}"
BIN="$ROOT/bin/stalwart"
CFG="$ROOT/etc/config.json"
PID="$ROOT/logs/stalwart.pid"
LOG="$ROOT/logs/runtime.log"

mkdir -p "$ROOT/logs" "$ROOT/data"
if [[ -f "$PID" ]] && kill -0 "$(cat "$PID")" 2>/dev/null; then
  echo "Stalwart already running (pid $(cat "$PID"))"
  exit 0
fi

nohup "$BIN" --config "$CFG" >>"$LOG" 2>&1 &
echo $! >"$PID"
sleep 1
if kill -0 "$(cat "$PID")" 2>/dev/null; then
  echo "Started Stalwart pid $(cat "$PID")"
else
  echo "Failed to start; see $LOG" >&2
  exit 1
fi
