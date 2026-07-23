<?php

/**
 * Example: config/synthese-engine.php
 *
 * Copy this into your project at `config/synthese-engine.php` and adjust the
 * sections/fields. Craft merges it over the plugin settings (supports
 * multi-environment: use '*' for all environments or specific env keys).
 *
 * Only the keys you include override the default/CP values.
 */

use craft\helpers\App;

return [
    // Branding / prompt
    'siteName' => 'My Site',
    // 'systemPrompt' => '...',            // empty = built-in default prompt
    'notAnswerableMessage' => 'I could not find enough information about this. Feel free to get in touch.',
    'exampleQueries' => [
        'What are your opening hours?',
        'What services do you offer?',
    ],

    // Index scope
    'includeSections' => ['pages', 'articles', 'services'],
    'excludeSections' => [],

    // Current calendar year only (e.g. news/topical)
    'currentYearOnlySections' => ['news'],

    // Rolling "last N months" window per section (map handle => months). Slides
    // forward daily; wins over currentYearOnlySections if a section is in both.
    'recentMonthsSections' => [
        // 'topical' => 12,
    ],

    // Per-section field extraction
    'fieldConfig' => [
        'pages' => [
            'fields' => ['title', 'summary'],
            'matrixFields' => [
                'contentBlocks' => ['text', 'richText'],
            ],
        ],
        'articles' => [
            'fields' => ['title', 'intro', '_author', '_dateCreated'],
            'matrixFields' => [
                'contentBlocks' => ['text', 'richText', 'faq'],
            ],
        ],
        'services' => [
            'fields' => ['title', 'summary'],
        ],
    ],

    // Semantic context hints (embedded with the content)
    'sectionContext' => [
        'articles' => 'This is an article.',
        'services' => 'This describes a service.',
    ],

    // Rerank boosts per section
    'sectionBoosts' => [
        'services' => 1.3,
        'pages' => 1.1,
        'articles' => 1.0,
        'news' => 0.9,
    ],

    // Source formatters (optional): override URL/title per section
    // 'sourceFormatters' => [
    //     'quotes' => ['urlOverride' => '/quotes', 'titleFrom' => 'text'],
    // ],

    // SQL parameters (must match the Supabase SQL you ran)
    'ftsLanguage' => 'english',
    'timezone' => 'UTC',

    // Cost
    'dailyBudgetUsd' => (float) App::env('SYNTHESE_DAILY_BUDGET_USD') ?: 1.00,
];
