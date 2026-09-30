# Turso for PHP: native driver and SQLite compatibility experiments

**Project status:** experiments are parked pending Turso 1.0. Keep SQLite for
current applications; retain this harness and the measured baseline for a future
rerun. No production migration is planned from these preliminary results.

## Start here: reproducible native comparison

Native Turso runs beside stock PDO SQLite; it does not replace PHP's SQLite library.
Install PHP 8.4 with FFI/PDO SQLite, Composer, Rust/rustup, a C compiler, Git and
Python 3, then run:

```bash
git clone https://github.com/survos-sites/turso-php-compat.git
cd turso-php-compat
./reproduce.sh native
# macOS Homebrew PHP 8.4:
PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php ./reproduce.sh native
```

The script fetches the pinned Turso source, builds its native SDK with two jobs,
installs locked DBAL dependencies, and runs both engines through connection and
relational correctness checks. Logs are in `results/`. Set `WORK_DIR` to place the
source/build on another volume, or `TURSO_SOURCE` to reuse a checkout of the pinned
revision. No service or container is started. macOS arm64 is verified; other hosts
remain unverified. First setup requires network access and several GiB of space.

**Priorities:** ordinary SQL, parameters/types, transactions, joins, grouping,
indexes, persistence and errors first. Full-text is optional follow-up work.
See the [native binding](examples/native/README.md) and the
[IMDb relational workload](examples/imdb/README.md) for full-data preparation.
**Full-scale IMDb results:** 12.8 million titles and 1.7 million ratings, with
matching query results across both engines. SQLite was faster and smaller in this
experiment. See the [report and raw evidence](examples/imdb/REPORT.md); FTS remains
optional follow-up.

For the older container experiment below, `./reproduce.sh compat` builds the images,
fetches the public demo and runs the DBAL examples and Symfony suite. `all` runs
native then compat; Docker is required only for those explicitly selected modes.
The container path was not rerun during the native-script change.

## Earlier SQLite-library compatibility experiment

Use the ordinary PDO SQLite driver with Turso's Rust SQLite-compatible engine. No custom Doctrine driver or application source patches.

