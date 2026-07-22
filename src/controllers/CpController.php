<?php

declare(strict_types=1);

namespace viesrood\synthese\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use viesrood\synthese\jobs\IndexEntryJob;
use viesrood\synthese\Plugin;
use viesrood\synthese\services\SupabaseSqlBuilder;
use yii\web\Response;

/**
 * CpController
 *
 * Control-panel-schermen (dashboard, tools) en admin-acties (connectie-test,
 * herindexeren, truncaten, SQL genereren). Admin-only.
 */
class CpController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin();
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        return $this->renderTemplate('synthese-engine/dashboard', [
            'plugin' => $plugin,
            'today' => $plugin->stats->getTodayStats(),
            'total' => $plugin->stats->getTotalStats(),
            'rollup' => $plugin->stats->getRollup(7),
            'vector' => $plugin->vector->getStats(),
            'recent' => $plugin->stats->getRecentQueries(20),
        ]);
    }

    public function actionTools(): Response
    {
        $settings = Plugin::$plugin->getSettings();
        return $this->renderTemplate('synthese-engine/tools', [
            'plugin' => Plugin::$plugin,
            'settings' => $settings,
            'sql' => (new SupabaseSqlBuilder())->build($settings),
        ]);
    }

    public function actionTestConnection(): Response
    {
        $this->requireAcceptsJson();
        $plugin = Plugin::$plugin;

        return $this->asJson([
            'embedding' => $plugin->embedding->testConnection(),
            'vector' => $plugin->vector->testConnection(),
            'synthesis' => $plugin->synthesis->testConnection(),
        ]);
    }

    public function actionReindex(): Response
    {
        $this->requirePostRequest();
        $settings = Plugin::$plugin->getSettings();

        $sections = !empty($settings->includeSections)
            ? $settings->includeSections
            : array_keys($settings->fieldConfig);

        if (empty($sections)) {
            Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'Geen te indexeren sections geconfigureerd.'));
            return $this->redirectToPostedUrl();
        }

        $count = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $entries = Entry::find()->section($sections)->siteId($site->id)->status(Entry::STATUS_LIVE)->all();
            foreach ($entries as $entry) {
                if (!Plugin::$plugin->eligibility->shouldIndexEntry($entry, $settings)) {
                    continue;
                }
                Craft::$app->getQueue()->push(new IndexEntryJob(['entryId' => $entry->id, 'siteId' => $site->id]));
                $count++;
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', '{n} entries in de wachtrij gezet voor indexering.', ['n' => $count]));
        return $this->redirectToPostedUrl();
    }

    public function actionTruncate(): Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->vector->truncate();
        Plugin::$plugin->cache->invalidate();
        Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', 'Alle chunks verwijderd uit de vector-store.'));
        return $this->redirectToPostedUrl();
    }
}
