<?php

namespace cadenzajon\stripeshippo\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%stripeshippofulfillment_shipments}}';

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'stripeCheckoutSessionId' => $this->string()->notNull(),
            'stripePaymentIntentId' => $this->string(),
            'orderNumber' => $this->string(),
            'shippoOrderId' => $this->string()->notNull(),
            'shippedAt' => $this->dateTime(),
            'importedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['stripeCheckoutSessionId'], true);
        $this->createIndex(null, $table, ['shippoOrderId']);
        $this->createIndex(null, $table, ['shippedAt']);
        $this->addForeignKey(null, $table, ['importedBy'], '{{%users}}', ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%stripeshippofulfillment_shipments}}');
        return true;
    }
}
