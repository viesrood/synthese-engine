<?php

declare(strict_types=1);

namespace viesrood\synthese\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use viesrood\synthese\Plugin;
use yii\web\Response;

/**
 * SearchController
 *
 * Public search API. A security layer (bot detection, rate limiting, budget,
 * global daily cap) around the hybrid retrieval pipeline (embed -> hybrid search
 * -> rerank -> answerability gate -> synthesis).
 */
class SearchController extends Controller
{
    protected array|int|bool $allowAnonymous = ['query', 'health'];
    public $enableCsrfValidation = false;

    public function actionQuery(): Response
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->getSettings();

        if ($this->isBot($settings->maxRequestsPerMinute)) {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Access denied.')], 403);
        }

        if (!$this->checkRateLimit($settings)) {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Too many requests. Please try again later.')], 429);
        }

        if ($plugin->stats->isDailyBudgetExceeded() || $this->isGlobalDailyLimitReached($settings->maxGlobalRequestsPerDay)) {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Service temporarily unavailable. Please try again tomorrow.')], 503);
        }

        $query = trim((string) (Craft::$app->getRequest()->getBodyParam('query') ?? Craft::$app->getRequest()->getBodyParam('q') ?? ''));

        if ($query === '') {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'No search query provided.')], 400);
        }
        if (mb_strlen($query) < 3) {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Search query must be at least 3 characters.')], 400);
        }
        if (mb_strlen($query) > 500) {
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Search query may be at most 500 characters.')], 400);
        }

        $query = $this->sanitizeQuery($query);
        $startTime = microtime(true);

        try {
            // 1. Cache
            $cached = $plugin->cache->get($query);
            if ($cached !== null) {
                $plugin->stats->logQuery($query, !empty($cached['sources']), true, 0, 0, ($cached['chunksUsed'] ?? 0), (microtime(true) - $startTime) * 1000);
                return $this->asJson([
                    'success' => true,
                    'answer' => $cached['answer'],
                    'sources' => $this->sourcesToRelativeUrls($cached['sources'] ?? []),
                    'cached' => true,
                ]);
            }

            // 2. Embed
            $queryEmbedding = $plugin->embedding->embed($query);
            if (empty($queryEmbedding)) {
                return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'Something went wrong processing your question.')], 500);
            }

            // 3. Hybrid retrieval
            $rawChunks = $plugin->vector->hybridSearch($queryEmbedding, $query, $settings->maxChunks);

            // 4. Rerank
            $chunks = $plugin->rerank->rerank($rawChunks);

            $metrics = $plugin->stats->computeMetrics($chunks);

            // 5. Answerability gate: skip the LLM on weak retrieval.
            if (!$plugin->answerability->isAnswerable($chunks)) {
                $plugin->stats->logQuery($query, false, false, $metrics['topScore'], $metrics['scoreSpread'], $metrics['chunksUsed'], (microtime(true) - $startTime) * 1000);
                return $this->asJson([
                    'success' => true,
                    'answer' => $settings->resolveNotAnswerableMessage(),
                    'sources' => [],
                    'cached' => false,
                ]);
            }

            // 6. Synthesis
            $result = $plugin->synthesis->synthesize($query, $chunks);
            if (!$result['success']) {
                return $this->jsonResponse(['success' => false, 'error' => $result['error'] ?? Craft::t('synthese-engine', 'Something went wrong generating the answer.')], 500);
            }

            // 7. Cache + global counter + log
            $plugin->cache->set($query, [
                'answer' => $result['answer'],
                'sources' => $result['sources'],
                'chunksUsed' => count($chunks),
            ]);
            $this->incrementGlobalDailyCounter();
            $plugin->stats->logQuery($query, !empty($result['sources']), false, $metrics['topScore'], $metrics['scoreSpread'], $metrics['chunksUsed'], (microtime(true) - $startTime) * 1000);

            return $this->asJson([
                'success' => true,
                'answer' => $result['answer'],
                'sources' => $this->sourcesToRelativeUrls($result['sources'] ?? []),
                'cached' => false,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Search error: ' . $e->getMessage(), __METHOD__);
            return $this->jsonResponse(['success' => false, 'error' => Craft::t('synthese-engine', 'An error occurred. Please try again later.')], 500);
        }
    }

    public function actionHealth(): Response
    {
        $plugin = Plugin::$plugin;
        $vector = $plugin->vector->isConfigured();
        $embedding = !empty(App::env('OPENAI_API_KEY'));
        $synthesis = !empty(App::env('GEMINI_API_KEY'));
        $healthy = $vector && $embedding && $synthesis;

        return $this->jsonResponse([
            'status' => $healthy ? 'healthy' : 'degraded',
            'services' => ['vector' => $vector, 'embedding' => $embedding, 'synthesis' => $synthesis],
        ], $healthy ? 200 : 503);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function sourcesToRelativeUrls(array $sources): array
    {
        foreach ($sources as &$source) {
            $url = $source['url'] ?? null;
            if ($url !== null && $url !== '' && !str_starts_with($url, '/')) {
                try {
                    $parsed = parse_url($url);
                    $source['url'] = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                } catch (\Throwable) {
                    // keep the original
                }
            }
        }
        unset($source);
        return $sources;
    }

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = Craft::$app->getResponse();
        $response->statusCode = $statusCode;
        $response->format = Response::FORMAT_JSON;
        $response->data = $data;
        return $response;
    }

    private function checkRateLimit(\viesrood\synthese\models\Settings $settings): bool
    {
        $cache = Craft::$app->getCache();
        $ip = $this->getUserIp();

        $keyMinute = 'synthese_ratelimit_minute_' . $ip;
        $keyHour = 'synthese_ratelimit_hour_' . $ip;
        $keyDay = 'synthese_ratelimit_day_' . date('Y-m-d') . '_' . $ip;

        $countMinute = (int) ($cache->get($keyMinute) ?: 0);
        $countHour = (int) ($cache->get($keyHour) ?: 0);
        $countDay = (int) ($cache->get($keyDay) ?: 0);

        if ($countMinute >= $settings->maxRequestsPerMinute || $countHour >= $settings->maxRequestsPerHour || $countDay >= $settings->maxRequestsPerDay) {
            return false;
        }

        $cache->set($keyMinute, $countMinute + 1, 60);
        $cache->set($keyHour, $countHour + 1, 3600);
        $cache->set($keyDay, $countDay + 1, strtotime('tomorrow') - time());

        return true;
    }

    private function sanitizeQuery(string $query): string
    {
        $query = str_replace("\0", '', $query);
        $query = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $query);
        $query = preg_replace('/\s+/', ' ', $query);
        return trim($query);
    }

    private function getUserIp(): string
    {
        $request = Craft::$app->getRequest();
        if ($request->getHeaders()->has('X-Forwarded-For')) {
            return trim(explode(',', $request->getHeaders()->get('X-Forwarded-For'))[0]);
        }
        if ($request->getHeaders()->has('X-Real-IP')) {
            return $request->getHeaders()->get('X-Real-IP');
        }
        return $request->getUserIP() ?? 'unknown';
    }

    private function isBot(int $rapidThreshold): bool
    {
        $request = Craft::$app->getRequest();
        $userAgent = $request->getUserAgent() ?? '';

        if ($userAgent === '') {
            return true;
        }

        $botPatterns = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python-requests', 'python-urllib', 'java/', 'httpclient', 'http_request', 'libwww', 'apache-httpclient', 'go-http-client', 'node-fetch', 'axios', 'postman', 'insomnia', 'headless', 'phantom', 'selenium', 'puppeteer', 'playwright'];
        $lower = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        // Honeypot: two requests within 500ms from the same IP = likely automated.
        $cache = Craft::$app->getCache();
        $ip = $this->getUserIp();
        $timingKey = 'synthese_timing_' . $ip;
        $lastRequest = $cache->get($timingKey);
        $now = microtime(true);
        $cache->set($timingKey, $now, 5);

        if ($lastRequest !== false && ($now - $lastRequest) < 0.5) {
            return true;
        }

        return false;
    }

    private function isGlobalDailyLimitReached(int $max): bool
    {
        $count = (int) (Craft::$app->getCache()->get('synthese_global_daily_' . date('Y-m-d')) ?: 0);
        return $count >= $max;
    }

    private function incrementGlobalDailyCounter(): void
    {
        $cache = Craft::$app->getCache();
        $key = 'synthese_global_daily_' . date('Y-m-d');
        $count = (int) ($cache->get($key) ?: 0);
        $cache->set($key, $count + 1, strtotime('tomorrow') - time());
    }
}
