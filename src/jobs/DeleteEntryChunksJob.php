<?php

declare(strict_types=1);

namespace viesrood\synthese\jobs;

use craft\queue\BaseJob;
use viesrood\synthese\Plugin;

/**
 * Verwijdert de chunks van een verwijderde entry uit de vector-store.
 */
class DeleteEntryChunksJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        Plugin::$plugin->vector->deleteByEntryId($this->entryId, $this->siteId);
        Plugin::$plugin->cache->invalidate();
    }

    protected function defaultDescription(): ?string
    {
        return "Synthese Engine: chunks van entry {$this->entryId} verwijderen";
    }
}
