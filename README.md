# Stripe → Shippo Fulfillment for Craft CMS

This Craft CMS 5 plugin gives administrators a fulfillment dashboard for paid Stripe Checkout orders. It reads orders directly from Stripe, creates Shippo orders manually or immediately after payment, and links to Shippo so an administrator can choose a rate and buy the label.

The plugin never buys labels. It is not a cart, inventory system, carrier tracker, or order database, and it does not require Craft Commerce. “Shipped” means Shippo reports a successful label transaction; it does not mean carrier acceptance, transit, or delivery.

## Behavior

- The **Fulfillment** control-panel section is visible and accessible only to Craft administrators.
- The dashboard reads the configured number of recent completed Checkout Sessions live from Stripe and omits unpaid sessions.
- Every Checkout line item is paginated and shown. The same complete set is used for email, weight calculation, and Shippo import.
- Import accepts only complete, paid (or no-payment-required), payment-mode sessions. Fully refunded sessions are blocked; partially refunded sessions remain importable with a warning.
- Manual import creates one Shippo order and links to Shippo's buy-label screen. Optional auto-import runs after a paid Stripe webhook. Neither workflow buys a label.
- A unique local claim suppresses duplicate Shippo imports. Ambiguous Shippo failures remain in `processing`; this plugin intentionally provides no reconciliation workflow for them.
- Admin email is best-effort deduplicated per Checkout Session. Failed sends can retry immediately, and abandoned claims become retryable after ten minutes. With auto-import enabled, an import exception is logged and stops that webhook invocation before email is attempted.
- `ship_after` dates are informational. They label an order Scheduled but never prevent or delay manual import, auto-import, or label purchase.
- On dashboard loads, the plugin asks Shippo whether imported orders have a successful label transaction and leaves the display label as **Shipped**. It stores no carrier or tracking data.

## Requirements

