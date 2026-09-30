#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p results
for engine in stock turso; do
  docker run --rm --network none -v "$PWD/work/symfony-demo:/app" -w /app -e APP_ENV=test \
    "turso-8498-franken-${engine}:isolated" php vendor/bin/phpunit | tee "results/symfony-full-${engine}.txt"
done
