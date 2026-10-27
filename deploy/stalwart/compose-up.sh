#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. On Ubuntu: sudo apt install docker.io docker-compose-v2" >&2
  exit 1
fi
docker compose pull
docker compose up -d
echo
echo "Stalwart is starting. Bootstrap admin password (first run only):"
docker compose logs --no-color stalwart 2>/dev/null | grep -A6 'temporary administrator' || true
echo
echo "Open setup: http://127.0.0.1:18080/admin"
echo "Remote tunnel: ssh -L 18080:127.0.0.1:18080 forge@SERVER"
echo "After TLS setup, HTTPS admin may be on https://SERVER:8443/admin"
