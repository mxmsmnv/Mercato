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
- headless/read API support for custom interfaces.

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

## Inventory

Products can use stock policies such as:

- deny checkout when stock is unavailable
- allow backorder
- allow preorder

Mercato checks purchasability before adding to cart and before payment. Stock,
reservations, refunds, and fulfilment changes are tracked through order and
inventory records.

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

## Template Customization

For ordinary storefront design work:

- Override templates in `/site/templates/mercato/`.
- Keep checkout validation, CSRF tokens, payment completion, order snapshots, and stock checks intact.
- Keep payment return handling in `mrc-success.php`.
- Keep cart and checkout forms connected to Mercato's public module methods.
- Avoid hard-coding one merchant's credentials, phone number, address, or legal copy into module templates.

For changes to the installable demo defaults, edit the module `templates/`,
`assets/demo-products/`, and `install/install.php`.

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

## License

MIT
