<?php

namespace cadenzajon\stripeshippo\records;

use craft\db\ActiveRecord;

/**
 * Atomic one-email-per-order claim.
 *
 * @property int $id
 * @property string $stripeCheckoutSessionId
 * @property string $status
 */
class Notification extends ActiveRecord
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return '{{%stripeshippofulfillment_notifications}}';
    }
}
