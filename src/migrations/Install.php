<?php

declare(strict_types=1);

namespace viesrood\synthese\migrations;

use craft\db\Migration;

/**
 * Install-migratie: maakt de lokale logtabel `{{%synthese_logs}}`.
 * (De Supabase-vector-store wordt apart geprovisioned, zie de tools/README.)
 */
class Install extends Migration
{
    public const TABLE = '{{%synthese_logs}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'query' => $this->string(500)->notNull(),
            'is_answerable' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'cache_hit' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'top_score' => $this->decimal(8, 6)->defaultValue(0),
            'score_spread' => $this->decimal(8, 6)->defaultValue(0),
            'chunks_used' => $this->smallInteger()->defaultValue(0),
            'duration_ms' => $this->integer()->defaultValue(0),
            'ip_hash' => $this->string(64)->defaultValue(''),
            'created_at' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, self::TABLE, ['created_at'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
