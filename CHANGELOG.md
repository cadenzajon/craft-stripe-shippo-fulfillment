# Release Notes for Stripe → Shippo Fulfillment

## Unreleased

### Fixed
- Stripe Checkout line items are fully paginated for dashboard display, Shippo import, weight calculation, and admin emails.
- Expected order-validation failures remain visible to administrators. Unexpected manual import failures return administrators to the dashboard with a generic message while the full exception is logged.
- Fully refunded Checkout Sessions are blocked from Shippo import; partial refunds remain importable with a dashboard warning.
- Dashboard, email, and Shippo payload amounts now respect Stripe zero- and three-decimal currencies and display the correct currency symbol.
- Invalid product weights fall back safely, and scheduled shipping dates now require an unambiguous `YYYY-MM-DD` value. Invalid metadata is logged.
- Paid-order admin emails use an atomic, leased claim per Checkout Session, suppressing ordinary concurrent/retried duplicates while allowing failed or abandoned sends to retry. Mail transport cannot guarantee exact-once delivery if a send outlives the ten-minute lease.

### Breaking change
- The Fulfillment control-panel section and actions are now restricted to Craft administrators. Previously authorized non-admin users no longer have access.

## 0.1.3 - 2026-08-18

### Added
- Added `weightMetadataKey` and `shipAfterMetadataKey` settings; empty values disable their respective Stripe Product metadata features.

## 0.1.2 - 2026-08-13

### Added
- Added delayed-payment success handling and shipment processing/failed states with the corresponding schema migration.

### Fixed
- Re-verified that a Checkout Session is complete, paid/no-payment-required, and in payment mode before fulfillment.
- Added an atomic per-session processing claim so concurrent manual/webhook imports cannot create duplicate Shippo orders.

## 0.1.1 - 2026-08-12

### Added
- Added the package icon used by Craft's control-panel navigation.

## 0.1.0 - 2026-07-23

### Added
- Fulfillment → Orders CP dashboard that reads recent orders live from Stripe.
- One-click import to Shippo (creates the order and deep-links to the buy-label screen; never buys a label).
- Live status: New, Scheduled, Label pending, Shipped, Refunded.
- Admin new-order email via the `craftcms/stripe` `checkout.session.completed` event.
- Shipped status detected by reading the Shippo order — no webhook; Shippo sends the customer their tracking email.
- `stripeshippofulfillment_shipments` crosswalk table (Stripe session ↔ Shippo order ↔ shipped_at).
- Plugin settings: Shippo token, sender address, auto-import, admin email, default weight.
