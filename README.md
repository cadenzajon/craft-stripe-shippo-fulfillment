# Stripe → Shippo Fulfillment

A Craft CMS 5 control-panel dashboard that reads your recent **Stripe** orders live and lets you import any of them into **Shippo** with one click to buy a shipping label. It never buys a label for you — that stays a human decision in the Shippo app.

It builds on the free [`craftcms/stripe`](https://plugins.craftcms.com/stripe) plugin (for the catalog sync and the Stripe API client) and does **not** require Craft Commerce.

## What it does

- **Live orders list** — the *Fulfillment → Orders* screen queries Stripe on load; nothing about a Stripe session is cached until you import it.
- **One-click import to Shippo** — creates a Shippo order (address, line items, weight, sender) and deep-links you to the buy-label screen.
- **Status at a glance** — New · Scheduled · Label pending · Shipped · Refunded, derived live from Stripe plus a small local crosswalk table.
- **Admin email** on every paid order, describing the contents and linking to the dashboard (and straight to Shippo when auto-import is on).
- **Customer “your order has shipped” email**, sent when the Shippo label is bought — tracking details come from the webhook and are never stored.

## Requirements

Craft CMS 5.6+, PHP 8.2+, the `craftcms/stripe` plugin, and a Shippo account.

## Install

```bash
composer require cadenzajon/craft-stripe-shippo-fulfillment
php craft plugin/install stripe-shippo-fulfillment
```

## Configure

In **Settings → Plugins → Stripe → Shippo Fulfillment**:

- **Shippo API token** — your Live (or Test) token; supports env vars like `$SHIPPO_API_TOKEN`.
- **Sender address** — used to prefill each Shippo order so rates resolve on the buy-label screen.
- **Auto-import to Shippo** — off by default (import by hand). On means the Shippo order is created as soon as an order is paid; a label is still never bought automatically.
- **Admin email**, **default weight**, and how many orders to show.

You can also drop a `config/stripe-shippo-fulfillment.php` file to set any of these per-environment.

## Webhooks

**Stripe** needs no new endpoint — the `craftcms/stripe` plugin already receives webhooks, and this plugin listens to its `checkout.session.completed` event for the admin email. Just make sure that plugin's webhook is registered.

**Shippo** posts to this plugin so shipped orders update themselves. Add a Shippo webhook for **`transaction_created`** pointing at:

```
https://your-site.tld/actions/stripe-shippo-fulfillment/webhooks/shippo?token=YOUR_TOKEN
```

Set the same `YOUR_TOKEN` as the **Shippo webhook token** in settings (blank disables the check).

## Data stored

One table, `stripeshippofulfillment_shipments`, written only after an import:

| Column | Purpose |
| --- | --- |
| `stripeCheckoutSessionId` | key back to Stripe (unique) |
| `stripePaymentIntentId` | target of the order-number deep link |
| `orderNumber` | short human reference |
| `shippoOrderId` | builds the Shippo deep link; label is read from the order on demand |
| `shippedAt` | set by the Shippo webhook |
| `importedBy` | Craft user who imported |

No carrier, tracking number, address, or amounts are cached — the dashboard reads those live from Stripe.

## A note on the shipped correlation

The Shippo `transaction_created` webhook delivers a transaction. This plugin matches it back to an order by the transaction’s `order` field, falling back to a `stripe_session=…` marker written into the Shippo order’s metadata at import. Confirm the linkage on your first live label; if your Shippo account surfaces it differently, the fallback marker still resolves it.

## License

MIT.
