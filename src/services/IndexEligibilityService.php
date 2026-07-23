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
     * Adds the active date-window filter (rolling months or current year) to a
     * bulk index query. No-op for sections without a window.
     */
    public function applySectionCriteria(EntryQuery $query, string $section, Settings $settings): EntryQuery
    {
        $window = $this->dateWindowForSection($section, $settings);
        if ($window === null) {
            return $query;
        }

        [$start, $end] = $window;
        $query->after($start);
        if ($end !== null) {
            $query->before($end);
        }

        return $query;
    }

    /**
     * Single-entry check for the active date window (rolling months / current year).
     */
    public function isEligible(Entry $entry, Settings $settings): bool
    {
        $section = $entry->section->handle ?? '';
        $window = $this->dateWindowForSection($section, $settings);
        if ($window === null) {
            return true;
        }

        if (!$entry->postDate) {
            return false;
        }

        [$start, $end] = $window;
        $postDate = \DateTimeImmutable::createFromInterface($entry->postDate);

        if ($postDate < $start) {
            return false;
        }

        return $end === null || $postDate < $end;
    }

    /**
     * The active date window for a section, or null when the section is not
     * date-restricted. A rolling "last N months" window takes precedence over
     * the calendar-year window when a section appears in both lists.
     *
     * @return array{0: \DateTimeImmutable, 1: ?\DateTimeImmutable}|null [start, end]; end is null for an open-ended rolling window.
     */
    public function dateWindowForSection(string $section, Settings $settings): ?array
    {
        if ($this->isRecentMonthsSection($section, $settings)) {
            return [$this->recentMonthsStart($section, $settings), null];
        }

        if ($this->isCurrentYearOnlySection($section, $settings)) {
            return $this->currentYearRange($settings);
        }

        return null;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function currentYearRange(Settings $settings): array
    {
        $now = $this->now($settings);
        $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);

        return [$start, $start->modify('+1 year')];
    }

    /**
     * Start of the rolling "last N months" window for a section (end = now).
     */
    public function recentMonthsStart(string $section, Settings $settings): \DateTimeImmutable
    {
        $months = max(1, (int) ($settings->recentMonthsSections[$section] ?? 0));

        return $this->now($settings)->modify("-{$months} months");
    }

    public function isCurrentYearOnlySection(string $section, Settings $settings): bool
    {
        return in_array($section, $settings->currentYearOnlySections, true);
    }

    public function isRecentMonthsSection(string $section, Settings $settings): bool
    {
        return array_key_exists($section, $settings->recentMonthsSections)
            && (int) $settings->recentMonthsSections[$section] > 0;
    }

    private function now(Settings $settings): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($settings->timezone ?: Craft::$app->getTimeZone());

        return new \DateTimeImmutable('now', $tz);
    }
}
