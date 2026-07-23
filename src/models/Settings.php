<?php

declare(strict_types=1);

namespace viesrood\synthese\models;

use craft\base\Model;

/**
 * Synthese Engine settings.
 *
 * This is the plugin's single source of configuration. Values can be overridden
 * with a site config file `config/synthese-engine.php` (Craft merges it
 * automatically, including multi-environment) or via the CP settings screen.
 * Secrets (API keys) belong in `.env`, not here.
 */
class Settings extends Model
{
    // ---------------------------------------------------------------------
    // Chunking
    // ---------------------------------------------------------------------

    /** Maximum chunk size in tokens (~4 characters per token). */
    public int $chunkSize = 500;

    /** Overlap between chunks in tokens; prevents context loss at boundaries. */
    public int $chunkOverlap = 50;

    // ---------------------------------------------------------------------
    // Retrieval
    // ---------------------------------------------------------------------

    /** Max number of candidate chunks the vector store returns. */
    public int $maxChunks = 20;

    /** Number of chunks passed to synthesis after reranking. */
    public int $topK = 8;

    /** Minimum cosine similarity (0-1) for a chunk to count as relevant. */
    public float $similarityThreshold = 0.3;

    /** Freshness boost window in days (RerankService). */
    public int $freshnessDays = 90;

    // ---------------------------------------------------------------------
    // Answerability gate
    // ---------------------------------------------------------------------

    /** Min number of chunks above the threshold before calling the LLM. */
    public int $answerabilityMinChunks = 2;

    /**
     * Min cosine similarity a chunk must reach to count towards the gate.
     * Tuned for text-embedding-3-small, where relevant matches typically score
     * ~0.4-0.55; a higher value (e.g. 0.60) rejects legitimate questions.
     */
    public float $answerabilityMinSimilarity = 0.35;

    // ---------------------------------------------------------------------
    // Models
    // ---------------------------------------------------------------------

    /** OpenAI embedding model. */
    public string $embeddingModel = 'text-embedding-3-small';

    /** Embedding dimensions (must match the model and the Supabase column). */
    public int $embeddingDimensions = 1536;

    /** Gemini model for synthesis. */
    public string $synthesisModel = 'gemini-2.5-flash-lite';

    // ---------------------------------------------------------------------
    // Vector store (Supabase)
    // ---------------------------------------------------------------------

    /** Supabase table name for the chunks. */
    public string $supabaseTable = 'content_chunks';

    /** Supabase RPC for hybrid (vector + full-text) matching. */
    public string $matchRpc = 'match_chunks_hybrid';

    // ---------------------------------------------------------------------
    // Content configuration (site-specific, but pure data)
    // ---------------------------------------------------------------------

    /**
     * Sections to index. Empty = all except `excludeSections`.
     * @var string[]
     */
    public array $includeSections = [];

    /** @var string[] Sections to explicitly exclude. */
    public array $excludeSections = [];

    /**
     * Per-section/entry-type field extraction.
     * Format: 'handle' => ['fields' => [...], 'matrixFields' => ['matrixHandle' => ['blockField', ...]]].
     * Special pseudo-fields: '_author', '_dateCreated', '_url'.
     * @var array<string, array>
     */
    public array $fieldConfig = [];

    /** @var string[] Default fields when a section is not in fieldConfig. */
    public array $defaultFields = ['title'];

    /**
     * Optional semantic context hint per section handle, embedded along with
     * the content for better matching. E.g. 'news' => 'This is a news item.'.
     * @var array<string, string>
     */
    public array $sectionContext = [];

    /**
     * Rerank multipliers per section handle.
     * @var array<string, float>
     */
    public array $sectionBoosts = [];

    /**
     * Sections whose entries only count in the current calendar year
     * (based on postDate and `timezone`).
     * @var string[]
     */
    public array $currentYearOnlySections = [];

    /**
     * Sections whose entries only count within a rolling window of the last N
     * months (based on postDate and `timezone`). Map of section handle => months,
     * e.g. ['news' => 6, 'blog' => 12]. Unlike `currentYearOnlySections` the
     * window has no fixed calendar boundary: it slides forward every day.
     * If a section appears in both lists, this rolling window takes precedence.
     * @var array<string, int>
     */
    public array $recentMonthsSections = [];

    /**
     * Optional per-section source formatters for the source list.
     * Format: 'handle' => ['urlOverride' => '/path', 'titleFrom' => 'fieldName'].
     * For more complex cases: use the EVENT_FORMAT_SOURCE event.
     * @var array<string, array>
     */
    public array $sourceFormatters = [];

