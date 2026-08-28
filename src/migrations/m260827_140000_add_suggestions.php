<?php

declare(strict_types=1);

namespace viesrood\synthese\migrations;

use craft\db\Migration;
use craft\db\Query;
use viesrood\synthese\helpers\LogTable;
use viesrood\synthese\helpers\QueryNormalizer;

/**
 * Adds the suggestion tables and the log columns they are harvested from.
 *
 * Three things the log table was missing before questions could be turned into
 * suggestions: a normalised form to group on, an unambiguous outcome (the
 * existing `is_answerable` means "the gate let it through" on one code path and
 * "the answer had sources" on another), and the number of sources as the actual
 * quality signal. `top_score` cannot serve as one: it holds an RRF fusion score,
 * which only orders chunks within a single query.
 */
class m260827_140000_add_suggestions extends Migration
{
    private const LOGS = '{{%synthese_logs}}';
    private const SUGGESTIONS = '{{%synthese_suggestions}}';
    private const VARIANTS = '{{%synthese_suggestion_variants}}';

    public function safeUp(): bool
    {
        $this->addLogColumns();
        $this->createSuggestionTables();
        $this->backfillNormalizedQueries();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::VARIANTS);
        $this->dropTableIfExists(self::SUGGESTIONS);

        foreach (['query_normalized', 'outcome', 'sources_count', 'harvested_at'] as $column) {
            if ($this->db->columnExists(self::LOGS, $column)) {
                $this->dropColumn(self::LOGS, $column);
            }
        }

        return true;
    }

    private function addLogColumns(): void
    {
        if (!$this->db->tableExists(self::LOGS) || LogTable::isLegacy($this->db)) {
            // A log table left behind by the older module is replaced whole by
            // m260828_090000_rebuild_module_log_table, which runs right after
            // this one. Columns added to it here would be thrown away with it.
            return;
        }

        if (!$this->db->columnExists(self::LOGS, 'query_normalized')) {
            $this->addColumn(self::LOGS, 'query_normalized', $this->string(500)->notNull()->defaultValue(''));
            $this->createIndex(null, self::LOGS, ['query_normalized'], false);
        }

        if (!$this->db->columnExists(self::LOGS, 'outcome')) {
            $this->addColumn(self::LOGS, 'outcome', $this->string(12)->notNull()->defaultValue('answered'));
            $this->createIndex(null, self::LOGS, ['outcome'], false);
        }

        if (!$this->db->columnExists(self::LOGS, 'sources_count')) {
            $this->addColumn(self::LOGS, 'sources_count', $this->smallInteger()->notNull()->defaultValue(0));
        }

        if (!$this->db->columnExists(self::LOGS, 'harvested_at')) {
            $this->addColumn(self::LOGS, 'harvested_at', $this->dateTime()->null());
            $this->createIndex(null, self::LOGS, ['harvested_at'], false);
        }
    }

    private function createSuggestionTables(): void
    {
        if (!$this->db->tableExists(self::SUGGESTIONS)) {
            $this->createTable(self::SUGGESTIONS, [
                'id' => $this->primaryKey(),
                // The text a visitor gets to see. May be a rewrite by an admin.
                'question' => $this->string(500)->notNull(),
                'question_normalized' => $this->string(500)->notNull(),
                // Packed float32 vector, see QueryNormalizer::packVector().
                'embedding' => $this->binary()->null(),
                'ask_count' => $this->integer()->notNull()->defaultValue(0),
                'asker_count' => $this->integer()->notNull()->defaultValue(0),
                'first_asked_at' => $this->dateTime()->null(),
                'last_asked_at' => $this->dateTime()->null(),
                'status' => $this->string(10)->notNull()->defaultValue('pending'),
                'pinned' => $this->tinyInteger(1)->notNull()->defaultValue(0),
                'edited_by_admin' => $this->tinyInteger(1)->notNull()->defaultValue(0),
                'origin' => $this->string(10)->notNull()->defaultValue('harvested'),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::SUGGESTIONS, ['status'], false);
            $this->createIndex(null, self::SUGGESTIONS, ['question_normalized'], true);
        }

        if (!$this->db->tableExists(self::VARIANTS)) {
            $this->createTable(self::VARIANTS, [
                'id' => $this->primaryKey(),
                'suggestionId' => $this->integer()->notNull(),
                // Every phrasing that resolves to this cluster, so a returning
                // variant is matched without spending an embedding on it.
                'query_normalized' => $this->string(500)->notNull(),
                'ask_count' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::VARIANTS, ['query_normalized'], true);
            $this->addForeignKey(null, self::VARIANTS, ['suggestionId'], self::SUGGESTIONS, ['id'], 'CASCADE', null);
        }
    }

    /**
     * Backfills the three derived columns for rows written before this
     * migration, and marks them as harvested.
     *
     * `outcome` is reconstructed from the two flags that used to carry it.
     * `sources_count` cannot be reconstructed at all, so those rows would never
     * pass the harvest filter anyway; marking them harvested says that out loud
     * instead of walking over them on every run.
     */
    private function backfillNormalizedQueries(): void
    {
        if (!$this->db->tableExists(self::LOGS) || LogTable::isLegacy($this->db)) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'query', 'is_answerable', 'cache_hit'])
            ->from(self::LOGS)
            ->where(['query_normalized' => ''])
            ->all($this->db);

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if ((int) $row['cache_hit'] === 1) {
                $outcome = 'cached';
            } elseif ((int) $row['is_answerable'] === 1) {
                $outcome = 'answered';
            } else {
                $outcome = 'gated';
            }

            $this->db->createCommand()->update(
                self::LOGS,
                [
                    'query_normalized' => mb_substr(QueryNormalizer::normalize((string) $row['query']), 0, 500),
                    'outcome' => $outcome,
                    'harvested_at' => $now,
                ],
                ['id' => $row['id']]
            )->execute();
        }
    }
}
