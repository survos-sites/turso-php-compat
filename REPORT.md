# Observed results — 2026-09-30

**The proof of concept works:** ordinary Doctrine DBAL can read an existing Folio through Turso, including joins, prepared parameters and its JSON-based virtual generated column. Debian-based FrankenPHP also successfully serves a Symfony 7.3 request and Folio reads. This currently requires an additional startup shim enabling Turso's experimental features; the default library cannot open this Folio.

## Tested configuration

- Turso PR #8498, commit `faac0360a3304b598da068a9c6fce356d532c195`.
- PHP 8.4.26, non-ZTS CLI and ZTS embedded PHP in Debian Bookworm FrankenPHP.
- Doctrine DBAL 4.5.0 / ORM 3.5.8; Symfony framework and components 7.3.x, including FrameworkBundle/HttpKernel 7.3.11.
- Linux arm64 containers. No AMD64 host or Dokku deployment was tested.
- Real local `smith/nmafa` bare Folio: 117 items, 3 cores, 193 links, 126 terms. Database copied unchanged; no application indexes were added. Its SHA-256 was `edf1e82b909c4229a699dfb06cdc35b36bf49b1778114448e52eecffd392fc15`.
- Synthetic minimal database and pinned Survos Pixie fixture.

## Differential results

| Target | Matches stock | Outcome |
| --- | ---: | --- |
| Stock PHP CLI | 24/24 successful cases | Baseline |
| Stock FrankenPHP PHP CLI | 24/24 successful cases | Baseline |
| Turso CLI, default, real Folio | 0/6 | Generated-column feature gate prevents opening |
| Turso CLI, generated columns enabled | 6/6 | Counts, joins, JSON, generated sorting and translations match |
| Turso PHP CLI, default | 13/24 | Basic DBAL, ORM CRUD and Symfony pass; Folio open is blocked |
| Turso FrankenPHP PHP CLI, default | 13/24 | Same results |
| Turso PHP CLI, experimental startup shim | 21/24 | All real-Folio cases, including DBAL introspection, match |
| Turso FrankenPHP PHP CLI, experimental startup shim | 21/24 | Same results |
| Stock FrankenPHP HTTP | HTTP 200 | Symfony kernel + Folio counts |
| Turso FrankenPHP HTTP, default | HTTP 500 | Folio open reports misleading “out of memory” |
| Turso FrankenPHP HTTP, experimental startup shim | HTTP 200 | Symfony kernel + correct Folio counts |

These fractions describe this focused suite, not a rerun of the PR author's 287 upstream PHP tests. A case matches only when its returned value equals the stock result.

## Three remaining differences with the startup shim

1. **PDO error reporting on a UNIQUE violation is wrong.** The constraint itself is enforced: only the original row remains. But `PDO::exec()` returns `false`, raises no exception despite `ERRMODE_EXCEPTION`, and `errorInfo()` reports `['00000', null, null]`. Stock throws SQLSTATE `23000`, driver code 19. See `tests/constraint-diagnostic.php`. This is more than an extended-result-code distinction.
2. **The C API does not enforce URI `mode=ro`.** A deliberate INSERT into a disposable copy succeeds, increasing the row count from 3 to 4; stock rejects it with driver code 8. The source fixtures and original local database remain untouched. Do not treat that URI option as write protection in this revision.
3. **The older Pixie fixture uses a STORED generated column.** Turso CLI explicitly reports “Stored generated columns are not supported”. Enabling experimental generated columns does not solve this. PHP's failed-open path obscures the reason as `SQLSTATE[HY000] [21] out of memory`.

## Build findings

The existing images compile SQLite/PDO directly into PHP. The working recipe rebuilds unmodified PHP source and, for FrankenPHP, its ZTS `libphp.so`; the FrankenPHP executable is unchanged.

Use Turso's header and `-lturso_sqlite3`. Also create a build-time `libsqlite3.so` alias pointing to Turso: PHP's configure probes otherwise detect the installed stock SQLite library and enable `sqlite3_load_extension`, causing a link failure. The successful binaries load `/opt/turso/lib/libturso_sqlite3.so`; the baseline loads system `libsqlite3.so.0`. Linkage and process-library evidence are in local result artifacts.

The startup shim calls the existing `turso_enable_experimental()` export before PHP starts. It enables generated columns, vacuum, WITHOUT ROWID and ATTACH globally. This is a runtime packaging adaptation, not a PHP or Doctrine source change.

## Application impact and scope

The DBAL demo uses the normal `pdo_sqlite` driver and existing SQLite platform. No custom driver, SQL dialect, ORM patch, Folio schema rewrite, or application architecture change is needed for the passing read cases. The current build/runtime configuration **does** need the changes above. The three differences prevent calling this a generally transparent or production-ready replacement.

Not tested: a complete Folio application, production concurrency/locking, performance, Turso-specific FTS or LLM capabilities, AMD64, or Dokku deployment. Those are separate next experiments. The repository's clean-checkout default is synthetic; the 117-item real-Folio results above came from the local, excluded fixture.

## Local artifacts

`results/` contains raw case JSON, differential comparisons, CLI output, HTTP logs, build logs, linkage evidence, exact image identifiers and Composer's advisory report. `fixtures/provenance.json` records local fixture origins/hashes. These files are intentionally excluded from Git because some include local fixture content. This report and the synthetic reproducers are safe to inspect independently.
