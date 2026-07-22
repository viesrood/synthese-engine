<?php

declare(strict_types=1);

namespace viesrood\synthese\events;

use yii\base\Event;

/**
 * Wordt getriggerd bij het opbouwen van een bron-item voor de bronnenlijst,
 * zodat consumer-sites per sectie de titel/URL kunnen aanpassen (vervangt
 * eerdere hardcoded sectie-specifieke logica in de plugincode).
 */
class FormatSourceEvent extends Event
{
    /** De ruwe chunk-rij uit de vector-store. @var array */
    public array $chunk = [];

    public string $section = '';

    /**
     * Het bron-item dat wordt teruggegeven; pas 'title'/'url' aan waar nodig.
     * @var array{number?: int, title: string, url: ?string, section: ?string}
     */
    public array $source = [];
}
