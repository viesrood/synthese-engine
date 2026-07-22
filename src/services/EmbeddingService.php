<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use viesrood\synthese\Plugin;

/**
 * EmbeddingService
 *
 * Converts text into vector embeddings via the OpenAI Embeddings API.
 */
class EmbeddingService extends Component
{
    private const OPENAI_BATCH_LIMIT = 100;

    private ?Client $client = null;

    private function getClient(): Client
    {
        if ($this->client === null) {
            $timeout = Plugin::$plugin->getSettings()->embeddingTimeout;
            $this->client = Craft::createGuzzleClient([
                'base_uri' => 'https://api.openai.com',
                'headers' => [
                    'Authorization' => 'Bearer ' . App::env('OPENAI_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
                'timeout' => $timeout,
                'connect_timeout' => 5,
            ]);
        }

        return $this->client;
    }

    /**
     * Embed a single text.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $embeddings = $this->embedBatch([$text]);
        return $embeddings[0] ?? [];
    }

    /**
     * Embed multiple texts. Automatically splits into batches of at most 100
     * (OpenAI limit).
     *
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $settings = Plugin::$plugin->getSettings();
        $out = [];

        foreach (array_chunk($texts, self::OPENAI_BATCH_LIMIT) as $batch) {
            $out = array_merge($out, $this->requestBatch($batch, $settings->embeddingModel, $settings->embeddingDimensions, $settings->maxRetries, $settings->retryBaseDelay));
        }

        return $out;
    }

    /**
     * @param string[] $texts
     * @return float[][]
     */
    private function requestBatch(array $texts, string $model, int $dimensions, int $maxRetries, int $baseDelay): array
    {
        $attempt = 0;
        $lastException = null;

        $payload = ['model' => $model, 'input' => $texts];
        // text-embedding-3-* supports an explicit dimensions parameter.
        if (str_starts_with($model, 'text-embedding-3')) {
            $payload['dimensions'] = $dimensions;
        }

        while ($attempt < $maxRetries) {
            try {
                $response = $this->getClient()->post('/v1/embeddings', ['json' => $payload]);
                $body = json_decode($response->getBody()->getContents(), true);
                $data = $body['data'] ?? [];

                usort($data, fn($a, $b) => $a['index'] <=> $b['index']);

                $tokens = $body['usage']['total_tokens'] ?? 0;
                Plugin::$plugin->stats->logEmbeddingUsage((int) $tokens, count($texts));

                return array_map(fn($item) => $item['embedding'], $data);
            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $maxRetries) {
                    sleep($baseDelay * (2 ** ($attempt - 1)));
                }
            }
        }

        throw new \RuntimeException(
            'EmbeddingService: max retries reached. Last error: ' . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException
        );
    }

    /**
     * Test connection for diagnostics.
     *
     * @return array{success: bool, error?: string, dimensions?: int}
     */
    public function testConnection(): array
    {
        if (!App::env('OPENAI_API_KEY')) {
            return ['success' => false, 'error' => 'OPENAI_API_KEY not configured'];
        }

        try {
            $vector = $this->embed('test');
            return ['success' => !empty($vector), 'dimensions' => count($vector)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
