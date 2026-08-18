<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Shipment;
use Craft;
use craft\helpers\Db;
use DateTime;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Turns a paid Stripe Checkout Session into a Shippo order and records the
 * crosswalk row. Idempotent and concurrency-safe: the session is claimed with a
 * local row (guarded by the unique session index) before the Shippo call, so two
 * simultaneous imports cannot create two Shippo orders. Only complete, paid,
 * payment-mode sessions are fulfilled.
 */
class Fulfillment extends Component
{
    public function importToShippo(string $sessionId, ?int $userId = null): Shipment
    {
        // Already imported, or an import is currently in flight: never duplicate.
        $existing = Shipment::findBySession($sessionId);
        if ($existing !== null && in_array($existing->status, [Shipment::STATUS_IMPORTED, Shipment::STATUS_PROCESSING], true)) {
            return $existing;
        }

        $orders = Plugin::getInstance()->stripeOrders;
        $client = $orders->getClient();
        $settings = Plugin::getInstance()->getSettings();

        $session = $client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['line_items.data.price.product', 'customer_details', 'payment_intent'],
        ]);

        // Refuse anything that is not a completed, paid, payment-mode checkout.
        // (Retrieving with the live key already scopes this to live sessions.)
        $this->assertFulfillable($session);

        $toAddress = $this->buildToAddress($session);
        if ($toAddress === null) {
            throw new RuntimeException("Session $sessionId has no shipping address.");
        }

        // Acquire an exclusive processing claim. Only the acquirer calls Shippo;
        // a concurrent caller gets null and returns the winner's row untouched,
        // so a duplicate webhook can never create a second Shippo order.
        $claim = $this->acquireClaim($session, $orders->reference($session), $userId, $existing);
        if ($claim === null) {
            $winner = Shipment::findBySession($session->id);
            if ($winner === null) {
                throw new RuntimeException("Lost the fulfillment claim for {$session->id} but no row was found.");
            }
            return $winner;
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

        try {
            $order = Plugin::getInstance()->shippo->createOrder($payload);
            $shippoOrderId = $order['object_id'] ?? null;
            if (!$shippoOrderId) {
                throw new RuntimeException('Shippo did not return an order id.');
            }
        } catch (\Throwable $e) {
            // Only release the claim for failures known to occur BEFORE the order
            // is created (Shippo 4xx). Ambiguous failures (timeout, 5xx, or a
            // malformed 200) might have created the order, so keep the claim
            // 'processing' to prevent a duplicate; an admin can clear a stuck row.
            if ($e instanceof ClientException) {
                $claim->status = Shipment::STATUS_FAILED;
                $claim->save(false);
            }
            throw $e;
        }

        $claim->shippoOrderId = $shippoOrderId;
        $claim->status = Shipment::STATUS_IMPORTED;
        if (!$claim->save()) {
            throw new RuntimeException(
                "Shippo order $shippoOrderId created but the shipment record failed to save: "
                . implode('; ', $claim->getFirstErrors())
            );
        }

        return $claim;
    }

    /**
     * Tries to take an exclusive 'processing' claim on the session. Returns the
     * owned row, or null if another caller owns it (fresh race, or a concurrent
     * retry of the same failed row). The caller must not call Shippo on null.
     */
    private function acquireClaim(object $session, string $reference, ?int $userId, ?Shipment $existing): ?Shipment
    {
        // Retry of a failed attempt: flip failed -> processing atomically. Only
        // one caller wins the conditional update.
        if ($existing !== null) {
            $affected = Shipment::updateAll(
                ['status' => Shipment::STATUS_PROCESSING, 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
                ['id' => $existing->id, 'status' => Shipment::STATUS_FAILED],
            );
            if ($affected !== 1) {
                return null; // another caller reclaimed it first
            }
            $existing->status = Shipment::STATUS_PROCESSING;
            return $existing;
        }

        $pi = $session->payment_intent ?? null;
        $claim = new Shipment();
        $claim->stripeCheckoutSessionId = $session->id;
        $claim->stripePaymentIntentId = is_object($pi) ? $pi->id : (is_string($pi) ? $pi : null);
        $claim->orderNumber = $reference;
        $claim->status = Shipment::STATUS_PROCESSING;
        $claim->importedBy = $userId;

        try {
            if (!$claim->save()) {
                throw new RuntimeException(
                    "Could not record the fulfillment claim for {$session->id}: "
                    . implode('; ', $claim->getFirstErrors())
                );
            }
        } catch (IntegrityException) {
            // A concurrent import inserted first — we did not acquire the claim.
            return null;
        }

        return $claim;
    }

    /**
     * @throws RuntimeException if the session must not be fulfilled.
     */
    private function assertFulfillable(object $session): void
    {
        if (($session->mode ?? null) !== 'payment') {
            throw new RuntimeException("Session {$session->id} is not a payment-mode checkout.");
        }
        if (($session->status ?? null) !== 'complete') {
            throw new RuntimeException("Session {$session->id} is not complete.");
        }
        $paymentStatus = $session->payment_status ?? null;
        if (!in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            throw new RuntimeException("Session {$session->id} is not paid (payment_status={$paymentStatus}).");
        }
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
            $weightKey = Plugin::getInstance()->getSettings()->weightMetadataKey;
            $unitOz = ($weightKey !== '' && isset($meta->$weightKey))
                ? (float)$meta->$weightKey
                : $defaultWeightOz;
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
