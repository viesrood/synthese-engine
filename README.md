# Synthese Engine

AI-gedreven semantische zoekfunctie voor Craft CMS 5. Indexeert Craft-entries als
vector-embeddings in Supabase (pgvector + full-text), zoekt hybride (vector + FTS
via Reciprocal Rank Fusion), herrangschikt lokaal, en synthetiseert een geciteerd
antwoord met Google Gemini - met een answerability-gate die de LLM overslaat bij
zwakke retrieval.

## Stack

| Onderdeel | Technologie |
|---|---|
| Vector-store | Supabase (PostgreSQL + pgvector, HNSW + GIN-FTS) |
| Embeddings | OpenAI `text-embedding-3-small` (1536 dim) |
| Synthese | Google Gemini `gemini-2.5-flash-lite` |
| Cache / rate-limiting | Craft-cache (Redis aanbevolen) |

## Installatie

```bash
composer require viesrood/synthese-engine
php craft plugin/install synthese-engine
```

Zet de vereiste env-vars (zie hieronder), provision Supabase (zie "Supabase"),
configureer je sections in `config/synthese-engine.php`, en indexeer:

```bash
php craft synthese-engine/index/all --verbose
php craft synthese-engine/stats/test
```

## Env-vars

```env
SUPABASE_URL="https://xxxxx.supabase.co"
SUPABASE_SERVICE_KEY="service-role-key"
OPENAI_API_KEY="sk-..."
GEMINI_API_KEY="..."
SYNTHESE_DAILY_BUDGET_USD="1.00"   # optioneel
```

De service-role-key wordt alleen server-side gebruikt (`VectorService`); een
publishable/anon-key wordt geweigerd.

## Supabase

De vector-store staat los van Craft en moet eenmalig geprovisioned worden. Genereer
de exacte SQL uit je instellingen en draai die in de Supabase SQL-editor:

```bash
php craft synthese-engine/setup/supabase
```

Dit maakt de chunk-tabel, de HNSW- en FTS-indexen, de hybride-RPC en de RLS-grants -
met jouw `ftsLanguage`, `timezone`, embedding-dimensies en "huidig jaar"-secties
ingevuld. Ook zichtbaar onder **CP -> Synthese Engine -> Tools**.

## Configuratie

Tuning- en merkinstellingen zijn beheerbaar via **CP -> Instellingen -> Plugins ->
Synthese Engine**. De structurele content-configuratie (welke sections/velden
geindexeerd worden) hoort in `config/synthese-engine.php` (ondersteunt
multi-environment); zie `docs/config.example.php`. Belangrijkste keys:

- `includeSections` / `excludeSections` - index-scope.
- `fieldConfig` - per section: `fields` (scalar, incl. pseudo-velden `_author`,
  `_dateCreated`, `_url`) en `matrixFields` (matrix-handle -> bloktype-handles).
- `sectionContext` - semantische hint per section, meegeembed voor betere matching.
- `sectionBoosts` - rerank-multiplier per section.
- `currentYearOnlySections` - secties die alleen in het huidige kalenderjaar meetellen.
- `sourceFormatters` - per section de bron-URL/titel aanpassen.
- `siteName`, `systemPrompt`, `notAnswerableMessage`, `exampleQueries`.

## Front-end

De plugin exposeert:

- `POST /api/synthese/search` (`{query}`) -> `{success, answer, sources[], cached}`
- `GET  /api/synthese/health`

De prefix is instelbaar (`routePrefix`). In Twig: `craft.synthese.searchUrl`,
`craft.synthese.isConfigured`, `craft.synthese.exampleQueries`. Zie
`example-templates/` voor een eenvoudige zoek- en resultatenweergave (ongestyled).

## Extension points

Site-specifieke logica hoort niet in de plugin. Gebruik events:

- `ChunkingService::EVENT_EXTRACT_CONTENT` - extra/aangepaste chunks toevoegen.
- `SynthesisService::EVENT_FORMAT_SOURCE` - titel/URL van een bron aanpassen.

Of config: `sourceFormatters` voor eenvoudige URL/titel-overrides per section.

## Console-commando's

| Commando | Beschrijving |
|---|---|
| `synthese-engine/index/all [--verbose]` | Alle indexeerbare live-entries |
| `synthese-engine/index/section <handle>` | Een section |
| `synthese-engine/index/entry <id>` | Een enkele entry |
| `synthese-engine/index/truncate` | Alle chunks verwijderen |
| `synthese-engine/stats` | Overzicht |
| `synthese-engine/stats/test` | Verbindingen testen |
| `synthese-engine/stats/recent [n]` | Recente zoekopdrachten |
| `synthese-engine/setup/supabase` | Supabase-SQL genereren |

## Licentie

MIT. Zie `LICENSE.md`.
