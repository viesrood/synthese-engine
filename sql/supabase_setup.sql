-- =============================================================================
-- Synthese Engine - Supabase setup (REFERENCE)
-- =============================================================================
-- Do NOT run this file blindly. Generate the SQL that exactly matches your
-- settings (table/RPC name, embedding dimensions, FTS language, timezone and the
-- "current year" sections):
--
--     php craft synthese-engine/setup/supabase
--
-- or via CP -> Synthese Engine -> Tools. Paste the output into the Supabase SQL
-- editor.
--
-- Below is example output (FTS language 'english', no year filter, 1536
-- dimensions, table content_chunks, RPC match_chunks_hybrid) purely to
-- illustrate what gets created: the pgvector extension, the chunk table, an HNSW
-- index, a generated FTS column + GIN index, the hybrid RRF RPC and RLS grants
-- for the service_role only.
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

-- ... HNSW index, FTS column/index, match_chunks_hybrid RPC and RLS grants:
-- see the generated output of synthese-engine/setup/supabase.
