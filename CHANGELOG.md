# Changelog

All notable changes to Mercato are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
ProcessWire module version integer: `100` = `1.0.0`, `110` = `1.1.0`, etc.

---

## [Unreleased]

## [1.4.0] - 2026-08-27

### Added

- McpServer provider discovery with 11 bounded commerce tools for PII-minimized order, fulfilment, inventory, and operational reads plus validated payment verification, shipment, label, tracking, fulfilment, and transactional-email operations.
- Hierarchical scope policy, exact confirmations for mutations and carrier costs, durable idempotency results, provider-local success/failure audit, structured human-review exceptions, and MCP/n8n orchestration documentation.

### Changed

- Shipment item normalization now rejects products outside the order snapshot and preserves exact variant IDs and labels.

## [1.3.2] - 2026-08-26

### Changed

- Ichiban now automatically becomes the authoritative SEO owner when installed. Mercato suppresses its metadata renderer, native sitemap route, configuration controls, and diagnostics in that state, while retaining its complete built-in SEO fallback when Ichiban is absent.

## [1.3.1] - 2026-08-26

### Added

- A permissioned visual notification designer with TinyMCE editing, safe event variables, isolated live previews, plain-text fallbacks, per-template reset, and shared email header/footer blocks.

### Changed

- Saved visual templates now participate in the transactional delivery pipeline while preserving existing event enablement, transport retries, idempotency, signed links, and file-based fallback overrides.

## [1.3.0] - 2026-08-25

### Added

- Provider-neutral address tax estimates with normalized checkout inputs, product tax codes, immutable jurisdiction/exemption snapshots, configurable retry/timeout/failure policies, and headless quote support.
- Idempotent tax commit, partial/full refund adjustment and void lifecycles, plus stored tax details in order views, receipts, analytics, reports, and CSV exports.
- Provider-neutral live parcel rates, final quote revalidation, service mapping, handling adjustments, multi-parcel snapshots, explicit fallback policy, and a development reference adapter.
- Idempotent shipment/label purchase, label reprint and void/refund admin actions, signed duplicate-safe tracking webhooks, non-regressing fulfilment state mapping, and private label URL redaction.
- Guarded production payment activation with mode-correct credential/HTTPS checks, gateway capability and webhook verification status, configurable request retry/timeout policy, and guided test scenarios.
- Remote payment verification snapshots, a five-state reconciliation queue, permissioned idempotent repair actions, expanded sensitive payload redaction, and payment launch/rotation/incident runbooks.
- First-class product options and variants with server-resolved combinations, variant-specific SKU, pricing, stock policy, measurements, images, status, and immutable cart/order snapshots.
- Variant management, CSV/API round-tripping, exact inventory reservation/sale/refund handling, low-stock reporting, duplicate validation, and a three-option demo product.
- Optional request-for-quote checkout using dedicated non-order records, immutable pricing and fulfilment snapshots, a seven-state lifecycle, signed status links, merchant/customer notifications, admin management, customer-owned history, and configurable inventory reservation on acceptance.
- Quote schema installer/repair support, permissions, hooks, and public service methods.
- Privacy retention policies for carts, drafts, webhooks, notification/payment/operational logs, customer data, provider references, and signed links, with scheduled bounded cleanup and launch dry runs.
- Permissioned customer JSON export, blocker-aware anonymization/deletion requests, legal-hold workflows, immutable financial preservation, token invalidation, policy-version audit trails, and extension hooks.
- Provider-neutral transactional email delivery with localized text/HTML overrides, per-event controls, previews and test sends, retry/idempotency auditing, lifecycle notifications, and bounce/complaint hooks.
- Storefront SEO metadata with canonical, robots, social, Product/Offer JSON-LD, alternate-language hooks, private-page exclusions, sitemap output, and launch diagnostics.
- Configurable disabled/optional/required account modes, secure hashed expiring identity tokens, login rate limits and session rotation, optimistic profile updates, account deletion integration, hooks, installer migration, and storefront templates.
- Reproducible complete release artifacts with production-only dependencies, normalized metadata and checksums; supported runtime matrix, missing-package diagnostics, runtime manifest checks, and newer-schema rollback protection.
- Operational health categories for application, database, storage, email, providers, webhooks, reservations, cron, backup, configuration, and checkout, plus admin readiness and merchant/host/implementer recovery responsibilities.
- Per-order analytics delivery state and locking, delayed consent activation, dataLayer/first-party adapters, recursive PII filtering, admin diagnostics, and external adapter hooks.
- Versioned end-to-end coverage and release thresholds for checkout, coupon, inventory, payment replay/retry, refunds, email, signed links, permissions, noindex, analytics, desktop/mobile browsers, and opt-in-only live-provider smoke.
- Native API pagination/filtering, CORS/HTTPS/body/rate controls, field errors, provider payment instructions, extension hooks, and replay/expiry/price/stock/privacy tests.

## [1.2.0] - 2026-07-30

### Added

- Optional `FieldtypeDimensions` product integration with actual-weight, dimensional-weight, and greater-of-both carrier rate modes.
- Country and region weight bands, configurable handling of missing measurements, quantity-aware totals, and immutable calculation details in fulfilment snapshots and order exports.
- Installer support for creating or attaching the configured dimensions field while preserving flat product shipping as the default and fallback.

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
