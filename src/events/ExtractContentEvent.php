<?php

declare(strict_types=1);

namespace viesrood\synthese\events;

use craft\elements\Entry;
use yii\base\Event;

/**
 * Wordt getriggerd tijdens het chunken van een entry, zodat consumer-sites
 * extra of aangepaste chunks kunnen toevoegen zonder de plugin te patchen.
 */
class ExtractContentEvent extends Event
{
    public Entry $entry;
    public string $section = '';

    /**
     * De tot dusver opgebouwde chunks; pas deze array aan om chunks toe te
     * voegen/te wijzigen. Elke chunk: ['text' => ..., 'chunk_type' => ..., 'chunk_index' => ...].
     * @var array<array{text: string, chunk_type: string, chunk_index: int}>
     */
    public array $chunks = [];
}
