<?php

declare(strict_types=1);

namespace viesrood\synthese\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use viesrood\synthese\Plugin;
use viesrood\synthese\services\SuggestionsService;
use yii\console\ExitCode;

/**
 * Suggestions: harvest asked questions into approval candidates, and prune the
 * log table they come from.
 *
 * Both are meant to run from cron, nightly.
 */
class SuggestionsController extends Controller
{
    /** Days of logs to keep; defaults to the `logRetentionDays` setting. */
    public ?int $days = null;

    /** Show every cluster the harvest touched. */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'prune' => ['days'],
            'harvest' => ['verbose'],
            default => [],
        };
    }

    /**
     * Reads new log rows and folds them into suggestion clusters.
     */
    public function actionHarvest(): int
    {
        $progress = $this->verbose
            ? function (string $question, bool $isNew): void {
                $this->stdout(($isNew ? '  new  ' : '  seen '), $isNew ? Console::FG_GREEN : Console::FG_GREY);
                $this->stdout($question . PHP_EOL);
            }
            : null;

        $stats = Plugin::$plugin->suggestions->harvest($progress);

        if (!empty($stats['refused'])) {
            $this->stdout(
                'Nothing collected: the suggestion mode is "'
                . Plugin::$plugin->getSettings()->suggestionMode
                . '", so questions asked by visitors are left alone.' . PHP_EOL,
                Console::FG_YELLOW
            );
            return ExitCode::OK;
        }

        $this->stdout(PHP_EOL);
        $this->stdout("Log rows read      : {$stats['scanned']}" . PHP_EOL);
        $this->stdout("Not eligible       : {$stats['skipped']}" . PHP_EOL);
        $this->stdout("New suggestions    : {$stats['newClusters']}" . PHP_EOL);
        $this->stdout("Updated suggestions: {$stats['updatedClusters']}" . PHP_EOL);
        $this->stdout("Embeddings spent   : {$stats['embedded']}" . PHP_EOL);

        $pending = Plugin::$plugin->suggestions->pendingCount();
        if ($pending > 0) {
            $this->stdout(PHP_EOL);
            $this->stdout("{$pending} waiting for approval in the control panel." . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Deletes log rows past the retention window, plus the harvested questions
     * that were never approved.
     *
     * The retention window comes from the service, not from the settings
     * model. Those two can differ: the number an admin sets in the control
     * panel is stored in the state table on purpose, because plugin settings
     * are project config and a deploy would put an admin's shortened retention
     * back to the developer's default. Reading the settings here would have
     * meant the control panel showed one number while this cron enforced
     * another.
     *
     * Approved suggestions are left alone. By the time an admin approves one it
     * is editorial content on the site, not a visitor's log line. Everything
     * still pending or rejected is a question a visitor typed that will never
     * be shown, so it goes the same way as the log rows it came from.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? Plugin::$plugin->suggestions->retentionDays();

        if ($days < 1) {
            $this->stdout('Retention is 0, nothing pruned.' . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $deleted = Plugin::$plugin->suggestions->pruneLogs($days);
        $this->stdout("{$deleted} log rows older than {$days} days deleted." . PHP_EOL, Console::FG_GREEN);

        $dropped = Plugin::$plugin->suggestions->pruneSuggestions($days);
        $this->stdout("{$dropped} unapproved harvested question(s) older than {$days} days deleted." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes every suggestion that came out of a visitor's question. Questions
     * an admin added themselves are kept.
     */
    public function actionForget(): int
    {
        $count = Plugin::$plugin->suggestions->harvestedCount();

        if ($count === 0) {
            $this->stdout('Nothing collected from visitors is on file.' . PHP_EOL);
            return ExitCode::OK;
        }

        if (!$this->confirm("Delete {$count} question(s) collected from visitors?")) {
            return ExitCode::OK;
        }

        $deleted = Plugin::$plugin->suggestions->forgetHarvested();
        $this->stdout("{$deleted} deleted." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Prints what visitors would be offered right now.
     */
    public function actionList(): int
    {
        $suggestions = Plugin::$plugin->suggestions;

        foreach ([
            SuggestionsService::STATUS_PENDING => 'Queue',
            SuggestionsService::STATUS_APPROVED => 'Approved',
        ] as $status => $label) {
            $rows = $suggestions->listByStatus($status, 50);
            $this->stdout(PHP_EOL . $label . ' (' . count($rows) . ')' . PHP_EOL, Console::FG_CYAN);

            foreach ($rows as $row) {
                $this->stdout(sprintf(
                    "  %-4s %-3s  %s" . PHP_EOL,
                    $row['ask_count'] . 'x',
                    $row['asker_count'] . 'p',
                    $row['question']
                ));
            }
        }

        return ExitCode::OK;
    }
}
