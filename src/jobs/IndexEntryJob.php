<?php

declare(strict_types=1);

namespace viesrood\synthese\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use viesrood\synthese\Plugin;

/**
 * (Her)indexeert een entry: chunk -> embed -> upsert naar de vector-store.
 */
class IndexEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        $plugin = Plugin::$plugin;
        $settings = $plugin->getSettings();

        $entry = Entry::find()->id($this->entryId)->siteId($this->siteId)->status(null)->one();
        if (!$entry) {
            return;
        }

        $section = $entry->section->handle ?? '';

        // Bestaande chunks altijd eerst verwijderen (ook bij depubliceren).
        $plugin->vector->deleteByEntryId($this->entryId, $this->siteId);

        if (!$plugin->eligibility->shouldIndexEntry($entry, $settings)) {
            $plugin->cache->invalidate();
            return;
        }

        $rawChunks = $plugin->chunking->chunkEntry($entry);
        if (empty($rawChunks)) {
            return;
        }

        $texts = array_column($rawChunks, 'text');
        $embeddings = $plugin->embedding->embedBatch($texts);

        $url = $entry->getUrl() ?? '';
        $title = (string) ($entry->title ?? '');
        $postDate = $entry->postDate?->format('Y-m-d H:i:s');
        $entryType = $entry->type->handle ?? '';

        $rows = [];
        foreach ($rawChunks as $i => $chunk) {
            $rows[] = [
                'entry_id' => $this->entryId,
                'site_id' => $this->siteId,
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
        $plugin->cache->invalidate();

        Craft::info("Entry {$this->entryId} ({$section}) geindexeerd - " . count($rows) . ' chunks', 'synthese-engine');
    }

    protected function defaultDescription(): ?string
    {
        return "Synthese Engine: entry {$this->entryId} indexeren";
    }
}
