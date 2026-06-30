# Mercato Agent Instructions

Mercato is a ProcessWire ecommerce module with an installable demo storefront. AI agents should use this file as behavioral guidance for working with the module. This file is not proof of the current state of a live site; inspect the active ProcessWire installation before making site-specific claims.

## What Mercato Provides

- Ecommerce pages, fields, templates, carts, checkout, payments, orders, fulfilment, discounts, inventory checks, refunds, customer records, recovery flows, and public storefront templates.
- An installable demo shop for a ceramics/homeware storefront using `templates/`, `assets/demo-products/`, and `install/install.php`.
- Storefront templates that can run with the configured frontend framework, with Tailwind as the default module configuration.

## Important Files

- `Mercato.module.php`: main module configuration, public API, payment flow, checkout/order helpers, frontend framework helpers.
- `ProcessMercato.module.php`: admin process UI.
- `install/install.php`: creates required fields, templates, pages, demo content, discounts, and copies storefront template files into `/site/templates/`.
- `templates/mrc-storefront.php`: shared storefront styling, header, footer, filters, cart icon, and helper functions.
- `templates/mrc-home.php`: demo storefront home page.
- `templates/mrc-products.php` and `templates/mrc-product.php`: product listing and product detail pages.
- `templates/mrc-collections.php` and `templates/mrc-collection.php`: collection index and collection detail pages.
- `templates/mrc-page.php`: content pages such as about, contact, policies, shipping, and care guide.
- `templates/mrc-checkout.php`: checkout, cart review, customer data, fulfilment selection, discounts, and payment UI.
- `templates/mrc-success.php`: payment return and order confirmation page.
- `assets/demo-products/`: demo product images installed with the demo shop.

## Public Calls Agents May Use

Use these module methods instead of inventing APIs:

- `$modules->get('Mercato')`: load the module.
- `$commerce->cart(?array $data = null)`: read or replace the session cart.
- `$commerce->productList(array $data = [])`: create a product list/cart-like object from stored items.
- `$commerce->formatPrice(float $price, ?bool $symbolBefore = null)`: format prices using module currency settings.
- `$commerce->completePayment(array $data = [])`: complete a gateway return and create/update the order.
- `$commerce->clearPendingCheckoutSession(bool $releaseReservation = true)`: clear pending checkout state.
- `$commerce->orderRepository()`: access order persistence helpers.
- `$commerce->fulfilmentService()`: access fulfilment methods and checkout delivery logic.
- `$commerce->getProductPurchasability(Page $product, int $requestedQuantity = 1, float $cartQuantity = 0.0, int $excludeOrderId = 0)`: check stock, preorder, backorder, and availability rules.
- `$commerce->getOrderAnalyticsEvent(Page $order, string $event = 'purchase')`: build storefront analytics payloads.
- `$commerce->getFrontendFramework()`: read the selected frontend framework.
- `$commerce->renderFrontendFrameworkAssets()`: output configured frontend framework assets.
- `$commerce->getFrontendUiClasses()`: get CSS class names for the active frontend mode.
- `$commerce->getStorefrontTemplateOverridePath(string $template)`: resolve site-level storefront template overrides.
- `$commerce->setMessage($message)` and `$commerce->getMessage()`: set/read storefront feedback messages.

Shared storefront helper functions are defined in `templates/mrc-storefront.php` and are safe to use from Mercato storefront templates after requiring that file:

- `mrc_storefront_assets(bool $isVanilla)`
- `mrc_storefront_header(Mercato $commerce, $pages, $config, $sanitizer, string $active = '')`
- `mrc_storefront_footer(Mercato $commerce, $pages, $config, $sanitizer)`
- `mrc_storefront_page_url($pages, $config, string $path)`
- `mrc_storefront_cart_icon()`
- `mrc_storefront_collection_feature_product($pages, Page $collection)`
- `mrc_storefront_filter_state($input, bool $includeCollection = true)`
- `mrc_storefront_product_selector(array $state, ?Page $collection = null)`
- `mrc_storefront_filter_form(array $state, $collections, $sanitizer, string $action, bool $includeCollection = true)`

## Building Or Editing A Storefront

- Prefer site-level overrides in `/site/templates/mercato/{framework}/{template}.php` or `/site/templates/mercato/{template}.php` when customizing a real site. Edit module `templates/` only when changing the installable demo defaults.
- Keep all storefront templates compatible with the override mechanism at the top of each template.
- Keep shared layout, colors, typography, header, footer, filters, and common visual rules in `mrc-storefront.php` unless a style is truly page-specific.
- Preserve `mrc_storefront_header()` and `mrc_storefront_footer()` alignment across pages.
- Use the existing demo brand direction unless the user explicitly changes it: Arlberg Ceramics, restrained luxury homeware, Cormorant Garamond display type, Inter UI type, muted ceramic palette, md-radius elements, no hover-heavy product cards.
- Keep the first screen useful. Do not create a marketing-only landing page when the user asks for a shop, category, product, checkout, success, or content page.
- Storefront pages should demonstrate real ecommerce behavior: collections, filters, cart, stock states, discounts, shipping/pickup/local delivery, digital goods, policy links, checkout validation, and order confirmation.
- Product cards should show real product imagery when available, clear price/stock information, and working add-to-cart controls where appropriate.
- Checkout and success pages must preserve payment, fulfilment, tax, discount, order snapshot, and analytics behavior.

## Installable Demo Rules

- `install/install.php` is responsible for creating installable demo pages and content. If a new demo page, collection, product, discount, image, or template is required, update the installer so a fresh user can reproduce the same demo.
- Template files are copied from module `templates/` to `/site/templates/` during install/update. Existing site templates should not be overwritten unless the overwrite option is explicitly used.
- Demo product images should live in `assets/demo-products/` and be assigned through installer logic.
- Keep demo data credible. Avoid fake-looking placeholder stores, fake addresses, fake phone numbers, and empty lorem ipsum.

## Safe Operations

- Read module settings and explain what they do.
- Inspect active ProcessWire fields, templates, pages, and installed modules before making site-specific recommendations.
- Add or improve storefront templates while preserving existing payment/order logic.
- Add installable demo content when the user asks for demo behavior.
- Use ProcessWire selectors and APIs rather than ad hoc SQL for page/content operations.

## Requires Approval Or Extra Care

- Enabling production mode or changing live gateway credentials.
- Changing payment method availability, webhook verification, order totals, tax behavior, inventory reservation, refund behavior, or email recipients.
- Overwriting existing `/site/templates/` files on a user's site.
- Deleting orders, customers, products, refunds, webhook logs, payment attempts, or audit data.
- Changing public API behavior used by external templates or hooks.

## Avoid

- Do not treat this file, README text, or demo assumptions as current live site truth. Verify the actual site state.
- Do not invent Mercato API methods. Use documented/public methods or inspect code first.
- Do not bypass checkout validation, purchasability checks, CSRF handling, payment completion, stock reservation, or policy acceptance.
- Do not hard-code one site's URLs, credentials, phone numbers, addresses, or legal text into module templates.
- Do not remove the vanilla fallback paths unless the module explicitly drops vanilla support.

## Olivia Compatibility Notes

- Treat AGENTS.md as agent behavior guidance.
- Treat README.md as high-level module purpose and installation guidance.
- Treat live ProcessWire site state as stronger than module documentation for questions about what currently exists.
- Surface conflicts between documentation and live state instead of hiding them.
- Olivia Ready is not a permission bypass. Human approval is still required for risky operations.
