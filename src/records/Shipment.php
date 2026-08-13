<?php

namespace cadenzajon\stripeshippo\records;

use craft\db\ActiveRecord;

/**
 * Crosswalk between a paid Stripe Checkout Session and its Shippo order.
 *
 * A row is claimed (status = processing) before the Shippo call, then completed
 * (status = imported, shippoOrderId set) or released (status = failed) so a retry
 * can run. The unique session index makes the claim atomic. No carrier or tracking
 * number is stored; `shippedAt` plus `shippoOrderId` link straight to the label.
 *
 * @property int $id
 * @property string $stripeCheckoutSessionId
 * @property string|null $stripePaymentIntentId
 * @property string|null $orderNumber
 * @property string|null $shippoOrderId
 * @property string $status
 * @property string|null $shippedAt
 * @property int|null $importedBy
 */
class Shipment extends ActiveRecord
{
    /** A row exists but the Shippo order call has not completed yet. */
    public const STATUS_PROCESSING = 'processing';
    /** The Shippo order was created; shippoOrderId is set. */
    public const STATUS_IMPORTED = 'imported';
    /** The Shippo call failed; the row is released for retry. */
    public const STATUS_FAILED = 'failed';

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
