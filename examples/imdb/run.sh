#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
dataset=${1:?Usage: examples/imdb/run.sh /external/path/imdb}
source_dir=${TURSO_SOURCE:?Set TURSO_SOURCE to the pinned Turso checkout}
php_bin=${PHP_BIN:-php}
# Keep optimized build artifacts off the internal SSD when the dataset is external.
if [[ ! -f "$dataset/dataset.json" ]]; then python3 examples/imdb/prepare.py "$dataset"; fi
sdk_target=${TURSO_TARGET_DIR:-$dataset/../native-target}
mkdir -p "$sdk_target"
sdk_target=$(cd "$sdk_target" && pwd)
[[ $(git -C "$source_dir" rev-parse HEAD) == faac0360a3304b598da068a9c6fce356d532c195 ]] || { echo 'Unexpected Turso revision' >&2; exit 1; }
(cd "$source_dir" && CARGO_TARGET_DIR="$sdk_target" cargo build --locked -p turso_sdk_kit --profile lib-release -j2)
case $(uname -s) in Darwin) ext=dylib;; Linux) ext=so;; *) echo 'Unsupported platform' >&2; exit 1;; esac
export TURSO_LIBRARY="$sdk_target/lib-release/libturso_sdk_kit.$ext"
export TURSO_BUILD_PROFILE=lib-release
"$php_bin" -d ffi.enable=1 examples/imdb/compare.php "$dataset"
