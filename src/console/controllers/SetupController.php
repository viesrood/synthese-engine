<?php

declare(strict_types=1);

namespace viesrood\synthese\console\controllers;

use craft\console\Controller;
use viesrood\synthese\Plugin;
use viesrood\synthese\services\SupabaseSqlBuilder;
use yii\console\ExitCode;

/**
 * Setup utilities.
 *
 * synthese-engine/setup/supabase   Print the ready-to-paste Supabase SQL
 *                                   (filled in with the current settings).
 */
class SetupController extends Controller
{
    public function actionSupabase(): int
    {
        $sql = (new SupabaseSqlBuilder())->build(Plugin::$plugin->getSettings());
        $this->stdout($sql . "\n");
        return ExitCode::OK;
    }
}