- PHP 8.2+
- Craft CMS 5.6+
- [`craftcms/stripe`](https://plugins.craftcms.com/stripe) 1.3+, installed and configured
- Stripe Checkout Sessions in payment mode
- A Shippo account and API token
- A working Craft mailer if admin notifications are required

Use matching modes: Stripe test credentials and Shippo test credentials for testing, then live credentials for real fulfillment.

## Installation

From the Craft project root:

```bash
composer require cadenzajon/craft-stripe-shippo-fulfillment
php craft plugin/install stripe
php craft plugin/install stripe-shippo-fulfillment
```

Composer installs the official Stripe plugin dependency. Skip `plugin/install stripe` if it is already installed. Craft runs this plugin's migrations automatically; existing installs should apply pending migrations during deployment in the usual Craft workflow.

## Configuration

### 1. Configure the official Stripe plugin

Put the keys for one Stripe mode in `.env`:

```dotenv
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
```

Reference them in `config/stripe.php`:

```php
<?php

use craft\helpers\App;

return [
    'secretKey' => App::env('STRIPE_SECRET_KEY'),
    'publishableKey' => App::env('STRIPE_PUBLISHABLE_KEY'),
];
```

You can configure the same values in the official plugin's control-panel settings instead. Environment variables keep credentials out of project config.

### 2. Configure Stripe webhooks

This plugin has no separate Stripe endpoint. It listens to verified events re-fired by `craftcms/stripe`, whose public endpoint is normally:

```text
https://example.com/stripe/webhooks/handle
```

The Stripe endpoint must subscribe to:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded` when delayed payment methods are enabled

An initially unpaid completion is ignored. The later asynchronous-success event triggers fulfillment and email after payment succeeds. Test and live endpoints have separate IDs and signing secrets.

If the separate companion plugin [`cadenzajon/craft-stripecart`](https://github.com/cadenzajon/craft-stripecart) is installed, its `stripe-cart` console command creates or updates the official Stripe plugin's saved endpoint with the required events:

```bash
php craft stripe-cart/webhooks/subscribe https://example.com/stripe/webhooks/handle
```

### 3. Configure Shippo and fulfillment

Store secrets and addresses in `.env` where appropriate:

```dotenv
SHIPPO_API_TOKEN=shippo_test_...
FULFILLMENT_ADMIN_EMAIL=orders@example.com
```

Open **Settings → Plugins → Stripe → Shippo Fulfillment** and configure:

- **Shippo API token:** a literal token or `$SHIPPO_API_TOKEN`.
- **Admin notification email:** a literal address or `$FULFILLMENT_ADMIN_EMAIL`. Blank falls back to Craft's system sender address; if both are blank, no email is sent.
- **Auto-import to Shippo:** off by default. When enabled, a qualifying paid webhook creates the Shippo order before composing the email.
- **Orders to show:** 1–100 recent completed sessions requested from Stripe; default 25.
- **Default weight (oz):** positive fallback unit weight; default 12.
- **Sender address:** used as Shippo's `from_address`. Street 1, city, and ZIP must be present before the plugin includes it.

The same settings can be supplied in `config/stripe-shippo-fulfillment.php`:

```php
<?php

return [
    'shippoApiToken' => '$SHIPPO_API_TOKEN',
    'adminEmail' => '$FULFILLMENT_ADMIN_EMAIL',
    'autoImportToShippo' => false,
    'lookback' => 25,
    'defaultWeightOz' => 12.0,
    'weightMetadataKey' => 'weight_oz',
    'shipAfterMetadataKey' => 'ship_after',
    'fromName' => 'Example Store',
    'fromStreet1' => '123 Main Street',
    'fromStreet2' => '',
    'fromCity' => 'Portland',
    'fromState' => 'OR',
    'fromZip' => '97205',
    'fromCountry' => 'US',
    'fromPhone' => '+1 503 555 0100',
    'fromEmail' => 'shipping@example.com',
];
```

`lookback` must be 1–100, `defaultWeightOz` at least 0.1, and `fromCountry` a two-letter code. The token and admin-email fields understand Craft environment-variable references.

### 4. Configure Stripe Product metadata

The plugin reads metadata from the Stripe Product attached to each line item's Price:

- `weight_oz` (configurable with `weightMetadataKey`) is unit weight in ounces. It must be finite and greater than zero. Missing or invalid values use `defaultWeightOz`; invalid values are logged. Set the key to `''` to always use the default.
- `ship_after` (configurable with `shipAfterMetadataKey`) must be an exact, valid `YYYY-MM-DD` date. Invalid values are ignored and logged. For an order with multiple dates, the latest is displayed. Set the key to `''` to disable scheduling.

Scheduling is informational only and never blocks an action.

### 5. Configure email

Admin notifications use Craft's configured mailer. Verify it can send before relying on the webhook workflow. Each message lists every order line and links to the dashboard; when auto-import has completed, it also links to the Shippo order.

Notification claims suppress ordinary duplicate/retried webhook messages. Exact-once delivery is impossible without mail-provider idempotency: an email send that runs longer than the ten-minute claim lease and later resumes could duplicate.

## Verification checklist

1. Confirm the official Stripe plugin connects using the intended test key.
2. Confirm its public webhook endpoint is registered with both required Checkout events.
3. Save a Shippo test token and a complete sender address.
4. Verify Craft can send mail if notifications are enabled.
5. Complete a test payment-mode Checkout that collects a shipping or billing address.
6. Deliver its Stripe webhook, then open **Fulfillment** as a Craft administrator.
7. Confirm all lines, the total/currency, address summary, refund warning, and informational schedule are correct.
8. With auto-import off, click **Import to Shippo** and confirm one Shippo test order is created.

## Import behavior

The importer re-fetches the Checkout Session and all of its line items before acquiring the local processing claim. It then:

1. validates payment mode, completion, payment status, refund status, and address;
2. resolves every product's unit weight and sums `weight × quantity` across every page;
3. converts Stripe minor-unit amounts using the session currency's zero-, two-, or three-decimal exponent;
4. creates a Shippo order with addresses, lines, totals, order reference, weight, and Stripe-session metadata;
5. stores the returned Shippo order ID and links the administrator to Shippo.

Shipping address is preferred; customer/billing address is the fallback. Expected validation failures are shown to the administrator. Unexpected upstream or programming errors produce a generic control-panel message and a full exception in Craft's logs.

Known Shippo client errors mark the claim failed so a later import can retry. Ambiguous failures that might have created a remote order remain `processing` to prevent an automatic duplicate. There is deliberately no built-in reconciliation or retry process for those rows.

## Status meanings

| Status | Meaning |
| --- | --- |
| **New** | No completed local Shippo import exists. |
| **Scheduled** | Not imported, and the latest valid `ship_after` date is in the future. This is informational. |
| **Label pending** | A Shippo order exists, but no successful label transaction has been observed. |
| **Shipped** | Shippo reports a successful label transaction. No carrier/tracking state is checked. |
| **Refunded** | Stripe reports the latest charge as fully refunded. Import is blocked. |

A partial refund appears as a secondary warning and remains importable. A full refund takes precedence over Shipped, Label pending, and Scheduled in the status column, although any stored shipment timestamps remain intact. Imported status takes precedence over Scheduled; the date can still be displayed.

## Data stored

The dashboard reads customer, address, line, amount, currency, and refund data live from Stripe. It stores no carrier or tracking details.

`stripeshippofulfillment_shipments` stores the unique Stripe Checkout Session ID, PaymentIntent ID, order reference, Shippo order ID, import status, shipped timestamp, importing Craft user, and timestamps.

`stripeshippofulfillment_notifications` stores the unique Stripe Checkout Session ID, notification claim status, and timestamps.

## Operational limitations

- Dashboard loads and webhook work call Stripe, Shippo, and the mailer synchronously; no Craft queue job or automatic backoff is used.
- Webhook exceptions are logged by this plugin. The current listener does not deliberately fail the upstream Stripe delivery to request a retry.
- With auto-import enabled, an import exception also prevents the admin email from being attempted during that webhook invocation.
- Processing Shippo claims have no built-in reconciliation workflow.
- Shippo label purchase and customer tracking notifications remain Shippo responsibilities.

## License

[MIT](LICENSE)
