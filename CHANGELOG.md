# Release Notes for Stripe → Shippo Fulfillment

## 0.1.0 - 2026-07-23

### Added
- Fulfillment → Orders CP dashboard that reads recent orders live from Stripe.
- One-click import to Shippo (creates the order and deep-links to the buy-label screen; never buys a label).
- Live status: New, Scheduled, Label pending, Shipped, Refunded.
- Admin new-order email via the `craftcms/stripe` `checkout.session.completed` event.
- Shippo `transaction_created` webhook that marks orders shipped and emails the customer their tracking.
- `stripeshippofulfillment_shipments` crosswalk table (Stripe session ↔ Shippo order ↔ shipped_at).
- Plugin settings: Shippo token, webhook token, sender address, auto-import, admin email, default weight.
