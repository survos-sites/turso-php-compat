#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p results
docker build --load --progress plain -f Dockerfile.turso -t turso-8498:isolated . >results/build-turso.log 2>&1
for runtime in php franken; do
  base='php:8.4-cli-bookworm@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee'
  [[ "$runtime" == franken ]] && base='dunglas/frankenphp:php8.4-bookworm@sha256:3672a0d093efc94375f3457467fa11d25a39f51c7969c4a827967ab29eb00eda'
  for engine in stock turso; do
    target=stock; [[ "$engine" == turso ]] && target=turso-php
    docker build --load --progress plain --target "$target" --build-arg "BASE=$base" -f Dockerfile.php -t "turso-8498-${runtime}-${engine}:isolated" . >"results/build-${runtime}-${engine}.log" 2>&1
  done
done
