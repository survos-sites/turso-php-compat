#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# Compile the optional generated-column startup shim if necessary.
./demo.sh experimental >/dev/null
name=turso-php-compat-demo
if docker container inspect "$name" >/dev/null 2>&1; then
  echo "Container $name already exists. Use docker start $name or docker rm -f $name before recreating." >&2
  exit 1
fi
docker run -d --name "$name" -p 127.0.0.1:8498:8080 \
  -v "$PWD/fixtures:/fixtures:ro" -v "$PWD/tests:/harness/tests:ro" \
  -v "$PWD/public:/harness/public:ro" -v "$PWD/demo.php:/harness/demo.php:ro" \
  -v "$PWD/results:/experiment:ro" -e LD_PRELOAD=/experiment/enable-experimental.so -e ENGINE=turso-experimental \
  turso-8498-franken-turso:isolated frankenphp run --config /harness/Caddyfile
echo 'DBAL demo: http://localhost:8498/dbal.php'
echo 'Stop: docker stop turso-php-compat-demo'
