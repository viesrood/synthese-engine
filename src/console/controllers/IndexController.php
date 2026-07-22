<?php

declare(strict_types=1);

namespace viesrood\synthese\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use viesrood\synthese\Plugin;
use yii\console\ExitCode;

/**
 * Indexeer entries in de vector-store.
 *
 * synthese-engine/index/all       Alle indexeerbare live-entries
 * synthese-engine/index/section   Een specifieke section
 * synthese-engine/index/entry     Een enkele entry (op id)
 * synthese-engine/index/truncate  Verwijder alle chunks
 */
class IndexController extends Controller
{
    public bool $verbose = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'all' || $actionID === 'section' ? ['verbose'] : []);
    }

    public function optionAliases(): array
    {
        return ['v' => 'verbose'];
    }

    public function actionAll(): int
    {
        $settings = Plugin::$plugin->getSettings();
        $sections = !empty($settings->includeSections) ? $settings->includeSections : array_keys($settings->fieldConfig);

        if (empty($sections)) {
            $this->stderr("Geen te indexeren sections geconfigureerd.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        return $this->indexSections($sections);
    }

    public function actionSection(string $handle): int
    {
        return $this->indexSections([$handle]);
    }

    public function actionEntry(int $id): int
    {
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            $this->stderr("Entry {$id} niet gevonden.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $n = $this->indexOne($entry);
        $this->stdout("Entry {$id}: {$n} chunks.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    public function actionTruncate(): int
    {
        if (!$this->confirm('Alle chunks uit de vector-store verwijderen?')) {
            return ExitCode::OK;
        }
        Plugin::$plugin->vector->truncate();
        Plugin::$plugin->cache->invalidate();
        $this->stdout("Vector-store geleegd.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @param string[] $sections
     */
    private function indexSections(array $sections): int
    {
        $settings = Plugin::$plugin->getSettings();
        $totalEntries = 0;
        $totalChunks = 0;

        foreach (\Craft::$app->getSites()->getAllSites() as $site) {
            $entries = Entry::find()->section($sections)->siteId($site->id)->status(Entry::STATUS_LIVE)->all();
            foreach ($entries as $entry) {
                if (!Plugin::$plugin->eligibility->shouldIndexEntry($entry, $settings)) {
                    continue;
                }
                $n = $this->indexOne($entry);
                $totalEntries++;
                $totalChunks += $n;
                if ($this->verbose) {
                    $this->stdout(sprintf("  [%s] %s (%s) - %d chunks\n", $site->handle, $entry->title, $entry->section->handle, $n));
                }
            }
        }

        Plugin::$plugin->cache->invalidate();
        $this->stdout("Klaar: {$totalEntries} entries, {$totalChunks} chunks.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function indexOne(Entry $entry): int
    {
        $plugin = Plugin::$plugin;
        $plugin->vector->deleteByEntryId($entry->id, $entry->siteId);

        if (!$plugin->eligibility->shouldIndexEntry($entry, $plugin->getSettings())) {
            return 0;
        }

        $rawChunks = $plugin->chunking->chunkEntry($entry);
        if (empty($rawChunks)) {
            return 0;
        }

        $embeddings = $plugin->embedding->embedBatch(array_column($rawChunks, 'text'));

        $url = $entry->getUrl() ?? '';
        $title = (string) ($entry->title ?? '');
        $postDate = $entry->postDate?->format('Y-m-d H:i:s');
        $entryType = $entry->type->handle ?? '';
        $section = $entry->section->handle ?? '';

        $rows = [];
        foreach ($rawChunks as $i => $chunk) {
            $rows[] = [
                'entry_id' => $entry->id,
                'site_id' => $entry->siteId,
                'section' => $section,
                'entry_type' => $entryType,
                'url' => $url,
                'title' => $title,
                'chunk_index' => $chunk['chunk_index'],
                'chunk_type' => $chunk['chunk_type'],
                'text' => $chunk['text'],
                'embedding' => $embeddings[$i] ?? [],
                'post_date' => $postDate,
            ];
        }

        $plugin->vector->upsert($rows);
        return count($rows);
    }
}
