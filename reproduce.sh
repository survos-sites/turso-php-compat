#!/usr/bin/env bash
# Reproduce one experiment or both; never starts a database server.
set -euo pipefail
cd "$(dirname "$0")"
mode=${1:-native}
case "$mode" in native|compat|all) ;; *) echo 'Usage: ./reproduce.sh [native|compat|all]' >&2; exit 2;; esac
mkdir -p results
if [[ "$mode" != compat ]]; then
  for command in git cargo python3; do command -v "$command" >/dev/null || { echo "Missing prerequisite: $command" >&2; exit 1; }; done
  php_bin=${PHP_BIN:-php}
  composer_bin=${COMPOSER_BIN:-$(command -v composer || true)}
  [[ -n "$composer_bin" ]] || { echo 'Install Composer or set COMPOSER_BIN to its PHP executable/phar.' >&2; exit 1; }
  "$php_bin" -d ffi.enable=1 -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {fwrite(STDERR,"Use PHP 8.4; set PHP_BIN if necessary.\n");exit(1);} foreach (["FFI","pdo_sqlite"] as $e) {if (!extension_loaded($e)) {fwrite(STDERR,"Missing extension: $e\n");exit(1);}}'
  workspace=${WORK_DIR:-$PWD/work/reproduction}
  mkdir -p "$workspace"
  workspace=$(cd "$workspace" && pwd)
  source_dir=${TURSO_SOURCE:-$workspace/turso}
  rev=faac0360a3304b598da068a9c6fce356d532c195
  if [[ ! -e "$source_dir" ]]; then
    git init "$source_dir"
    git -C "$source_dir" remote add origin https://github.com/tursodatabase/turso.git
    git -C "$source_dir" fetch --depth 1 origin "$rev"
    git -C "$source_dir" checkout --detach FETCH_HEAD
  fi
  ./examples/native/build.sh "$source_dir" 2>&1 | tee results/native-build.log
  "$php_bin" "$composer_bin" install --working-dir=examples/native --no-interaction --no-progress --prefer-dist 2>&1 | tee results/native-install.log
  TURSO_SOURCE="$source_dir" "$php_bin" -d ffi.enable=1 examples/native/smoke.php | tee results/native-smoke.txt
  TURSO_SOURCE="$source_dir" "$php_bin" -d ffi.enable=1 examples/native/relational.php | tee results/native-relational.txt
  "$php_bin" -v > results/native-environment.txt
  git -C "$source_dir" rev-parse HEAD >> results/native-environment.txt
  git -C "$source_dir" status --short >> results/native-environment.txt
  cargo --version >> results/native-environment.txt
  uname -a >> results/native-environment.txt
fi
if [[ "$mode" != native ]]; then
  command -v docker >/dev/null || { echo 'Docker is required for the compatibility experiment.' >&2; exit 1; }
  ./build.sh
  ./fetch-demo.sh
  ./demo.sh stock | tee results/demo-stock.txt
  ./demo.sh turso | tee results/demo-turso.txt
  ./examples/symfony/setup.sh
  ./examples/symfony/test.sh
fi
echo 'Reproduction completed. Logs: results/. Full-scale performance and FTS are separate experiments.'
