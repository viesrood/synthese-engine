<?php

declare(strict_types=1);

namespace viesrood\synthese\events;

use yii\base\Event;

/**
 * Triggered when building a source item for the sources list, so consumer
 * sites can customize the title/URL per section (replaces earlier hardcoded
 * section-specific logic in the plugin code).
 */
class FormatSourceEvent extends Event
{
    /** The raw chunk row from the vector store. @var array */
    public array $chunk = [];

    public string $section = '';

    /**
     * The source item that is returned; adjust 'title'/'url' where needed.
     * @var array{number?: int, title: string, url: ?string, section: ?string}
     */
    public array $source = [];
}
