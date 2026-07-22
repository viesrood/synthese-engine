<?php

declare(strict_types=1);

namespace viesrood\synthese\models;

use craft\base\Model;

/**
 * Synthese Engine instellingen.
 *
 * Dit is de enige configuratie-bron van de plugin. Waarden kunnen worden
 * overschreven via een site-config-bestand `config/synthese-engine.php`
 * (Craft merget dat automatisch, incl. multi-environment) of via de
 * CP-instellingenpagina. Secrets (API-keys) horen in `.env`, niet hier.
 */
class Settings extends Model
{
    // ---------------------------------------------------------------------
    // Chunking
    // ---------------------------------------------------------------------

    /** Maximale chunk-grootte in tokens (~4 karakters per token). */
    public int $chunkSize = 500;

    /** Overlap tussen chunks in tokens; voorkomt contextverlies op grenzen. */
    public int $chunkOverlap = 50;

    // ---------------------------------------------------------------------
    // Retrieval
    // ---------------------------------------------------------------------

    /** Max. aantal kandidaat-chunks dat de vector-store teruggeeft. */
    public int $maxChunks = 20;

    /** Aantal chunks dat na rerank naar de synthese gaat. */
    public int $topK = 8;

    /** Minimale cosine-similarity (0-1) om een chunk als relevant te tellen. */
    public float $similarityThreshold = 0.3;

    /** Versheid-boost venster in dagen (RerankService). */
    public int $freshnessDays = 90;

    // ---------------------------------------------------------------------
    // Answerability-gate
    // ---------------------------------------------------------------------

    /** Min. aantal chunks boven de drempel voordat de LLM wordt aangeroepen. */
    public int $answerabilityMinChunks = 2;

    /** Min. similarity die een chunk moet halen om mee te tellen in de gate. */
    public float $answerabilityMinSimilarity = 0.60;

    // ---------------------------------------------------------------------
    // Modellen
    // ---------------------------------------------------------------------

    /** OpenAI embedding-model. */
    public string $embeddingModel = 'text-embedding-3-small';

    /** Embedding-dimensies (moet overeenkomen met het model en de Supabase-kolom). */
    public int $embeddingDimensions = 1536;

    /** Gemini-model voor synthese. */
    public string $synthesisModel = 'gemini-2.5-flash-lite';

    // ---------------------------------------------------------------------
    // Vector-store (Supabase)
    // ---------------------------------------------------------------------

    /** Supabase-tabelnaam voor de chunks. */
    public string $supabaseTable = 'content_chunks';

    /** Supabase-RPC voor hybride (vector + full-text) matching. */
    public string $matchRpc = 'match_chunks_hybrid';

    // ---------------------------------------------------------------------
    // Content-configuratie (site-specifiek, maar puur data)
    // ---------------------------------------------------------------------

    /**
     * Sections om te indexeren. Leeg = alle behalve `excludeSections`.
     * @var string[]
     */
    public array $includeSections = [];

    /** @var string[] Sections om expliciet uit te sluiten. */
    public array $excludeSections = [];

    /**
     * Per-section/entry-type veld-extractie.
     * Format: 'handle' => ['fields' => [...], 'matrixFields' => ['matrixHandle' => ['blockField', ...]]].
     * Speciale pseudo-velden: '_author', '_dateCreated', '_url'.
     * @var array<string, array>
     */
    public array $fieldConfig = [];

    /** @var string[] Default velden als een section niet in fieldConfig staat. */
    public array $defaultFields = ['title'];

    /**
     * Optionele semantische context-hint per section-handle, meegeembed
     * voor betere matching. Bijv. 'news' => 'Dit is een nieuwsbericht.'.
     * @var array<string, string>
     */
    public array $sectionContext = [];

    /**
     * Rerank-multipliers per section-handle.
     * @var array<string, float>
     */
    public array $sectionBoosts = [];

    /**
     * Sections waarvan entries alleen meetellen in het huidige kalenderjaar
     * (op basis van postDate en `timezone`).
     * @var string[]
     */
    public array $currentYearOnlySections = [];

