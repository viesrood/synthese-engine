# Changelog

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
