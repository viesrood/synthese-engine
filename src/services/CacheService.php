<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use viesrood\synthese\Plugin;

/**
 * CacheService
 *
 * Cachet gegenereerde antwoorden (Craft-cache/Redis) om API-kosten te beperken.
 * Invalidatie via een versie-marker (geen tag-based invalidatie in Craft).
 */
class CacheService extends Component
{
    private const CACHE_PREFIX = 'synthese_';

    public function get(string $query): ?array
    {
        if (Plugin::$plugin->getSettings()->cacheDuration <= 0) {
            return null;
        }

        $cached = Craft::$app->getCache()->get($this->generateCacheKey($query));
        return $cached !== false ? $cached : null;
    }

    public function set(string $query, array $response): bool
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;
        if ($duration <= 0) {
            return true;
        }

        $response['cachedAt'] = time();
        $response['cached'] = true;

        return Craft::$app->getCache()->set($this->generateCacheKey($query), $response, $duration);
    }

    /**
     * Invalideer alle antwoorden (versie-bump). Gebruikt door de index-/delete-jobs.
     */
    public function invalidate(): bool
    {
        return Craft::$app->getCache()->set(self::CACHE_PREFIX . 'version', time(), 0);
    }

    public function invalidateAll(): bool
    {
        return $this->invalidate();
    }

    private function generateCacheKey(string $query): string
    {
        $version = Craft::$app->getCache()->get(self::CACHE_PREFIX . 'version') ?: 1;
        return self::CACHE_PREFIX . 'v' . $version . '_' . md5($this->normalizeQuery($query));
    }

    private function normalizeQuery(string $query): string
    {
        $query = mb_strtolower($query, 'UTF-8');
        $query = preg_replace('/\s+/', ' ', $query);
        return rtrim(trim($query), '?!.');
    }
}
