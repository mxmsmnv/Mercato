# Changelog

All notable changes to Mercato are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
ProcessWire module version integer: `100` = `1.0.0`, `110` = `1.1.0`, etc.

---

## [1.1.0] - 2026-07-08

### Added

- Admin interface localization: ready-made translations for all 23 other
  official EU languages (Bulgarian, Croatian, Czech, Danish, Dutch,
  Estonian, Finnish, French, German, Greek, Hungarian, Irish, Italian,
  Latvian, Lithuanian, Maltese, Polish, Portuguese, Romanian, Slovak,
  Slovenian, Spanish, Swedish), covering the dashboard, products, orders,
  fulfilment, customers, discounts, webhooks, inventory, launch checklist,
  and module configuration screens (`languages/*.csv`), installable via
  ProcessWire's core Language Support module.

## [1.0.0] - 2026-06-30

Initial public release.

### Added

**Core commerce**
- ProcessWire-native commerce toolkit with products, carts, checkout, orders, payments, discounts, fulfilment, inventory, customer records, and public order flows.
- Products are regular ProcessWire pages with price, tax rate, SKU, stock, stock policy, product type, digital files, shipping notes, images, and collection relationships.
- Orders are stored as ProcessWire pages under a configurable hidden parent.
- Cart and product list APIs for storefront templates and custom integrations.
- Public order status, receipt, payment retry, and digital download flows.

**Storefront**
- Installable demo storefront with home, products, product detail, collections, collection detail, checkout, success, editorial pages, policy pages, and care guide.
- Arlberg Ceramics demo content with real product images, collections, physical products, digital products, gift cards, low-stock products, sold-out states, preorder and backorder examples.
- Bundled storefront templates for Tailwind, with framework support for `vanilla`, `tailwind`, `bootstrap`, and `uikit`.
- Site-level template override support via `/site/templates/mercato/`.
- Demo templates are copied only when missing unless template overwrite is explicitly enabled.

**Payments**
- Payment gateway support for Stripe, Mollie, PayPal, bank transfer, and Demo Payment.
- Production mode disables Demo Payment and expects live gateway configuration.
- Gateway setup status and launch-readiness checks.
- Webhook endpoints for Stripe, Mollie, and PayPal.

**Discounts**
- Coupon and discount rules for percentage discounts, fixed discounts, free shipping, product targeting, collection targeting, customer/email targeting, usage limits, per-customer limits, minimum order totals, and active date windows.
- Discount totals and details stored on orders for stable receipts and admin views.

**Fulfilment and inventory**
- Fulfilment support for carrier delivery, store pickup, and local delivery.
- Checkout snapshots for delivery address, pickup location, local delivery details, delivery region, delivery window, notes, company, Tax/VAT number, and purchase order number.
- Inventory policies for deny checkout, preorder, and backorder.
- Stock reservations, low-stock detection, refund inventory handling, and fulfilment state tracking.

**Admin**
- ProcessWire admin dashboard under **Setup -> Mercato**.
- Admin sections for dashboard, products, orders, manual orders, fulfilment, customers, recovery, search, reports, discounts, inventory, launch checklist, webhooks, payment attempts, refunds, and exports.
- CSV exports for orders, products, customers, recovery, discounts, webhooks, payment attempts, refunds, inventory, fulfilment, notifications, launch checks, and support records.
- Launch checklist for storefront, payments, operations, customer communication, tax/shipping readiness, and demo checkout flow.

**Customer communication and recovery**
- Order confirmation, payment link, shipping, pickup-ready, and local-delivery notification support.
- Abandoned checkout recovery tooling with cooldowns, suppression list, unsubscribe links, payment-link emails, and recovery audit trail.

**Developer and integration support**
- Headless/read API for store info, products, collections, cart quote, checkout metadata, and order lookup surfaces.
- Extensible gateway interface for custom payment providers.
- Hooks for cart, checkout, payment, discount, fulfilment, tax, shipping, returns, analytics, product reviews, related products, and background jobs.
- Public methods for order lookups, receipt URLs, download URLs, customer summaries, purchasability checks, refunds, returns, partial fulfilment, shipments, store credit, and background jobs.

### Security

- Gateway webhook signature verification for supported providers.
- CSRF protection for storefront and admin commerce actions.
- Public order status, receipt, payment retry, and download URLs use signed tokens.
- Hidden order storage; public customers use generated URLs rather than admin URLs.
- Production mode separates live/test behavior and disables Demo Payment.

### Notes

- Mercato is designed to add commerce functionality to an existing ProcessWire site without taking over the whole website.
- Existing `/site/templates/` files are not overwritten unless the merchant explicitly enables template overwrite.
- Back up the database, `/site/templates/`, `/site/assets/`, and deployment-specific custom files before installing on an existing or production site.
