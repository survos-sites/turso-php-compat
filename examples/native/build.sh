#!/usr/bin/env bash
set -euo pipefail
source_dir=${1:?Usage: build.sh /path/to/turso-checkout}
rev=faac0360a3304b598da068a9c6fce356d532c195
[[ $(git -C "$source_dir" rev-parse HEAD) == "$rev" ]] || { echo "Expected Turso revision $rev" >&2; exit 1; }
(cd "$source_dir" && cargo build --locked -p turso_sdk_kit -j2)
echo 'SDK built in target/debug. Use its turso.h from the same checkout.'
