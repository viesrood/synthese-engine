<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use viesrood\synthese\events\ExtractContentEvent;
use viesrood\synthese\Plugin;

/**
 * ChunkingService
 *
 * Zet een Craft-entry om naar tekst-chunks met een chunk-type, op basis van de
 * `fieldConfig` uit de instellingen. Consumers kunnen extractie aanpassen via
 * het EVENT_EXTRACT_CONTENT-event.
 *
 * Elke chunk: ['text' => string, 'chunk_type' => string, 'chunk_index' => int].
 * De volledige Supabase-rij (entry_id, section, url, ...) wordt in de index-job
 * samengesteld.
 */
class ChunkingService extends Component
{
    /** @event ExtractContentEvent Kans om per blok/veld extra tekst toe te voegen of te vervangen. */
    public const EVENT_EXTRACT_CONTENT = 'extractContent';

    private const CHARS_PER_TOKEN = 4;

    /**
     * @return array<array{text: string, chunk_type: string, chunk_index: int}>
     */
    public function chunkEntry(Entry $entry): array
    {
        $settings = Plugin::$plugin->getSettings();
        $section = $entry->section->handle ?? null;
        if (!$section) {
            return [];
        }

        $config = $settings->fieldConfig[$section] ?? ['fields' => $settings->defaultFields];
        $chunks = [];
        $index = 0;

        // Titel-chunk (optioneel voorafgegaan door sectie-context voor betere matching)
        $title = (string) ($entry->title ?? '');
        if ($title !== '') {
            $context = $settings->sectionContext[$section] ?? '';
            $chunks[] = [
                'text' => $context !== '' ? $context . "\n" . $title : $title,
                'chunk_type' => 'title',
                'chunk_index' => $index++,
            ];
        }

        // Scalar-velden (+ pseudo-velden)
        foreach (($config['fields'] ?? []) as $fieldHandle) {
            if ($fieldHandle === 'title') {
                continue;
            }

            $text = str_starts_with($fieldHandle, '_')
                ? $this->extractPseudoField($entry, $fieldHandle)
                : $this->extractText($entry->getFieldValue($fieldHandle) ?? '');

            if ($text === '') {
                continue;
            }

            $chunkType = $this->guessChunkType($fieldHandle);
            foreach ($this->splitIntoChunks($text, $settings->chunkSize, $settings->chunkOverlap) as $chunk) {
                $chunks[] = ['text' => $chunk, 'chunk_type' => $chunkType, 'chunk_index' => $index++];
            }
        }

        // Matrix-velden
        foreach (($config['matrixFields'] ?? []) as $matrixHandle => $blockTypes) {
            $matrix = $entry->getFieldValue($matrixHandle);
            if (!$matrix) {
                continue;
            }

            foreach ($matrix->all() as $block) {
                $type = $block->type->handle ?? '';
                if (!in_array($type, $blockTypes, true)) {
                    continue;
                }

                $text = $this->extractBlockText($block, $type);
                if ($text === '') {
                    continue;
                }

                $chunkType = $type === 'faq' ? 'faq' : 'body';
                foreach ($this->splitIntoChunks($text, $settings->chunkSize, $settings->chunkOverlap) as $chunk) {
                    $chunks[] = ['text' => $chunk, 'chunk_type' => $chunkType, 'chunk_index' => $index++];
                }
            }
        }

        // Extension point: laat consumers extra chunks toevoegen.
        if ($this->hasEventHandlers(self::EVENT_EXTRACT_CONTENT)) {
            $event = new ExtractContentEvent(['entry' => $entry, 'section' => $section, 'chunks' => $chunks]);
            $this->trigger(self::EVENT_EXTRACT_CONTENT, $event);
            $chunks = $event->chunks;
            // Herindexeer de chunk_index-waarden voor de zekerheid.
            foreach ($chunks as $i => &$c) {
                $c['chunk_index'] = $i;
            }
            unset($c);
        }

        return $chunks;
    }

    private function extractPseudoField(Entry $entry, string $handle): string
    {
        return match ($handle) {
            '_author' => trim((string) ($entry->getAuthor()?->fullName ?? $entry->getAuthor()?->name ?? '')),
            '_dateCreated' => $entry->dateCreated?->format('Y-m-d') ?? '',
            '_url' => (string) ($entry->getUrl() ?? ''),
            default => '',
        };
    }

    private function extractText(mixed $value): string
    {
        if (is_string($value)) {
            return trim(strip_tags($value));
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return trim(strip_tags((string) $value));
        }
        return '';
    }

    private function extractBlockText(object $block, string $type): string
    {
        $parts = [];

        if ($type === 'faq') {
            try {
                $faqItems = $block->getFieldValue('faqItems');
                if ($faqItems) {
                    foreach ($faqItems->all() as $item) {
                        $q = $this->extractText($item->getFieldValue('question') ?? '');
                        $a = $this->extractText($item->getFieldValue('answer') ?? '');
                        if ($q !== '') {
                            $parts[] = Craft::t('synthese-engine', 'Vraag') . ': ' . $q;
                        }
                        if ($a !== '') {
                            $parts[] = Craft::t('synthese-engine', 'Antwoord') . ': ' . $a;
                        }
                    }
                }
            } catch (\Throwable) {
                // veld bestaat mogelijk niet
            }

            return implode("\n\n", $parts);
        }

        $candidates = match ($type) {
            'richText' => ['richText'],
            'plainText' => ['plainText', 'text'],
            'textImageBlock' => ['blockContent', 'richText', 'plainText'],
            default => ['richText', 'plainText', 'text', 'blockContent', 'copy', 'quote'],
        };

        foreach ($candidates as $fieldHandle) {
            try {
                $text = $this->extractText($block->getFieldValue($fieldHandle));
                if ($text !== '') {
                    $parts[] = $text;
                }
            } catch (\Throwable) {
                // veld bestaat niet op dit bloktype
            }
        }

        return implode("\n\n", $parts);
    }

    private function guessChunkType(string $fieldHandle): string
    {
        return match (true) {
            str_contains($fieldHandle, 'Intro') || str_contains($fieldHandle, 'Preview') || $fieldHandle === 'intro' || $fieldHandle === 'summary' => 'intro',
            default => 'body',
        };
    }

    /**
     * @return string[]
     */
    private function splitIntoChunks(string $text, int $chunkTokens, int $overlapTokens): array
    {
        $maxChars = $chunkTokens * self::CHARS_PER_TOKEN;
        $overlapChars = $overlapTokens * self::CHARS_PER_TOKEN;

        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);
        $step = max(1, $maxChars - $overlapChars);

        while ($offset < $length) {
            $chunks[] = mb_substr($text, $offset, $maxChars);
            $offset += $step;
        }

        return $chunks;
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
