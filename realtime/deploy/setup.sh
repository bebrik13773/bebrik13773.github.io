#!/usr/bin/env bash
# Первичная настройка VPS (Ubuntu 22.04/24.04 или Debian 12), запускать от root:
#   curl -fsSL https://raw.githubusercontent.com/bebrik13773/bebrik13773.github.io/main/realtime/deploy/setup.sh | bash -s -- bober-forest.duckdns.org
set -euo pipefail
DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || { echo "Использование: setup.sh ДОМЕН"; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo "Нужен root"; exit 1; }

apt-get update -y
apt-get install -y git curl ufw ca-certificates
command -v docker >/dev/null || curl -fsSL https://get.docker.com | sh

# Файл подкачки 512 МБ, если памяти мало и подкачки нет
if [ "$(free -m | awk '/^Mem:/{print $2}')" -lt 1500 ] && [ "$(swapon --show | wc -l)" -eq 0 ]; then
  fallocate -l 512M /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

ufw allow 22/tcp; ufw allow 80/tcp; ufw allow 443/tcp
ufw --force enable

mkdir -p /opt
if [ ! -d /opt/wf/.git ]; then
  git clone --depth 1 https://github.com/bebrik13773/bebrik13773.github.io.git /opt/wf
fi
cd /opt/wf/realtime
if [ ! -f .env ]; then
  cp deploy/env.example .env
  sed -i "s#^WF_DOMAIN=.*#WF_DOMAIN=${DOMAIN}#" .env
fi
BUILD_SHA="$(git -C /opt/wf rev-parse --short HEAD)" docker compose up -d --build
echo "Готово. Проверь: https://${DOMAIN}/health"
