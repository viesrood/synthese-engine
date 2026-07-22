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
 * Determines which entries may be indexed and searched: the section gate
 * (include/exclude/fieldConfig) plus the optional "current year only" rule.
 */
class IndexEligibilityService extends Component
{
    /**
     * The full gate used by the auto-index event.
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
     * Is this section part of the index scope at all?
     */
    public function isIndexableSection(string $section, Settings $settings): bool
    {
        if (in_array($section, $settings->excludeSections, true)) {
            return false;
        }

        if (!empty($settings->includeSections)) {
            return in_array($section, $settings->includeSections, true);
        }

        // No explicit include list: fall back on the fieldConfig keys.
        if (!empty($settings->fieldConfig)) {
            return array_key_exists($section, $settings->fieldConfig);
        }

        return true;
    }

    /**
     * Adds the "current year" date filter to a bulk index query.
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
     * Single-entry check for the "current year" rule.
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
