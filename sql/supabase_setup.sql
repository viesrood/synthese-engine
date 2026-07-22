-- =============================================================================
-- Synthese Engine - Supabase setup (REFERENTIE)
-- =============================================================================
-- Draai NIET dit bestand klakkeloos. Genereer de SQL die exact matcht met jouw
-- instellingen (tabel-/RPC-naam, embedding-dimensies, FTS-taal, timezone en de
-- "huidig jaar"-secties):
--
--     php craft synthese-engine/setup/supabase
--
-- of via CP -> Synthese Engine -> Tools. Plak de output in de Supabase SQL-editor.
--
-- Onderstaand een voorbeeld-uitvoer (FTS-taal 'dutch', geen jaar-filter,
-- 1536 dimensies, tabel content_chunks, RPC match_chunks_hybrid) puur ter
-- illustratie van wat er wordt aangemaakt: pgvector-extensie, de chunk-tabel,
-- een HNSW-index, een gegenereerde FTS-kolom + GIN-index, de hybride RRF-RPC en
-- RLS-grants voor uitsluitend de service_role.
-- =============================================================================

CREATE SCHEMA IF NOT EXISTS extensions;
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA extensions;

CREATE TABLE IF NOT EXISTS public.content_chunks (
    id BIGSERIAL PRIMARY KEY,
    entry_id INTEGER NOT NULL,
    site_id INTEGER NOT NULL DEFAULT 1,
    section TEXT NOT NULL,
    entry_type TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    chunk_index SMALLINT NOT NULL DEFAULT 0,
    chunk_type TEXT NOT NULL DEFAULT 'body',
    text TEXT NOT NULL,
    embedding extensions.VECTOR(1536),
    post_date TIMESTAMP WITH TIME ZONE,
    indexed_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE (entry_id, site_id, chunk_index)
);

-- ... HNSW-index, FTS-kolom/index, match_chunks_hybrid-RPC en RLS-grants:
-- zie de gegenereerde output van synthese-engine/setup/supabase.
