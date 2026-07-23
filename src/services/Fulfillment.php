<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Shipment;
use Craft;
use DateTime;
use RuntimeException;
use yii\base\Component;

/**
 * Turns a paid Stripe Checkout Session into a Shippo order and records the
 * crosswalk row. Idempotent: importing an already-imported session is a no-op.
 */
class Fulfillment extends Component
{
    public function importToShippo(string $sessionId, ?int $userId = null): Shipment
    {
        $existing = Shipment::findBySession($sessionId);
        if ($existing !== null) {
            return $existing;
        }

        $orders = Plugin::getInstance()->stripeOrders;
        $client = $orders->getClient();
        $settings = Plugin::getInstance()->getSettings();

        $session = $client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['line_items.data.price.product', 'customer_details', 'payment_intent'],
        ]);

        $toAddress = $this->buildToAddress($session);
        if ($toAddress === null) {
            throw new RuntimeException("Session $sessionId has no shipping address.");
        }

        [$lineItems, $weightOz] = $this->buildLineItems($session, $settings->defaultWeightOz);

        $payload = [
            'to_address' => $toAddress,
            'line_items' => $lineItems,
            'placed_at' => (new DateTime())->setTimestamp($session->created)->format(DateTime::ATOM),
            'order_number' => $orders->reference($session),
            'order_status' => 'PAID',
            'weight' => (string)round($weightOz, 2),
            'weight_unit' => 'oz',
            'currency' => strtoupper($session->currency ?? 'USD'),
            'total_price' => number_format(($session->amount_total ?? 0) / 100, 2, '.', ''),
            'subtotal_price' => number_format(($session->amount_subtotal ?? $session->amount_total ?? 0) / 100, 2, '.', ''),
            'shipping_cost' => number_format(($session->shipping_cost->amount_total ?? 0) / 100, 2, '.', ''),
            'shipping_cost_currency' => strtoupper($session->currency ?? 'USD'),
            // Traceability back to the Stripe session.
            'metadata' => "stripe_session={$session->id}",
        ];

        if ($from = $settings->getFromAddress()) {
            $payload['from_address'] = $from;
        }

        $order = Plugin::getInstance()->shippo->createOrder($payload);
        $shippoOrderId = $order['object_id'] ?? null;
        if (!$shippoOrderId) {
            throw new RuntimeException('Shippo did not return an order id.');
        }

        $pi = $session->payment_intent ?? null;

        $shipment = new Shipment();
        $shipment->stripeCheckoutSessionId = $session->id;
        $shipment->stripePaymentIntentId = is_object($pi) ? $pi->id : (is_string($pi) ? $pi : null);
        $shipment->orderNumber = $orders->reference($session);
        $shipment->shippoOrderId = $shippoOrderId;
        $shipment->importedBy = $userId;
        $shipment->save();

        return $shipment;
    }

    private function buildToAddress(object $session): ?array
    {
        $shipping = $session->shipping_details ?? $session->collected_information->shipping_details ?? null;
        $address = is_object($shipping) ? ($shipping->address ?? null) : null;
        $details = $session->customer_details ?? null;

        // Fall back to the billing address when shipping wasn't collected.
        if ($address === null && is_object($details)) {
            $address = $details->address ?? null;
        }
        if ($address === null) {
            return null;
        }

        return [
            'name' => (is_object($shipping) ? ($shipping->name ?? null) : null) ?? ($details->name ?? ''),
            'street1' => $address->line1 ?? '',
            'street2' => $address->line2 ?? '',
            'city' => $address->city ?? '',
            'state' => $address->state ?? '',
            'zip' => $address->postal_code ?? '',
            'country' => $address->country ?? 'US',
            'phone' => $details->phone ?? '',
            'email' => $details->email ?? '',
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function buildLineItems(object $session, float $defaultWeightOz): array
    {
        $items = [];
        $totalOz = 0.0;

        foreach (($session->line_items->data ?? []) as $li) {
            $qty = $li->quantity ?? 1;
            $product = $li->price->product ?? null;
            $meta = is_object($product) ? ($product->metadata ?? null) : null;
            $unitOz = isset($meta->weight_oz) ? (float)$meta->weight_oz : $defaultWeightOz;
            $totalOz += $unitOz * $qty;

            $items[] = [
                'title' => $li->description ?? 'Item',
                'quantity' => $qty,
                'total_price' => number_format(($li->amount_total ?? 0) / 100, 2, '.', ''),
                'currency' => strtoupper($session->currency ?? 'USD'),
                'weight' => (string)round($unitOz, 2),
                'weight_unit' => 'oz',
                'sku' => is_object($product) ? ($product->id ?? '') : '',
            ];
        }

        return [$items, $totalOz];
    }
}
