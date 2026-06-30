<?php
namespace ProcessWire;
/**
 * mrc-product.php
 *
 * Template for product pages. Customize to match your site layout.
 * This is a minimal starting point — add your own HTML structure.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-product')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';
$cart     = $commerce->cart();
$checkoutPage = $pages->get('/' . ltrim((string) ($commerce->cancel_page ?: 'checkout'), '/') . '/');
$checkoutUrl = ($checkoutPage && $checkoutPage->id) ? $checkoutPage->url : $config->urls->root . 'checkout/';
$productsPage = $pages->get('/products/');
$productsUrl = ($productsPage && $productsPage->id) ? $productsPage->url : $config->urls->root . 'products/';
$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$csrf = $session->CSRF ?? null;
$csrfInput = ($csrf && method_exists($csrf, 'renderInput')) ? (string) $csrf->renderInput() : '';
$hasValidCsrf = static function () use ($csrf): bool {
    return !$csrf || !method_exists($csrf, 'hasValidToken') || (bool) $csrf->hasValidToken();
};
$cartItems = $cart->values();
$cartLineCount = $cart->count();
$cartQuantity = 0.0;
$cartProductQuantity = 0.0;
$catalogAction = (string) $input->post('mrc_action');
foreach ($cartItems as $cartItem) {
    $itemQuantity = (float) ($cartItem['quantity'] ?? 0);
    $cartQuantity += $itemQuantity;
    if ((int) ($cartItem['product_id'] ?? 0) === (int) $page->id) {
        $cartProductQuantity += $itemQuantity;
    }
}
$purchasability = $commerce->getProductPurchasability($page, 1, $cartProductQuantity);
$stockPolicy = (string) $purchasability['stock_policy'];
$allowsOversell = (bool) $purchasability['allows_oversell'];
$remainingStock = (int) $purchasability['remaining_stock'];
$canAddToCart = (bool) $purchasability['ok'];
$stockLabel = (string) $purchasability['stock_label'];
$unavailableLabel = (string) $purchasability['unavailable_label'];
$reviewSummary = $commerce->getProductReviewSummary($page);
$relatedProducts = $commerce->getProductRelatedProducts($page, 4);

if ($catalogAction === 'clear_cart') {
    try {
        if (!$hasValidCsrf()) {
            throw new WireException('Form session expired. Please reload the page and try again.');
        }
        $commerce->clearPendingCheckoutSession();
        $cart->delete();
        $commerce->setMessage('Cart cleared.');
    } catch (WireException $e) {
        $commerce->setMessage('Error: ' . $e->getMessage());
    }
    $session->redirect($input->url(true));
}

// Handle add-to-cart POST
if ($catalogAction === 'add_to_cart') {
    $quantity = max(1, (int) $input->post->int('quantity'));
    try {
        if (!$hasValidCsrf()) {
            throw new WireException('Form session expired. Please reload the page and try again.');
        }
        $purchaseCheck = $commerce->getProductPurchasability($page, $quantity, $cartProductQuantity);
        if (empty($purchaseCheck['ok'])) {
            throw new WireException((string) ($purchaseCheck['first_error'] ?: 'This product is not available.'));
        }
        $commerce->clearPendingCheckoutSession();
        $cart->add([
            'id'       => $page->path,
            'quantity' => $quantity,
        ]);
        $commerce->setMessage('Added to cart.');
    } catch (WireException $e) {
        $commerce->setMessage('Error: ' . $e->getMessage());
    }
    $session->redirect($input->url(true));
}

$message = $commerce->getMessage();
$productPanelClass = $isVanilla
    ? $ui['panel']
    : 'mx-auto w-full max-w-7xl px-4 pb-16 md:px-8';
$productHeaderClass = $isVanilla
    ? 'mrc-product-header'
    : 'mb-8 flex flex-wrap items-center justify-between gap-6 border-b border-[#d8cdbc] pb-6';
$productCartClass = $isVanilla
    ? 'mrc-product-cart'
    : 'flex min-w-[min(100%,340px)] flex-wrap items-center justify-end gap-3 border border-[#d8cdbc] bg-[#fffaf2]/90 p-3';
$productCartCopyClass = $isVanilla
    ? 'mrc-product-cart-copy'
    : 'grid gap-0.5 text-sm text-[#6e5b4d]';
$productCartLabelClass = $isVanilla
    ? 'mrc-product-cart-label'
    : 'text-[11px] font-semibold uppercase tracking-[0.24em] text-[#8a4b3e]';
$productCartTotalClass = $isVanilla
    ? 'mrc-product-cart-total'
    : 'text-lg font-semibold text-[#5b241f]';
$reviewSummaryClass = $isVanilla
    ? 'mrc-review-summary'
    : 'mb-5 text-sm text-[#7a6758]';
$inCartClass = $isVanilla
    ? 'mrc-product-in-cart'
    : 'inline-flex rounded-md bg-[#efe3d2] px-2 py-1 text-xs font-semibold leading-none text-[#5b241f]';
$relatedProductsClass = $isVanilla
    ? 'mrc-related-products'
    : 'mrc-section-reveal mt-14 border-t border-[#d8cdbc] pt-8';
$relatedGridClass = $isVanilla
    ? 'mrc-related-grid'
    : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4';
$relatedCardClass = $isVanilla
    ? 'mrc-related-card'
    : 'grid gap-2 rounded-md border border-[#d8cdbc] bg-[#fffaf2] p-4 text-[#33251f] no-underline';
$relatedCardTitleClass = $isVanilla
    ? 'mrc-related-card-title'
    : 'font-semibold';
$productImage = ($page->hasField('mrc_images') && count($page->mrc_images)) ? $page->mrc_images->first() : null;

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $sanitizer->entities($page->title) ?></title>
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
        .mrc-product-header {
            align-items: start;
            display: flex;
            gap: 18px;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .mrc-product-header h1 { margin-bottom: 0; }
        .mrc-product-cart {
            align-items: center;
            background: var(--pw-inputs-background, #f8f8f8);
            border: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            border-radius: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
            min-width: min(100%, 320px);
            padding: 12px;
        }
        .mrc-product-cart form { margin: 0; }
        .mrc-product-cart-copy { display: grid; gap: 2px; }
        .mrc-product-cart-label {
            color: var(--pw-muted-color, rgba(0,0,0,0.55));
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .mrc-product-cart-total { font-size: 18px; font-weight: 700; }
        .mrc-review-summary {
            color: var(--pw-muted-color, rgba(0,0,0,0.55));
            font-size: 14px;
            margin: -8px 0 18px;
        }
        .mrc-review-summary a { color: inherit; }
        .mrc-related-products {
            border-top: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            margin-top: 28px;
            padding-top: 22px;
        }
        .mrc-related-products h2 { font-size: 20px; margin: 0 0 14px; }
        .mrc-related-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }
        .mrc-related-card {
            border: 1px solid var(--pw-border-color, rgba(0,0,0,0.16));
            border-radius: 6px;
            color: var(--pw-text-color, #111);
            display: grid;
            gap: 8px;
            padding: 12px;
            text-decoration: none;
        }
        .mrc-related-card-title { font-weight: 700; }
        @media (max-width: 720px) {
            .mrc-product-header { display: grid; }
            .mrc-product-cart { justify-content: flex-start; }
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
        .mrc-page a { color: var(--pw-main-color); }
        .mrc-wrap {
            background: var(--pw-blocks-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            max-width: 760px;
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
        .mrc-product-media {
            background: var(--pw-inputs-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            margin-bottom: 22px;
            max-width: 720px;
        }
        .mrc-product-image {
            aspect-ratio: 3 / 2;
            display: block;
            object-fit: cover;
            width: 100%;
        }
        .mrc-price { color: var(--pw-main-color); font-size: 28px; font-weight: 700; margin: 0 0 20px; }
        .mrc-shipping { color: var(--pw-muted-color); margin-bottom: 18px; }
        .mrc-description { color: var(--pw-text-color); margin-bottom: 24px; }
        .mrc-message {
            background: var(--pw-inputs-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            margin-bottom: 20px;
            padding: 12px 16px;
        }
        .mrc-form label { color: var(--pw-text-color); display: block; font-weight: 600; margin-bottom: 12px; }
        .mrc-form input {
            background: var(--pw-inputs-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            color: var(--pw-text-color);
            min-height: 38px;
            padding: 6px 10px;
        }
        .mrc-button {
            background: var(--pw-text-color);
            border: 1px solid transparent;
            border-radius: 6px;
            color: var(--pw-blocks-background);
            cursor: pointer;
            font-weight: 700;
            min-height: 40px;
            padding: 0 18px;
        }
        .mrc-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .mrc-button-secondary {
            background: transparent;
            border-color: var(--pw-text-color);
            color: var(--pw-text-color);
            text-decoration: none;
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $ui['body'] ?>">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'products') ?>
<?php endif; ?>
<main class="<?= $productPanelClass ?>">

<?php if ($message): ?>
    <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($message) ?></div>
<?php endif; ?>

<div class="<?= $productHeaderClass ?>">
    <div>
        <span class="<?= $ui['kicker'] ?>">Product</span>
        <h1 class="<?= $isVanilla ? '' : 'mrc-display max-w-3xl text-5xl font-semibold leading-none md:text-7xl' ?>"><?= $sanitizer->entities($page->title) ?></h1>
    </div>
    <aside class="<?= $productCartClass ?>" aria-label="Cart summary">
        <?php if ($cartLineCount > 0): ?>
            <div class="<?= $productCartCopyClass ?>">
                <span class="<?= $productCartLabelClass ?>">Cart</span>
                <span><?= (int) $cartQuantity ?> item<?= (int) $cartQuantity === 1 ? '' : 's' ?> / <?= (int) $cartLineCount ?> line<?= $cartLineCount === 1 ? '' : 's' ?></span>
            </div>
            <div class="<?= $productCartTotalClass ?>"><?= $commerce->formatPrice($cart->getSum()) ?></div>
            <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Checkout</a>
            <form method="post" action="">
                <input type="hidden" name="mrc_action" value="clear_cart">
                <?= $csrfInput ?>
                <button class="<?= $ui['buttonSecondary'] ?>" type="submit">Clear cart</button>
            </form>
        <?php else: ?>
            <div class="<?= $productCartCopyClass ?>">
                <span class="<?= $productCartLabelClass ?>">Cart</span>
                <span>Empty</span>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php if (!$isVanilla): ?>
<section class="mrc-section-reveal grid gap-8 lg:grid-cols-[1.08fr_0.92fr] lg:items-start">
    <div class="overflow-hidden rounded-md bg-[#d8cdbc]">
        <?php if ($productImage): ?>
            <img class="block aspect-[4/5] w-full object-cover" src="<?= $sanitizer->entities($productImage->url) ?>" alt="<?= $sanitizer->entities($page->title) ?>">
        <?php endif; ?>
    </div>
    <aside class="rounded-md border border-[#d8cdbc] bg-[#fffaf2] p-5 shadow-[0_22px_70px_rgba(62,43,31,0.08)] md:p-8 lg:sticky lg:top-6">
        <?php if ($reviewSummary): ?>
            <p class="<?= $reviewSummaryClass ?>">
                <?php if ((string) ($reviewSummary['url'] ?? '') !== ''): ?>
                    <a href="<?= $sanitizer->entities((string) $reviewSummary['url']) ?>"><?= $sanitizer->entities((string) $reviewSummary['label']) ?></a>
                <?php else: ?>
                    <?= $sanitizer->entities((string) $reviewSummary['label']) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p class="<?= $ui['price'] ?>"><?= $commerce->formatPrice($page->mrc_price) ?></p>
        <?php if ($page->hasField('mrc_shipping_price')): ?>
            <p class="<?= $ui['shipping'] ?>">
                Shipping: <?= ((float) $page->mrc_shipping_price > 0) ? $commerce->formatPrice((float) $page->mrc_shipping_price) : 'Free' ?>
                <?php if ($page->hasField('mrc_shipping_note') && $page->mrc_shipping_note): ?>
                    &middot; <?= $sanitizer->entities($page->mrc_shipping_note) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($page->hasField('mrc_stock')): ?>
            <p class="<?= $ui['shipping'] ?>"><?= $sanitizer->entities($stockLabel) ?></p>
        <?php endif; ?>
        <?php if ($cartProductQuantity > 0): ?>
            <p><span class="<?= $inCartClass ?>"><?= (int) $cartProductQuantity ?> in cart</span></p>
        <?php endif; ?>

        <?php if ($page->mrc_description): ?>
            <div class="<?= $ui['description'] ?>"><?= $page->mrc_description ?></div>
        <?php endif; ?>

        <form class="<?= $ui['form'] ?>" method="post" action="">
            <input type="hidden" name="mrc_action" value="add_to_cart">
            <?= $csrfInput ?>
            <label class="grid gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-[#8a4b3e]">
                Quantity
                <input class="<?= $ui['input'] ?>" type="number" name="quantity" value="1" min="1" <?= !$allowsOversell && $remainingStock > 0 ? 'max="' . (int) $remainingStock . '"' : '' ?> <?= !$canAddToCart ? 'disabled' : '' ?>>
            </label>
            <div class="<?= $ui['actions'] ?> gap-3">
                <button class="<?= $ui['button'] ?> mrc-cart-button w-full" type="submit" <?= !$canAddToCart ? 'disabled' : '' ?>><?= mrc_storefront_cart_icon() ?><span><?= $canAddToCart ? 'Add to Cart' : $sanitizer->entities($unavailableLabel) ?></span></button>
                <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Back to products</a>
                <?php if ($cart->count() > 0): ?>
                    <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Checkout</a>
                <?php endif; ?>
            </div>
        </form>
    </aside>
</section>
<?php else: ?>
    <?php if ($reviewSummary): ?>
        <p class="<?= $reviewSummaryClass ?>">
            <?php if ((string) ($reviewSummary['url'] ?? '') !== ''): ?>
                <a href="<?= $sanitizer->entities((string) $reviewSummary['url']) ?>"><?= $sanitizer->entities((string) $reviewSummary['label']) ?></a>
            <?php else: ?>
                <?= $sanitizer->entities((string) $reviewSummary['label']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($productImage): ?>
        <div class="<?= $ui['imageWrap'] ?>">
            <img class="<?= $ui['image'] ?>" src="<?= $sanitizer->entities($productImage->url) ?>" alt="<?= $sanitizer->entities($page->title) ?>">
        </div>
    <?php endif; ?>
    <p class="<?= $ui['price'] ?>"><?= $commerce->formatPrice($page->mrc_price) ?></p>
    <?php if ($page->hasField('mrc_shipping_price')): ?>
        <p class="<?= $ui['shipping'] ?>">
            Shipping: <?= ((float) $page->mrc_shipping_price > 0) ? $commerce->formatPrice((float) $page->mrc_shipping_price) : 'Free' ?>
            <?php if ($page->hasField('mrc_shipping_note') && $page->mrc_shipping_note): ?>
                &middot; <?= $sanitizer->entities($page->mrc_shipping_note) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($page->hasField('mrc_stock')): ?>
        <p class="<?= $ui['shipping'] ?>"><?= $sanitizer->entities($stockLabel) ?></p>
    <?php endif; ?>
    <?php if ($cartProductQuantity > 0): ?>
        <p><span class="<?= $inCartClass ?>"><?= (int) $cartProductQuantity ?> in cart</span></p>
    <?php endif; ?>
    <?php if ($page->mrc_description): ?>
        <div class="<?= $ui['description'] ?>"><?= $page->mrc_description ?></div>
    <?php endif; ?>
    <form class="<?= $ui['form'] ?>" method="post" action="">
        <input type="hidden" name="mrc_action" value="add_to_cart">
        <?= $csrfInput ?>
        <label>
            Quantity:
            <input class="<?= $ui['input'] ?>" type="number" name="quantity" value="1" min="1" <?= !$allowsOversell && $remainingStock > 0 ? 'max="' . (int) $remainingStock . '"' : '' ?> <?= !$canAddToCart ? 'disabled' : '' ?>>
        </label>
        <div class="<?= $ui['actions'] ?>">
            <button class="<?= $ui['button'] ?>" type="submit" <?= !$canAddToCart ? 'disabled' : '' ?>><?= $canAddToCart ? 'Add to Cart' : $sanitizer->entities($unavailableLabel) ?></button>
            <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Back to products</a>
            <?php if ($cart->count() > 0): ?>
                <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Checkout</a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php if ($relatedProducts): ?>
    <section class="<?= $relatedProductsClass ?>" aria-label="Related products">
        <h2 class="<?= $isVanilla ? '' : 'mrc-display mb-5 text-4xl font-semibold' ?>">Related products</h2>
        <div class="<?= $relatedGridClass ?>">
            <?php foreach ($relatedProducts as $relatedProduct): ?>
                <a class="<?= $relatedCardClass ?>" href="<?= $sanitizer->entities($relatedProduct->url) ?>">
                    <span class="<?= $relatedCardTitleClass ?>"><?= $sanitizer->entities($relatedProduct->title) ?></span>
                    <span class="<?= $ui['price'] ?>"><?= $commerce->formatPrice((float) $relatedProduct->mrc_price) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

</main>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
</body>
</html>
