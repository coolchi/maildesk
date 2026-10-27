#!/usr/bin/env bash
set -euo pipefail
ROOT="${STALWART_HOME:-/home/forge/mail/stalwart}"
PID="$ROOT/logs/stalwart.pid"
if [[ -f "$PID" ]] && kill -0 "$(cat "$PID")" 2>/dev/null; then
  echo "running pid=$(cat "$PID")"
  ss -tlnp 2>/dev/null | grep -F "pid=$(cat "$PID")" || ss -tlnp 2>/dev/null | grep stalwart || true
else
  echo "stopped"
  exit 1
fi
