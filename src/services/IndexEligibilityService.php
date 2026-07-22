<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;
use viesrood\synthese\models\Settings;

/**
 * IndexEligibilityService
 *
 * Bepaalt welke entries geindexeerd en doorzocht mogen worden: de sectie-gate
 * (include/exclude/fieldConfig) plus de optionele "alleen huidig jaar"-regel.
 */
class IndexEligibilityService extends Component
{
    /**
     * De volledige gate die het auto-index-event gebruikt.
     */
    public function shouldIndexEntry(Entry $entry, Settings $settings): bool
    {
        if ($entry->status !== Entry::STATUS_LIVE) {
            return false;
        }

        $section = $entry->section->handle ?? '';

        if (!$this->isIndexableSection($section, $settings)) {
            return false;
        }

        return $this->isEligible($entry, $settings);
    }

    /**
     * Is deze sectie uberhaupt onderdeel van de index-scope?
     */
    public function isIndexableSection(string $section, Settings $settings): bool
    {
        if (in_array($section, $settings->excludeSections, true)) {
            return false;
        }

        if (!empty($settings->includeSections)) {
            return in_array($section, $settings->includeSections, true);
        }

        // Geen expliciete include-lijst: val terug op de fieldConfig-sleutels.
        if (!empty($settings->fieldConfig)) {
            return array_key_exists($section, $settings->fieldConfig);
        }

        return true;
    }

    /**
     * Voegt het "huidig jaar"-datumfilter toe aan een bulk-index-query.
     */
    public function applySectionCriteria(EntryQuery $query, string $section, Settings $settings): EntryQuery
    {
        if (!$this->isCurrentYearOnlySection($section, $settings)) {
            return $query;
        }

        [$start, $end] = $this->currentYearRange($settings);

        return $query->after($start)->before($end);
    }

    /**
     * Enkele-entry-check voor de "huidig jaar"-regel.
     */
    public function isEligible(Entry $entry, Settings $settings): bool
    {
        $section = $entry->section->handle ?? '';
        if (!$this->isCurrentYearOnlySection($section, $settings)) {
            return true;
        }

        if (!$entry->postDate) {
            return false;
        }

        [$start, $end] = $this->currentYearRange($settings);
        $postDate = \DateTimeImmutable::createFromInterface($entry->postDate);

        return $postDate >= $start && $postDate < $end;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function currentYearRange(Settings $settings): array
    {
        $tz = new \DateTimeZone($settings->timezone ?: Craft::$app->getTimeZone());
        $now = new \DateTimeImmutable('now', $tz);
        $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);

        return [$start, $start->modify('+1 year')];
    }

    public function isCurrentYearOnlySection(string $section, Settings $settings): bool
    {
        return in_array($section, $settings->currentYearOnlySections, true);
    }
}
