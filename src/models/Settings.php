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
    public const MODE_OFF = 'off';
    public const MODE_MANUAL = 'manual';
    public const MODE_MODERATED = 'moderated';

    /** @return array<string, string> value => label, for the settings screen. */
    public static function suggestionModeOptions(): array
    {
        return [
            self::MODE_MODERATED => 'Collect questions visitors ask, show them after approval',
            self::MODE_MANUAL => 'Only questions I add myself; do not collect visitors\' questions',
            self::MODE_OFF => 'No suggestions at all',
        ];
    }

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

    /**
     * Explicit field handles to extract per matrix block type, overriding the
     * built-in guesses in ChunkingService.
     *
     * A listed handle may point at plain text, at a nested Matrix field, or at
     * an Entries relation. In the latter two cases the referenced entries are
     * followed and their text is attributed to the entry being indexed, which
     * is what you want for reusable content fragments: the text is on the page,
     * so it belongs in that page's chunks.
     *
     * Format: 'blockTypeHandle' => ['fieldHandle', ...].
     * @var array<string, string[]>
     */
    public array $blockFields = [];

    /**
     * How deep to follow nested matrices and entry relations while chunking.
     * Guards against a fragment that (indirectly) includes itself.
     */
    public int $maxBlockDepth = 3;

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
    // Learned suggestions
    // ---------------------------------------------------------------------

    /**
     * What the suggestion chips may draw on. This is a privacy decision, so it
     * belongs to whoever runs the site rather than to a config file: leave it
     * out of `config/synthese-engine.php`, or Craft locks the field in the
     * control panel and an admin can no longer change it.
     *
     * - MODE_OFF        no suggestions at all; only the fixed exampleQueries.
     * - MODE_MANUAL     only questions an admin typed in themselves. Questions
     *                   asked by visitors are not collected.
     * - MODE_MODERATED  questions asked by visitors are collected and offered
     *                   back once an admin approved them.
     */
    public string $suggestionMode = self::MODE_MODERATED;

    /**
     * How many different visitors must have asked something before it shows up
     * in the approval queue. Keeps one person from pushing their own wording in.
     */
    public int $suggestionMinAskers = 2;

    /** Length bounds for a harvested question, in characters. A chip stays short. */
    public int $suggestionMinLength = 10;
    public int $suggestionMaxLength = 120;

    /**
     * Cosine similarity above which a new phrasing joins an existing cluster
     * instead of starting one.
     *
     * Deliberately high. Short questions about the same product score highly
     * against each other, so the band where a reworded duplicate lives overlaps
     * the band where two genuinely different questions live: measured on one
     * site, "what is X" against a reworded "what is X" scored 0.72 while "what
     * is X" against "what does X cost" scored 0.71. No threshold separates
     * those. At 0.90 only near-identical phrasings fold together, and anything
     * looser is left for the person approving the queue to decide on.
     */
    public float $suggestionClusterThreshold = 0.90;

    /** Substrings that disqualify a question outright (case-insensitive). */
    public array $suggestionBlocklist = [];

    /** How many approved suggestions the chips show. */
    public int $suggestionsPerPage = 6;

    /** How many "others also asked" suggestions accompany an answer; 0 = off. */
    public int $relatedSuggestionsCount = 3;

    /**
     * Cosine floor for those. Mid-range scores are unreliable as an identity
     * test (see suggestionClusterThreshold) but work fine for ranking, which is
     * all this does: on the same measurements, questions about one product sat
     * around 0.71 and questions about a different subject around 0.55.
     */
    public float $relatedMinSimilarity = 0.60;

    /**
     * Days to keep rows in `synthese_logs`. They hold free text typed by
     * visitors next to a stable IP hash, so they should not live forever.
     * 0 = keep everything.
     */
    public int $logRetentionDays = 90;

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
            [['chunkSize', 'chunkOverlap', 'maxChunks', 'topK', 'embeddingDimensions', 'maxBlockDepth'], 'integer', 'min' => 1],
            [['suggestionMinAskers', 'suggestionMinLength', 'suggestionMaxLength', 'suggestionsPerPage'], 'integer', 'min' => 1],
            [['relatedSuggestionsCount', 'logRetentionDays'], 'integer', 'min' => 0],
            [['similarityThreshold', 'answerabilityMinSimilarity', 'suggestionClusterThreshold', 'relatedMinSimilarity'], 'number', 'min' => 0, 'max' => 1],
            [['dailyBudgetUsd'], 'number', 'min' => 0],
            [['siteName', 'embeddingModel', 'synthesisModel', 'supabaseTable', 'matchRpc', 'ftsLanguage', 'timezone', 'routePrefix'], 'string'],
            [['includeSections', 'excludeSections', 'fieldConfig', 'blockFields', 'sectionBoosts', 'sectionContext', 'currentYearOnlySections', 'recentMonthsSections', 'sourceFormatters', 'noInfoPhrases', 'exampleQueries', 'suggestionBlocklist', 'defaultFields', 'pricing'], 'safe'],
            [['autoIndex'], 'boolean'],
            [['suggestionMode'], 'in', 'range' => [self::MODE_OFF, self::MODE_MANUAL, self::MODE_MODERATED]],
        ];
    }
}
