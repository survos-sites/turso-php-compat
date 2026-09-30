# Turso PR #8498: PHP/Folio compatibility experiment

Repository: `survos-sites/turso-php-compat`. Local checkout: `~/sites/turso-php-compat`.

**Observed proof of concept:** real Folio reads work through stock Doctrine DBAL on Turso, including a live FrankenPHP request, when generated columns are enabled at startup. See [REPORT.md](REPORT.md) for the remaining incompatibilities.

This is an isolated Linux container harness, not an application migration or deployment. Start with `REPORT.md` for observed results. No production database or application configuration is modified.

## Quick DBAL proof of concept

```sh
python3 tests/prepare_fixtures.py
./run.sh                  # expected non-zero when incompatibilities are detected
./demo.sh                 # Turso with experimental generated columns enabled
./demo.sh stock           # same DBAL application, stock SQLite
./serve.sh                # http://localhost:8498/dbal.php (FrankenPHP)
```

`demo.php` uses ordinary Doctrine `DriverManager::getConnection()`, a join/count query, and a parameterized query reading a generated sort column. No custom DBAL driver is used. The demo uses a synthetic Folio by default. To use a local checkpointed Folio instead, run `python3 tests/prepare_fixtures.py --folio /path/to/file.folio` before the demo. Source files are copied and are never opened by Turso in place.

## Reproduce

Requirements: Docker with BuildKit, Python 3, network access for the initial builds, and roughly 8 GB of Docker memory. Tested on Linux arm64 containers on an Apple Silicon host. AMD64/Dokku-host execution has not been verified.

```sh
./run.sh
# Optional diagnostic: enable the C library's experimental features at process startup.
./run-experimental.sh
```

The demo server stays running until `docker stop turso-php-compat-demo`; it binds only to localhost.

Both test scripts finish with a non-zero exit status if the PHP differential suite contains incompatibilities; results remain saved for inspection. HTTP outcomes are reported separately in JSON.

The first script builds Turso CLI and its SQLite-compatible C library from exact PR commit `faac0360a3304b598da068a9c6fce356d532c195`. It first compares six Folio reads in Turso CLI against Python's stock SQLite, both with and without `--experimental-generated-columns`. It then tests stock and Turso PHP 8.4, stock and Turso Debian-based FrankenPHP, and finally real HTTP requests to FrankenPHP bound only to localhost on temporary ports. Each HTTP container is stopped afterward.

`--load` is intentional: the user's BuildKit builder uses the docker-container driver. No image is pushed. The experimental script compiles a startup shim inside the already-built container, then mounts it read-only for the diagnostic runs.

`Dockerfile.turso` and the Turso build stages in `Dockerfile.php` contain the same build recipe. They build both packages together; Cargo feature unification therefore includes CLI-requested core features (including FTS) in this library build. This is a recorded build choice, not a claim to reproduce the author's original 287-test environment. The `capi` Cargo feature is empty at this revision.

## PHP build

The stock images have SQLite compiled into PHP, so installing a second extension or using `LD_PRELOAD` alone is not a sound replacement test. `tests/rebuild-php.sh` rebuilds the image's bundled PHP 8.4 source, without source patches, using:

```sh
SQLITE_CFLAGS='-I/opt/turso/include'
SQLITE_LIBS='-L/opt/turso/lib -Wl,-rpath,/opt/turso/lib -lturso_sqlite3'
```

PHP’s configure probes also hard-code `-lsqlite3`, so the build creates `/opt/turso/lib/libsqlite3.so -> libturso_sqlite3.so` before configuring. Without this alias, the installed system SQLite library causes false capability detection and a link failure for `sqlite3_load_extension`.

The FrankenPHP variant also rebuilds the ZTS embedded `libphp.so`, which the unchanged FrankenPHP executable loads. PHP source, Doctrine and application code are not patched. Turso's supplied header defines `SQLITE_OMIT_LOAD_EXTENSION`, so SQLite loadable-extension support is omitted in the Turso build. Both the CLI binary and embedded library linkage are recorded. Runtime results also record loaded SQLite library paths from `/proc/self/maps`.

The Composer lock pins DBAL 4.5.0, ORM 3.5.8 and Symfony 7.3 components. The lock was generated with `composer update --no-install --no-blocking`, solely to permit the requested historical Symfony version in this disposable test environment. See `results/composer-audit.json`; this dependency set is not a production recommendation.

## Fixture handling

- `fixtures/selected.folio`: byte-for-byte copy of `~/data/folio-bare/smith/nmafa.folio`, with no WAL present when copied. Stock SQLite integrity check passed. It contains 117 items and a JSON-based virtual generated column.
- `fixtures/moma.pixy.db`: checked-in Survos Pixie fixture at a pinned repository commit.
- `fixtures/bootstrap.folio.sqlite`: checked-in Folio bootstrap fixture, retained for further investigation.
- `fixtures/minimal.sqlite`: synthetic three-row SQLite file; `tests/make_minimal.py` can regenerate it after explicitly removing the old file.

Exact origins and SHA-256 hashes are in `fixtures/provenance.json`. The original local Folio is never mounted into a container. Fixture mounts are read-only. Each PHP test process copies fixtures to its own disposable temporary directory before opening them. This allows SQLite WAL sidecar creation without altering the supplied database files. Deliberate write tests run only on those disposable copies or in-memory databases. No production data is needed.

The local Folio is excluded by `.gitignore`. Do not publish the local fixture or full fixture-derived results as part of an upstream bug report without reviewing their content. A synthetic reproducer should be used for public reports.

## What the smoke suite measures

24 independently executed cases cover SQLite3/PDO reads, named and positional parameters, JSON, pagination, commit/rollback, insert IDs, blobs/NULL, a scalar UDF, custom collation, constraint codes, read-only URI enforcement, DBAL queries/schema/savepoints, ORM schema creation and CRUD with JSON hydration, a booted Symfony 7.3 kernel request, Folio counts/joins/JSON/generated sorting/translations/catalog/schema, and Pixie catalog reads.

Every case runs in a separate PHP process with a 30-second timeout, so an exception, abort or crash cannot hide later results. A pass in the comparison means the case succeeds and returns exactly the same value as stock SQLite. Error-free execution alone is not counted as compatibility. See `tests/compare.py` and raw JSON results for distinctions between exceptions, process failures and value differences.

The HTTP test runs the Symfony kernel request and Folio reads inside FrankenPHP's actual request handler. The CLI suite inside that image is separately recorded; it is not a substitute for HTTP validation.

## Experimental-feature diagnostic

`run-experimental.sh` adds a tiny startup library that calls the PR's existing `turso_enable_experimental()` export. It does not replace SQLite functions or patch PHP/Turso, but it **is an extra deployment adaptation**, not the default drop-in behavior. It enables generated columns, vacuum, WITHOUT ROWID and ATTACH globally. Experimental and default results are recorded separately.

This is a focused smoke test, not the upstream PHP conformance suite, a performance benchmark, a concurrency test, a full Folio application boot, or a Dokku deployment. It does not establish production readiness.
