<?php

declare(strict_types=1);

namespace viesrood\synthese\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use viesrood\synthese\Plugin;
use yii\console\ExitCode;

/**
 * Statistieken en diagnostiek.
 *
 * synthese-engine/stats            Overzicht (vandaag + totaal + rollup)
 * synthese-engine/stats/test       Test de verbindingen (OpenAI/Supabase/Gemini)
 * synthese-engine/stats/recent     Recente zoekopdrachten
 * synthese-engine/stats/costs      Kosten van vandaag
 */
class StatsController extends Controller
{
    public function actionIndex(): int
    {
        $plugin = Plugin::$plugin;
        $today = $plugin->stats->getTodayStats();
        $total = $plugin->stats->getTotalStats();
        $rollup = $plugin->stats->getRollup(7);
        $vector = $plugin->vector->getStats();

        $this->stdout("== Vandaag ==\n", Console::FG_CYAN);
        $this->stdout("Synthese-requests: {$today['synthesis']['requests']}\n");
        $this->stdout("Kosten: \${$today['costs']['total']}\n");
        $this->stdout("\n== 7 dagen ==\n", Console::FG_CYAN);
        $this->stdout("Zoekopdrachten: {$rollup['total']} | beantwoordbaar: {$rollup['answerableRate']}% | cache-hit: {$rollup['cacheHitRate']}%\n");
        $this->stdout("\n== Totaal ==\n", Console::FG_CYAN);
        $this->stdout('Zoekopdrachten: ' . ($total['totalQueries'] ?? 0) . "\n");
        $this->stdout('Chunks in index: ' . ($vector['chunks'] ?? 0) . "\n");

        return ExitCode::OK;
    }

    public function actionTest(): int
    {
        $plugin = Plugin::$plugin;

        $checks = [
            'OpenAI (embeddings)' => $plugin->embedding->testConnection(),
            'Supabase (vector)' => $plugin->vector->testConnection(),
            'Gemini (synthese)' => $plugin->synthesis->testConnection(),
        ];

        $ok = true;
        foreach ($checks as $label => $result) {
            if (!empty($result['success'])) {
                $this->stdout("[OK]   {$label}\n", Console::FG_GREEN);
            } else {
                $ok = false;
                $this->stdout("[FOUT] {$label}: " . ($result['error'] ?? 'onbekend') . "\n", Console::FG_RED);
            }
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionRecent(int $limit = 20): int
    {
        foreach (Plugin::$plugin->stats->getRecentQueries($limit) as $row) {
            $flag = $row['is_answerable'] ? '+' : '-';
            $this->stdout(sprintf("%s [%s] %s (%dms)\n", $flag, $row['created_at'], $row['query'], (int) $row['duration_ms']));
        }
        return ExitCode::OK;
    }

    public function actionCosts(): int
    {
        $this->stdout('Kosten vandaag: $' . round(Plugin::$plugin->stats->getTodayCosts(), 4) . "\n");
        return ExitCode::OK;
    }
}
