<?php
namespace ProcessWire;
/**
 * mrc-products.php
 *
 * Public product catalog page for Mercato storefronts.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-products')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';
$cart = $commerce->cart();
$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$collections = $pages->find('template=mrc-collection, parent=/collections/, sort=sort, sort=title');
$filterState = mrc_storefront_filter_state($input, true);
$products = $pages->find(mrc_storefront_product_selector($filterState));
$checkoutPage = $pages->get('/' . ltrim((string) ($commerce->cancel_page ?: 'checkout'), '/') . '/');
$checkoutUrl = ($checkoutPage && $checkoutPage->id) ? $checkoutPage->url : $config->urls->root . 'checkout/';
$cartItems = $cart->values();
$cartLineCount = $cart->count();
$cartQuantity = 0.0;
$cartProductQuantities = [];
foreach ($cartItems as $cartItem) {
    $itemQuantity = (float) ($cartItem['quantity'] ?? 0);
    $cartQuantity += $itemQuantity;
    $productId = (int) ($cartItem['product_id'] ?? 0);
    if ($productId > 0) {
        $cartProductQuantities[$productId] = ($cartProductQuantities[$productId] ?? 0.0) + $itemQuantity;
    }
}
$csrf = $session->CSRF ?? null;
$csrfInput = ($csrf && method_exists($csrf, 'renderInput')) ? (string) $csrf->renderInput() : '';
$hasValidCsrf = static function () use ($csrf): bool {
    return !$csrf || !method_exists($csrf, 'hasValidToken') || (bool) $csrf->hasValidToken();
};

$catalogAction = (string) $input->post('mrc_action');
if ($catalogAction === 'catalog_clear_cart') {
    try {
        if (!$hasValidCsrf()) {
            throw new WireException('Form session expired. Please reload the page and try again.');
        }

        $commerce->clearPendingCheckoutSession();
        $cart->delete();
        $commerce->analyticsService()->track('cart_change', ['action' => 'clear', 'currency' => (string) $commerce->currency, 'value' => 0]);
        $commerce->setMessage('Cart cleared.');
    } catch (WireException $e) {
        $commerce->setMessage('Error: ' . $e->getMessage());
    }
    $session->redirect($page->url);
}

if ($catalogAction === 'catalog_add_to_cart') {
    try {
        if (!$hasValidCsrf()) {
            throw new WireException('Form session expired. Please reload the page and try again.');
        }

        $product = $pages->get((int) $input->post->int('product_id'));
        if (!$product || !$product->id || $product->template->name !== 'mrc-product') {
            throw new WireException('Product is no longer available.');
        }

        $quantity = max(1, (int) $input->post->int('quantity'));
        $currentCartQuantity = (float) ($cartProductQuantities[(int) $product->id] ?? 0);
        $purchaseCheck = $commerce->getProductPurchasability($product, $quantity, $currentCartQuantity);
        if (empty($purchaseCheck['ok'])) {
            throw new WireException((string) ($purchaseCheck['first_error'] ?: 'Product is not available.'));
        }

        $commerce->clearPendingCheckoutSession();
        $cart->add([
            'id' => $product->path,
            'quantity' => $quantity,
        ]);
        $commerce->analyticsService()->track('cart_change', ['action' => 'add', 'product_id' => (int) $product->id, 'quantity' => $quantity, 'price' => round((float) ($purchaseCheck['resolved_price'] ?? $product->mrc_price), 2), 'currency' => (string) $commerce->currency]);
        $commerce->setMessage('Added to cart.');
    } catch (WireException $e) {
        $commerce->setMessage('Error: ' . $e->getMessage());
    }
    $session->redirect($page->url);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $searchTerm = trim((string) $input->get->text('q'));
    $commerce->analyticsService()->track($searchTerm !== '' ? 'search' : 'collection_view', ['query_length' => strlen($searchTerm), 'result_count' => $products->count(), 'collection_id' => 0]);
}

$message = $commerce->getMessage();
$isHomePage = (string) $page->path === '/';
$documentTitle = $isHomePage ? 'Arlberg Ceramics' : ($page->title ?: 'Products');
$catalogTitle = $isHomePage ? 'Shop' : ($page->title ?: 'Products');
$productCardOverride = $commerce->getStorefrontTemplateOverridePath('product-card');
$catalogShellClass = $isVanilla
    ? $ui['shell'] . ' mrc-catalog-shell'
    : 'mx-auto grid w-full max-w-7xl gap-10 px-4 pb-16 md:px-8';
$catalogPanelClass = $isVanilla
    ? $ui['panel'] . ' mrc-catalog-panel'
    : 'mrc-section-reveal';
$catalogHeaderClass = $isVanilla
    ? 'mrc-catalog-header'
    : 'mb-8 flex flex-wrap items-start justify-between gap-6 border-b border-[#d8cdbc] pb-8';
$miniCartClass = $isVanilla
    ? 'mrc-mini-cart'
    : 'flex min-w-[min(100%,340px)] flex-wrap items-center justify-end gap-3 border border-[#d8cdbc] bg-[#fffaf2]/90 p-3';
$miniCartCopyClass = $isVanilla
    ? 'mrc-mini-cart-copy'
    : 'grid gap-0.5 text-sm text-[#6e5b4d]';
$miniCartLabelClass = $isVanilla
    ? 'mrc-mini-cart-label'
    : 'text-[11px] font-semibold uppercase tracking-[0.24em] text-[#8a4b3e]';
$miniCartTotalClass = $isVanilla
    ? 'mrc-mini-cart-total'
    : 'text-lg font-semibold text-[#5b241f]';
$catalogGridClass = $isVanilla
    ? 'mrc-catalog-grid'
    : 'grid gap-x-7 gap-y-12 sm:grid-cols-2 lg:grid-cols-4';
$productCardClass = $isVanilla
    ? 'mrc-product-card'
    : 'grid gap-5 rounded-md border border-[#d8cdbc] bg-[#fffaf2] p-4';
$productCardMediaClass = $isVanilla
    ? 'mrc-product-card-media'
    : 'block aspect-[4/5] overflow-hidden rounded-md bg-[#fbf6ed]';
$productCardImageClass = $isVanilla
    ? ''
    : 'block h-full w-full object-cover';
$productCardPlaceholderClass = $isVanilla
    ? 'mrc-product-card-placeholder'
    : 'flex h-full items-center justify-center text-xs font-semibold uppercase tracking-[0.24em] text-[#6b5848]';
$productCardTitleClass = $isVanilla
    ? 'mrc-product-card-title'
    : 'flex flex-wrap items-start justify-between gap-2';
$productTitleClass = $isVanilla
    ? ''
    : 'm-0 text-lg font-medium leading-tight text-[#33251f]';
$reviewSummaryClass = $isVanilla
    ? 'mrc-review-summary'
    : 'text-sm text-[#7a6758]';
$productMetaClass = $isVanilla
    ? 'mrc-product-card-meta'
    : 'text-sm text-[#7a6758]';
$productCardActionsClass = $isVanilla
    ? 'mrc-product-card-actions'
    : 'mt-auto grid gap-3 border-t border-[#e4d9c8] pt-4';
$quantityInputClass = $isVanilla
    ? ''
    : 'min-h-11 w-[72px] rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-2 py-1';
$inCartClass = $isVanilla
    ? 'mrc-product-in-cart'
    : 'inline-flex rounded-md bg-[#efe3d2] px-2 py-1 text-xs font-semibold leading-none text-[#5b241f]';
$featuredProduct = $products->count() ? $products->first() : null;
$featuredImageUrl = '';
if ($featuredProduct && $featuredProduct->hasField('mrc_images') && $featuredProduct->mrc_images && $featuredProduct->mrc_images->count()) {
    $featuredImageUrl = $featuredProduct->mrc_images->first()->url;
}
$heroSlides = $pages->find('template=mrc-product, mrc_images.count>0, sort=sort, sort=title, limit=4');
$seoHead = $commerce->seoService()->render($page, ['type' => 'catalog', 'page_num' => (int) $input->pageNum, 'image' => $featuredImageUrl]);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= $seoHead ?>
    <?= $frameworkAssets ?>
    <?= mrc_storefront_assets($isVanilla) ?>
    <?php if (!$isVanilla): ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600;700&display=swap');
        .mrc-luxury-theme { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        .mrc-display { font-family: "Cormorant Garamond", Georgia, serif; letter-spacing: 0; }
        @media (prefers-reduced-motion: reduce) {
            .mrc-section-reveal { animation: none; }
        }
    </style>
    <?php endif; ?>
    <style>
        .mrc-catalog-shell {
            grid-template-columns: 1fr !important;
            margin-inline: auto;
            width: min(1180px, 100%);
        }
        .mrc-catalog-grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .mrc-product-card {
            background: var(--pw-blocks-background, #fff);
            border: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            border-radius: 6px;
            display: grid;
            gap: 18px;
            padding: 18px;
        }
        .mrc-product-card-media {
            aspect-ratio: 3 / 2;
            background: var(--pw-inputs-background, #f8f8f8);
            border-radius: 6px;
            display: block;
            overflow: hidden;
        }
        .mrc-product-card-media img {
            display: block;
            height: 100%;
            object-fit: cover;
            width: 100%;
        }
        .mrc-product-card-placeholder {
            align-items: center;
            color: var(--pw-muted-color, rgba(0,0,0,0.55));
            display: flex;
            font-size: 12px;
            height: 100%;
            justify-content: center;
            text-transform: uppercase;
        }
        .mrc-product-card h2 { font-size: 20px; margin: 0; }
        .mrc-product-card p { margin: 0; }
        .mrc-product-card-title { align-items: start; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; }
        .mrc-review-summary {
            color: var(--pw-muted-color, rgba(0,0,0,0.55));
            font-size: 13px;
        }
        .mrc-review-summary a { color: inherit; }
        .mrc-product-in-cart {
            background: rgba(235, 29, 97, 0.1);
            background: color-mix(in srgb, var(--pw-main-color, #eb1d61) 14%, transparent);
            border: 1px solid rgba(235, 29, 97, 0.24);
            border: 1px solid color-mix(in srgb, var(--pw-main-color, #eb1d61) 30%, transparent);
            color: var(--pw-text-color, #111);
            display: inline-flex;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            border-radius: 6px;
            padding: 6px 8px;
        }
        .mrc-product-card-meta { color: var(--pw-muted-color, rgba(0,0,0,0.55)); }
        .mrc-product-card-actions { display: grid; gap: 12px; margin-top: auto; }
        .mrc-product-card-actions form { margin: 0; }
        .mrc-product-card-actions label { margin: 0; }
        .mrc-product-card-actions input[type="number"] {
            background: var(--pw-inputs-background, #f8f8f8);
            border: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            border-radius: 6px;
            color: var(--pw-text-color, #111);
            min-height: 40px;
            padding: 4px 8px;
            width: 72px;
        }
        .mrc-product-card-actions button[disabled] { cursor: not-allowed; opacity: 0.55; }
        .mrc-catalog-header {
            align-items: start;
            display: flex;
            gap: 18px;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .mrc-catalog-header h1 { margin-bottom: 0; }
        .mrc-mini-cart {
            align-items: center;
            background: var(--pw-inputs-background, #f8f8f8);
            border: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            border-radius: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: flex-end;
            min-width: min(100%, 320px);
            padding: 12px;
        }
        .mrc-mini-cart-copy { display: grid; gap: 2px; }
        .mrc-mini-cart-label {
            color: var(--pw-muted-color, rgba(0,0,0,0.55));
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .mrc-mini-cart-total { font-size: 18px; font-weight: 700; }
        .mrc-mini-cart-empty { color: var(--pw-muted-color, rgba(0,0,0,0.55)); }
        .mrc-mini-cart form { margin: 0; }
        @media (max-width: 720px) {
            .mrc-catalog-header { display: grid; }
            .mrc-mini-cart { justify-content: flex-start; }
        }
    </style>
    <?php if ($isVanilla): ?>
    <style>
        .mrc-page {
            --pw-spacing: 25px;
            --pw-main-color: #eb1d61;
            --pw-text-color: #111;
            --pw-muted-color: rgba(0,0,0,0.55);
            --pw-border-color: rgba(0,0,0,0.16);
            --pw-main-background: #eee;
            --pw-inputs-background: #f8f8f8;
            --pw-blocks-background: #fff;
            color: var(--pw-text-color);
            background: var(--pw-main-background);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
            margin: 0;
            min-height: 100vh;
            padding: var(--pw-spacing);
        }
        .mrc-shell { display: grid; gap: var(--pw-spacing); max-width: 1180px; }
        .mrc-wrap {
            background: var(--pw-blocks-background);
            border: 1px solid var(--pw-border-color);
            padding: var(--pw-spacing);
        }
        .mrc-kicker {
            color: var(--pw-muted-color);
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .mrc-price {
            color: var(--pw-main-color);
            font-size: 22px;
            font-weight: 700;
            margin-top: 6px;
        }
        .mrc-button {
            background: var(--pw-text-color);
            border: 1px solid transparent;
            border-radius: 6px;
            color: var(--pw-blocks-background);
            cursor: pointer;
            display: inline-flex;
            font-weight: 700;
            min-height: 40px;
            padding: 0 18px;
            text-decoration: none;
            align-items: center;
            justify-content: center;
        }
        .mrc-button-secondary {
            background: transparent;
            border-color: var(--pw-text-color);
            color: var(--pw-text-color);
        }
        .mrc-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $ui['body'] ?>">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'products') ?>
<section class="mrc-section-reveal mx-auto grid w-full max-w-7xl gap-8 px-4 pb-10 pt-3 md:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-end">
    <div class="pb-4">
        <span class="<?= $ui['kicker'] ?>">Hand-finished tableware</span>
        <h1 class="mrc-display max-w-3xl text-6xl font-semibold leading-[0.92] text-[#33251f] md:text-8xl">Ceramics with alpine calm.</h1>
        <p class="mt-6 max-w-xl text-lg leading-8 text-[#6e5b4d]">A complete Mercato demo storefront for tactile homeware: tableware, gifts, low-stock pieces, preorder products, discounts, shipping, pickup, local delivery, and digital gift cards.</p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a class="<?= $ui['button'] ?>" href="#shop">Shop collection</a>
            <?php if ($collections->count()): ?>
                <a class="<?= $ui['buttonSecondary'] ?>" href="#collections">Explore categories</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="mrc-hero-slider" data-mrc-slider>
        <?php $slideIndex = 0; ?>
        <?php foreach ($heroSlides as $slideProduct): ?>
            <?php $slideImage = $slideProduct->mrc_images->first(); ?>
            <article class="mrc-hero-slide <?= $slideIndex === 0 ? 'is-active' : '' ?>" data-mrc-slide>
                <img src="<?= $sanitizer->entities($slideImage->url) ?>" alt="<?= $sanitizer->entities($slideProduct->title) ?>">
                <div class="mrc-hero-slide-caption">
                    <p class="text-xs font-semibold uppercase tracking-[0.26em]">New season</p>
                    <p class="mrc-display mt-2 text-5xl font-semibold"><?= $sanitizer->entities($slideProduct->title) ?></p>
                    <p class="mt-2 max-w-md text-sm leading-6"><?= $commerce->formatPrice((float) $slideProduct->mrc_price) ?> · <?= $sanitizer->entities((string) ($slideProduct->mrc_shipping_note ?: 'Ready for checkout test')) ?></p>
                </div>
            </article>
            <?php $slideIndex++; ?>
        <?php endforeach; ?>
        <?php if ($heroSlides->count() > 1): ?>
            <div class="mrc-slider-dots" aria-label="Hero slides">
                <?php $slideIndex = 0; ?>
                <?php foreach ($heroSlides as $slideProduct): ?>
                    <button class="mrc-slider-dot <?= $slideIndex === 0 ? 'is-active' : '' ?>" type="button" data-mrc-dot aria-label="Show <?= $sanitizer->entities($slideProduct->title) ?>"></button>
                    <?php $slideIndex++; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<main class="<?= $catalogShellClass ?>">
    <?php if (!$isVanilla): ?>
    <section class="mrc-feature-band mrc-section-reveal">
        <article class="mrc-feature-tile">
            <span class="<?= $ui['kicker'] ?>">Discounts</span>
            <h2 class="mrc-display m-0 text-3xl font-semibold">Codes and targets</h2>
            <p class="m-0 text-[#6e5b4d]">Test cart-wide codes, collection-specific offers, usage limits, and minimum order totals.</p>
        </article>
        <article class="mrc-feature-tile">
            <span class="<?= $ui['kicker'] ?>">Inventory</span>
            <h2 class="mrc-display m-0 text-3xl font-semibold">Stock states</h2>
            <p class="m-0 text-[#6e5b4d]">See available pieces, low-stock messages, sold-out states, preorder behavior, and reservation flow.</p>
        </article>
        <article class="mrc-feature-tile">
            <span class="<?= $ui['kicker'] ?>">Fulfilment</span>
            <h2 class="mrc-display m-0 text-3xl font-semibold">Delivery options</h2>
            <p class="m-0 text-[#6e5b4d]">Demo physical products with shipping fees, free shipping, pickup, and local delivery notes.</p>
        </article>
        <article class="mrc-feature-tile">
            <span class="<?= $ui['kicker'] ?>">Digital</span>
            <h2 class="mrc-display m-0 text-3xl font-semibold">Downloads</h2>
            <p class="m-0 text-[#6e5b4d]">Gift cards and care guides cover digital files, download limits, expiry, and order emails.</p>
        </article>
    </section>
    <?php endif; ?>
    <?php if (!$isVanilla && $collections->count()): ?>
    <section id="collections" class="mrc-section-reveal grid gap-4 md:grid-cols-4">
        <?php foreach ($collections as $collection): ?>
            <?php
            $collectionImageUrl = '';
            $collectionProduct = mrc_storefront_collection_feature_product($pages, $collection);
            if ($collectionProduct && $collectionProduct->id && $collectionProduct->hasField('mrc_images') && $collectionProduct->mrc_images && $collectionProduct->mrc_images->count()) {
                $collectionImageUrl = $collectionProduct->mrc_images->first()->url;
            }
            ?>
            <a class="relative min-h-[220px] overflow-hidden rounded-md bg-[#d8cdbc] text-[#fffaf2] no-underline" href="<?= $sanitizer->entities($collection->url) ?>">
                <?php if ($collectionImageUrl !== ''): ?>
                    <img class="absolute inset-0 h-full w-full object-cover" src="<?= $sanitizer->entities($collectionImageUrl) ?>" alt="<?= $sanitizer->entities($collection->title) ?>">
                <?php endif; ?>
                <span class="absolute inset-0 bg-[#33251f]/35"></span>
                <span class="absolute bottom-0 left-0 right-0 p-5">
                    <span class="block text-xs font-semibold uppercase tracking-[0.24em]">Collection</span>
                    <span class="mrc-display mt-2 block text-3xl font-semibold"><?= $sanitizer->entities($collection->title) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <section class="<?= $catalogPanelClass ?>">
        <?php if ($message): ?>
            <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($message) ?></div>
        <?php endif; ?>
        <div class="<?= $catalogHeaderClass ?>">
            <div>
                <span class="<?= $ui['kicker'] ?>">Storefront</span>
                <h1 id="shop" class="<?= $isVanilla ? '' : 'mrc-display text-5xl font-semibold leading-none md:text-6xl' ?>"><?= $sanitizer->entities($catalogTitle) ?></h1>
            </div>
            <aside class="<?= $miniCartClass ?>" aria-label="Cart summary">
                <?php if ($cartLineCount > 0): ?>
                    <div class="<?= $miniCartCopyClass ?>">
                        <span class="<?= $miniCartLabelClass ?>">Cart</span>
                        <span><?= (int) $cartQuantity ?> item<?= (int) $cartQuantity === 1 ? '' : 's' ?> / <?= (int) $cartLineCount ?> line<?= $cartLineCount === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="<?= $miniCartTotalClass ?>"><?= $commerce->formatPrice($cart->getSum()) ?></div>
                    <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Checkout</a>
                    <form method="post" action="<?= $sanitizer->entities($page->url) ?>">
                        <input type="hidden" name="mrc_action" value="catalog_clear_cart">
                        <?= $csrfInput ?>
                        <button class="<?= $ui['buttonSecondary'] ?>" type="submit">Clear cart</button>
                    </form>
                <?php else: ?>
                    <div class="<?= $miniCartCopyClass ?> <?= $isVanilla ? 'mrc-mini-cart-empty' : '' ?>">
                        <span class="<?= $miniCartLabelClass ?>">Cart</span>
                        <span>Empty</span>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
        <?= mrc_storefront_filter_form($filterState, $collections, $sanitizer, $page->url, true) ?>
        <?php if ($products->count() === 0): ?>
            <p>No products are available yet.</p>
        <?php else: ?>
            <div class="<?= $catalogGridClass ?>">
                <?php foreach ($products as $product): ?>
                    <?php
                    $imageUrl = '';
                    if ($product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                        $imageUrl = $product->mrc_images->first()->url;
                    }
                    $inCartQuantity = (float) ($cartProductQuantities[(int) $product->id] ?? 0);
                    $cardVariants = $commerce->variantService()->getDefinition($product)['variants'];
                    $cardVariant = current(array_filter($cardVariants, static fn(array $variant): bool => $variant['status'] === 'active')) ?: null;
                    $hasVariants = $cardVariants !== [];
                    $purchasability = $commerce->getProductPurchasability($product, 1, $inCartQuantity, 0, $cardVariant ? (string) $cardVariant['id'] : null);
                    $allowsOversell = (bool) $purchasability['allows_oversell'];
                    $remainingStock = (int) $purchasability['remaining_stock'];
                    $available = (bool) $purchasability['ok'];
                    $stockLabel = (string) $purchasability['stock_label'];
                    $unavailableLabel = (string) $purchasability['unavailable_label'];
                    $reviewSummary = $commerce->getProductReviewSummary($product);
                    ?>
                    <?php if ($productCardOverride !== ''): ?>
                        <?php include $productCardOverride; ?>
                    <?php else: ?>
                    <article class="<?= $productCardClass ?>">
                        <a class="<?= $productCardMediaClass ?>" href="<?= $sanitizer->entities($product->url) ?>" aria-label="View <?= $sanitizer->entities($product->title) ?>">
                            <?php if ($imageUrl !== ''): ?>
                                <img class="<?= $productCardImageClass ?>" src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($product->title) ?>">
                            <?php else: ?>
                                <span class="<?= $productCardPlaceholderClass ?>">Product</span>
                            <?php endif; ?>
                        </a>
                        <div>
                            <div class="<?= $productCardTitleClass ?>">
                                <h2 class="<?= $productTitleClass ?>"><?= $sanitizer->entities($product->title) ?></h2>
                                <?php if ($inCartQuantity > 0): ?>
                                    <span class="<?= $inCartClass ?>"><?= (int) $inCartQuantity ?> in cart</span>
                                <?php endif; ?>
                            </div>
                            <p class="<?= $ui['price'] ?>"><?= $commerce->formatPrice((float) $product->mrc_price) ?></p>
                            <?php if ($reviewSummary): ?>
                                <p class="<?= $reviewSummaryClass ?>">
                                    <?php if ((string) ($reviewSummary['url'] ?? '') !== ''): ?>
                                        <a href="<?= $sanitizer->entities((string) $reviewSummary['url']) ?>"><?= $sanitizer->entities((string) $reviewSummary['label']) ?></a>
                                    <?php else: ?>
                                        <?= $sanitizer->entities((string) $reviewSummary['label']) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($product->hasField('mrc_shipping_price')): ?>
                                <p class="<?= $productMetaClass ?>">
                                    Shipping: <?= ((float) $product->mrc_shipping_price > 0) ? $commerce->formatPrice((float) $product->mrc_shipping_price) : 'Free' ?>
                                </p>
                            <?php endif; ?>
                            <p class="<?= $productMetaClass ?>"><?= $sanitizer->entities($stockLabel) ?></p>
                        </div>
                        <div class="<?= $productCardActionsClass ?>">
                            <?php if ($hasVariants): ?>
                                <a class="<?= $ui['button'] ?> mrc-cart-button" href="<?= $sanitizer->entities($product->url) ?>"><span>Choose options</span></a>
                            <?php elseif ($available): ?>
                                <form class="mrc-card-purchase-form" method="post" action="<?= $sanitizer->entities($page->url) ?>">
                                    <input type="hidden" name="mrc_action" value="catalog_add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= (int) $product->id ?>">
                                    <?= $csrfInput ?>
                                    <label>
                                        <span class="<?= $miniCartLabelClass ?>">Qty</span>
                                        <input class="<?= $quantityInputClass ?>" type="number" name="quantity" value="1" min="1" <?= !$allowsOversell ? 'max="' . (int) $remainingStock . '"' : '' ?>>
                                    </label>
                                    <button class="<?= $ui['button'] ?> mrc-cart-button" type="submit"><?= mrc_storefront_cart_icon() ?><span>Add to cart</span></button>
                                </form>
                            <?php else: ?>
                                <button class="<?= $ui['button'] ?> mrc-cart-button" type="button" disabled><?= mrc_storefront_cart_icon() ?><span><?= $sanitizer->entities($unavailableLabel) ?></span></button>
                            <?php endif; ?>
                            <a class="<?= $ui['buttonSecondary'] ?> mrc-card-secondary-link" href="<?= $sanitizer->entities($product->url) ?>">View product</a>
                        </div>
                    </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
</body>
</html>
