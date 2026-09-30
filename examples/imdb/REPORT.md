# Native Turso versus SQLite: IMDb results

Measured 2026-09-30. **Both native DBAL drivers returned identical results at full scale. SQLite was faster and smaller on this workload and implementation.** This is an application-path experiment, not a universal engine ranking.

## Dataset and setup

Official IMDb non-commercial snapshots: **12,824,993 titles and 1,715,569 ratings**. All title kinds are included, not just movies. Baseline: 1,509,404,672 bytes. No OCR, generated columns, captions, FTS, or vectors.

PHP 8.4.25, DBAL 4.3.2, stock PDO SQLite versus the custom PHP FFI native Turso SDK driver. Turso source `faac0360a3304b598da068a9c6fce356d532c195`, SDK `lib-release` profile (optimized, no LTO). The separate bindings/c UNIQUE patch is not part of this native SDK path. No PHP rebuild or SQLite replacement.

Apple M4 Pro (14 cores), 48 GB RAM, macOS 27.0; both databases on the same X10 USB SSD/APFS volume. Both report WAL journal mode and synchronous=2 (FULL). Equal settings do not prove equal durability under failure; no crash-recovery tests were performed.

Each run copies the same baseline, builds the same three ordinary indexes, checks table counts, executes each query four times, compares ordered result hashes, and checkpoints/truncates WAL. Run A is SQLite first; run B reverses engine order. Copy time is excluded from index time.

## Build time and size

| Metric | SQLite A / B | Turso A / B |
| --- | ---: | ---: |
| All three indexes (seconds) | 16.926 / 15.443 | 51.753 / 52.096 |
| Explicit checkpoint (seconds) | 0.009 / 0.009 | 0.003 / 0.003 |
| Final database + sidecars (bytes, same both runs) | 2,342,457,344 | 2,454,462,464 |

Turso used **4.78% more final space**. Its remaining WAL was zero bytes. Pre-checkpoint WAL/SHM sizes and checkpoint results are retained in the raw reports; intermediate file sizes are not the final database size. Automatic checkpoint work can occur during index creation.

## Query latency

Median of the three subsequent executions, in milliseconds. First executions are retained in raw results; none are called cold-cache measurements.

| Query | SQLite A / B (ms) | Turso A / B (ms) |
| --- | ---: | ---: |
| year_filter | 0.043 / 0.036 | 0.140 / 0.138 |
| title_lookup | 0.018 / 0.017 | 0.093 / 0.093 |
| join_top_votes | 0.088 / 0.102 | 68.059 / 68.510 |
| group_counts | 2677.177 / 2664.448 | 4131.576 / 4134.163 |
| missing_rating | 0.180 / 0.185 | 0.821 / 0.831 |

Every query matched across both engines and all executions: year range, exact title lookup, ordered ratings join, grouping across all titles, and missing-rating left join. Most queries intentionally return at most 100 rows; the group query scans the whole title table and returns 11 groups. This does not test large result streaming.

## Ordered-join diagnostic

The original query orders by `r.votes DESC, t.id`. Turso reports a general sorter; SQLite reports sorting only the last order term. Changing only the equivalent tie-break expression to `r.title_id` removes the sorter in both plans.

| Ordering expression | SQLite median (ms) | Turso median (ms) |
| --- | ---: | ---: |
| `r.votes DESC, t.id` | 0.097 | 66.513 |
| `r.votes DESC, r.title_id` | 0.061 | 0.370 |

All four variants returned the same ordered-result hash. This supports an optimizer/order-inference explanation for much of the join gap, without proving every internal cause. The original workload is unchanged; the rewrite is a separate diagnostic. No upstream comment has been posted.

## Correctness and limits

- Optimized native smoke tests also passed create/read/insert, positional/named parameters, Unicode, NULL/boolean/integer handling, last-insert ID, UNIQUE errors, commit/rollback, persistence, JSON queries, updates/deletes and index operations.
- This is a thin prototype driver: buffered results, generic exception mapping, no binary/LOB binding. FFI versus PDO overhead contributes to small-query timings. ORM/schema tooling is not established by these checks.
- Two order-reversed runs and three subsequent timings per query establish repeatability here, not statistical tail-latency or universal performance claims. No controlled OS cache eviction, concurrency, crash durability, or full import-speed comparison.
- The shared baseline was imported once using stock SQLite. We measured index construction on both copies, not native Turso bulk ingestion. Original TSV snapshots, hashes, and local databases are retained on external storage.
- The reverse run took 110.86 seconds overall; OS maximum resident set was 2,055,012,352 bytes for the combined process. This is not a per-engine memory comparison.
- Full text is optional follow-up. [Cue inventory](../native/CUE.md) identifies 21 transcripts / 21,892 lines as a local multilingual functional fixture; no caption content is published. Vectors remain deferred.

## Reproduce and evidence

Follow [README](README.md). Use `ENGINE_ORDER=turso-first` for the reverse run and `join-diagnostic.php` for the ordering probe. Retain the source downloads: IMDb URLs change over time, so checksums identify this exact snapshot.

- [SQLite-first raw report](evidence/sqlite-first.json)
- [Turso-first raw report](evidence/turso-first.json)
- [Join diagnostic](evidence/join-diagnostic.json)
- [Environment](evidence/environment.json)

No application or production configuration was changed. Dataset copies are outside the repository.
