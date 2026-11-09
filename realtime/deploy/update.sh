#!/usr/bin/env bash
# Обновление на сервере: bash /opt/wf/realtime/deploy/update.sh
set -euo pipefail
cd /opt/wf
git pull --ff-only
cd realtime
BUILD_SHA="$(git -C /opt/wf rev-parse --short HEAD)" docker compose up -d --build
docker image prune -f >/dev/null
echo "Обновлено до $(git -C /opt/wf rev-parse --short HEAD)"