    // ---------------------------------------------------------------------
    // Branding / prompt
    // ---------------------------------------------------------------------

    /** Site name referenced in the system prompt. */
    public string $siteName = 'the website';

    /**
     * Optional full system prompt. Empty = the built-in default prompt (with
     * `siteName` filled in) is used. The built-in prompt is language-neutral:
     * it answers in the same language as the question.
     */
    public string $systemPrompt = '';

    /**
     * Answer shown when the answerability gate fails (no LLM call).
     * Empty = a built-in, translatable default is used (so Dutch sites get a
     * Dutch message via the `nl` translation).
     */
    public string $notAnswerableMessage = '';

    /**
     * Phrases that indicate the LLM had no relevant answer; on a match the
     * sources are omitted. Language-specific: set these to match the language
     * your content and answers are in.
     * @var string[]
     */
    public array $noInfoPhrases = [
        'insufficient information',
        'no relevant information',
        'cannot answer',
        'could not be answered',
        'no information found',
        'no answer',
        'not in the sources',
        'sources do not contain',
        'sources do not provide',
    ];

    /** @var string[] Example questions for the search UI. */
    public array $exampleQueries = [];

    // ---------------------------------------------------------------------
    // SQL parameters (for the generated Supabase setup SQL)
    // ---------------------------------------------------------------------

    /** PostgreSQL full-text-search language (e.g. 'english', 'dutch'). */
    public string $ftsLanguage = 'english';

    /** Timezone for the "current year" filter in the RPC. */
    public string $timezone = 'UTC';

    // ---------------------------------------------------------------------
    // Caching
    // ---------------------------------------------------------------------

    /** Cache duration for answers in seconds; 0 = disabled. */
    public int $cacheDuration = 3600;

    // ---------------------------------------------------------------------
    // Indexing
    // ---------------------------------------------------------------------

    /** Automatically (re)index on entry save/delete. */
    public bool $autoIndex = true;

    // ---------------------------------------------------------------------
    // Routing
    // ---------------------------------------------------------------------

    /** URL prefix for the public search endpoints (no leading slash). */
    public string $routePrefix = 'api/synthese';

    // ---------------------------------------------------------------------
    // Rate limiting / cost
    // ---------------------------------------------------------------------

    public int $maxRequestsPerMinute = 10;
    public int $maxRequestsPerHour = 50;
    public int $maxRequestsPerDay = 100;
    public int $maxGlobalRequestsPerDay = 500;

    /** Daily budget in USD; 0 = no limit. Can be set via SYNTHESE_DAILY_BUDGET_USD. */
    public float $dailyBudgetUsd = 1.00;

    /** Token prices per 1M tokens for the cost calculation. */
    public array $pricing = [
        'embedding' => 0.02,
        'synthesisInput' => 0.10,
        'synthesisOutput' => 0.40,
    ];

    // ---------------------------------------------------------------------
    // Timeouts / retries
    // ---------------------------------------------------------------------

    public int $synthesisTimeout = 30;
    public int $embeddingTimeout = 10;
    public int $vectorTimeout = 10;
    public int $maxRetries = 3;
    public int $retryBaseDelay = 1;

    /**
     * The message to show when the answerability gate fails: the configured
     * value, or a built-in translatable default (so a site's language kicks in
     * via the translation category).
     */
    public function resolveNotAnswerableMessage(): string
    {
        return $this->notAnswerableMessage !== ''
            ? $this->notAnswerableMessage
            : \Craft::t('synthese-engine', 'I could not find enough information to answer your question.');
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['chunkSize', 'chunkOverlap', 'maxChunks', 'topK', 'embeddingDimensions'], 'integer', 'min' => 1],
            [['similarityThreshold', 'answerabilityMinSimilarity'], 'number', 'min' => 0, 'max' => 1],
            [['dailyBudgetUsd'], 'number', 'min' => 0],
            [['siteName', 'embeddingModel', 'synthesisModel', 'supabaseTable', 'matchRpc', 'ftsLanguage', 'timezone', 'routePrefix'], 'string'],
            [['includeSections', 'excludeSections', 'fieldConfig', 'sectionBoosts', 'sectionContext', 'currentYearOnlySections', 'recentMonthsSections', 'sourceFormatters', 'noInfoPhrases', 'exampleQueries', 'defaultFields', 'pricing'], 'safe'],
            [['autoIndex'], 'boolean'],
        ];
    }
}
