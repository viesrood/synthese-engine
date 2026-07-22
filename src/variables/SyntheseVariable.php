<?php

declare(strict_types=1);

namespace viesrood\synthese\variables;

use Craft;
use craft\helpers\App;
use viesrood\synthese\Plugin;

/**
 * Twig variable: craft.synthese.*
 */
class SyntheseVariable
{
    public function searchUrl(): string
    {
        return '/' . trim(Plugin::$plugin->getSettings()->routePrefix, '/') . '/search';
    }

    public function isConfigured(): bool
    {
        return Plugin::$plugin->vector->isConfigured()
            && !empty(App::env('OPENAI_API_KEY'))
            && !empty(App::env('GEMINI_API_KEY'));
    }

    /**
     * @return array{minQueryLength: int, maxQueryLength: int, siteName: string}
     */
    public function config(): array
    {
        return [
            'minQueryLength' => 3,
            'maxQueryLength' => 500,
            'siteName' => Plugin::$plugin->getSettings()->siteName,
        ];
    }

    /**
     * @return string[]
     */
    public function exampleQueries(): array
    {
        return Plugin::$plugin->getSettings()->exampleQueries;
    }

    public function getCsrfTokenName(): string
    {
        return Craft::$app->getConfig()->getGeneral()->csrfTokenName;
    }

    public function getCsrfToken(): string
    {
        return Craft::$app->getRequest()->getCsrfToken();
    }

    /**
     * Admins only (dashboard/diagnostics).
     */
    public function stats(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }
        return Plugin::$plugin->stats->getTodayStats();
    }

    public function vectorStats(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }
        return Plugin::$plugin->vector->getStats();
    }
}
