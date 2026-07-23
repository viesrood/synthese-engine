# Synthese Engine

AI-powered semantic search for Craft CMS 5. Indexes Craft entries as vector
embeddings in Supabase (pgvector + full-text), searches hybrid (vector + FTS via
Reciprocal Rank Fusion), reranks locally, and synthesizes a cited answer with
Google Gemini - with an answerability gate that skips the LLM on weak retrieval.

## Stack

| Component | Technology |
|---|---|
| Vector store | Supabase (PostgreSQL + pgvector, HNSW + GIN FTS) |
| Embeddings | OpenAI `text-embedding-3-small` (1536 dim) |
| Synthesis | Google Gemini `gemini-2.5-flash-lite` |
| Cache / rate limiting | Craft cache (Redis recommended) |

## Installation

```bash
composer require viesrood/synthese-engine
php craft plugin/install synthese-engine
```

Set the required env vars (see below), provision Supabase (see "Supabase"),
configure your sections in `config/synthese-engine.php`, and index:

```bash
php craft synthese-engine/index/all --verbose
php craft synthese-engine/stats/test
```

## Env vars

```env
SUPABASE_URL="https://xxxxx.supabase.co"
SUPABASE_SERVICE_KEY="service-role-key"
OPENAI_API_KEY="sk-..."
GEMINI_API_KEY="..."
SYNTHESE_DAILY_BUDGET_USD="1.00"   # optional
```

The service role key is only used server-side (`VectorService`); a
publishable/anon key is rejected.

## Supabase

The vector store is separate from Craft and must be provisioned once. Generate the
exact SQL from your settings and run it in the Supabase SQL editor:

```bash
php craft synthese-engine/setup/supabase
```

This creates the chunk table, the HNSW and FTS indexes, the hybrid RPC and the RLS
grants - with your `ftsLanguage`, `timezone`, embedding dimensions and
"current year" sections filled in. Also available under **CP -> Synthese Engine ->
Tools**.

## Configuration

Tuning and branding settings can be managed via **CP -> Settings -> Plugins ->
Synthese Engine**. The structural content configuration (which sections/fields are
indexed) belongs in `config/synthese-engine.php` (supports multi-environment); see
`docs/config.example.php`. Key options:

- `includeSections` / `excludeSections` - index scope.
- `fieldConfig` - per section: `fields` (scalar, incl. pseudo-fields `_author`,
  `_dateCreated`, `_url`) and `matrixFields` (matrix handle -> block type handles).
- `sectionContext` - semantic hint per section, embedded for better matching.
- `sectionBoosts` - rerank multiplier per section.
- `currentYearOnlySections` - sections that only count in the current calendar year.
- `recentMonthsSections` - sections that only count within a rolling window of the
  last N months (map of `handle => months`, e.g. `['news' => 6]`); slides forward
  daily and wins over `currentYearOnlySections` when a section is in both.
- `sourceFormatters` - override the source URL/title per section.
- `siteName`, `systemPrompt`, `notAnswerableMessage`, `exampleQueries`.

## Languages

The plugin's user-facing strings default to English and are translatable via the
`synthese-engine` translation category (a Dutch translation ships in
`src/translations/nl`). The built-in system prompt is language-neutral: it answers
in the same language as the question, so a Dutch site answers in Dutch out of the
box. Set `systemPrompt` explicitly to override.

## Front end

The plugin exposes:

- `POST /api/synthese/search` (`{query}`) -> `{success, answer, sources[], cached}`
- `GET  /api/synthese/health`

The prefix is configurable (`routePrefix`). In Twig: `craft.synthese.searchUrl`,
`craft.synthese.isConfigured`, `craft.synthese.exampleQueries`. See
`example-templates/` for a simple search and results view (unstyled).

## Extension points

Site-specific logic does not belong in the plugin. Use events:

- `ChunkingService::EVENT_EXTRACT_CONTENT` - add/adjust chunks.
- `SynthesisService::EVENT_FORMAT_SOURCE` - adjust a source's title/URL.

Or config: `sourceFormatters` for simple per-section URL/title overrides.

## Console commands

| Command | Description |
|---|---|
| `synthese-engine/index/all [--verbose]` | All indexable live entries |
| `synthese-engine/index/section <handle>` | A single section |
| `synthese-engine/index/entry <id>` | A single entry |
| `synthese-engine/index/truncate` | Delete all chunks |
| `synthese-engine/stats` | Overview |
| `synthese-engine/stats/test` | Test connections |
| `synthese-engine/stats/recent [n]` | Recent searches |
| `synthese-engine/setup/supabase` | Generate the Supabase SQL |

## License

MIT. See `LICENSE.md`.
