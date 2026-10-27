#!/usr/bin/env bash
set -euo pipefail
ROOT="${STALWART_HOME:-/home/forge/mail/stalwart}"
PID="$ROOT/logs/stalwart.pid"
if [[ -f "$PID" ]]; then
  kill "$(cat "$PID")" 2>/dev/null || true
  rm -f "$PID"
  echo "Stopped Stalwart"
else
  pkill -f "$ROOT/bin/stalwart" 2>/dev/null || true
  echo "No pid file; sent pkill"
fi
