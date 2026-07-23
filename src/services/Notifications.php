<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Shipment;
use Craft;
use craft\helpers\UrlHelper;
use yii\base\Component;

/**
 * Order-related email sent from this plugin (not the cart), so it can describe
 * the order contents and deep-link straight to Shippo.
 */
class Notifications extends Component
{
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

        $orders = Plugin::getInstance()->stripeOrders;
        $client = $orders->getClient();
        $session = $client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['line_items', 'customer_details'],
        ]);

        $ref = $orders->reference($session);
        $lines = [];
        foreach (($session->line_items->data ?? []) as $li) {
            $lines[] = ($li->quantity ?? 1) . ' × ' . ($li->description ?? 'Item');
        }

        $dashboard = UrlHelper::cpUrl('stripe-shippo-fulfillment');
        $body = "New order {$ref}\n\n"
            . implode("\n", $lines) . "\n\n"
            . 'Total: $' . number_format(($session->amount_total ?? 0) / 100, 2) . "\n"
            . 'Customer: ' . ($session->customer_details->name ?? '—')
            . ' <' . ($session->customer_details->email ?? '—') . ">\n\n"
            . "Fulfillment dashboard: {$dashboard}\n";

        if ($shipment !== null) {
            $body .= 'Buy the label in Shippo: ' . Plugin::getInstance()->shippo->appUrl($shipment->shippoOrderId) . "\n";
        }

        return Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject("New order {$ref}")
            ->setTextBody($body)
            ->send();
    }

    /**
     * Customer "your order has shipped" email, sent when the Shippo label is bought.
     * Tracking details come from the webhook payload and are not stored.
     *
     * @param array<string, mixed> $transaction Shippo transaction payload
     */
    public function sendCustomerShippedEmail(Shipment $shipment, array $transaction): bool
    {
        $client = Plugin::getInstance()->stripeOrders->getClient();
        $session = $client->checkout->sessions->retrieve($shipment->stripeCheckoutSessionId, [
            'expand' => ['customer_details'],
        ]);

        $to = $session->customer_details->email ?? null;
        if (!$to) {
            return false;
        }

        $ref = $shipment->orderNumber ?: Plugin::getInstance()->stripeOrders->reference($session);
        $body = "Good news — your order {$ref} is on its way.\n";

        $tracking = $transaction['tracking_number'] ?? null;
        $trackingUrl = $transaction['tracking_url_provider'] ?? null;
        if ($tracking) {
            $body .= "\nTracking: {$tracking}\n";
        }
        if ($trackingUrl) {
            $body .= "Track it: {$trackingUrl}\n";
        }
        $body .= "\nThank you.\n";

        return Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject("Your order {$ref} has shipped")
            ->setTextBody($body)
            ->send();
    }
}
