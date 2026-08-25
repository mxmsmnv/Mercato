<?php
namespace ProcessWire;
/**
 * mrc-collection.php
 *
 * Public collection page for Mercato storefronts.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-collection')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';

$cart = $commerce->cart();
$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$productsPage = $pages->get('/products/');
$productsUrl = ($productsPage && $productsPage->id) ? $productsPage->url : $config->urls->root . 'products/';
$checkoutPage = $pages->get('/' . ltrim((string) ($commerce->cancel_page ?: 'checkout'), '/') . '/');
$checkoutUrl = ($checkoutPage && $checkoutPage->id) ? $checkoutPage->url : $config->urls->root . 'checkout/';
$filterState = mrc_storefront_filter_state($input, false);
$products = $pages->find(mrc_storefront_product_selector($filterState, $page));
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') $commerce->analyticsService()->track('collection_view', ['collection_id' => (int) $page->id, 'name' => (string) $page->title, 'result_count' => $products->count()]);

$message = $commerce->getMessage();
$featuredProduct = mrc_storefront_collection_feature_product($pages, $page);
if (!$featuredProduct || !$featuredProduct->id) {
    $featuredProduct = $products->count() ? $products->first() : null;
}
$featuredImageUrl = '';
if ($featuredProduct && $featuredProduct->hasField('mrc_images') && $featuredProduct->mrc_images && $featuredProduct->mrc_images->count()) {
    $featuredImageUrl = $featuredProduct->mrc_images->first()->url;
}
$seoHead = $commerce->seoService()->render($page, ['type' => 'collection', 'page_num' => (int) $input->pageNum, 'image' => $featuredImageUrl]);

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
</head>
<body class="<?= $ui['body'] ?>">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'collections') ?>
<section class="mrc-section-reveal mx-auto grid w-full max-w-7xl gap-8 px-4 pb-10 pt-3 md:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-end">
    <div class="pb-4">
        <span class="<?= $ui['kicker'] ?>">Collection</span>
        <h1 class="mrc-display max-w-3xl text-6xl font-semibold leading-[0.92] text-[#33251f] md:text-8xl"><?= $sanitizer->entities($page->title ?: 'Collection') ?></h1>
        <?php if ($page->hasField('mrc_description') && $page->mrc_description): ?>
            <div class="mt-6 max-w-xl text-lg leading-8 text-[#6e5b4d]"><?= $page->mrc_description ?></div>
        <?php else: ?>
            <p class="mt-6 max-w-xl text-lg leading-8 text-[#6e5b4d]">Curated pieces from the Mercato ceramics demo catalog.</p>
        <?php endif; ?>
    </div>
    <div class="relative min-h-[360px] overflow-hidden rounded-md bg-[#d8cdbc]">
        <?php if ($featuredImageUrl !== ''): ?>
            <img class="h-full min-h-[360px] w-full object-cover" src="<?= $sanitizer->entities($featuredImageUrl) ?>" alt="<?= $sanitizer->entities($page->title) ?>">
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<main class="<?= $isVanilla ? $ui['shell'] . ' mrc-catalog-shell' : 'mx-auto grid w-full max-w-7xl gap-10 px-4 pb-16 md:px-8' ?>">
    <section class="<?= $isVanilla ? $ui['panel'] : 'mrc-section-reveal' ?>">
        <?php if ($message): ?>
            <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($message) ?></div>
        <?php endif; ?>
        <div class="<?= $isVanilla ? 'mrc-catalog-header' : 'mb-8 flex flex-wrap items-start justify-between gap-6 border-b border-[#d8cdbc] pb-8' ?>">
            <div>
                <span class="<?= $ui['kicker'] ?>">Products</span>
                <h2 class="<?= $isVanilla ? '' : 'mrc-display text-5xl font-semibold leading-none md:text-6xl' ?>"><?= $products->count() ?> piece<?= $products->count() === 1 ? '' : 's' ?></h2>
            </div>
            <aside class="<?= $isVanilla ? 'mrc-mini-cart' : 'flex min-w-[min(100%,340px)] flex-wrap items-center justify-end gap-3 border border-[#d8cdbc] bg-[#fffaf2]/90 p-3' ?>" aria-label="Cart summary">
                <?php if ($cartLineCount > 0): ?>
                    <div class="<?= $isVanilla ? 'mrc-mini-cart-copy' : 'grid gap-0.5 text-sm text-[#6e5b4d]' ?>">
                        <span class="<?= $isVanilla ? 'mrc-mini-cart-label' : 'text-[11px] font-semibold uppercase tracking-[0.24em] text-[#8a4b3e]' ?>">Cart</span>
                        <span><?= (int) $cartQuantity ?> item<?= (int) $cartQuantity === 1 ? '' : 's' ?></span>
                    </div>
                    <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Checkout</a>
                    <form method="post" action="<?= $sanitizer->entities($page->url) ?>">
                        <input type="hidden" name="mrc_action" value="catalog_clear_cart">
                        <?= $csrfInput ?>
                        <button class="<?= $ui['buttonSecondary'] ?>" type="submit">Clear cart</button>
                    </form>
                <?php else: ?>
                    <div class="<?= $isVanilla ? 'mrc-mini-cart-copy mrc-mini-cart-empty' : 'grid gap-0.5 text-sm text-[#6e5b4d]' ?>">
                        <span class="<?= $isVanilla ? 'mrc-mini-cart-label' : 'text-[11px] font-semibold uppercase tracking-[0.24em] text-[#8a4b3e]' ?>">Cart</span>
                        <span>Empty</span>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
        <?= mrc_storefront_filter_form($filterState, new PageArray(), $sanitizer, $page->url, false) ?>
        <?php if (!$products->count()): ?>
            <p>No products are available in this collection yet.</p>
        <?php else: ?>
            <div class="<?= $isVanilla ? 'mrc-catalog-grid' : 'grid gap-x-7 gap-y-12 sm:grid-cols-2 lg:grid-cols-4' ?>">
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
                    ?>
                    <article class="<?= $isVanilla ? 'mrc-product-card' : 'grid gap-5 rounded-md border border-[#d8cdbc] bg-[#fffaf2] p-4' ?>">
                        <a class="<?= $isVanilla ? 'mrc-product-card-media' : 'block aspect-[4/5] overflow-hidden rounded-md bg-[#fbf6ed]' ?>" href="<?= $sanitizer->entities($product->url) ?>" aria-label="View <?= $sanitizer->entities($product->title) ?>">
                            <?php if ($imageUrl !== ''): ?>
                                <img class="<?= $isVanilla ? '' : 'block h-full w-full object-cover' ?>" src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($product->title) ?>">
                            <?php else: ?>
                                <span class="<?= $isVanilla ? 'mrc-product-card-placeholder' : 'flex h-full items-center justify-center text-xs font-semibold uppercase tracking-[0.24em] text-[#6b5848]' ?>">Product</span>
                            <?php endif; ?>
                        </a>
                        <div>
                            <h3 class="<?= $isVanilla ? '' : 'm-0 text-lg font-medium leading-tight text-[#33251f]' ?>"><?= $sanitizer->entities($product->title) ?></h3>
                            <p class="<?= $ui['price'] ?>"><?= $commerce->formatPrice((float) $product->mrc_price) ?></p>
                            <p class="<?= $isVanilla ? 'mrc-product-card-meta' : 'text-sm text-[#7a6758]' ?>"><?= $sanitizer->entities($stockLabel) ?></p>
                        </div>
                        <div class="<?= $isVanilla ? 'mrc-product-card-actions' : 'mt-auto grid gap-3 border-t border-[#e4d9c8] pt-4' ?>">
                            <?php if ($hasVariants): ?>
                                <a class="<?= $ui['button'] ?> mrc-cart-button" href="<?= $sanitizer->entities($product->url) ?>"><span>Choose options</span></a>
                            <?php elseif ($available): ?>
                                <form class="mrc-card-purchase-form" method="post" action="<?= $sanitizer->entities($page->url) ?>">
                                    <input type="hidden" name="mrc_action" value="catalog_add_to_cart">
                                    <input type="hidden" name="product_id" value="<?= (int) $product->id ?>">
                                    <?= $csrfInput ?>
                                    <label>
                                        <span class="<?= $isVanilla ? 'mrc-mini-cart-label' : 'text-[11px] font-semibold uppercase tracking-[0.24em] text-[#8a4b3e]' ?>">Qty</span>
                                        <input class="<?= $isVanilla ? '' : 'min-h-11 w-[72px] rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-2 py-1' ?>" type="number" name="quantity" value="1" min="1" <?= !$allowsOversell ? 'max="' . (int) $remainingStock . '"' : '' ?>>
                                    </label>
                                    <button class="<?= $ui['button'] ?> mrc-cart-button" type="submit"><?= mrc_storefront_cart_icon() ?><span>Add to cart</span></button>
                                </form>
                            <?php else: ?>
                                <button class="<?= $ui['button'] ?> mrc-cart-button" type="button" disabled><?= mrc_storefront_cart_icon() ?><span><?= $sanitizer->entities($unavailableLabel) ?></span></button>
                            <?php endif; ?>
                            <a class="<?= $ui['buttonSecondary'] ?> mrc-card-secondary-link" href="<?= $sanitizer->entities($product->url) ?>">View product</a>
                        </div>
                    </article>
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
