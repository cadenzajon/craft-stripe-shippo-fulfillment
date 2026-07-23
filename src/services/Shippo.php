<?php

namespace cadenzajon\stripeshippo\services;

use cadenzajon\stripeshippo\Plugin;
use Craft;
use GuzzleHttp\Client;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Thin Shippo REST client. Creates orders and reads them back; it never buys a
 * label — that is always a human action in the Shippo app.
 */
class Shippo extends Component
{
    private const BASE_URI = 'https://api.goshippo.com/';

    private ?Client $client = null;

    public function isConfigured(): bool
    {
        return Plugin::getInstance()->getSettings()->getShippoApiToken() !== '';
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $token = Plugin::getInstance()->getSettings()->getShippoApiToken();
            if ($token === '') {
                throw new InvalidConfigException('No Shippo API token is configured.');
            }
            $this->client = Craft::createGuzzleClient([
                'base_uri' => self::BASE_URI,
                'headers' => [
                    'Authorization' => "ShippoToken $token",
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        $response = $this->client()->post('orders/', ['json' => $payload]);
        return json_decode((string)$response->getBody(), true) ?: [];
    }

    /** @return array<string, mixed> */
    public function getOrder(string $orderId): array
    {
        $response = $this->client()->get("orders/$orderId");
        return json_decode((string)$response->getBody(), true) ?: [];
    }

    /** Deep link to the order in the Shippo app, where the label is bought. */
    public function appUrl(string $orderId): string
    {
        return "https://apps.goshippo.com/orders/$orderId";
    }
}
