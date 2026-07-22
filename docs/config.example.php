<?php

/**
 * Voorbeeld: config/synthese-engine.php
 *
 * Kopieer naar je project onder `config/synthese-engine.php` en pas de sections/
 * velden aan. Craft merget dit over de plugin-instellingen (ondersteunt
 * multi-environment: gebruik '*' voor alle omgevingen of specifieke env-keys).
 *
 * Alleen keys die je opneemt overschrijven de standaard/CP-waarden.
 */

use craft\helpers\App;

return [
    // Merk / prompt
    'siteName' => 'Mijn Site',
    // 'systemPrompt' => '...',            // leeg = ingebouwde standaardprompt
    'notAnswerableMessage' => 'Ik heb hier onvoldoende informatie over gevonden. Neem gerust contact op.',
    'exampleQueries' => [
        'Wat zijn jullie openingstijden?',
        'Welke diensten bieden jullie aan?',
    ],

    // Index-scope
    'includeSections' => ['pages', 'articles', 'services'],
    'excludeSections' => [],

    // Alleen huidig kalenderjaar (bv. nieuws/actueel)
    'currentYearOnlySections' => ['news'],

    // Per-section veld-extractie
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

    // Semantische context-hints (meegeembed)
    'sectionContext' => [
        'articles' => 'Dit is een artikel.',
        'services' => 'Dit beschrijft een dienst.',
    ],

    // Rerank-boosts per section
    'sectionBoosts' => [
        'services' => 1.3,
        'pages' => 1.1,
        'articles' => 1.0,
        'news' => 0.9,
    ],

    // Bron-formatters (optioneel): pas URL/titel per section aan
    // 'sourceFormatters' => [
    //     'quotes' => ['urlOverride' => '/quotes', 'titleFrom' => 'text'],
    // ],

    // SQL-parameters (moeten matchen met de gedraaide Supabase-SQL)
    'ftsLanguage' => 'dutch',
    'timezone' => 'Europe/Amsterdam',

    // Kosten
    'dailyBudgetUsd' => (float) App::env('SYNTHESE_DAILY_BUDGET_USD') ?: 1.00,
];
