<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use viesrood\synthese\Plugin;

/**
 * StatsService
 *
 * Logging (to `{{%synthese_logs}}`), cost accounting (via the cache) and
 * reporting for the dashboard and the widget.
 */
class StatsService extends Component
{
    private const TABLE = '{{%synthese_logs}}';
    private const CK_SYNTH_TOKENS = 'synthese_daily_tokens_';
    private const CK_EMBED_TOKENS = 'synthese_embedding_tokens_';

    // -----------------------------------------------------------------
    // Query logging (DB)
    // -----------------------------------------------------------------

    public function logQuery(
        string $query,
        bool $isAnswerable,
        bool $cacheHit,
        float $topScore,
        float $scoreSpread,
        int $chunksUsed,
        float $durationMs,
    ): void {
        try {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'query' => mb_substr($query, 0, 500),
                'is_answerable' => (int) $isAnswerable,
                'cache_hit' => (int) $cacheHit,
                'top_score' => $topScore,
                'score_spread' => $scoreSpread,
                'chunks_used' => $chunksUsed,
                'duration_ms' => (int) $durationMs,
                'ip_hash' => $this->hashIp(),
                'created_at' => date('Y-m-d H:i:s'),
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('StatsService::logQuery failed: ' . $e->getMessage(), 'synthese-engine');
        }
    }

    /**
     * @param array[] $chunks
     * @return array{topScore: float, scoreSpread: float, chunksUsed: int}
     */
    public function computeMetrics(array $chunks): array
    {
        if (empty($chunks)) {
            return ['topScore' => 0.0, 'scoreSpread' => 0.0, 'chunksUsed' => 0];
        }

        $scores = array_map(fn($c) => (float) ($c['final_score'] ?? $c['similarity'] ?? 0), $chunks);

        return [
            'topScore' => max($scores),
            'scoreSpread' => max($scores) - min($scores),
            'chunksUsed' => count($chunks),
        ];
    }

    // -----------------------------------------------------------------
    // Cost accounting (cache)
    // -----------------------------------------------------------------

    public function logEmbeddingUsage(int $tokens, int $batchSize): void
    {
        $key = self::CK_EMBED_TOKENS . date('Y-m-d');
        $current = Craft::$app->getCache()->get($key) ?: ['tokens' => 0, 'requests' => 0];
        $current['tokens'] += $tokens;
        $current['requests'] += $batchSize;
        Craft::$app->getCache()->set($key, $current, $this->untilTomorrowPlusDay());
    }

    public function logSynthesisUsage(int $inputTokens, int $outputTokens, float $responseTime): void
    {
        $key = self::CK_SYNTH_TOKENS . date('Y-m-d');
        $current = Craft::$app->getCache()->get($key) ?: ['inputTokens' => 0, 'outputTokens' => 0, 'requests' => 0, 'totalTime' => 0];
        $current['inputTokens'] += $inputTokens;
        $current['outputTokens'] += $outputTokens;
        $current['requests'] += 1;
        $current['totalTime'] += $responseTime;
        Craft::$app->getCache()->set($key, $current, $this->untilTomorrowPlusDay());
    }

    public function isDailyBudgetExceeded(): bool
    {
        $budget = Plugin::$plugin->getSettings()->dailyBudgetUsd;
        if ($budget <= 0) {
            return false;
        }
        return $this->getTodayCosts() >= $budget;
    }

