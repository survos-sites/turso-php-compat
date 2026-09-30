# Cue caption candidate (optional follow-up)

A read-only inventory of the developer's Cue checkout found two distinct datasets:

| Database relative to Cue | Content | Scale |
| --- | --- | --- |
| `subtitles.db` | OpenSubtitles catalogue metadata, no actual caption text | 5,877 movies; 10,416 subtitle records |
| `var/data.db` | App data including parsed transcripts in JSON | 21 transcripts; 17,297 timed groups; 21,892 caption lines |

The transcript corpus contains 16 Spanish and 5 English transcripts. `transcript_text`
is empty; actual text lives in `transcript.subtitles` JSON arrays of objects with
`start`, `end` and `lines`. Neither database currently has FTS virtual tables.

This is a useful multilingual functional FTS fixture, but not a large-scale corpus.
Keep the source SQLite database untouched. Agree on search-document granularity
(timed caption group versus whole transcript) and identical language/tokenization
settings before comparing indexes. Caption extraction for a derived search index
must not alter the source JSON. Vectors remain deferred.

No Cue databases or caption text are distributed in this repo. The app also contains
user tables that are irrelevant to the benchmark; an eventual fixture exporter
should select only explicitly required transcript fields. Source/redistribution
terms need to accompany any publicly distributed caption fixture.

The current full-scale benchmark uses official IMDb title and rating tables, not
Cue. Ordinary relational behavior and indexes have priority over full-text work.
