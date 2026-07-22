<?php

declare(strict_types=1);

namespace viesrood\synthese\console\controllers;

use craft\console\Controller;
use viesrood\synthese\Plugin;
use viesrood\synthese\services\SupabaseSqlBuilder;
use yii\console\ExitCode;

/**
 * Setup-hulpmiddelen.
 *
 * synthese-engine/setup/supabase   Print de klaar-om-te-plakken Supabase-SQL
 *                                   (gevuld met de huidige instellingen).
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
