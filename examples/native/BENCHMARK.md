# Full-scale Folio benchmark design

## Dataset and schema

Use an entire large bare Folio, not a sampled subset. The local candidate is a
4.7 GiB newspaper Folio with 1,311,865 items. Data files remain local and ignored.
The original is preserved unchanged. Record its SHA-256 and actual row counts.

Create separate SQLite and Turso derivatives from the same consistent source
snapshot. Preserve all JSON bytes and existing table definitions, including
virtual generated columns. Do not flatten JSON or create materialized DTO tables.
Inventory existing indexes: the current candidate already contains ordinary and
unique indexes despite living under `folio-bare`.

Apply the same additional ordinary-index definitions to both. For full text, index
the same fields and rows using SQLite FTS5 and Turso's native FTS. Their physical
index structures and syntax differ; match intended coverage, then record tokenizer,
stopword, stemming, token-length and ranking differences instead of assuming
identical search semantics. Probe indexing of virtual generated text columns first;
report unsupported behavior rather than silently materializing JSON fields.
No vector indexes or embeddings in this experiment.

## Measurement gates

1. Native binding correctness, including streaming/bounded memory access and error
   mapping. Current adapter buffers results and is only a connection prototype.
2. Small synthetic capability checks for generated columns, their ordinary indexes,
   and full-text indexing. These establish support, not performance.
3. Enough free space for two derivatives, FTS growth, WAL/temporary files, and builds.
   Do not start a full build when the remaining disk cannot accommodate it.
4. Equivalent data/schema checks before timing. Hash ordered logical results and
   compare row counts. Include search hit-set comparisons for agreed test terms.
5. Record engine versions, SDK feature flags, PHP/DBAL versions, build profile,
   machine resources, journal/durability settings and query plans. Debug Rust
   timings cannot establish production performance.

## Results to collect

- Copy/import time separately from ordinary-index and full-text build time.
- Base database size, indexed size, and total size including WAL/supporting files.
- Checkpoint time and post-checkpoint size reported separately.
- Repeated identical bounded-result queries: IDs, JSON predicates, generated-column
  filters, indexed sorting, pagination, joins and full-text searches.
- First-run and warm-run latency, median and tail values. Do not label a first run
  “cold cache” without controlling both database and OS caches.
- Peak process memory and matching result counts/checksums.

Execute one engine/build at a time with bounded compilation parallelism. Keep the
existing SQLite compatibility report separate from native SDK results. No full-scale
benchmark has been run yet.
