<?php

namespace cadenzajon\stripeshippo\migrations;

use craft\db\Migration;

class m260908_000001_add_notifications extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%stripeshippofulfillment_notifications}}';
        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'stripeCheckoutSessionId' => $this->string()->notNull(),
            'status' => $this->string()->notNull()->defaultValue('processing'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['stripeCheckoutSessionId'], true);
        $this->createIndex(null, $table, ['status']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%stripeshippofulfillment_notifications}}');
        return true;
    }
}
