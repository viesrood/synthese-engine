# Changelog

## 1.3.0 - 2026-08-27

### Added
- The choice of whether questions typed by visitors may be collected at all now
  belongs to whoever runs the site, on Synthese Engine &rsaquo; Suggestions.
  Three options: collect and show after approval (the default), only questions an
  admin adds themselves, or no suggestions at all. Switching away from collecting
  also hides questions harvested earlier, even ones approved at the time, and a
  button deletes them outright. Questions added by hand are kept.
- `suggestions/forget` does the same from the console.
- New table `{{%synthese_state}}`. The mode, `suggestionMinAskers` and
  `logRetentionDays` live there rather than in the plugin settings, because
  plugin settings are project config: a file in the repository that a deploy
  checks out and re-applies. An admin switching collection off in the control
  panel would have it switched back on by the next deploy, silently. The plugin
  settings still supply the defaults, and the settings screen says where the real
  choice is made.
- `craft.synthese.learnsFromVisitors()`, so a template can tell visitors their
  questions are being kept only when that is actually happening.

### Changed
- `suggestionsEnabled` (boolean, added in 1.2.0 earlier today) is replaced by
  `suggestionMode`. A boolean could not express "keep the suggestions, stop
  collecting", which is the middle ground most sites want.

## 1.2.0 - 2026-08-27

### Added
- Learned suggestions. Questions visitors actually asked are harvested into
  clusters and, once an admin approves them, offered back to other visitors: in
  the suggestion chips (`craft.synthese.suggestedQueries()`) and as an "others
  also asked" list in the search response (`related`). Nothing a visitor types
  reaches the site without a person approving it first.
- `SuggestionsService`, two tables (`synthese_suggestions` and
  `synthese_suggestion_variants`), a Control Panel screen with a queue and an
  approved and rejected list, and the console commands
  `synthese-engine/suggestions/harvest`, `.../prune` and `.../list`.
- Clustering is two-stage: identical after normalisation first, which is free,
  then cosine over stored embeddings, which costs one embedding per genuinely
  new phrasing. Both stages share `QueryNormalizer` with the answer cache, so
  "same question" means the same thing everywhere.
- The embeddings live in MySQL as packed float32 blobs rather than in the vector
  store. There are at most a few hundred, a cosine loop in PHP is plenty, and a
  Supabase project on the free tier pauses after inactivity, which would leave a
  nightly job silently doing nothing.
- Related questions ride along on the embedding the search already computed, so
  they cost no extra API call. They are cached with the answer, which means a
  newly approved suggestion shows up there once the answer cache expires.
- New settings: `suggestionMinAskers`,
  `suggestionMinLength`, `suggestionMaxLength`, `suggestionClusterThreshold`,
  `suggestionBlocklist`, `suggestionsPerPage`, `relatedSuggestionsCount`,
  `relatedMinSimilarity` and `logRetentionDays`.
- `synthese_logs` gained `query_normalized`, `outcome`, `sources_count` and
  `harvested_at`. `outcome` ('answered', 'gated' or 'cached') replaces having to
  read `is_answerable` and `cache_hit` together, which meant two different things
  on two code paths and neither on the third. `sources_count` is the quality
  signal a suggestion is filtered on.
- `logRetentionDays` plus `suggestions/prune`: the log table holds free text
  typed by visitors next to a stable IP hash, and it had no retention at all.

### Notes on the cluster threshold
`suggestionClusterThreshold` defaults to 0.90 and should stay high. Measured
against a live index, a reworded duplicate ("what is X" against "X, what is that
exactly") scored 0.7241 while two genuinely different questions ("what is X"
against "what does X cost") scored 0.7106. No threshold separates those, because
short questions about one product score highly against each other regardless.
At 0.90 only near-identical phrasings fold together and the rest is left to the
person working the queue, which is the safe way round. The same scores are fine
for *ranking*, which is what `relatedMinSimilarity` (0.60) uses them for.

### Fixed
- `top_score` was never usable as a cross-query quality measure and is no longer
  treated as one. It carries the RRF fusion score from `RerankService`, roughly
  0.02 to 0.05, while the answerability gate compares raw cosine similarity
  against `answerabilityMinSimilarity`. Two different scales.

## 1.1.1 - 2026-08-27

### Fixed
- Inline citations now point at a source that actually exists. The prompt
  numbered the retrieved chunks while the rendered list numbers unique entries,
  so with `topK = 8` spread over four pages the model happily wrote `[7]` under
  a list of four. `buildSources()` (formerly `extractUniqueSources()`) now hands
  both the list and a chunk-to-source map to `formatChunksForPrompt()`: chunks
  from the same entry share one number, and no number can fall outside the list.
  Chunks that share a source repeat their header in the prompt rather than being
  merged, so the rerank order is left intact.
- The header of a chunk in the prompt uses the title its source ended up with,
  so what the model cites and what the visitor clicks carry the same name even
  when a `sourceFormatters` entry or an `EVENT_FORMAT_SOURCE` handler renamed it.
- Added a guard that removes citations the visitor cannot follow, in both the
  single (`[7]`) and the grouped (`[3, 4, 5]`) form. This covers the two cases
  the numbering fix cannot: a `noInfoPhrase` match empties the source list after
  the answer was written, and a model can always make a number up.

Note that sources are now built before the Gemini call rather than after, so
`EVENT_FORMAT_SOURCE` fires on every query, including the ones that end up
showing no sources at all.

## 1.1.0 - 2026-08-12

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
