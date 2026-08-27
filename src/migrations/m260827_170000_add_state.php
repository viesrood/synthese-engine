<?php

declare(strict_types=1);

namespace viesrood\synthese\migrations;

use craft\db\Migration;

/**
 * A small key/value store for choices that belong to whoever runs the site.
 *
 * Craft keeps plugin settings in project config, which is a file in the repo.
 * On a deploy that checks the working tree out (`git checkout -f`) and applies
 * the result, anything an admin changed in the control panel is silently put
 * back. That is tolerable for a tuning number and not tolerable for the switch
 * that says whether questions typed by visitors may be collected at all: it
 * would revert to whatever the developer committed, without anyone noticing.
 *
 * So that switch, and the two numbers that go with it, live here instead. The
 * plugin settings still supply the defaults.
 */
class m260827_170000_add_state extends Migration
{
    public const TABLE = '{{%synthese_state}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'key' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->addPrimaryKey(null, self::TABLE, ['key']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
