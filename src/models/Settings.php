<?php

namespace cadenzajon\stripeshippo\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

class Settings extends Model
{
    /** Shippo API token. Supports env vars, e.g. `$SHIPPO_API_TOKEN`. */
    public string $shippoApiToken = '';

    /** Where new-order notifications are sent. Blank falls back to the system email. */
    public string $adminEmail = '';

    /** Create the Shippo order automatically on checkout.session.completed. Off = import by hand from the dashboard. */
    public bool $autoImportToShippo = false;

    /** How many recent Stripe orders the dashboard reads. */
    public int $lookback = 25;

    /** Default parcel weight (oz) for a line item with no `weight_oz` product metadata. */
    public float $defaultWeightOz = 12.0;

    // Sender / return address used to prefill the Shippo order so rates resolve.
    public string $fromName = '';
    public string $fromStreet1 = '';
    public string $fromStreet2 = '';
    public string $fromCity = '';
    public string $fromState = '';
    public string $fromZip = '';
    public string $fromCountry = 'US';
    public string $fromPhone = '';
    public string $fromEmail = '';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['shippoApiToken', 'adminEmail'],
            ],
        ];
    }

    public function getShippoApiToken(): string
    {
        return App::parseEnv($this->shippoApiToken) ?: '';
    }

    public function getAdminEmail(): string
    {
        return App::parseEnv($this->adminEmail) ?: (Craft::$app->getProjectConfig()->get('email.fromEmail') ?? '');
    }

    /** @return array<string, mixed>|null Shippo address payload, or null if not configured. */
    public function getFromAddress(): ?array
    {
        if (!$this->fromStreet1 || !$this->fromCity || !$this->fromZip) {
            return null;
        }

        return [
            'name' => $this->fromName,
            'street1' => $this->fromStreet1,
            'street2' => $this->fromStreet2,
            'city' => $this->fromCity,
            'state' => $this->fromState,
            'zip' => $this->fromZip,
            'country' => $this->fromCountry ?: 'US',
            'phone' => $this->fromPhone,
            'email' => $this->fromEmail,
        ];
    }

    public function rules(): array
    {
        return [
            [['lookback'], 'integer', 'min' => 1, 'max' => 100],
            [['defaultWeightOz'], 'number', 'min' => 0.1],
            [['fromCountry'], 'string', 'length' => 2],
            [['autoImportToShippo'], 'boolean'],
        ];
    }
}
