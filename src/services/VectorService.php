<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use viesrood\synthese\Plugin;

/**
 * VectorService
 *
 * Beheert de vector-store in Supabase (PostgreSQL + pgvector) via PostgREST.
 * Hybride retrieval (vector + full-text, RRF) via de `matchRpc`-functie.
 */
class VectorService extends Component
{
    private ?Client $client = null;
    private string $supabaseUrl = '';
    private string $serviceKey = '';

    public function init(): void
    {
        parent::init();
        $this->supabaseUrl = rtrim(App::env('SUPABASE_URL') ?? '', '/');
        $this->serviceKey = App::env('SUPABASE_SERVICE_KEY') ?? '';
    }

    public function isConfigured(): bool
    {
        if ($this->supabaseUrl === '' || $this->serviceKey === '') {
            return false;
        }
        // Een publishable/anon-key kan niet schrijven; wijs 'm af.
        return !str_starts_with($this->serviceKey, 'sb_publishable_');
    }

    private function table(): string
    {
        return '/rest/v1/' . Plugin::$plugin->getSettings()->supabaseTable;
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = Craft::createGuzzleClient([
                'base_uri' => $this->supabaseUrl,
                'headers' => [
                    'apikey' => $this->serviceKey,
                    'Authorization' => 'Bearer ' . $this->serviceKey,
                    'Content-Type' => 'application/json',
                    'Prefer' => 'return=minimal',
                ],
                'timeout' => Plugin::$plugin->getSettings()->vectorTimeout,
                'connect_timeout' => 5,
            ]);
        }

        return $this->client;
    }

    /**
     * Upsert chunks. Elke chunk is een platte rij die overeenkomt met de
     * `content_chunks`-kolommen (entry_id, site_id, section, entry_type, url,
     * title, chunk_index, chunk_type, text, embedding, post_date).
     *
     * @param array[] $chunks
     */
    public function upsert(array $chunks): void
    {
        if (empty($chunks) || !$this->isConfigured()) {
            return;
        }

        $this->getClient()->post($this->table(), [
            'headers' => ['Prefer' => 'resolution=merge-duplicates,return=minimal'],
            'json' => $chunks,
        ]);
    }

    /**
     * Hybride zoekopdracht via de RRF-RPC.
     *
     * @param float[] $queryEmbedding
     * @return array[]
     */
    public function hybridSearch(array $queryEmbedding, string $queryText, ?int $limit = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $limit ??= Plugin::$plugin->getSettings()->maxChunks;
        $rpc = '/rest/v1/rpc/' . Plugin::$plugin->getSettings()->matchRpc;

        $response = $this->getClient()->post($rpc, [
            'json' => [
                'query_embedding' => $queryEmbedding,
                'query_text' => $queryText,
                'match_count' => $limit,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        return is_array($body) ? $body : [];
    }

    public function deleteByEntryId(int $entryId, ?int $siteId = null): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $query = ['entry_id' => 'eq.' . $entryId];
        if ($siteId !== null) {
            $query['site_id'] = 'eq.' . $siteId;
        }

        $this->getClient()->delete($this->table(), ['query' => $query]);
    }

    public function deleteBySectionOutsideDateRange(string $section, \DateTimeInterface $start, \DateTimeInterface $end): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->getClient()->delete($this->table(), [
            'query' => [
                'section' => 'eq.' . $section,
                'or' => sprintf(
                    '(post_date.lt.%s,post_date.gte.%s,post_date.is.null)',
                    $start->format(\DateTimeInterface::ATOM),
                    $end->format(\DateTimeInterface::ATOM),
                ),
            ],
        ]);
    }

    /**
     * Verwijder alle chunks.
     */
    public function truncate(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->getClient()->delete($this->table(), [
            'query' => ['entry_id' => 'gte.0'],
        ]);
    }

    /**
     * @return array{chunks: int}
     */
    public function getStats(): array
    {
        if (!$this->isConfigured()) {
            return ['chunks' => 0];
        }

        $response = $this->getClient()->get($this->table(), [
            'query' => ['select' => 'id'],
            'headers' => ['Prefer' => 'count=exact', 'Range-Unit' => 'items', 'Range' => '0-0'],
        ]);

        $contentRange = $response->getHeaderLine('Content-Range'); // "0-0/1234"
        $total = 0;
        if (str_contains($contentRange, '/')) {
            $total = (int) substr($contentRange, strpos($contentRange, '/') + 1);
        }

        return ['chunks' => $total];
    }

    /**
     * @return array{success: bool, error?: string, chunks?: int}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'SUPABASE_URL/SUPABASE_SERVICE_KEY niet (juist) geconfigureerd'];
        }

        try {
            $stats = $this->getStats();
            return ['success' => true, 'chunks' => $stats['chunks']];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
