#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
# Build a diagnostic startup shim using the already-built image's compiler.
docker run --rm --network none -v "$PWD/tests:/sources:ro" -v "$PWD/results:/results" turso-8498-php-turso:isolated \
  cc -shared -fPIC /sources/enable-experimental.c -L/opt/turso/lib -Wl,-rpath,/opt/turso/lib -lturso_sqlite3 -o /results/enable-experimental.so
for runtime in php franken; do
  docker run --rm --network none -v "$PWD/fixtures:/fixtures:ro" -v "$PWD/tests:/harness/tests:ro" -v "$PWD/results:/experiment:ro" \
    -e LD_PRELOAD=/experiment/enable-experimental.so -e ENGINE=turso-experimental \
    "turso-8498-${runtime}-turso:isolated" >"results/${runtime}-experimental.json" 2>"results/${runtime}-experimental.stderr"
done
python3 tests/compare.py results experimental
python3 tests/http_compare.py experimental
python3 tests/compare.py results experimental --strict
