<?php

namespace cadenzajon\stripeshippo\records;

use craft\db\ActiveRecord;

/**
 * Crosswalk between a paid Stripe Checkout Session and its Shippo order.
 *
 * A row exists only once an order has been imported to Shippo — nothing about a
 * Stripe session is cached before that. No carrier or tracking number is stored;
 * `shippedAt` plus `shippoOrderId` are enough to link straight to the label.
 *
 * @property int $id
 * @property string $stripeCheckoutSessionId
 * @property string|null $stripePaymentIntentId
 * @property string|null $orderNumber
 * @property string $shippoOrderId
 * @property string|null $shippedAt
 * @property int|null $importedBy
 */
class Shipment extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stripeshippofulfillment_shipments}}';
    }

    public static function findBySession(string $sessionId): ?self
    {
        return static::findOne(['stripeCheckoutSessionId' => $sessionId]);
    }

    public static function findByShippoOrder(string $shippoOrderId): ?self
    {
        return static::findOne(['shippoOrderId' => $shippoOrderId]);
    }
}
