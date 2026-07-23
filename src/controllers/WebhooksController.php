<?php

namespace cadenzajon\stripeshippo\controllers;

use cadenzajon\stripeshippo\Plugin;
use cadenzajon\stripeshippo\records\Shipment;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Receives Shippo webhooks. (Stripe webhooks are handled through the
 * craftcms/stripe plugin's event, not here.)
 *
 * Point a Shippo webhook for `transaction_created` at:
 *   https://your-site.tld/actions/stripe-shippo-fulfillment/webhooks/shippo?token=YOUR_TOKEN
 */
class WebhooksController extends Controller
{
    protected array|int|bool $allowAnonymous = ['shippo' => self::ALLOW_ANONYMOUS_LIVE];
    public $enableCsrfValidation = false;

    public function actionShippo(): Response
    {
        $this->requirePostRequest();

        $expected = Plugin::getInstance()->getSettings()->getShippoWebhookToken();
        if ($expected !== '' && !hash_equals($expected, (string)$this->request->getQueryParam('token'))) {
            throw new BadRequestHttpException('Invalid webhook token.');
        }

        $payload = $this->request->getRawBody();
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new BadRequestHttpException('Malformed payload.');
        }

        // Shippo wraps the object under `data`; the event name is in `event`.
        $type = $event['event'] ?? null;
        $object = $event['data'] ?? $event;

        if ($type === 'transaction_created' || $type === 'transaction_updated') {
            $this->handleTransaction($object);
        }

        return $this->asRaw('ok');
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function handleTransaction(array $transaction): void
    {
        $shipment = $this->matchShipment($transaction);
        if ($shipment === null) {
            Craft::warning('Shippo transaction did not match a known order.', __METHOD__);
            return;
        }

        Plugin::getInstance()->fulfillment->markShipped($shipment);

        try {
            Plugin::getInstance()->notifications->sendCustomerShippedEmail($shipment, $transaction);
        } catch (\Throwable $e) {
            Craft::error("Customer shipped email failed: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Correlate the transaction back to a stored shipment. Prefers an explicit
     * order id on the transaction; falls back to the `stripe_session=…` marker
     * we write into the Shippo order metadata at import time.
     *
     * @param array<string, mixed> $transaction
     */
    private function matchShipment(array $transaction): ?Shipment
    {
        if (!empty($transaction['order'])) {
            $orderId = is_array($transaction['order'])
                ? ($transaction['order']['object_id'] ?? null)
                : $transaction['order'];
            if ($orderId && ($shipment = Shipment::findByShippoOrder($orderId))) {
                return $shipment;
            }
        }

        $metadata = $transaction['metadata'] ?? '';
        if (is_string($metadata) && preg_match('/stripe_session=(\S+)/', $metadata, $m)) {
            return Shipment::findBySession($m[1]);
        }

        return null;
    }
}
