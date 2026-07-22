<?php

declare(strict_types=1);

namespace viesrood\synthese;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Dashboard;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use viesrood\synthese\jobs\DeleteEntryChunksJob;
use viesrood\synthese\jobs\IndexEntryJob;
use viesrood\synthese\models\Settings;
use viesrood\synthese\services\AnswerabilityService;
use viesrood\synthese\services\CacheService;
use viesrood\synthese\services\ChunkingService;
use viesrood\synthese\services\EmbeddingService;
use viesrood\synthese\services\IndexEligibilityService;
use viesrood\synthese\services\RerankService;
use viesrood\synthese\services\StatsService;
use viesrood\synthese\services\SynthesisService;
use viesrood\synthese\services\VectorService;
use viesrood\synthese\variables\SyntheseVariable;
use viesrood\synthese\widgets\SyntheseWidget;
use yii\base\Event;

/**
 * Synthese Engine plugin.
 *
 * AI-driven semantic search: Craft entries -> OpenAI embeddings ->
 * Supabase (pgvector + full-text, hybrid RRF) -> Google Gemini synthesis with
 * source attribution.
 *
 * @property-read ChunkingService $chunking
 * @property-read EmbeddingService $embedding
 * @property-read VectorService $vector
 * @property-read RerankService $rerank
 * @property-read AnswerabilityService $answerability
 * @property-read IndexEligibilityService $eligibility
 * @property-read SynthesisService $synthesis
 * @property-read CacheService $cache
 * @property-read StatsService $stats
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'chunking' => ChunkingService::class,
            'embedding' => EmbeddingService::class,
            'vector' => VectorService::class,
            'rerank' => RerankService::class,
            'answerability' => AnswerabilityService::class,
            'eligibility' => IndexEligibilityService::class,
            'synthesis' => SynthesisService::class,
            'cache' => CacheService::class,
            'stats' => StatsService::class,
        ]);

        $this->registerControllerNamespace();
        $this->registerVariable();
        $this->registerWidget();
        $this->registerEntryEvents();

        if (Craft::$app->getRequest()->getIsCpRequest() || Craft::$app->getRequest()->getIsSiteRequest()) {
            $this->registerUrlRules();
        }

        Craft::info('Synthese Engine plugin initialized', __METHOD__);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Typed settings accessor.
     */
    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();
        return $settings;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('synthese-engine/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Synthese Engine';
        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'synthese-engine'],
            'tools' => ['label' => 'Tools', 'url' => 'synthese-engine/tools'],
            'settings' => ['label' => 'Settings', 'url' => 'settings/plugins/synthese-engine'],
        ];
        return $item;
    }

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    private function registerControllerNamespace(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'viesrood\\synthese\\console\\controllers';
        } else {
            $this->controllerNamespace = 'viesrood\\synthese\\controllers';
        }
    }

    private function registerUrlRules(): void
    {
        $prefix = trim($this->getSettings()->routePrefix, '/');

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) use ($prefix) {
                $event->rules["POST {$prefix}/search"] = 'synthese-engine/search/query';
                $event->rules["GET {$prefix}/health"] = 'synthese-engine/search/health';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['synthese-engine'] = 'synthese-engine/cp/index';
                $event->rules['synthese-engine/tools'] = 'synthese-engine/cp/tools';
            }
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('synthese', SyntheseVariable::class);
            }
        );
    }

    private function registerWidget(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SyntheseWidget::class;
            }
        );
    }

    private function registerEntryEvents(): void
    {
        if (!$this->getSettings()->autoIndex) {
            return;
        }

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;

                if (!$this->eligibility->shouldIndexEntry($entry, $this->getSettings())) {
                    return;
                }

                Craft::$app->getQueue()->push(new IndexEntryJob([
                    'entryId' => $entry->id,
                    'siteId' => $entry->siteId,
                ]));
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_DELETE,
            function (Event $event) {
                /** @var Entry $entry */
                $entry = $event->sender;

                Craft::$app->getQueue()->push(new DeleteEntryChunksJob([
                    'entryId' => $entry->id,
                    'siteId' => $entry->siteId,
                ]));
            }
        );
    }
}
