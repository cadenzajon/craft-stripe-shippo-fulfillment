<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Shipment;
use craft\stripe\Plugin as StripePlugin;
use DateTime;
use Stripe\StripeClient;
use yii\base\Component;

/**
 * Reads recent orders live from Stripe and joins them against the local Shippo
 * crosswalk to derive a fulfillment status. Nothing here is cached.
 */
class StripeOrders extends Component
{
    public const STATUS_NEW = 'new';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LABEL_PENDING = 'label_pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_REFUNDED = 'refunded';

    public function isConfigured(): bool
    {
        return !empty(StripePlugin::getInstance()->getApi()->getApiKey());
    }

    public function getClient(): StripeClient
    {
        return StripePlugin::getInstance()->getApi()->getClient();
    }

    /**
     * @return array<int, array<string, mixed>> Normalized order rows, newest first.
     */
    public function getRecentOrders(?int $limit = null): array
    {
        $limit = $limit ?? Plugin::getInstance()->getSettings()->lookback;
        $client = $this->getClient();

        $sessions = $client->checkout->sessions->all([
            'limit' => $limit,
            'status' => 'complete',
            'expand' => ['data.payment_intent.latest_charge'],
        ]);

        $orders = [];
        foreach ($sessions->data as $session) {
            if (($session->payment_status ?? null) === 'unpaid') {
                continue;
            }
            $orders[] = $this->normalize($session, $client);
        }

        return $orders;
    }

    private function normalize(object $session, StripeClient $client): array
    {
        $lineItems = $client->checkout->sessions->allLineItems($session->id, [
            'limit' => 20,
            'expand' => ['data.price.product'],
        ])->data;

        $items = [];
        $shipAfter = null;
        foreach ($lineItems as $li) {
            $product = $li->price->product ?? null;
            $meta = is_object($product) ? ($product->metadata ?? null) : null;

            $items[] = [
                'title' => $li->description ?? ($meta->name ?? 'Item'),
                'qty' => $li->quantity ?? 1,
                'productId' => is_object($product) ? $product->id : (is_string($product) ? $product : null),
            ];

            $after = $meta->ship_after ?? null;
            if ($after) {
                $ts = strtotime($after);
                if ($ts && ($shipAfter === null || $ts > $shipAfter)) {
                    $shipAfter = $ts;
                }
            }
        }

        $pi = $session->payment_intent ?? null;
        $piId = is_object($pi) ? $pi->id : (is_string($pi) ? $pi : null);
        $charge = is_object($pi) ? ($pi->latest_charge ?? null) : null;
        $refunded = is_object($charge) && ($charge->refunded ?? false);

        $shipment = Shipment::findBySession($session->id);
        $scheduled = $shipAfter !== null && $shipAfter > time();

        $status = match (true) {
            $refunded => self::STATUS_REFUNDED,
            $shipment && $shipment->shippedAt => self::STATUS_SHIPPED,
            $shipment !== null => self::STATUS_LABEL_PENDING,
            $scheduled => self::STATUS_SCHEDULED,
            default => self::STATUS_NEW,
        };

        $shipping = $session->shipping_details ?? $session->collected_information->shipping_details ?? null;
        $address = is_object($shipping) ? ($shipping->address ?? null) : null;

        return [
            'ref' => $this->reference($session),
            'sessionId' => $session->id,
            'paymentIntentId' => $piId,
            'placedAt' => (new DateTime())->setTimestamp($session->created),
            'customerName' => $session->customer_details->name ?? (is_object($shipping) ? ($shipping->name ?? null) : null),
            'customerEmail' => $session->customer_details->email ?? null,
            'shipTo' => $address ? trim(($address->city ?? '') . ', ' . ($address->state ?? ''), ', ') : null,
            'items' => $items,
            'total' => $this->money($session->amount_total ?? 0, $session->currency ?? 'usd'),
            'status' => $status,
            'scheduled' => $scheduled,
            'shipAfter' => $shipAfter ? (new DateTime())->setTimestamp($shipAfter) : null,
            'shippoOrderId' => $shipment->shippoOrderId ?? null,
            'shippedAt' => $shipment && $shipment->shippedAt ? new DateTime($shipment->shippedAt) : null,
        ];
    }

    /** A short, human order reference — never the raw cs_live_… id. */
    public function reference(object $session): string
    {
        $meta = $session->metadata ?? null;
        $fromMeta = is_object($meta) ? ($meta->order_number ?? null) : null;
        return $fromMeta ?: strtoupper(substr($session->id, -8));
    }

    private function money(int $amount, string $currency): string
    {
        return '$' . number_format($amount / 100, 2) . ($currency !== 'usd' ? ' ' . strtoupper($currency) : '');
    }
}