    public function getTodayCosts(): float
    {
        $pricing = Plugin::$plugin->getSettings()->pricing;
        $today = date('Y-m-d');
        $synthesis = Craft::$app->getCache()->get(self::CK_SYNTH_TOKENS . $today) ?: [];
        $embedding = Craft::$app->getCache()->get(self::CK_EMBED_TOKENS . $today) ?: [];

        $embeddingCost = (($embedding['tokens'] ?? 0) / 1_000_000) * ($pricing['embedding'] ?? 0.02);
        $synthInputCost = (($synthesis['inputTokens'] ?? 0) / 1_000_000) * ($pricing['synthesisInput'] ?? 0.10);
        $synthOutputCost = (($synthesis['outputTokens'] ?? 0) / 1_000_000) * ($pricing['synthesisOutput'] ?? 0.40);

        return $embeddingCost + $synthInputCost + $synthOutputCost;
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    public function getTodayStats(): array
    {
        $today = date('Y-m-d');
        $synthesis = Craft::$app->getCache()->get(self::CK_SYNTH_TOKENS . $today) ?: [];
        $embedding = Craft::$app->getCache()->get(self::CK_EMBED_TOKENS . $today) ?: [];

        return [
            'date' => $today,
            'synthesis' => [
                'requests' => $synthesis['requests'] ?? 0,
                'inputTokens' => $synthesis['inputTokens'] ?? 0,
                'outputTokens' => $synthesis['outputTokens'] ?? 0,
                'avgResponseTime' => ($synthesis['requests'] ?? 0) > 0 ? round(($synthesis['totalTime'] ?? 0) / $synthesis['requests'], 2) : 0,
            ],
            'embedding' => [
                'requests' => $embedding['requests'] ?? 0,
                'tokens' => $embedding['tokens'] ?? 0,
            ],
            'costs' => ['total' => round($this->getTodayCosts(), 4), 'currency' => 'USD'],
        ];
    }

    /**
     * 7-day rollup from the log table (for the dashboard widget).
     */
    public function getRollup(int $days = 7): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $row = (new Query())
                ->select([
                    'total' => 'COUNT(*)',
                    'answerable' => 'SUM([[is_answerable]])',
                    'cached' => 'SUM([[cache_hit]])',
                    'avgDuration' => 'AVG([[duration_ms]])',
                ])
                ->from(self::TABLE)
                ->where(['>=', 'created_at', $since])
                ->one();

            $total = (int) ($row['total'] ?? 0);
            return [
                'days' => $days,
                'total' => $total,
                'answerable' => (int) ($row['answerable'] ?? 0),
                'cached' => (int) ($row['cached'] ?? 0),
                'answerableRate' => $total > 0 ? round(((int) $row['answerable'] / $total) * 100, 1) : 0,
                'cacheHitRate' => $total > 0 ? round(((int) $row['cached'] / $total) * 100, 1) : 0,
                'avgDurationMs' => (int) round((float) ($row['avgDuration'] ?? 0)),
            ];
        } catch (\Throwable $e) {
            Craft::warning('StatsService::getRollup failed: ' . $e->getMessage(), 'synthese-engine');
            return ['days' => $days, 'total' => 0, 'answerable' => 0, 'cached' => 0, 'answerableRate' => 0, 'cacheHitRate' => 0, 'avgDurationMs' => 0];
        }
    }

    public function getRecentQueries(int $limit = 50): array
    {
        try {
            return (new Query())
                ->select(['query', 'is_answerable', 'cache_hit', 'top_score', 'chunks_used', 'duration_ms', 'created_at'])
                ->from(self::TABLE)
                ->orderBy(['created_at' => SORT_DESC])
                ->limit($limit)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getTotalStats(): array
    {
        try {
            $r = (new Query())
                ->select([
                    'totalQueries' => 'COUNT(*)',
                    'answerable' => 'SUM([[is_answerable]])',
                    'cached' => 'SUM([[cache_hit]])',
                    'avgDuration' => 'AVG([[duration_ms]])',
                ])
                ->from(self::TABLE)
                ->one();

            $total = (int) ($r['totalQueries'] ?? 0);
            return [
                'totalQueries' => $total,
                'answerableQueries' => (int) ($r['answerable'] ?? 0),
                'cachedQueries' => (int) ($r['cached'] ?? 0),
                'cacheHitRate' => $total > 0 ? round(((int) $r['cached'] / $total) * 100, 2) : 0,
                'avgDurationMs' => (int) round((float) ($r['avgDuration'] ?? 0)),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function hashIp(): string
    {
        try {
            $request = Craft::$app->getRequest();
            $ip = $request->getUserIP() ?? '';
            if ($ip === '') {
                return '';
            }
            $salt = Craft::$app->getConfig()->getGeneral()->securityKey ?? '';
            return substr(hash('sha256', $ip . $salt), 0, 64);
        } catch (\Throwable) {
            return '';
        }
    }

    private function untilTomorrowPlusDay(): int
    {
        return strtotime('tomorrow') - time() + 86400;
    }
}