**Verified:** the unmodified [Symfony Demo v2.8.1](https://github.com/symfony/demo/tree/c1691a84ccf7a4836d7a48355562b3c300924db5) passes **51 tests / 115 assertions** on stock SQLite and Turso PR #8498. Its blog also serves through Debian-based FrankenPHP. This example needs no experimental feature opt-in.

This is an experiment, not a production-ready SQLite replacement. See [findings](REPORT.md), including read-only enforcement and error-reporting differences.

## Reproduce from a clean checkout

Requirements: Docker/BuildKit with about 8 GB or more available memory, Python 3, Git, Bash, and internet access during setup. No host PHP, Composer, Symfony CLI, or private dataset is required. The verified platform is **Linux arm64** (containers on Apple Silicon). Other architectures have not been validated.

```bash
git clone https://github.com/survos-sites/turso-php-compat.git
cd turso-php-compat
./build.sh
./fetch-demo.sh
./demo.sh stock
./demo.sh turso
```

`build.sh` builds the pinned Turso source and four PHP images: stock/Turso PHP CLI, and stock/Turso Debian FrankenPHP. Initial builds take several minutes. Build output is saved under `results/`. It uses `--load`, so images are available locally even with a docker-container BuildKit builder. Nothing is pushed to a container registry.

`fetch-demo.sh` downloads the official Symfony Demo SQLite database from commit `c1691a84ccf7a4836d7a48355562b3c300924db5`, verifies SHA-256 `8c4c99c238c083e52aaefd060185d0c55f2a719a7ab15d721e8fa375aea35b2e`, and checks database integrity. The dataset has 30 blog posts, users, comments, and tags. It is fetched on demand, not committed here. Upstream distributes the demo under the [MIT license](https://github.com/symfony/demo/blob/c1691a84ccf7a4836d7a48355562b3c300924db5/LICENSE).

`demo.php` is a short DBAL program: it opens a disposable copy, runs a join/count query and a parameterized tag query, and prints JSON. Both engines should return the same post data; the `engine` label differs. The harness locks PHP 8.4 / DBAL 4.5.0 / ORM 3.5.8 / Symfony 7.3.x dependencies.

To serve that DBAL example through FrankenPHP:

```bash
./serve.sh
# Open http://localhost:8498/dbal.php
# Stop/remove it when finished:
docker stop turso-php-compat-demo
docker rm turso-php-compat-demo
```

## Prove the actual Symfony application works

This uses the official application's own code, templates, database and Composer lock, without changing its database driver or SQL. At the pinned revision, its lock contains Symfony 7.3.2, DBAL 4.3.2 and ORM 3.5.2.

```bash
./examples/symfony/setup.sh
./examples/symfony/test.sh
# Expected on both engines: OK (51 tests, 115 assertions)
./examples/symfony/serve.sh turso
# Open http://localhost:8499/en/blog/
# Optional comparison server:
./examples/symfony/serve.sh stock
# Open http://localhost:8500/en/blog/
```

Setup fetches the pinned commit into ignored `work/symfony-demo`, installs its locked dependencies inside the stock PHP container, and runs its normal asset/setup scripts. The upstream scripts create `.env.local`; the application/test runs can modify the disposable databases and cache. No migrations, fixtures reload, or application source changes are necessary. Test output is saved locally in `results/symfony-full-{stock,turso}.txt`.

The upstream suite includes blog reads, search, login/authorization checks, and a comment-write flow. The HTTP server is an additional real FrankenPHP check. These are compatibility tests, not performance benchmarks.

Stop a server with `docker stop turso-symfony-demo` (or `stock-symfony-demo`); remove its container before recreating it. All example ports bind only to localhost.

## What changes underneath PHP

```text
Symfony → Doctrine → PHP PDO SQLite → libsqlite3
                                    ↘ libturso_sqlite3
```

The images compile SQLite/PDO into PHP. `tests/rebuild-php.sh` rebuilds the unchanged PHP source against Turso's header and library. For FrankenPHP it also rebuilds the ZTS embedded `libphp.so`; the FrankenPHP executable stays unchanged. It uses `SQLITE_CFLAGS=-I/opt/turso/include` and `SQLITE_LIBS=-L/opt/turso/lib -Wl,-rpath,/opt/turso/lib -lturso_sqlite3`.

PHP's configure probes separately hard-code `-lsqlite3`. The script creates a build-time `libsqlite3.so` alias to Turso so those probes do not accidentally inspect stock SQLite. Turso lacks SQLite's loadable-extension API, so that PHP capability is compiled out.

The base experiment pins draft PR #8498 at `faac0360a3304b598da068a9c6fce356d532c195`; image bases and Composer dependencies are also pinned. Debian package mirrors are not snapshotted, so this is a reproducible recipe rather than a promise of byte-identical rebuilds. The C library and CLI are built together, which includes the CLI's core features, including FTS, through Cargo feature unification.

## Development with Symfony CLI

FrankenPHP is not required for development. A separate native PHP CLI/FPM installation linked to Turso can be selected explicitly:

```bash
# Illustrative: this native development runtime has not been built yet.
export SYMFONY_CLI_PHP_BINARY_PATH="/absolute/path/to/turso-php/bin/php"
symfony php -v
symfony console about
symfony server:start
```

Symfony CLI detects companion FPM/CGI binaries in a conventional installation. Your stock PHP can remain installed. Native macOS development needs macOS PHP and Turso's `.dylib`; the Linux `.so` runtime is not usable natively on a Mac. See [Symfony's PHP selection documentation](https://github.com/symfony-cli/phpstore#version-selection).

## Additional experiments and evidence

The older `run.sh` / `run-experimental.sh` suite covers 24 targeted cases, including synthetic Folio and public Pixie fixtures. It is optional and separate from the simpler Symfony demo. It deliberately exits non-zero on known mismatches, after saving results. A real local Folio can be selected explicitly with `python3 tests/prepare_fixtures.py --folio /path/to/checkpointed.folio`.

**No local Folio or raw dataset results are committed.** `fixtures/`, `results/` and `work/` are ignored. Historical real-Folio results in [REPORT.md](REPORT.md) describe the earlier local run; they are not the clean-checkout default. Local provenance and raw JSON files mentioned there are generated evidence, not downloadable repository files.

The Symfony 7.3 pins intentionally reproduce the requested historical stack. Regenerating the harness lock required Composer's advisory-blocking override. That exception is confined to this experimental project; it does not update or weaken any application configuration.

## Native Turso driver experiment

[Native PHP FFI + DBAL prototype](examples/native/README.md) opens Turso through its
SDK kit alongside unchanged PDO SQLite. It needs neither a custom PHP build nor
Podman. PHP 8.4 / DBAL 4 smoke tests pass for both connections; this is separate
from the SQLite compatibility experiments above.
