<?php

declare(strict_types=1);

namespace viesrood\synthese\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use viesrood\synthese\jobs\IndexEntryJob;
use viesrood\synthese\Plugin;
use viesrood\synthese\models\Settings;
use viesrood\synthese\services\SuggestionsService;
use viesrood\synthese\services\SupabaseSqlBuilder;
use yii\web\Response;

/**
 * CpController
 *
 * Control panel screens (dashboard, suggestions, tools) and admin actions
 * (connection test, reindex, truncate, harvest, SQL generation). Admin only.
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

    public function actionSuggestions(): Response
    {
        $suggestions = Plugin::$plugin->suggestions;
        $status = (string) (Craft::$app->getRequest()->getQueryParam('status') ?? SuggestionsService::STATUS_PENDING);

        if (!in_array($status, [
            SuggestionsService::STATUS_PENDING,
            SuggestionsService::STATUS_APPROVED,
            SuggestionsService::STATUS_REJECTED,
        ], true)) {
            $status = SuggestionsService::STATUS_PENDING;
        }

        return $this->renderTemplate('synthese-engine/suggestions', [
            'plugin' => Plugin::$plugin,
            'settings' => Plugin::$plugin->getSettings(),
            'status' => $status,
            'rows' => $suggestions->listByStatus($status),
            'pendingCount' => $suggestions->pendingCount(),
            'harvestedCount' => $suggestions->harvestedCount(),
            // The effective values, which may differ from the plugin settings:
            // these three are stored in the database so a deploy cannot revert
            // what an admin chose. See SuggestionsService::mode().
            'mode' => $suggestions->mode(),
            'modeOptions' => Settings::suggestionModeOptions(),
            'minAskers' => $suggestions->minAskers(),
            'retentionDays' => $suggestions->retentionDays(),
        ]);
    }

    /**
     * One endpoint for approve / reject / pin / rewrite / delete / add, so the
     * screen needs a single form target.
     */
    public function actionSuggestionAction(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $suggestions = Plugin::$plugin->suggestions;
        $id = (int) $request->getBodyParam('id');
        $op = (string) $request->getBodyParam('op');

        $ok = match ($op) {
            'approve' => $suggestions->setStatus($id, SuggestionsService::STATUS_APPROVED),
            'reject' => $suggestions->setStatus($id, SuggestionsService::STATUS_REJECTED),
            'requeue' => $suggestions->setStatus($id, SuggestionsService::STATUS_PENDING),
            'pin' => $suggestions->setPinned($id, true),
            'unpin' => $suggestions->setPinned($id, false),
            'rewrite' => $suggestions->rewrite($id, (string) $request->getBodyParam('question')),
            'delete' => $suggestions->delete($id),
            'add' => $suggestions->addManual((string) $request->getBodyParam('question')),
            default => false,
        };

        // Deleting collected questions reports how many went, because "done"
        // is not a useful answer to "did you actually remove my data".
        if ($op === 'forget') {
            $expected = $suggestions->harvestedCount();
            $deleted = $suggestions->forgetHarvested();

            if ($deleted === 0 && $expected > 0) {
                Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'Could not delete the collected questions.'));
            } else {
                Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', '{n} collected questions deleted.', ['n' => $deleted]));
            }

            return $this->redirectToPostedUrl();
        }

        if ($ok) {
            Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', 'Suggestion updated.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'Could not update the suggestion.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Saves the three choices that belong to whoever runs the site.
     *
     * They go to the database rather than to the plugin settings: plugin
     * settings are project config, and a deploy that checks the repo out would
     * put them back.
     */
    public function actionSaveSuggestionSettings(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $suggestions = Plugin::$plugin->suggestions;
        $previous = $suggestions->mode();

        $ok = $suggestions->setMode((string) $request->getBodyParam('mode'))
            && $suggestions->setMinAskers((int) $request->getBodyParam('minAskers'))
            && $suggestions->setRetentionDays((int) $request->getBodyParam('retentionDays'));

        if (!$ok) {
            Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'Could not save those settings.'));
            return $this->redirectToPostedUrl();
        }

        $mode = $suggestions->mode();
        $collected = $suggestions->harvestedCount();

        // Turning collection off leaves whatever was already collected in place,
        // so say so rather than let the admin assume it is gone.
        if ($previous === Settings::MODE_MODERATED && $mode !== Settings::MODE_MODERATED && $collected > 0) {
            Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', 'Saved. {n} questions collected earlier are no longer shown, but are still stored; use the delete button to remove them.', ['n' => $collected]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', 'Settings saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Runs a harvest from the screen, so an admin does not have to wait for the
     * nightly cron to see the effect of a change.
     */
    public function actionHarvest(): Response
    {
        $this->requirePostRequest();
        $stats = Plugin::$plugin->suggestions->harvest();

        if (!empty($stats['refused'])) {
            Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'Collecting visitors\' questions is switched off in the settings.'));
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', '{scanned} log rows read, {new} new and {updated} updated suggestions.', [
            'scanned' => $stats['scanned'],
            'new' => $stats['newClusters'],
            'updated' => $stats['updatedClusters'],
        ]));

        return $this->redirectToPostedUrl();
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
            Craft::$app->getSession()->setError(Craft::t('synthese-engine', 'No indexable sections configured.'));
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

        Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', '{n} entries queued for indexing.', ['n' => $count]));
        return $this->redirectToPostedUrl();
    }

    public function actionTruncate(): Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->vector->truncate();
        Plugin::$plugin->cache->invalidate();
        Craft::$app->getSession()->setNotice(Craft::t('synthese-engine', 'All chunks deleted from the vector store.'));
        return $this->redirectToPostedUrl();
    }
}
