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

    /**
     * Questions to offer the visitor: the approved ones people actually asked,
     * topped up from `exampleQueries` when there are not enough yet.
     *
     * The top-up is what makes this safe to switch on straight away. A fresh
     * install, or a site that has not gathered enough traffic, shows exactly
     * what it showed before instead of an empty row.
     *
     * @return string[]
     */
    public function suggestedQueries(?int $limit = null): array
    {
        $settings = Plugin::$plugin->getSettings();
        $limit = $limit ?? $settings->suggestionsPerPage;

        if ($limit < 1) {
            return [];
        }

        $questions = array_column(Plugin::$plugin->suggestions->approved($limit), 'question');

        if (count($questions) >= $limit) {
            return $questions;
        }

        $seen = [];
        foreach ($questions as $question) {
            $seen[mb_strtolower($question, 'UTF-8')] = true;
        }

        foreach ($settings->exampleQueries as $example) {
            if (count($questions) >= $limit) {
                break;
            }
            if (!isset($seen[mb_strtolower((string) $example, 'UTF-8')])) {
                $questions[] = (string) $example;
            }
        }

        return $questions;
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
