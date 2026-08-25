# Mercato

Mercato is a ProcessWire-native commerce toolkit. It turns a ProcessWire site
into a flexible commerce platform without forcing the project into a rigid shop
system.

Mercato can run as a complete storefront, but it is not limited to one storefront
shape. It provides the commerce layer for stores, catalogs, paid content, service
workflows, custom checkout flows, and headless/API-driven experiences.

It adds products, cart, checkout, orders, payments, discounts, fulfilment,
inventory handling, customer records, public order flows, operational admin
tools, and an installable demo storefront.

The bundled demo is a real storefront, not a placeholder catalog. A fresh install
creates an Arlberg Ceramics homeware shop with products, collections, product
images, discounts, checkout, policy pages, and order confirmation pages.

![Mercato](assets/Mercato.png)

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development:
[GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or
[smnv.org/sponsor](https://smnv.org/sponsor/).

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+

## Positioning

Mercato is not just another shop module. It is a commerce foundation for
ProcessWire projects that need to stay flexible.

Traditional shop systems usually expect the website to become the shop. Mercato
takes the opposite approach: the shop becomes part of the ProcessWire site. It
uses ProcessWire pages, templates, fields, permissions, and admin workflows
instead of replacing them with a separate ecommerce world.

Use Mercato when you want:

- a full demo storefront out of the box;
- a reusable commerce layer for custom ProcessWire websites;
- physical products, digital products, services, placeholders, preorders, and backorders;
- checkout, payments, refunds, fulfilment, recovery, audit logs, and reports in one module;
- storefront templates that can be replaced or extended at site level;
- headless/read API support for custom interfaces, including native iOS guest checkout through `/api/mercato/v1`.

In short: Mercato is a commerce layer for ProcessWire, with a complete demo shop
as proof that the layer can ship a real storefront.

## What It Includes

- Product pages with price, tax rate, stock, shipping notes, digital files, and collections.
- Cart and checkout templates with quantity updates, discounts, fulfilment options, policy acceptance, and payment selection.
- Orders stored as ProcessWire pages under a hidden orders parent.
- Admin dashboard under **Setup → Mercato** for orders, products, customers, discounts, payments, refunds, fulfilment, reports, webhooks, and launch readiness.
- Payment gateways for Stripe, Mollie, PayPal, Demo Payment, and offline bank transfer.
- Fulfilment support for carrier delivery, store pickup, and local delivery.
- Coupon and discount rules with product, collection, customer, usage, and minimum-total targeting.
- Inventory rules for in-stock, low-stock, sold-out, preorder, and backorder behavior.
- Public order status, receipt, and download flows.
- Installable demo storefront templates and demo product images.

## Installation

Copy the module into your ProcessWire modules directory:

```bash
cp -r Mercato /site/modules/
```

Then open the ProcessWire admin:

1. Go to **Modules → Refresh**.
2. Install **Mercato**.
3. Open **Modules → Configure → Mercato**.
4. Run **Run Mercato installer** if the installer did not run automatically.
5. Open **Setup → Mercato** to configure and test the shop.

Mercato creates required fields, templates, pages, admin permissions, demo data,
and storefront template files.

## Existing Sites And Backups

Mercato is designed to add commerce functionality to an existing ProcessWire site
without taking over the whole website.

The installer creates Mercato-specific fields, templates, pages, permissions,
demo products, collections, and order storage. It does not remove existing site
content. Bundled storefront template files are copied into `/site/templates/`
only when the target files are missing. Existing template files are not replaced
unless you explicitly enable **Overwrite existing template files in
/site/templates/** in the module settings.

That said, installing any commerce module changes the site schema. Before adding
Mercato to an existing or production website, make a backup of:

- the ProcessWire database;
- `/site/templates/`;
- `/site/assets/`;
- any custom files that are part of your deployment.

If you are testing Mercato on an established site, install it first on a staging
copy. Keep **Production mode** off until checkout, payments, emails, fulfilment,
tax, inventory, and policy pages have been tested end to end.

## Production-readiness acceptance

Mercato includes a deterministic backend and real-browser acceptance suite for checkout, payment lifecycle, stock, refunds, fulfilment, privacy, analytics, accessibility, and responsive storefront behavior. Run it only against an isolated non-production ProcessWire installation:

```bash
MERCATO_E2E_SITE=/absolute/path/to/processwire \
MERCATO_E2E_BASE_URL=https://shop.test \
php scripts/run-acceptance.php
```

The release threshold is zero failed scenarios, zero serious/critical axe violations, successful fixture cleanup, and retained JSON/Markdown and Playwright diagnostics. See [ACCEPTANCE.md](ACCEPTANCE.md) for setup, browser versions, coverage, report paths, and the separately gated live-provider smoke policy.

Native clients use `/api/mercato/v1`; see [HEADLESS_API.md](HEADLESS_API.md) for resources, SDK/redirect flows, opaque credentials, errors, deep links, and compatibility.

## Demo Storefront

On a fresh install, Mercato creates a demo shop with:

- `/` storefront home page
- `/products/` product catalog
- `/products/{product}/` product detail pages
- `/collections/` collection index
- `/collections/{collection}/` collection pages
- `/checkout/` checkout page
- `/checkout/success/` order confirmation page
- `/about-us/`
- `/contact-us/`
- `/privacy-policy/`
- `/terms-of-use/`
- `/shipping-and-returns/`
- `/care-guide/`

The demo includes tableware, serveware, gift cards, limited-stock products,
preorder products, physical fulfilment, digital delivery, product images, and
discount examples.

Demo product images are stored in:

```text
assets/demo-products/
```

Storefront templates are stored in:

```text
templates/
```

During install or repair, the module copies bundled template files into
`/site/templates/`. Existing site template files are not overwritten unless you
enable **Overwrite existing template files in /site/templates/** in the module
settings.

## Storefront Templates

Bundled templates:

```text
templates/home.php
templates/mrc-storefront.php
templates/mrc-home.php
templates/mrc-products.php
templates/mrc-product.php
templates/mrc-collections.php
templates/mrc-collection.php
templates/mrc-page.php
templates/mrc-checkout.php
templates/mrc-success.php
templates/mrc-order.php
templates/mrc-orders.php
```

`mrc-storefront.php` contains the shared storefront design system: header,
footer, common colors, typography, cart icon, filter helpers, and shared layout
helpers.

For a real site, prefer site-level overrides instead of editing module files:

```text
/site/templates/mercato/{framework}/{template}.php
/site/templates/mercato/{template}.php
```

The selected frontend framework is configured in **Modules → Configure →
Mercato**. Supported modes are:

- `vanilla`
- `tailwind`
- `bootstrap`
- `uikit`

Tailwind is the default for the installable demo storefront.

## Quick Start

1. Keep **Production mode** off while configuring.
2. Enable **Demo Payment** or add test gateway keys.
3. Open `/products/`.
4. Add a product to the cart.
5. Complete checkout.
6. Open **Setup → Mercato → Orders** and inspect the created order.
7. Open **Setup → Mercato → Launch** and resolve launch-readiness items before going live.

## Payment Methods

Mercato includes these payment methods:

- **Stripe** for card and supported Stripe payment methods.
- **Mollie** for Mollie-hosted payments.
- **PayPal** for PayPal checkout.
- **Bank transfer** for offline invoice-style orders.
- **Demo Payment** for test workflows while production mode is off.

Production mode disables Demo Payment and expects live gateway configuration.
Always configure provider webhooks before taking live orders.

## Fulfilment

Mercato can present and store fulfilment snapshots for:

- carrier delivery
- store pickup
- local delivery

Checkout can store shipping address, pickup location, local delivery details,
delivery region, delivery window, delivery note, company, Tax/VAT number, and
purchase order number where configured.

Carrier delivery uses product flat rates by default. When
`FieldtypeDimensions` is installed, enable **Weight and dimensions** in the
module settings and run the installer to create or attach `mrc_dimensions`.
Rates can then use total actual weight, dimensional weight, or the greater of
both. Configure bands as `scope|min kg|max kg|rate|label`, where scope is `*`,
an ISO country code such as `GB`, or a region such as `GB:ENG`. Product
quantities are included, and the configured missing-measurement policy either
falls back to flat shipping, ignores the item, or disables carrier delivery.
The chosen band and calculation inputs are frozen in the fulfilment snapshot.

## Discounts

Mercato supports coupon and discount rules for:

- percentage discounts
- fixed discounts
- free shipping
- product targeting
- collection targeting
- customer/email targeting
- usage limits
- per-customer limits
- minimum order totals
- active date windows

Discount totals are stored on orders so receipts and admin views remain stable
after checkout.

## Product Variants

Products remain single-SKU by default. To add variants, open **Setup → Mercato
→ Products → Detail** and use the variant manager. `mrc_variant_options` defines
stable option/value ids and `mrc_variants` lists only valid combinations. A
variant can override SKU, absolute price (or use `price_adjustment`), stock,
low-stock threshold, stock policy, shipping price, weight, dimensions, images,
and lifecycle status.

```json
{
  "options": {"size": "350ml", "glaze": "oatmeal", "material": "stoneware"},
  "sku": "CER-MUG-350-OAT",
  "price": 32,
  "stock": 6,
  "stock_policy": "deny",
  "status": "active"
}
```

Add a selection with
`$commerce->cart()->add(['id' => $product->id, 'variant_options' => $selection])`.
The server resolves the exact combination and ignores submitted commercial
values. Cart keys use `product_id::variant_id`; order lines retain `variant_id`,
stable option ids, human labels, SKU, price, measurements, and image URLs even
after catalog edits. `getProductPurchasability()` accepts an optional fifth
variant id or selection argument.

The read API returns `variant_options` and purchasable `variants`. Product CSV
export/import round-trips `variant_options_json` and `variants_json`; JSON cells
must be CSV-quoted when they contain commas. Stock-pressure exports emit one row
per variant. Existing integrations that omit variant data continue to work for
products whose variant arrays are empty.

## Inventory

Products can use stock policies such as:

- deny checkout when stock is unavailable
- allow backorder
- allow preorder

Mercato checks purchasability before adding to cart and before payment. Stock,
reservations, refunds, and fulfilment changes are tracked through order and
inventory records.

## Quote Requests

Mercato can run an optional request-for-quote checkout alongside normal payment checkout. Enable it in the module settings and run the installer/repair to create dedicated `mrc-quote` records under the hidden `/quotes/` parent.

Quote requests:

- reuse server-authoritative cart, discount, tax, shipping, fulfilment, and customer snapshots;
- never initialize a payment gateway or appear in order revenue/fulfilment reports;
- default to no inventory reservation, with an optional reserve-on-acceptance policy;
- follow the `submitted`, `under-review`, `quoted`, `accepted`, `declined`, `expired`, and `converted` lifecycle;
- provide signed customer status URLs and an account-owned `/my-quotes/` history surface;
- notify the customer and configured merchant recipient when notification sender settings are available.

Public integration methods include `$commerce->submitQuoteRequest()`, `$commerce->updateQuoteStatus()`, and `$commerce->quoteService()`. Projects can extend submission and status changes through the `quoteSubmitted` and `quoteStatusChanged` hooks.

## Address-based tax providers

The default `manual` provider preserves Mercato's existing gross-price behavior and product tax rates. An external ProcessWire module can implement `MercatoTaxProviderInterface` and add its instance through the `taxProviders` hook; set its provider key in Mercato's tax settings. No Mercato core edit is required.

Provider estimates receive normalized cart lines and product tax codes, customer exemption data, the final delivery address, shipping, discount, currency, merchant registrations, nexus regions, a timeout, and a deterministic idempotency key. The returned jurisdiction, rates, taxable/exempt amounts, exemptions, reference, and input snapshot are stored on the order. Paid orders are committed once under an order lock; partial/full refunds and failed payments invoke provider refund and void operations with distinct idempotency keys.

Failure policy is explicit: block checkout (`fail_closed`), record and use manual rates (`manual_fallback`), or record an explicit zero-tax result (`zero_tax`). Configure retries and timeout to match the provider. Order views, receipts, analytics, and CSV exports use the stored calculation rather than recalculating historical tax.

Tax registrations, nexus, tax codes, rates, exemptions, and provider settings are merchant responsibilities. Mercato's configuration and documentation are technical tools and do not replace accounting, tax, or legal advice. Test the provider's sandbox against every jurisdiction and refund flow before production use.

## Live shipping providers

Set the live shipping provider key under fulfilment settings to add address-based parcel services alongside pickup and local delivery. `manual` preserves flat product rates and weight bands. The built-in `reference` adapter is credential-free test/example code only and must not be used as a real carrier.

Separate ProcessWire modules implement `MercatoShippingProviderInterface` and register instances through the `shippingProviders` hook. The adapter receives normalized origin/destination, quantity-aware parcel dimensions and weights, declared value, currency, timeout, and quote TTL. It implements rate quote, shipment creation, label purchase/retrieval, tracking, shipment void, label refund, and signed webhook parsing. Provider modules should keep API credentials in their own protected configuration.

Checkout service keys are re-quoted against the final validated address before payment. The selected provider rate and parcel input become part of the immutable fulfilment snapshot. Configure service mapping, handling adjustments, allowed regions, combined/per-item parcels, retries, timeout, and either explicit manual fallback or fail-closed behavior. Optional manual rates can remain visible alongside successful live rates.

Authorized order staff can purchase/reprint or void/refund a label and see provider references and tracking audit history. Label purchase and webhook processing are replay-safe. The tracking endpoint is `POST /api/mercato/shipping-webhook?provider={key}`; the adapter verifies the raw payload signature before parsing it. Provider label URLs are redacted from logs and CSV exports. A label purchase does not itself prove carrier acceptance, and carrier billing, dangerous-goods rules, customs, insurance, printer formats, pickup manifests, and refund eligibility remain operational responsibilities of the adapter and merchant.

## Going Live

Before enabling production mode:

- Run **Run Mercato installer** after deploying the latest module files.
- Open **Setup → Mercato → Launch** and resolve blocking items.
- Configure live gateway keys and webhook secrets/IDs.
- Configure receipt and policy pages.
- Configure sender email and customer notification templates.
- Configure fulfilment methods, countries, delivery regions, tax settings, and shipping settings.
- Complete at least one full test checkout for each payment method you plan to use.
- Confirm order status, receipt, fulfilment, inventory, and refund behavior in the admin.

For the complete supported-version matrix, deterministic Composer lock strategy, release ZIP assembly, fresh install, upgrade, cache, scheduler, environment configuration, and database-aware rollback procedure, see [DEPLOYMENT.md](DEPLOYMENT.md). Source checkouts require `composer install`; official release artifacts include the locked production `vendor/` graph.

Production operations also expose a PII-free `/api/mercato/health` liveness check and bearer-authenticated detailed diagnostics, backup/verified-restore readiness, and an independent fail-closed checkout switch. The restore drill and merchant/host/implementer responsibilities are defined in `DEPLOYMENT.md`.

Consent-aware commerce analytics is optional and disabled by default. The versioned event schema, exact-once purchase/refund delivery, identifier minimization, consent API, dataLayer behavior, adapter interface, and diagnostics are documented in [ANALYTICS.md](ANALYTICS.md).

### Payment launch and incident runbook

Production activation is a guarded transition. The merchant must explicitly confirm the checklist, Demo Payment must be disabled, the site and webhook URLs must use HTTPS, enabled gateways must have mode-correct live credentials, and live/test credentials cannot match. Gateway readiness cards show credentials, webhook verification mechanism, required events, capabilities, and current verification state without displaying secrets.

Before launch, use the non-production Webhooks verification panel with separate fixture orders for success/finalization, decline, cancellation, retry success, delayed/processing webhook, and duplicate callback replay. Then issue both a partial and full Demo/test-provider refund from paid fixtures and verify inventory and totals. The controlled live smoke test should use the provider's smallest permitted real amount and an ordinary provider-hosted/card-element flow; never enter card data into Mercato admin, logs, notes, or fixtures. Confirm the provider dashboard, webhook row, local paid state, one inventory decrement, one confirmation, receipt, and refund before opening checkout traffic.

Payment Attempts includes a Reconciliation Queue for `paid_unfinalized`, `finalized_unpaid`, `duplicate_attempt`, `missing_webhook`, and `refund_mismatch`. Open an order and click **Verify remote state** before repair. Available repairs are intentionally narrow and idempotent: apply a remotely verified paid state, replay local finalization, or reconcile a provider refund. Every verification and repair is permission-checked, CSRF-protected, confirmed where mutating, and written to `mercato-payment-reconciliation`; unsupported or ambiguous cases stay manual.

For webhook-secret rotation, add/activate the new endpoint secret in Stripe before removing the old one; update Mercato and send a provider test event. Mollie verifies payment state by authenticated server-side re-fetch, so rotate the API key and immediately test a payment-status callback. For PayPal, create the replacement webhook, subscribe the required events, save its new Webhook ID, verify a signed event, then remove the old webhook. Keep the old credential available only for the provider's documented overlap window.

During an incident, disable affected checkout methods or production mode without deleting orders, preserve provider references and logs, and export the reconciliation queue. Compare remote state before repair; do not mark a charge paid from customer screenshots. After recovery, replay signed events where the provider supports it, run remote verification, repair only listed mismatches, reconcile refunds/inventory, and document the reason. Roll back credentials/configuration to the last verified set if new credentials fail, but never roll back order, attempt, webhook, refund, tax, or inventory records.

### Transactional email operations

Mercato renders both plain-text and HTML for order confirmation, payment failure/recovery, refund, cancellation, shipment/tracking, pickup-ready, local-delivery, account-created, and account-security events. Merchants can enable events individually, select a locale, preview every built-in template, and send a test copy from **Modules → Mercato → Email Notifications**. Production readiness treats an invalid sender as a launch blocker when transactional events are enabled.

Safe merchant overrides are data files, not executable PHP. Put them at `/site/templates/mercato/emails/{locale}/{event}.txt` and `.html`, or omit the locale directory for a common fallback. Supported event names are `order_confirmation`, `payment_failed`, `payment_recovery`, `refund`, `cancellation`, `shipment_tracking`, `pickup_ready`, `local_delivery`, `account_created`, and `account_security`. Placeholders use `{name}` syntax. Customer/order values are escaped for HTML, unsafe scripts, event attributes, embedded forms, and `javascript:`/`data:` URLs are removed, while signed public links remain escaped and intact.

ProcessWire WireMail is the default transport. A provider module can implement `MercatoEmailTransportInterface` and replace it through the `Mercato::emailTransport` hook. Delivery attempts are written to `mercato-notifications` with a masked recipient, recipient hash, transport, provider message ID, provider status, retry count, and final result. The idempotency key prevents replayed commerce events from sending duplicates. Failed events remain visible in Customer Emails and can be retried from the corresponding order action without creating a second commerce event.

Provider modules that support delivery callbacks can implement `MercatoEmailWebhookAdapterInterface`, register the adapter through `Mercato::emailWebhookAdapters`, and point signed bounce/complaint callbacks at `/api/mercato/email-webhook?provider={name}`. The adapter must verify the provider signature before returning normalized events; duplicate provider event IDs are ignored.

Before launch, publish SPF for the sending service, enable DKIM signing, and add a DMARC policy with reporting. Keep the visible From domain aligned with the authenticated domain, use a monitored Reply-To address, and configure bounce/complaint handling. If delivery fails, check the transport readiness result, ProcessWire mail configuration, DNS alignment, provider suppression list, masked attempt log, provider message ID, and webhook signature/endpoint. Never paste SMTP/API secrets or full provider payloads into order notes or logs.

### Storefront SEO and structured data

Bundled catalog, collection, product, and content templates call `$commerce->seoService()->render($page)` inside `<head>`. The service emits exactly one escaped title, description, canonical URL, robots directive, Open Graph/Twitter metadata, language alternates, breadcrumbs, and applicable Organization, WebSite/SearchAction, Product, Offer, or AggregateOffer JSON-LD. Product price, currency, SKU, images, availability, condition, and canonical URL come from the saved product/variant and purchasability services used by the storefront.

Editors can set `mrc_seo_title`, `mrc_seo_description`, and `mrc_seo_robots`; blank fields fall back to page/product copy and module defaults. Pagination gets a distinct `/pageN/` canonical while filter/tracking query parameters are removed. Archived/discontinued, unpublished, hidden, deleted, and redirected resources are excluded or noindexed; unavailable active products remain explicit `OutOfStock` offers. Checkout, cart/account surfaces, order status/receipt/download pages, quote/customer pages, and any tokenized URL are always `noindex,nofollow,noarchive` and excluded from `/sitemap-mercato.xml`.

Project code can customize results without replacing templates:

```php
$wire->addHookAfter('Mercato::storefrontSeoMetadata', function($event) {
    $metadata = $event->return;
    $metadata['open_graph']['og:locale'] = 'en_GB';
    $event->return = $metadata;
});

$wire->addHookAfter('Mercato::storefrontSeoAlternates', function($event) {
    $event->return = ['en' => 'https://shop.example/products/', 'de' => 'https://shop.example/de/produkte/'];
});
```

Use `storefrontSitemapEntries` to add project-specific public URLs. The Launch page includes an SEO diagnostic table for missing descriptions/images and invalid canonicals. Site overrides should keep the single `seoService()->render()` call; do not add a second canonical or hand-build product prices in JSON-LD.

### Privacy retention, export, and deletion

Mercato separates operational cleanup from legal and accounting obligations. Configure retention windows for abandoned carts, draft orders, webhook payloads, email delivery logs, failed payment attempts, operational logs, customer data, failed-order provider references, and signed public links. A value of `0` disables automatic cleanup for that category. The daily background job is bounded by the configured batch limit (maximum 500), retry-safe, and available as a non-mutating dry run under **Setup → Mercato → Launch**. Review the exact report before confirming a live batch.

Staff with `mercato-manage-privacy` can export a customer's portable JSON record or review and fulfil a deletion request from the customer detail page. Exports include account data, orders and immutable line-item/financial snapshots, quotes, and recovery preferences; the response uses private/no-store cache headers and is not retained by Mercato. Deletion is implemented as auditable anonymization: contact, address, account, and quote identity data is removed, signed order and quote links are rotated or expired, while invoice numbers, product snapshots, totals, tax, payment/refund state, fulfilment evidence, and provider linkage required for financial integrity remain intact.

Legal holds, open disputes/refunds, inventory reservations, and active paid fulfilment block anonymization and are shown in the review. Hold changes and privacy requests record the reason, policy version, status, operator, request ID, and timestamps. Automated cleanup redacts sensitive payload/content while preserving event status, linkage, and retry evidence. Project modules can adjust category rules with `privacyRetentionRules`, append portable export data with `privacyExport`, and react after anonymization with `privacyAnonymized`.

Configure the signed-link window to expire old order status, receipt, payment, and download URLs. Quote links also obey their stored expiry. Recovery-unsubscribe tokens deliberately remain valid so a deleted or inactive customer is not accidentally re-subscribed. Backups, restored database snapshots, search indexes, analytics systems, mail/payment/shipping providers, and files exported by staff are outside Mercato's live-database erasure boundary; document their deletion windows and restoration procedure in the backup policy note and verify each processor separately. These controls are technical tooling, not a substitute for a merchant-specific legal retention policy.

### Customer accounts and order history

Customer accounts use ProcessWire users, passwords, roles, sessions, CSRF protection, and secure cookie configuration. Run the installer/repair after upgrading to create the `mercato-customer` role, account/profile fields, order ownership fields, `/account/` page, and bundled `mrc-account.php` template. Set accounts to **Disabled**, **Optional**, or **Verified account required at checkout**. Disabled and Optional modes preserve guest checkout; the account page clearly reports disabled mode instead of creating a parallel identity system.

Registration and password-reset requests return enumeration-safe responses. Verification, reset, and guest-order claim tokens are random, stored only as hashes, expire on the configured horizon, and are cleared after use. ProcessWire rotates the session ID during login, while Mercato adds per-session/IP-and-email rate limiting. A verified account can maintain profile details, communication preferences, and an address book with optimistic revision checks, and can see only orders/quotes whose stored customer user ID matches exactly. Account history is paginated and links to the existing receipt, status, tracking, refund, and paid digital-download surfaces.

Eligible guest orders are not matched merely because a customer types an email. The logged-in account must already control that verified email, the unowned order email must match exactly after normalization, and a second one-time confirmation is sent to that same destination. Existing ownership is never overwritten. Account deletion delegates to the privacy workflow: active fulfilment, reservations, refunds/disputes, and legal holds block it; permitted identity data is anonymized while required financial records remain.

Public integrations use `$commerce->customerAccountService()` for registration, verification, authentication, profile updates, ownership checks, order pagination, and claims. Site templates can override `/site/templates/mercato/mrc-account.php`. Hooks are available as `customerAccountRegistrationData`, `customerAccountCreated`, `customerAccountVerified`, `customerAccountProfileUpdated`, and `customerAccountClaimed`. When migrating existing customers, verify their destination first, then use the claim workflow; do not bulk-link records by email or overwrite a non-zero owner ID.

## Template Customization

For ordinary storefront design work:

- Override templates in `/site/templates/mercato/`.
- Keep checkout validation, CSRF tokens, payment completion, order snapshots, and stock checks intact.
- Keep payment return handling in `mrc-success.php`.
- Keep cart and checkout forms connected to Mercato's public module methods.
- Avoid hard-coding one merchant's credentials, phone number, address, or legal copy into module templates.

For changes to the installable demo defaults, edit the module `templates/`,
`assets/demo-products/`, and `install/install.php`.

## Admin interface language

The Mercato admin (dashboard, products, orders, fulfilment, customers,
discounts, webhooks, inventory, launch checklist, and module settings — not
the storefront templates, which are plain PHP and translated by editing the
templates directly) ships with ready-made translations for all 23 other
official EU languages: Bulgarian, Croatian, Czech, Danish, Dutch, Estonian,
Finnish, French, German, Greek, Hungarian, Irish, Italian, Latvian,
Lithuanian, Maltese, Polish, Portuguese, Romanian, Slovak, Slovenian,
Spanish, and Swedish — following ProcessWire's standard module-translation
mechanism (`Modules::getModuleLanguageFiles()` + `languages/*.csv`).

To install one: **Setup > Modules > Mercato (or Mercato Dashboard) >
"install translations"** (link appears once Language Support is installed
and at least one non-default language page exists) → pick the CSV for each
target language → Submit. Admins then see the Mercato interface in their own
PW admin language automatically. Requires ProcessWire's core **Language
Support** module — see
[processwire.com/modules/language-support](https://processwire.com/modules/language-support/).

## AI Agent Notes

`AGENTS.md` contains guidance for AI agents and Olivia-style tooling. It explains
how agents should reason about Mercato, which files matter, which public calls
are safe to use, and what operations require care.

Use `AGENTS.md` as behavioral guidance, not as proof of the current state of a
live ProcessWire site.

## Security Notes

- Restrict access to the ProcessWire database and admin panel.
- Never disable gateway webhook signature verification in production.
- Keep production gateway keys out of code and configure them through module settings.
- Do not expose hidden order pages directly.
- Use the public receipt and order-status URLs generated by Mercato instead of exposing admin URLs.
- Treat refunds, inventory changes, payment method changes, and template overwrites as sensitive operations.

## Troubleshooting

| Symptom | Check |
|---|---|
| Checkout shows a gateway setup error | Open **Setup → Mercato → Launch** and the gateway settings. Missing keys, webhook secrets, or production blockers are listed there. |
| Success page stays pending | Confirm the provider webhook endpoint is configured and reachable. |
| Demo storefront did not update | Run **Run Mercato installer**. Enable template overwrite only if you want bundled templates to replace `/site/templates/` files. |
| Cart or checkout uses old content | Clear stale sessions, then re-add products to the cart. |
| Demo Payment is missing | Confirm production mode is off. Demo Payment is disabled in production mode. |
| Products are missing from the demo | Run the installer repair action from module settings. Existing merchant products are preserved where possible. |
| Transactional email is blocked | Validate sender name/email, enabled events, WireMail/provider setup, SPF/DKIM/DMARC, and the latest masked attempt in **Customer Emails**. |
| SEO metadata is missing or duplicated | Run the Launch SEO diagnostics and ensure the active site template contains exactly one `$commerce->seoService()->render($page)` call. |

## License

MIT
