<?php

namespace cadenzajon\stripeshippo\migrations;

use craft\db\Migration;

/**
 * Adds a lifecycle status to shipments and makes shippoOrderId nullable so a row
 * can be claimed (status = processing) before the Shippo API call. This turns the
 * old racy check-then-create import into an atomic claim guarded by the unique
 * session index.
 */
class m260813_000001_add_shipment_status extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%stripeshippofulfillment_shipments}}';

        if (!$this->db->columnExists($table, 'status')) {
            // Existing rows already hold a Shippo order id, so they are imported.
            $this->addColumn($table, 'status', $this->string()->notNull()->defaultValue('imported')->after('shippoOrderId'));
            $this->createIndex(null, $table, ['status']);
        }

        // A claim row is written before the Shippo order exists.
        $this->alterColumn($table, 'shippoOrderId', $this->string()->null());

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%stripeshippofulfillment_shipments}}';

        if ($this->db->columnExists($table, 'status')) {
            $this->dropColumn($table, 'status');
        }
        // Non-imported claim rows have a null shippoOrderId and would break the
        // NOT NULL restore, so remove them first.
        $this->delete($table, ['shippoOrderId' => null]);
        $this->alterColumn($table, 'shippoOrderId', $this->string()->notNull());

        return true;
    }
}
