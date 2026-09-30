#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
rev=c1691a84ccf7a4836d7a48355562b3c300924db5
mkdir -p work results
if [[ ! -d work/symfony-demo/.git ]]; then
  git init work/symfony-demo
  git -C work/symfony-demo remote add origin https://github.com/symfony/demo.git
  git -C work/symfony-demo fetch --depth 1 origin "$rev"
  git -C work/symfony-demo checkout --detach FETCH_HEAD
fi
[[ "$(git -C work/symfony-demo rev-parse HEAD)" == "$rev" ]] || { echo 'Unexpected demo revision' >&2; exit 1; }
docker run --rm -v "$PWD/work/symfony-demo:/app" -w /app turso-8498-php-stock:isolated composer install --no-interaction --no-progress --prefer-dist
