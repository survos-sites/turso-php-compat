#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p results
python3 tests/prepare_fixtures.py
# PHP and FrankenPHP are built from the same locked application dependencies.
docker build --load --progress plain -f Dockerfile.turso -t turso-8498:isolated . >results/build-turso.log 2>&1
python3 tests/cli_compare.py
python3 tests/cli_compare.py --generated-columns
for runtime in php franken; do
  if [[ "$runtime" == php ]]; then
    base='php:8.4-cli-bookworm@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee'
  else
    base='dunglas/frankenphp:php8.4-bookworm@sha256:3672a0d093efc94375f3457467fa11d25a39f51c7969c4a827967ab29eb00eda'
  fi
  for engine in stock turso; do
    target=stock
    [[ "$engine" == turso ]] && target=turso-php
    tag="turso-8498-${runtime}-${engine}:isolated"
    docker build --load --progress plain --target "$target" --build-arg "BASE=$base" -f Dockerfile.php -t "$tag" . >"results/build-${runtime}-${engine}.log" 2>&1
    docker run --rm --network none -v "$PWD/fixtures:/fixtures:ro" -v "$PWD/tests:/harness/tests:ro" -v "$PWD/public:/harness/public:ro" "$tag" >"results/${runtime}-${engine}.json" 2>"results/${runtime}-${engine}.stderr"
    docker run --rm --network none "$tag" sh -c 'php -v; php --ri sqlite3; php --ri pdo_sqlite; for f in /usr/local/bin/php /usr/local/lib/libphp.so /usr/local/bin/frankenphp; do echo "$f"; readelf -d "$f"; ldd "$f"; done' >"results/linkage-${runtime}-${engine}.txt" 2>&1
  done
done
python3 tests/compare.py results
python3 tests/http_compare.py

# Non-zero signals observed compatibility differences after all evidence is saved.
python3 tests/compare.py results --strict
