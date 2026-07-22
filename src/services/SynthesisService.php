<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use viesrood\synthese\events\FormatSourceEvent;
use viesrood\synthese\Plugin;

/**
 * SynthesisService
 *
 * Genereert een samenhangend, geciteerd antwoord via Google Gemini op basis van
 * de opgehaalde chunks en een system-prompt.
 */
class SynthesisService extends Component
{
    /** @event FormatSourceEvent Kans om per bron de titel/URL aan te passen. */
    public const EVENT_FORMAT_SOURCE = 'formatSource';

    private const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private ?Client $client = null;

    /**
     * @param array[] $chunks Herwogen chunks
     * @return array
     */
    public function synthesize(string $query, array $chunks): array
    {
        $settings = Plugin::$plugin->getSettings();
        $model = $settings->synthesisModel;
        $apiKey = App::env('GEMINI_API_KEY');

        if (!$apiKey) {
            Craft::error('GEMINI_API_KEY niet geconfigureerd', __METHOD__);
            return ['success' => false, 'error' => Craft::t('synthese-engine', 'AI-service niet geconfigureerd')];
        }

        if (empty($chunks)) {
            return [
                'success' => true,
                'answer' => $settings->notAnswerableMessage,
                'sources' => [],
                'tokensInput' => 0,
                'tokensOutput' => 0,
            ];
        }

        $fullPrompt = $this->buildFullPrompt(
            $this->buildSystemPrompt($settings->systemPrompt, $settings->siteName),
            $this->formatChunksForPrompt($chunks),
            $query
        );

        $client = $this->getClient($settings->synthesisTimeout);
        $url = self::GEMINI_API_URL . "/{$model}:generateContent?key={$apiKey}";
        $maxRetries = $settings->maxRetries;
        $baseDelay = $settings->retryBaseDelay;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $startTime = microtime(true);

                $response = $client->post($url, [
                    'json' => [
                        'contents' => [['parts' => [['text' => $fullPrompt]]]],
                        'generationConfig' => ['temperature' => 0.3, 'topP' => 0.8, 'maxOutputTokens' => 1024],
                        'safetySettings' => [
                            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                        ],
                    ],
                ]);

                $responseTime = microtime(true) - $startTime;
                $data = json_decode($response->getBody()->getContents(), true);
                $answer = $this->extractAnswer($data);

                if ($answer === null) {
                    Craft::warning('Gemini gaf een leeg antwoord', __METHOD__);
                    return ['success' => false, 'error' => Craft::t('synthese-engine', 'Geen antwoord ontvangen van AI')];
                }

                $tokensInput = $data['usageMetadata']['promptTokenCount'] ?? 0;
                $tokensOutput = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

                Plugin::$plugin->stats->logSynthesisUsage($tokensInput, $tokensOutput, $responseTime);

                $noInfo = $this->answerIndicatesNoRelevantInfo($answer, $settings->noInfoPhrases);
                $sources = $noInfo ? [] : $this->extractUniqueSources($chunks);

                return [
                    'success' => true,
                    'answer' => $answer,
                    'sources' => $sources,
                    'tokensInput' => $tokensInput,
                    'tokensOutput' => $tokensOutput,
                    'responseTime' => $responseTime,
                    'chunksUsed' => count($chunks),
                ];
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode() ?? 0;
                if ($statusCode === 429 || $statusCode >= 500) {
                    sleep($baseDelay * (2 ** $attempt));
                    continue;
                }
                Craft::error("Gemini API-fout: {$e->getMessage()}", __METHOD__);
                return ['success' => false, 'error' => Craft::t('synthese-engine', 'Er is een fout opgetreden bij het genereren van het antwoord')];
            } catch (\Throwable $e) {
                Craft::error("Synthese-fout: {$e->getMessage()}", __METHOD__);
                if ($attempt < $maxRetries - 1) {
                    sleep($baseDelay * (2 ** $attempt));
                    continue;
                }
                return ['success' => false, 'error' => Craft::t('synthese-engine', 'Er is een fout opgetreden')];
            }
        }

        return ['success' => false, 'error' => Craft::t('synthese-engine', 'Synthese mislukt na meerdere pogingen')];
    }

    private function buildSystemPrompt(string $custom, string $siteName): string
    {
        if (trim($custom) !== '') {
            return $custom;
        }

        return <<<PROMPT
Je bent een kennisassistent die vragen beantwoordt op basis van de aangeleverde bronnen van {$siteName}.

INSTRUCTIES:
1. Baseer je antwoord UITSLUITEND op de gegeven bronnen
2. Gebruik inline citations: [1], [2], etc. verwijzend naar de bronnummers
3. Als de bronnen onvoldoende informatie bevatten, zeg dit eerlijk
4. Als bronnen elkaar tegenspreken, benoem dit
5. Schrijf in het Nederlands
6. Wees beknopt maar volledig (150-300 woorden)
7. Begin direct met het antwoord, geen inleidende zinnen als "Op basis van..."
8. Gebruik geen markdown formatting, alleen platte tekst met [n] citations
9. Geef geen informatie die niet in de bronnen staat
PROMPT;
    }

    private function formatChunksForPrompt(array $chunks): string
    {
        $formatted = [];
        foreach ($chunks as $index => $chunk) {
            $num = $index + 1;
            $title = $chunk['entry_title'] ?? $chunk['title'] ?? 'Onbekend';
            $content = $chunk['content'] ?? $chunk['text'] ?? '';
            $formatted[] = "[{$num}] {$title}\n{$content}";
        }
        return implode("\n\n---\n\n", $formatted);
    }

    private function buildFullPrompt(string $systemPrompt, string $chunks, string $query): string
    {
        return <<<PROMPT
{$systemPrompt}

BRONNEN:
{$chunks}

VRAAG: {$query}
PROMPT;
    }

    private function extractAnswer(array $data): ?string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        return $parts[0]['text'] ?? null;
    }

    /**
     * @param string[] $phrases
     */
    private function answerIndicatesNoRelevantInfo(string $answer, array $phrases): bool
    {
        $lower = mb_strtolower($answer);
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($lower, mb_strtolower($phrase))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array[] $chunks
     * @return array[]
     */
    private function extractUniqueSources(array $chunks): array
    {
        $settings = Plugin::$plugin->getSettings();
        $formatters = $settings->sourceFormatters;

        $sources = [];
        $seen = [];

        foreach ($chunks as $chunk) {
            $entryId = $chunk['entry_id'] ?? null;
            if ($entryId && isset($seen[$entryId])) {
                continue;
            }

            $section = $chunk['section'] ?? null;
            $title = $chunk['entry_title'] ?? $chunk['title'] ?? 'Onbekend';
            $url = $this->toRelativeUrl($chunk['entry_url'] ?? $chunk['url'] ?? null);

            // Config-gestuurde bron-formatter per sectie.
            if ($section !== null && isset($formatters[$section])) {
                $fmt = $formatters[$section];
                if (!empty($fmt['urlOverride'])) {
                    $url = $fmt['urlOverride'];
                }
                if (!empty($fmt['titleFrom']) && !empty($chunk[$fmt['titleFrom']])) {
                    $title = $chunk[$fmt['titleFrom']];
                }
            }

            $source = [
                'number' => count($sources) + 1,
                'title' => $title,
                'url' => $url,
                'section' => $section,
            ];

            // Extension point: consumer kan titel/URL nog aanpassen.
            if ($this->hasEventHandlers(self::EVENT_FORMAT_SOURCE)) {
                $event = new FormatSourceEvent(['chunk' => $chunk, 'section' => (string) $section, 'source' => $source]);
                $this->trigger(self::EVENT_FORMAT_SOURCE, $event);
                $source = $event->source;
            }

            $sources[] = $source;
            if ($entryId) {
                $seen[$entryId] = true;
            }
        }

        return $sources;
    }

    private function toRelativeUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || str_starts_with($url, '/')) {
            return $url;
        }
        try {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '/';
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            return $path . $query;
        } catch (\Throwable) {
            return $url;
        }
    }

    private function getClient(int $timeout): Client
    {
        if ($this->client === null) {
            $this->client = Craft::createGuzzleClient(['timeout' => $timeout, 'connect_timeout' => 5]);
        }
        return $this->client;
    }

    /**
     * @return array{success: bool, error?: string, model?: string, duration?: float}
     */
    public function testConnection(): array
    {
        $settings = Plugin::$plugin->getSettings();
        $model = $settings->synthesisModel;
        $apiKey = App::env('GEMINI_API_KEY');

        if (!$apiKey) {
            return ['success' => false, 'error' => 'GEMINI_API_KEY niet geconfigureerd'];
        }

        try {
            $start = microtime(true);
            $response = $this->getClient(10)->post(self::GEMINI_API_URL . "/{$model}:generateContent?key={$apiKey}", [
                'json' => [
                    'contents' => [['parts' => [['text' => 'Zeg alleen "OK"']]]],
                    'generationConfig' => ['maxOutputTokens' => 10],
                ],
            ]);
            return ['success' => $response->getStatusCode() === 200, 'duration' => round(microtime(true) - $start, 3), 'model' => $model];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
