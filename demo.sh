#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
./fetch-demo.sh >&2
engine=${1:-turso}
extra=()
case "$engine" in
  stock) tag=turso-8498-php-stock:isolated; extra=(-e ENGINE=stock) ;;
  turso) tag=turso-8498-php-turso:isolated; extra=(-e ENGINE=turso) ;;
  experimental)
    tag=turso-8498-php-turso:isolated
    if [[ ! -f results/enable-experimental.so ]]; then
      docker run --rm --network none -v "$PWD/tests:/sources:ro" -v "$PWD/results:/results" "$tag" \
        cc -shared -fPIC /sources/enable-experimental.c -L/opt/turso/lib -Wl,-rpath,/opt/turso/lib -lturso_sqlite3 -o /results/enable-experimental.so
    fi
    extra=(-v "$PWD/results:/experiment:ro" -e LD_PRELOAD=/experiment/enable-experimental.so -e ENGINE=turso-experimental)
    ;;
  *) echo 'Usage: ./demo.sh [stock|turso|experimental]' >&2; exit 2 ;;
esac
docker run --rm --network none -v "$PWD/fixtures:/fixtures:ro" -v "$PWD/demo.php:/harness/demo.php:ro" "${extra[@]}" "$tag" php /harness/demo.php
