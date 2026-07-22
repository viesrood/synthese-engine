# Changelog

## 1.0.0 - Unreleased

### Toegevoegd
- Eerste release: herbruikbare Craft 5-plugin, samengevoegd uit de twee
  `syntheseEngine`-modules (Viesrood + Van Meijel).
- Hybride retrieval: vector (pgvector/HNSW) + full-text (GIN) via Reciprocal Rank
  Fusion, lokale rerank (title-/section-/versheid-boosts) en answerability-gate.
- OpenAI-embeddings, Google-Gemini-synthese met geciteerde bronnen.
- Operationele laag: bot-detectie, per-IP + globale rate-limiting, dagbudget,
  antwoord-cache.
- CP: dashboard, tools (verbindingstest, herindex, truncate, SQL-generatie) en
  instellingenscherm; dashboard-widget.
- Settings-model als enige config-bron (+ `config/synthese-engine.php`-override);
  geen site-specifieke handles of merktekst meer in de plugincode.
- Extension points `EVENT_EXTRACT_CONTENT` en `EVENT_FORMAT_SOURCE` plus
  `sourceFormatters`-config.
- Geparametriseerde Supabase-setup-SQL (`synthese-engine/setup/supabase`).
- Console: `index/*`, `stats/*`, `setup/supabase`.
