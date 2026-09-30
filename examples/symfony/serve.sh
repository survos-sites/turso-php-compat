#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
engine=${1:-turso}
case "$engine" in stock|turso) ;; *) echo 'Usage: serve.sh [stock|turso]' >&2; exit 2;; esac
name="${engine}-symfony-demo"
if docker container inspect "$name" >/dev/null 2>&1; then
  echo "Container $name already exists. Stop/remove it before recreating." >&2; exit 1
fi
port=8499; [[ "$engine" == stock ]] && port=8500
docker run -d --name "$name" -p "127.0.0.1:${port}:8080" \
 -v "$PWD/work/symfony-demo:/app" -v "$PWD/examples/symfony/Caddyfile:/etc/caddy/Caddyfile:ro" \
 -w /app -e APP_ENV=prod -e APP_DEBUG=0 "turso-8498-franken-${engine}:isolated" frankenphp run --config /etc/caddy/Caddyfile
echo "Symfony Demo: http://localhost:${port}/en/blog/"
