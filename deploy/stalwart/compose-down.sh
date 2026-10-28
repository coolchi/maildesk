#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
docker compose down
echo "Stopped. Volumes stalwart-etc / stalwart-data were kept."
