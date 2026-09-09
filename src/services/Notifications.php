<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Notification;
use cadenzajon\stripeshippo\records\Shipment;
use Craft;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Order-related email sent from this plugin (not the cart), so it can describe
 * the order contents and deep-link straight to Shippo.
 */
class Notifications extends Component
{
    private const CLAIM_TIMEOUT = '-10 minutes';

    /**
     * Admin notice on a new paid order. Deep-links to the CP dashboard and, if
     * the order was already imported, straight to the Shippo order.
     */
    public function sendAdminOrderEmail(string $sessionId, ?Shipment $shipment = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $to = $settings->getAdminEmail();
        if ($to === '') {
            return false;
        }

        $notification = $this->acquire($sessionId);
        if ($notification === null) {
            return Notification::findOne(['stripeCheckoutSessionId' => $sessionId])?->status
                === Notification::STATUS_SENT;
        }

        try {
            $orders = Plugin::getInstance()->stripeOrders;
            $client = $orders->getClient();
            $session = $client->checkout->sessions->retrieve($sessionId, [
                'expand' => ['customer_details'],
            ]);

            $ref = $orders->reference($session);
            $lines = [];
            foreach ($orders->getAllLineItems($sessionId, false) as $li) {
                $lines[] = ($li->quantity ?? 1) . ' × ' . ($li->description ?? 'Item');
            }

            $dashboard = UrlHelper::cpUrl('stripe-shippo-fulfillment');
            $body = "New order {$ref}\n\n"
                . implode("\n", $lines) . "\n\n"
                . 'Total: ' . $orders->formatAmount($session->amount_total ?? 0, $session->currency ?? 'usd') . "\n"
                . 'Customer: ' . ($session->customer_details->name ?? '—')
                . ' <' . ($session->customer_details->email ?? '—') . ">\n\n"
                . "Fulfillment dashboard: {$dashboard}\n";

            if ($shipment?->status === Shipment::STATUS_IMPORTED && $shipment->shippoOrderId) {
                $body .= 'Buy the label in Shippo: ' . Plugin::getInstance()->shippo->appUrl($shipment->shippoOrderId) . "\n";
            }

            $sent = Craft::$app->getMailer()
                ->compose()
                ->setTo($to)
                ->setSubject("New order {$ref}")
                ->setTextBody($body)
                ->send();

            $notification->status = $sent ? Notification::STATUS_SENT : Notification::STATUS_FAILED;
            $notification->save(false);

            return $sent;
        } catch (\Throwable $e) {
            $notification->status = Notification::STATUS_FAILED;
            $notification->save(false);
            throw $e;
        }
    }

    private function acquire(string $sessionId): ?Notification
    {
        $existing = Notification::findOne(['stripeCheckoutSessionId' => $sessionId]);
        if ($existing !== null) {
            if ($existing->status === Notification::STATUS_SENT) {
                return null;
            }

            $affected = Notification::updateAll(
                ['status' => Notification::STATUS_PROCESSING, 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
                [
                    'and',
                    ['id' => $existing->id],
                    ['or',
                        ['status' => Notification::STATUS_FAILED],
                        ['and',
                            ['status' => Notification::STATUS_PROCESSING],
                            ['<', 'dateUpdated', Db::prepareDateForDb(new DateTime(self::CLAIM_TIMEOUT))],
                        ],
                    ],
                ],
            );
            if ($affected !== 1) {
                return null;
            }
            $existing->status = Notification::STATUS_PROCESSING;
            return $existing;
        }

        $claim = new Notification([
            'stripeCheckoutSessionId' => $sessionId,
            'status' => Notification::STATUS_PROCESSING,
        ]);
        try {
            return $claim->save() ? $claim : null;
        } catch (IntegrityException) {
            return null;
        }
    }
}
