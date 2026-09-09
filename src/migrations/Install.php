<?php

namespace cadenzajon\stripeshippo\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createShipmentsTable();
        $this->createNotificationsTable();

        return true;
    }

    private function createShipmentsTable(): void
    {
        $table = '{{%stripeshippofulfillment_shipments}}';
        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'stripeCheckoutSessionId' => $this->string()->notNull(),
            'stripePaymentIntentId' => $this->string(),
            'orderNumber' => $this->string(),
            // Null until the Shippo order is created; a claim row is written first.
            'shippoOrderId' => $this->string(),
            'status' => $this->string()->notNull()->defaultValue('imported'),
            'shippedAt' => $this->dateTime(),
            'importedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, ['stripeCheckoutSessionId'], true);
        $this->createIndex(null, $table, ['shippoOrderId']);
        $this->createIndex(null, $table, ['status']);
        $this->createIndex(null, $table, ['shippedAt']);
        $this->addForeignKey(null, $table, ['importedBy'], '{{%users}}', ['id'], 'SET NULL', null);

    }

    private function createNotificationsTable(): void
    {
        $table = '{{%stripeshippofulfillment_notifications}}';
        if ($this->db->tableExists($table)) {
            return;
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
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%stripeshippofulfillment_notifications}}');
        $this->dropTableIfExists('{{%stripeshippofulfillment_shipments}}');
        return true;
    }
}
