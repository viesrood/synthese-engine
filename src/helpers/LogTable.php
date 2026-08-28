<?php

declare(strict_types=1);

namespace viesrood\synthese\helpers;

use craft\db\Connection;
use craft\db\Migration;

/**
 * The shape of `{{%synthese_logs}}`, in one place.
 *
 * This plugin grew out of a `syntheseEngine` module, and a site that ran that
 * module still has its log table: same name, entirely different columns
 * (`chunksUsed`, `cached`, `ipAddress`, `dateCreated`). The install migration
 * used to leave any existing table alone, so on such a site the old table
 * survived the switch and every insert from `StatsService::logQuery()` hit
 * columns that were not there. That failure is caught and written to the log as
 * a warning, so nothing about the site looked broken: search kept working and
 * the dashboard just stayed empty.
 *
 * Hence a marker-based check rather than a version flag: the table itself says
 * which of the two it is, whatever route the site took to get here.
 */
final class LogTable
{
    public const TABLE = '{{%synthese_logs}}';

    /**
     * Columns this plugin's log table has carried since 1.0.0. The module's
     * table has none of them.
     */
    private const MARKERS = ['is_answerable', 'top_score', 'ip_hash', 'created_at'];

    /**
     * Whether a table by our name exists that is not ours.
     */
    public static function isLegacy(Connection $db): bool
    {
        if (!$db->tableExists(self::TABLE)) {
            return false;
        }

        foreach (self::MARKERS as $column) {
            if (!$db->columnExists(self::TABLE, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed> Column definitions for `createTable()`.
     */
    public static function definition(Migration $m): array
    {
        return [
            'id' => $m->primaryKey(),
            'query' => $m->string(500)->notNull(),
            // Normalised form, so log rows can be grouped without re-deriving it.
            'query_normalized' => $m->string(500)->notNull()->defaultValue(''),
            'is_answerable' => $m->tinyInteger(1)->notNull()->defaultValue(0),
            // 'answered', 'gated' or 'cached'. Unambiguous, unlike is_answerable.
            'outcome' => $m->string(12)->notNull()->defaultValue('answered'),
            'cache_hit' => $m->tinyInteger(1)->notNull()->defaultValue(0),
            'top_score' => $m->decimal(8, 6)->defaultValue(0),
            'score_spread' => $m->decimal(8, 6)->defaultValue(0),
            'chunks_used' => $m->smallInteger()->defaultValue(0),
            'sources_count' => $m->smallInteger()->notNull()->defaultValue(0),
            'duration_ms' => $m->integer()->defaultValue(0),
            'ip_hash' => $m->string(64)->defaultValue(''),
            'created_at' => $m->dateTime()->notNull(),
            'harvested_at' => $m->dateTime()->null(),
        ];
    }

    public static function createIndexes(Migration $m): void
    {
        $m->createIndex(null, self::TABLE, ['created_at'], false);
        $m->createIndex(null, self::TABLE, ['query_normalized'], false);
        $m->createIndex(null, self::TABLE, ['outcome'], false);
        $m->createIndex(null, self::TABLE, ['harvested_at'], false);
    }
}
