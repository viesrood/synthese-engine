<?php

declare(strict_types=1);

namespace viesrood\synthese\migrations;

use craft\db\Migration;
use viesrood\synthese\helpers\LogTable;

/**
 * Install migration: creates the local log table `{{%synthese_logs}}` and the
 * two suggestion tables.
 * (The Supabase vector store is provisioned separately, see the tools/README.)
 */
class Install extends Migration
{
    public const TABLE = '{{%synthese_logs}}';
    public const SUGGESTIONS = '{{%synthese_suggestions}}';
    public const VARIANTS = '{{%synthese_suggestion_variants}}';
    public const STATE = '{{%synthese_state}}';

    public function safeUp(): bool
    {
        $this->createLogTable();
        $this->createSuggestionTables();
        $this->createStateTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::STATE);
        $this->dropTableIfExists(self::VARIANTS);
        $this->dropTableIfExists(self::SUGGESTIONS);
        $this->dropTableIfExists(self::TABLE);
        return true;
    }

    private function createLogTable(): void
    {
        if (LogTable::isLegacy($this->db)) {
            // A log table left behind by the older `syntheseEngine` module.
            // Its rows cannot be carried over: they hold a full IP address
            // where this table holds a hash, and none of the retrieval
            // measurements the plugin logs. See helpers/LogTable.
            $this->dropTableIfExists(LogTable::TABLE);
        }

        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->createTable(self::TABLE, LogTable::definition($this));
        LogTable::createIndexes($this);
    }

    /**
     * Choices that belong to whoever runs the site, kept out of project config
     * so a deploy cannot put them back. See m260827_170000_add_state.
     */
    private function createStateTable(): void
    {
        if ($this->db->tableExists(self::STATE)) {
            return;
        }

        $this->createTable(self::STATE, [
            'key' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->addPrimaryKey(null, self::STATE, ['key']);
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
}
