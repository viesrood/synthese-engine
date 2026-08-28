<?php

declare(strict_types=1);

namespace viesrood\synthese\migrations;

use craft\db\Migration;
use viesrood\synthese\helpers\LogTable;

/**
 * Replaces a `{{%synthese_logs}}` left behind by the older `syntheseEngine`
 * module with this plugin's own.
 *
 * Until now the install migration skipped a table that already existed, so a
 * site that came from the module kept the module's columns. Nothing said so:
 * `StatsService::logQuery()` catches its own failure and writes a warning, so
 * search kept answering while the log stayed at the last row the module wrote.
 * It surfaced only when m260827_140000 tried to read `is_answerable` on such a
 * table and the whole update aborted.
 *
 * The old rows are dropped rather than converted. They carry a full IP address
 * next to free text a visitor typed, which this table deliberately no longer
 * stores, and none of the retrieval measurements every later feature reads.
 */
class m260828_090000_rebuild_module_log_table extends Migration
{
    public function safeUp(): bool
    {
        if (!LogTable::isLegacy($this->db)) {
            return true;
        }

        $this->dropTableIfExists(LogTable::TABLE);
        $this->createTable(LogTable::TABLE, LogTable::definition($this));
        LogTable::createIndexes($this);

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to restore: what this replaced belonged to a module that is
        // no longer installed. Dropping our own table here would take the log
        // of every site that never had the module's one.
        return true;
    }
}