    /**
     * Optionele bron-formatters per section-handle voor de bronnenlijst.
     * Format: 'handle' => ['urlOverride' => '/pad', 'titleFrom' => 'veldnaam'].
     * Voor complexere gevallen: gebruik het EVENT_FORMAT_SOURCE-event.
     * @var array<string, array>
     */
    public array $sourceFormatters = [];

    // ---------------------------------------------------------------------
    // Branding / prompt
    // ---------------------------------------------------------------------

    /** Sitenaam die in de system-prompt wordt genoemd. */
    public string $siteName = 'de website';

    /**
     * Optionele volledige system-prompt. Leeg = de ingebouwde standaardprompt
     * (met `siteName` ingevuld) wordt gebruikt.
     */
    public string $systemPrompt = '';

    /** Antwoord als de answerability-gate faalt (geen LLM-call). */
    public string $notAnswerableMessage = 'Ik heb geen relevante informatie gevonden om je vraag te beantwoorden.';

    /**
     * Frasen die aangeven dat de LLM geen relevant antwoord had; bij een match
     * worden de bronnen weggelaten.
     * @var string[]
     */
    public array $noInfoPhrases = [
        'onvoldoende informatie',
        'geen relevante informatie',
        'kan ik niet beantwoorden',
        'niet beantwoord worden',
        'geen informatie gevonden',
        'geen antwoord',
        'niet in de bronnen',
        'bronnen bevatten geen',
        'bronnen bieden geen',
    ];

    /** @var string[] Voorbeeldvragen voor de zoek-UI. */
    public array $exampleQueries = [];

    // ---------------------------------------------------------------------
    // SQL-parameters (voor de gegenereerde Supabase-setup-SQL)
    // ---------------------------------------------------------------------

    /** PostgreSQL full-text-search-taal (bijv. 'dutch', 'english'). */
    public string $ftsLanguage = 'dutch';

    /** Timezone voor de "huidig jaar"-filter in de RPC. */
    public string $timezone = 'Europe/Amsterdam';

    // ---------------------------------------------------------------------
    // Caching
    // ---------------------------------------------------------------------

    /** Cache-duur voor antwoorden in seconden; 0 = uit. */
    public int $cacheDuration = 3600;

    // ---------------------------------------------------------------------
    // Indexering
    // ---------------------------------------------------------------------

    /** Automatisch (her)indexeren bij entry save/delete. */
    public bool $autoIndex = true;

    // ---------------------------------------------------------------------
    // Routing
    // ---------------------------------------------------------------------

    /** URL-prefix voor de publieke zoek-endpoints (zonder leading slash). */
    public string $routePrefix = 'api/synthese';

    // ---------------------------------------------------------------------
    // Rate limiting / kosten
    // ---------------------------------------------------------------------

    public int $maxRequestsPerMinute = 10;
    public int $maxRequestsPerHour = 50;
    public int $maxRequestsPerDay = 100;
    public int $maxGlobalRequestsPerDay = 500;

    /** Dagbudget in USD; 0 = geen limiet. Kan via SYNTHESE_DAILY_BUDGET_USD. */
    public float $dailyBudgetUsd = 1.00;

    /** Tokenprijzen per 1M tokens voor de kostenberekening. */
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
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['chunkSize', 'chunkOverlap', 'maxChunks', 'topK', 'embeddingDimensions'], 'integer', 'min' => 1],
            [['similarityThreshold', 'answerabilityMinSimilarity'], 'number', 'min' => 0, 'max' => 1],
            [['dailyBudgetUsd'], 'number', 'min' => 0],
            [['siteName', 'embeddingModel', 'synthesisModel', 'supabaseTable', 'matchRpc', 'ftsLanguage', 'timezone', 'routePrefix'], 'string'],
            [['includeSections', 'excludeSections', 'fieldConfig', 'sectionBoosts', 'sectionContext', 'currentYearOnlySections', 'sourceFormatters', 'noInfoPhrases', 'exampleQueries', 'defaultFields', 'pricing'], 'safe'],
            [['autoIndex'], 'boolean'],
        ];
    }
}
