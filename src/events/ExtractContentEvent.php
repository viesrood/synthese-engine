<?php

declare(strict_types=1);

namespace viesrood\synthese\events;

use craft\elements\Entry;
use yii\base\Event;

/**
 * Triggered while chunking an entry, so consumer sites can add extra or
 * customized chunks without patching the plugin.
 */
class ExtractContentEvent extends Event
{
    public Entry $entry;
    public string $section = '';

    /**
     * The chunks built up so far; modify this array to add or change chunks.
     * Each chunk: ['text' => ..., 'chunk_type' => ..., 'chunk_index' => ...].
     * @var array<array{text: string, chunk_type: string, chunk_index: int}>
     */
    public array $chunks = [];
}
