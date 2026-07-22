<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use viesrood\synthese\models\Settings;

/**
 * SupabaseSqlBuilder
 *
 * Generates the ready-to-paste Supabase setup SQL from the settings (table/RPC
 * name, embedding dimensions, FTS language, timezone and the per-section
 * "current year" filter). This keeps site-specific literals ('dutch',
 * 'Europe/Amsterdam', section names) out of the plugin code.
 */
class SupabaseSqlBuilder
{
    public function build(Settings $settings): string
    {
        $table = $this->ident($settings->supabaseTable);
        $rpc = $this->ident($settings->matchRpc);
        $dims = (int) $settings->embeddingDimensions;
        $lang = $this->literal($settings->ftsLanguage ?: 'simple');
        $seq = $table . '_id_seq';
        $yearFilter = $this->yearFilter($settings);

        return <<<SQL
-- =============================================================================
-- Synthese Engine - Supabase setup SQL (generated)
-- Run this in the Supabase SQL editor.
-- =============================================================================

-- 1. pgvector outside the API-exposed public schema
CREATE SCHEMA IF NOT EXISTS extensions;
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA extensions;

-- 2. Chunk table
CREATE TABLE IF NOT EXISTS public.{$table} (
    id          BIGSERIAL PRIMARY KEY,
    entry_id    INTEGER   NOT NULL,
    site_id     INTEGER   NOT NULL DEFAULT 1,
    section     TEXT      NOT NULL,
    entry_type  TEXT      NOT NULL DEFAULT '',
    url         TEXT      NOT NULL DEFAULT '',
    title       TEXT      NOT NULL DEFAULT '',
    chunk_index SMALLINT  NOT NULL DEFAULT 0,
    chunk_type  TEXT      NOT NULL DEFAULT 'body',
    text        TEXT      NOT NULL,
    embedding   extensions.VECTOR({$dims}),
    post_date   TIMESTAMP WITH TIME ZONE,
    indexed_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE (entry_id, site_id, chunk_index)
);

-- 3. HNSW index for fast nearest-neighbour search
CREATE INDEX IF NOT EXISTS {$table}_embedding_hnsw_idx
    ON public.{$table}
    USING hnsw (embedding extensions.vector_cosine_ops)
    WITH (m = 16, ef_construction = 64);

-- 4. Full-text-search column + GIN index
ALTER TABLE public.{$table}
    ADD COLUMN IF NOT EXISTS fts TSVECTOR
    GENERATED ALWAYS AS (to_tsvector({$lang}, coalesce(title, '') || ' ' || coalesce(text, ''))) STORED;

CREATE INDEX IF NOT EXISTS {$table}_fts_idx
    ON public.{$table}
    USING GIN (fts);

-- 5. Hybrid search RPC (Reciprocal Rank Fusion)
CREATE OR REPLACE FUNCTION public.{$rpc}(
    query_embedding extensions.VECTOR({$dims}),
    query_text      TEXT,
    match_count     INT DEFAULT 20
)
RETURNS TABLE (
    id          BIGINT,
    entry_id    INTEGER,
    site_id     INTEGER,
    section     TEXT,
    entry_type  TEXT,
    url         TEXT,
    title       TEXT,
    chunk_index SMALLINT,
    chunk_type  TEXT,
    text        TEXT,
    post_date   TIMESTAMP WITH TIME ZONE,
    similarity  FLOAT,
    rrf_score   FLOAT
)
LANGUAGE SQL STABLE
SET search_path = ''
AS \$\$
    WITH
    vector_results AS (
        SELECT
            c.id, c.entry_id, c.site_id, c.section, c.entry_type, c.url, c.title,
            c.chunk_index, c.chunk_type, c.text, c.post_date,
            1 - (c.embedding OPERATOR(extensions.<=>) query_embedding) AS similarity,
            ROW_NUMBER() OVER (ORDER BY c.embedding OPERATOR(extensions.<=>) query_embedding) AS vector_rank
        FROM public.{$table} c
        WHERE c.embedding IS NOT NULL{$yearFilter}
        ORDER BY c.embedding OPERATOR(extensions.<=>) query_embedding
        LIMIT match_count * 2
    ),
    fts_results AS (
        SELECT
            c.id,
            ROW_NUMBER() OVER (ORDER BY ts_rank_cd(c.fts, websearch_to_tsquery({$lang}, query_text)) DESC) AS fts_rank
        FROM public.{$table} c
        WHERE c.fts @@ websearch_to_tsquery({$lang}, query_text){$yearFilter}
        ORDER BY ts_rank_cd(c.fts, websearch_to_tsquery({$lang}, query_text)) DESC
        LIMIT match_count * 2
    ),
    combined AS (
        SELECT
            v.id, v.entry_id, v.site_id, v.section, v.entry_type, v.url, v.title,
            v.chunk_index, v.chunk_type, v.text, v.post_date, v.similarity,
            COALESCE(1.0 / (60 + v.vector_rank), 0) + COALESCE(1.0 / (60 + f.fts_rank), 0) AS rrf_score
        FROM vector_results v
        LEFT JOIN fts_results f ON f.id = v.id
    )
    SELECT
        id, entry_id, site_id, section, entry_type, url, title,
        chunk_index, chunk_type, text, post_date, similarity, rrf_score
    FROM combined
    ORDER BY rrf_score DESC
    LIMIT match_count;
\$\$;

-- 6. Only the backend service role may read/write (RLS)
ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY;

REVOKE ALL ON TABLE public.{$table} FROM PUBLIC, anon, authenticated;
REVOKE ALL ON SEQUENCE public.{$seq} FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.{$rpc}(extensions.VECTOR, TEXT, INTEGER) FROM PUBLIC, anon, authenticated;

GRANT SELECT, INSERT, UPDATE, DELETE ON public.{$table} TO service_role;
GRANT EXECUTE ON FUNCTION public.{$rpc}(extensions.VECTOR, TEXT, INTEGER) TO service_role;
GRANT USAGE, SELECT ON SEQUENCE public.{$seq} TO service_role;

DROP POLICY IF EXISTS "Backend service manages chunks" ON public.{$table};
CREATE POLICY "Backend service manages chunks"
    ON public.{$table}
    FOR ALL
    TO service_role
    USING (true)
    WITH CHECK (true);
SQL;
    }

    /**
     * Builds the per-section "current year" condition, or an empty string when
     * there are no currentYearOnlySections.
     */
    private function yearFilter(Settings $settings): string
    {
        $sections = array_values(array_filter($settings->currentYearOnlySections));
        if (empty($sections)) {
            return '';
        }

        $tz = $this->literal($settings->timezone ?: 'UTC');
        $array = implode(', ', array_map(fn($s) => $this->literal($s), $sections));

        return "\n          AND (\n"
            . "              c.section <> ALL (ARRAY[{$array}])\n"
            . "              OR (\n"
            . "                  c.post_date >= DATE_TRUNC('year', CURRENT_TIMESTAMP AT TIME ZONE {$tz}) AT TIME ZONE {$tz}\n"
            . "                  AND c.post_date < (DATE_TRUNC('year', CURRENT_TIMESTAMP AT TIME ZONE {$tz}) + INTERVAL '1 year') AT TIME ZONE {$tz}\n"
            . "              )\n"
            . "          )";
    }

    /** Safe SQL identifier (only [a-z0-9_]). */
    private function ident(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        return $clean !== '' ? $clean : 'content_chunks';
    }

    /** Safe SQL string literal. */
    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
