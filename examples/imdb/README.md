# IMDb relational workload (FTS is deferred)

Use official IMDb non-commercial TSV snapshots for titles and ratings. This is a
simple relational workload, independent of Folio JSON and OCR. Read the
[IMDb dataset terms](https://developer.imdb.com/non-commercial-datasets/) before use.
Data is fetched, not redistributed in Git.

```bash
# First build and verify the native driver:
PHP_BIN=/path/to/php8.4 ./reproduce.sh native

# Explicit full-data download; choose storage with at least 20 GiB free:
python3 examples/imdb/prepare.py /Volumes/YOUR_DRIVE/turso-imdb
TURSO_SOURCE="$PWD/work/reproduction/turso" /path/to/php8.4 -d ffi.enable=1 \
  examples/imdb/compare.php /Volumes/YOUR_DRIVE/turso-imdb
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

These are preliminary application-path measurements using a debug SDK and buffered
PHP adapter. They do not establish production engine speed or controlled cold-cache
performance. Checkpoint timing, query plans, reversed engine order, additional repeats,
and build-profile parity remain necessary for a formal benchmark. Full-scale IMDb
execution has not yet been validated; only a tiny synthetic TSV fixture has been
used to test this pipeline. FTS and Folio-specific generated columns are subsequent
work, not prerequisites for this workload.
