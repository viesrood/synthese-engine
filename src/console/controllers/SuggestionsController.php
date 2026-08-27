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
     * Deletes log rows past the retention window. The suggestions stay: they
     * hold the question text only, never the IP hash it arrived with.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? Plugin::$plugin->getSettings()->logRetentionDays;

        if ($days < 1) {
            $this->stdout('Retention is 0, nothing pruned.' . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $deleted = Plugin::$plugin->suggestions->pruneLogs($days);
        $this->stdout("{$deleted} log rows older than {$days} days deleted." . PHP_EOL, Console::FG_GREEN);

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
