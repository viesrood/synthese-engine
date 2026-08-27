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
 * Generates a coherent, cited answer via Google Gemini based on the retrieved
 * chunks and a system prompt.
 */
class SynthesisService extends Component
{
    /** @event FormatSourceEvent Opportunity to adjust the title/URL per source. */
    public const EVENT_FORMAT_SOURCE = 'formatSource';

    private const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private ?Client $client = null;

    /**
     * @param array[] $chunks Reranked chunks
     * @return array
     */
    public function synthesize(string $query, array $chunks): array
    {
        $settings = Plugin::$plugin->getSettings();
        $model = $settings->synthesisModel;
        $apiKey = App::env('GEMINI_API_KEY');

        if (!$apiKey) {
            Craft::error('GEMINI_API_KEY not configured', __METHOD__);
            return ['success' => false, 'error' => Craft::t('synthese-engine', 'AI service not configured')];
        }

        if (empty($chunks)) {
            return [
                'success' => true,
                'answer' => $settings->resolveNotAnswerableMessage(),
                'sources' => [],
                'tokensInput' => 0,
                'tokensOutput' => 0,
            ];
        }

        // Build the source list up front: the prompt must cite the same numbers
        // the visitor sees. Several chunks can share one source, so numbering
        // the chunks separately would produce citations that point past the end
        // of the list.
        [$sources, $chunkNumbers] = $this->buildSources($chunks);

        $fullPrompt = $this->buildFullPrompt(
            $this->buildSystemPrompt($settings->systemPrompt, $settings->siteName),
            $this->formatChunksForPrompt($chunks, $sources, $chunkNumbers),
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
                    Craft::warning('Gemini returned an empty answer', __METHOD__);
                    return ['success' => false, 'error' => Craft::t('synthese-engine', 'No answer received from AI')];
                }

                $tokensInput = $data['usageMetadata']['promptTokenCount'] ?? 0;
                $tokensOutput = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

                Plugin::$plugin->stats->logSynthesisUsage($tokensInput, $tokensOutput, $responseTime);

                $noInfo = $this->answerIndicatesNoRelevantInfo($answer, $settings->noInfoPhrases);
                if ($noInfo) {
                    $sources = [];
                }

                // Safety net: drop any citation the visitor cannot follow, both
                // when the sources were just dropped and when the model made a
                // number up.
                $answer = $this->stripOrphanCitations($answer, $sources);

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
                Craft::error("Gemini API error: {$e->getMessage()}", __METHOD__);
                return ['success' => false, 'error' => Craft::t('synthese-engine', 'An error occurred while generating the answer')];
            } catch (\Throwable $e) {
                Craft::error("Synthesis error: {$e->getMessage()}", __METHOD__);
                if ($attempt < $maxRetries - 1) {
                    sleep($baseDelay * (2 ** $attempt));
                    continue;
                }
                return ['success' => false, 'error' => Craft::t('synthese-engine', 'An error occurred')];
            }
        }

        return ['success' => false, 'error' => Craft::t('synthese-engine', 'Synthesis failed after multiple attempts')];
    }

    private function buildSystemPrompt(string $custom, string $siteName): string
    {
        if (trim($custom) !== '') {
            return $custom;
        }

        return <<<PROMPT
You are a knowledge assistant that answers questions based on the provided sources from {$siteName}.

INSTRUCTIONS:
1. Base your answer SOLELY on the given sources
2. Use inline citations: [1], [2], etc. referring to the source numbers
3. If the sources contain insufficient information, say so honestly
4. If sources contradict each other, point this out
5. Write your answer in the same language as the question
6. Be concise but complete (150-300 words)
7. Start directly with the answer, no introductory phrases like "Based on..."
8. Do not use markdown formatting, only plain text with [n] citations
9. Do not provide information that is not in the sources
PROMPT;
    }

    /**
     * @param array[] $chunks
     * @param array[] $sources Sources as built by buildSources()
     * @param int[] $numbers Chunk key => source number, as built by buildSources()
     */
    private function formatChunksForPrompt(array $chunks, array $sources, array $numbers): string
    {
        $sectionContext = Plugin::$plugin->getSettings()->sectionContext;
        // Sources are numbered from 1; index them so a chunk can borrow the
        // title its source ended up with (a formatter or an event handler may
        // have changed it), keeping prompt and rendered list in step.
        $titles = array_column($sources, 'title', 'number');
        $formatted = [];
        foreach ($chunks as $index => $chunk) {
            $num = $numbers[$index] ?? ($index + 1);
            $title = $titles[$num] ?? $chunk['entry_title'] ?? $chunk['title'] ?? 'Unknown';
            $content = $chunk['content'] ?? $chunk['text'] ?? '';
            // Surface the per-section context to the LLM (not just to the
            // embedding), so it can tell e.g. a client case study apart from the
            // site's own services.
            $context = $sectionContext[$chunk['section'] ?? ''] ?? '';
            $header = $context !== '' ? "[{$num}] {$title} ({$context})" : "[{$num}] {$title}";
            $formatted[] = "{$header}\n{$content}";
        }
        return implode("\n\n---\n\n", $formatted);
    }

    private function buildFullPrompt(string $systemPrompt, string $chunks, string $query): string
    {
        return <<<PROMPT
{$systemPrompt}

SOURCES:
{$chunks}

QUESTION: {$query}
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
     * Groups the chunks into a numbered list of unique sources.
     *
     * Returns both the list and a map of chunk key => source number, so the
     * prompt can cite exactly the numbers the visitor gets to see. Two chunks
     * from the same entry share one number; a chunk without an entry id keeps
     * getting a source of its own.
     *
     * @param array[] $chunks
     * @return array{0: array[], 1: int[]}
     */
    private function buildSources(array $chunks): array
    {
        $settings = Plugin::$plugin->getSettings();
        $formatters = $settings->sourceFormatters;

        $sources = [];
        $numbers = [];
        $seen = [];

        foreach ($chunks as $index => $chunk) {
            $entryId = $chunk['entry_id'] ?? null;
            if ($entryId && isset($seen[$entryId])) {
                $numbers[$index] = $seen[$entryId];
                continue;
            }

            $section = $chunk['section'] ?? null;
            $title = $chunk['entry_title'] ?? $chunk['title'] ?? 'Unknown';
            $url = $this->toRelativeUrl($chunk['entry_url'] ?? $chunk['url'] ?? null);

            // Config-driven source formatter per section.
            if ($section !== null && isset($formatters[$section])) {
                $fmt = $formatters[$section];
                if (!empty($fmt['urlOverride'])) {
                    $url = $fmt['urlOverride'];
                }
                if (!empty($fmt['titleFrom']) && !empty($chunk[$fmt['titleFrom']])) {
                    $title = $chunk[$fmt['titleFrom']];
                }
            }

            $number = count($sources) + 1;

            $source = [
                'number' => $number,
                'title' => $title,
                'url' => $url,
                'section' => $section,
            ];

            // Extension point: a consumer can still adjust the title/URL.
            if ($this->hasEventHandlers(self::EVENT_FORMAT_SOURCE)) {
                $event = new FormatSourceEvent(['chunk' => $chunk, 'section' => (string) $section, 'source' => $source]);
                $this->trigger(self::EVENT_FORMAT_SOURCE, $event);
                $source = $event->source;
            }

            $sources[] = $source;
            $numbers[$index] = $number;
            if ($entryId) {
                $seen[$entryId] = $number;
            }
        }

        return [$sources, $numbers];
    }

    /**
     * Removes [n] citations that point at a source which is not in the list.
     *
     * Handles the grouped form the models like to produce ("[3, 4, 5]") as well
     * as the single one, dropping only the numbers that have no source and
     * leaving the bracket out entirely once nothing is left of it.
     *
     * With an empty list every citation goes: that is the case where a
     * noInfoPhrase matched and the sources were dropped after the fact.
     *
     * @param array[] $sources
     */
    private function stripOrphanCitations(string $answer, array $sources): string
    {
        $count = count($sources);

        $stripped = preg_replace_callback(
            '/\[\s*\d+(?:\s*,\s*\d+)*\s*\]/',
            static function (array $m) use ($count): string {
                preg_match_all('/\d+/', $m[0], $found);
                $kept = array_filter(
                    array_map('intval', $found[0]),
                    static fn(int $number): bool => $number >= 1 && $number <= $count
                );
                if ($kept === []) {
                    return '';
                }
                // Leave a fully valid citation exactly as the model wrote it.
                return count($kept) === count($found[0]) ? $m[0] : '[' . implode(', ', $kept) . ']';
            },
            $answer
        );

        if ($stripped === null || $stripped === $answer) {
            return $answer;
        }

        // Tidy up what the removal leaves behind: a space in front of the
        // punctuation that followed the citation, and doubled spaces.
        $stripped = preg_replace('/[ \t]+([.,;:!?)])/u', '$1', $stripped) ?? $stripped;
        $stripped = preg_replace('/[ \t]{2,}/u', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/[ \t]+$/mu', '', $stripped) ?? $stripped;

        return $stripped;
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
            return ['success' => false, 'error' => 'GEMINI_API_KEY not configured'];
        }

        try {
            $start = microtime(true);
            $response = $this->getClient(10)->post(self::GEMINI_API_URL . "/{$model}:generateContent?key={$apiKey}", [
                'json' => [
                    'contents' => [['parts' => [['text' => 'Reply with only "OK"']]]],
                    'generationConfig' => ['maxOutputTokens' => 10],
                ],
            ]);
            return ['success' => $response->getStatusCode() === 200, 'duration' => round(microtime(true) - $start, 3), 'model' => $model];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
