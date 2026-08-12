# Changelog

## Unreleased

### Added
- `blockFields` setting: explicit field handles per matrix block type, overriding
  the built-in `richText`/`plainText`/`blockContent` guesses in `ChunkingService`.
  Sites whose blocks carry their text in headings, intros or overlines had that
  content silently left out of the index.
- A handle listed in `blockFields` may point at a nested Matrix field or at an
  Entries relation. Both are element queries in Craft 5, so one code path follows
  sub-blocks and reusable content fragments alike, attributing their text to the
  entry being indexed. That is what you want for a fragment: it has no URL of its
  own, but its text is rendered on the page that includes it, so it belongs in
  that page's chunks. `maxBlockDepth` (default 3) plus a per-path visited set
  bound the recursion and break relation cycles.
  A related entry contributes nothing when its body yields no text, so a fragment
  built only from blocks you did not configure no longer produces a chunk that is
  just its heading. Its title is prefixed for context unless the first block
  already opens with it.
- `recentMonthsSections` setting: restrict a section to a rolling "last N months"
  window (map of `handle => months`), as an alternative to the fixed-calendar
  `currentYearOnlySections`. Enforced both at index time (`IndexEligibilityService`)
  and query time (the generated `match_chunks_hybrid` RPC). Rolling months take
  precedence over the calendar-year window when a section is in both lists.

## 1.0.0 - 2026-07-22

### Added
- Initial release: a reusable Craft 5 plugin, merged from the two
  `syntheseEngine` modules (Viesrood + Van Meijel).
- Hybrid retrieval: vector (pgvector/HNSW) + full-text (GIN) via Reciprocal Rank
  Fusion, local rerank (title/section/freshness boosts) and an answerability gate.
- OpenAI embeddings, Google Gemini synthesis with cited sources.
- Operational layer: bot detection, per-IP + global rate limiting, daily budget,
  answer cache.
- CP: dashboard, tools (connection test, reindex, truncate, SQL generation) and a
  settings screen; dashboard widget.
- Settings model as the single source of config (+ `config/synthese-engine.php`
  override); no site-specific handles or branding left in the plugin code.
- Extension points `EVENT_EXTRACT_CONTENT` and `EVENT_FORMAT_SOURCE` plus the
  `sourceFormatters` config.
- Parameterized Supabase setup SQL (`synthese-engine/setup/supabase`).
- English source strings with a bundled Dutch (`nl`) translation.
- Console: `index/*`, `stats/*`, `setup/supabase`.

### Fixed
- Answerability gate default `answerabilityMinSimilarity` lowered from 0.60 to
  0.35. For `text-embedding-3-small`, relevant matches typically score ~0.4-0.55,
  so 0.60 rejected legitimate questions with a "no information found" answer.
