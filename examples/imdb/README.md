# IMDb relational workload (FTS is deferred)

**Full-scale run complete:** 12,824,993 titles and 1,715,569 ratings. Both engines
returned identical results in two reversed-order runs. See [measurements and
limitations](REPORT.md).

Use official IMDb non-commercial TSV snapshots for titles and ratings. This is a
simple relational workload, independent of Folio JSON and OCR. Read the
[IMDb dataset terms](https://developer.imdb.com/non-commercial-datasets/) before use.
Data is fetched, not redistributed in Git.

```bash
# First build and verify the native driver:
PHP_BIN=/path/to/php8.4 ./reproduce.sh native

# Explicit full-data download; choose storage with at least 20 GiB free:
python3 examples/imdb/prepare.py /Volumes/YOUR_DRIVE/turso-imdb
TURSO_SOURCE="$PWD/work/reproduction/turso" PHP_BIN=/path/to/php8.4 \
  ./examples/imdb/run.sh /Volumes/YOUR_DRIVE/turso-imdb
```

The importer streams gzip TSV rows into `base.sqlite`, preserving NULLs and types.
It records URLs, compressed-file SHA-256 hashes, acquisition time, row counts and
SQLite version. Existing `base.sqlite` is never overwritten. IMDb updates its URLs;
retain the downloaded files and `sources.json` to reproduce the exact dataset.
`--offline` requires those files and verifies their recorded checksums.

The comparison makes independent copies, adds identical indexes, and checks result
hashes for range filters, title lookup, joins, grouping and missing-rating queries.
Each run has a new directory. It records index time, first/subsequent query timings
and database/sidecar sizes after connection close. Copy time is separate.

The runner builds the SDK with its dedicated `lib-release` profile and two jobs,
keeping build output beside the external dataset. Direct invocation of compare.php
defaults to a debug library unless TURSO_LIBRARY and TURSO_BUILD_PROFILE are set.
These are preliminary application-path measurements using a buffered PHP adapter. They do not establish production engine speed or controlled cold-cache
performance. Checkpoint time, query plans and actual library hash/profile are recorded. Use
ENGINE_ORDER=turso-first for a reversed-order run. Additional repeats and controlled
cache conditions remain necessary for a formal benchmark. Full-scale IMDb execution has been validated on the recorded snapshot. A tiny
synthetic TSV fixture was also used to check NULL and Unicode import handling. FTS and Folio-specific generated columns are subsequent
work, not prerequisites for this workload.

## Reversed order and join diagnostic

```bash
ENGINE_ORDER=turso-first TURSO_SOURCE="$PWD/work/reproduction/turso" \
  PHP_BIN=/path/to/php8.4 ./examples/imdb/run.sh /Volumes/YOUR_DRIVE/turso-imdb

# Choose a completed run directory printed by the runner:
TURSO_SOURCE="$PWD/work/reproduction/turso" \
  TURSO_LIBRARY=/Volumes/YOUR_DRIVE/native-target/lib-release/libturso_sdk_kit.dylib \
  /path/to/php8.4 -d ffi.enable=1 examples/imdb/join-diagnostic.php \
  /Volumes/YOUR_DRIVE/turso-imdb/run-EXACT_ID
```

The diagnostic performs SELECT/EXPLAIN queries only and preserves the original
benchmark SQL. On Linux the library suffix is `.so`.
