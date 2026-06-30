<?php
namespace ProcessWire;
/**
 * mrc-page.php
 *
 * Editorial storefront page for Mercato demo shops.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-page')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';

$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$pageName = (string) $page->name;
$active = strpos($pageName, 'contact') !== false ? 'contact' : (strpos($pageName, 'about') !== false ? 'about' : '');
$body = $page->hasField('mrc_description') ? (string) $page->mrc_description : '';
$productsUrl = mrc_storefront_page_url($pages, $config, 'products');
$collectionsUrl = mrc_storefront_page_url($pages, $config, 'collections');
$checkoutUrl = mrc_storefront_page_url($pages, $config, trim((string) ($commerce->cancel_page ?: 'checkout'), '/'));
$isAbout = $pageName === 'about-us';
$isContact = $pageName === 'contact-us';
$isCare = $pageName === 'care-guide';
$isShipping = $pageName === 'shipping-and-returns';
$isPolicy = in_array($pageName, ['privacy-policy', 'terms-of-use', 'refund-policy'], true);
$heroProductPath = match ($pageName) {
    'contact-us' => '/products/dinnerware-starter-set/',
    'shipping-and-returns' => '/products/terracotta-serving-bowl/',
    'care-guide' => '/products/oatmeal-dinner-plate/',
    'privacy-policy', 'terms-of-use' => '/products/ceramics-gift-card/',
    default => '/products/stoneware-mug/',
};
$story = match (true) {
    $isAbout => [
        'kicker' => 'Studio standard',
        'title' => 'A complete shop built around objects people actually buy.',
        'intro' => 'Arlberg Ceramics is structured like a calm independent homeware brand: browseable collections, tactile products, limited runs, giftable items, digital care content, checkout trust, and post-purchase service.',
        'rows' => [
            ['Real catalogue', 'Tableware, serveware, gift cards, preorder pieces, low-stock products, sold-out states, discounts, and digital downloads live in one storefront.'],
            ['Operational surface', 'The public pages point into order status, fulfilment notes, shipping choices, refunds, emails, and policy acceptance instead of ending at pretty copy.'],
            ['Honest demo', 'No invented phone number or address. The store feels real because the commerce behavior is real, not because it pretends to be a fictional merchant.'],
        ],
    ],
    $isContact => [
        'kicker' => 'Service desk',
        'title' => 'A support page that routes customers without fake details.',
        'intro' => 'The demo keeps contact honest while still showing the service layer a merchant needs: order questions, delivery updates, pickup notes, and product care after checkout.',
        'rows' => [
            ['Order questions', 'Customers can use order notes, confirmation emails, and status links to keep support connected to the purchase record.'],
            ['Fulfilment updates', 'Tracking, pickup instructions, local delivery notes, and fulfilment history can be tested from real orders.'],
            ['Post-purchase care', 'Digital care guides and order status pages give the customer somewhere useful to go after payment.'],
        ],
    ],
    $isShipping => [
        'kicker' => 'Fulfilment',
        'title' => 'Shipping, pickup, and returns presented as one customer journey.',
        'intro' => 'This page makes the logistics side of Mercato visible: calculated shipping, free-shipping logic, pickup language, local delivery, order snapshots, and refund-ready records.',
        'rows' => [
            ['Carrier delivery', 'Shipping totals are calculated before payment and preserved on the order so the receipt stays auditable.'],
            ['Pickup and local delivery', 'Merchant-selected fulfilment methods can carry customer-facing pickup notes and delivery context.'],
            ['Returns workflow', 'Refund foundations connect back to order records instead of living as isolated page copy.'],
        ],
    ],
    $isCare => [
        'kicker' => 'Care library',
        'title' => 'Post-purchase content that makes digital fulfilment useful.',
        'intro' => 'Care content gives the store a reason to sell and deliver files next to physical goods: paid-only downloads, expiry, limits, email links, and practical customer value.',
        'rows' => [
            ['Daily use', 'Care guidance supports the catalogue and helps customers understand how handmade ceramic finishes age, clean, and travel.'],
            ['Digital fulfilment', 'Guides make an elegant test case for secure files, download limits, expiry dates, and order email links.'],
            ['Support reduction', 'Helpful content after checkout lowers repetitive service questions and makes account/status pages feel alive.'],
        ],
    ],
    $isPolicy => [
        'kicker' => 'Checkout trust',
        'title' => 'Policies are part of the transaction, not a forgotten footer.',
        'intro' => 'These pages are shaped to test policy visibility, acceptance, and order evidence. The copy is demo-ready, but the flow behaves like a production shop.',
        'rows' => [
            ['Checkout acceptance', 'Policy links are present in checkout so acceptance can be recorded and reviewed on the order.'],
            ['Order evidence', 'Acceptance details live beside payment, fulfilment, customer, and refund information.'],
            ['Production copy', 'A real merchant can replace the text while keeping the same commerce-aware page structure.'],
        ],
    ],
    default => [
        'kicker' => 'Storefront',
        'title' => 'Every page should move the customer closer to a real order.',
        'intro' => 'The demo storefront connects presentation, merchandising, checkout, fulfilment, and service without relying on placeholder business details.',
        'rows' => [
            ['Browse', 'Collections, product states, and content pages work together as a real store.'],
            ['Checkout', 'Discounts, shipping, policy acceptance, and payment all stay visible in the flow.'],
            ['Operate', 'Orders, refunds, fulfilment, emails, and downloads remain testable after purchase.'],
        ],
    ],
};
$board = match (true) {
    $isAbout => [
        ['Merchandising', 'Collection pages, product cards, filters, low-stock language, sale states, and product detail layouts.'],
        ['Checkout depth', 'Coupons, minimum totals, shipping choices, policy acceptance, payment records, and order snapshots.'],
        ['Aftercare', 'Status pages, fulfilment notes, refund records, receipt links, and digital care downloads.'],
        ['Brand restraint', 'A real-sector ceramic shop with no fake address, no fake phone, and no toy catalogue.'],
    ],
    $isContact => [
        ['Before checkout', 'Product and delivery questions stay close to the catalogue and cart context.'],
        ['During checkout', 'Order notes capture practical customer details before payment is completed.'],
        ['After checkout', 'Confirmation emails and status pages become the customer service spine.'],
        ['Merchant view', 'The team can review order history, fulfilment updates, refunds, and customer messages together.'],
    ],
    $isShipping => [
        ['Shipping fees', 'Product-level fees and free-shipping rules are visible in cart and checkout totals.'],
        ['Pickup notes', 'Pickup can be presented as a first-class fulfilment option, not a buried exception.'],
        ['Local delivery', 'Delivery language can be tested without inventing a public storefront address.'],
        ['Returns record', 'Refund readiness stays connected to the order, payment, and fulfilment trail.'],
    ],
    $isCare => [
        ['Download product', 'A care guide can be sold as a digital product next to physical ceramics.'],
        ['Email access', 'Order emails can link customers back to paid content and order status.'],
        ['Expiry limits', 'Download expiry and limits are easy to test without creating artificial products.'],
        ['Useful content', 'Care pages support the brand while also proving real post-purchase functionality.'],
    ],
    $isPolicy => [
        ['Privacy', 'Data language appears where customer identity, orders, and payment records matter.'],
        ['Terms', 'Purchase terms sit close to checkout, receipts, fulfilment, and refunds.'],
        ['Refunds', 'Return logic can be explained publicly and reconciled privately on the order.'],
        ['Evidence', 'Accepted policy references stay attached to the transaction record.'],
    ],
    default => [
        ['Products', 'Physical, preorder, sold-out, sale, and digital products all exist in the same demo.'],
        ['Collections', 'Category browsing and filters show how a real customer narrows a catalogue.'],
        ['Cart', 'Totals, discounts, shipping, and policies can be tested before payment.'],
        ['Orders', 'Customer-facing and merchant-facing records stay connected after checkout.'],
    ],
};
$proofs = match (true) {
    $isContact => [['4', 'support routes'], ['0', 'fake contacts'], ['1', 'order-linked record'], ['24h', 'demo response window']],
    $isShipping => [['3', 'fulfilment modes'], ['100%', 'checkout-visible fees'], ['0', 'hidden delivery text'], ['1', 'order trail']],
    $isCare => [['2', 'digital scenarios'], ['1', 'paid-only guide'], ['7d', 'expiry test'], ['0', 'throwaway PDFs']],
    $isPolicy => [['3', 'policy surfaces'], ['1', 'checkout acceptance'], ['100%', 'order evidence'], ['0', 'buried legal links']],
    default => [['8', 'public products'], ['4', 'collections'], ['3', 'fulfilment modes'], ['0', 'fake contacts']],
};
$cta = match (true) {
    $isContact => ['Start from an order, not a loose inbox.', 'The contact flow is intentionally tied to checkout, confirmation, and status behavior so customer service can be tested as part of commerce.'],
    $isShipping => ['Make logistics visible before payment.', 'Customers should understand shipping, pickup, delivery, and returns before they commit to the order.'],
    $isCare => ['Turn aftercare into a real product surface.', 'Care guides let the demo prove digital fulfilment while giving the storefront a credible post-purchase layer.'],
    $isPolicy => ['Keep trust close to checkout.', 'Legal pages support the transaction when they are visible, accepted, and connected to the order record.'],
    default => ['A showroom for the whole Mercato system.', 'The site is designed to test the storefront, cart, checkout, orders, fulfilment, refunds, discounts, and digital delivery in one believable shop.'],
};

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $sanitizer->entities($page->title) ?></title>
    <?= $frameworkAssets ?>
    <?= mrc_storefront_assets($isVanilla) ?>
</head>
<body class="<?= $ui['body'] ?> mrc-content-page">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, $active) ?>
<?php endif; ?>
<main>
    <?php if (!$isVanilla): ?>
    <section class="mrc-section mrc-hero">
        <div class="mrc-hero-copy">
            <span class="<?= $ui['kicker'] ?>">Arlberg Ceramics</span>
            <h1 class="mrc-display mrc-hero-title"><?= $sanitizer->entities($page->title) ?></h1>
            <?php if ($body !== ''): ?>
                <div class="mrc-lead"><?= $body ?></div>
            <?php endif; ?>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="<?= $ui['button'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Shop products</a>
                <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($collectionsUrl) ?>">View collections</a>
            </div>
        </div>
        <div class="mrc-media-frame">
            <?php
            $imageUrl = '';
            $product = $pages->get($heroProductPath);
            if (!$product || !$product->id || !$product->hasField('mrc_images') || !$product->mrc_images->count()) {
                $product = $pages->get('template=mrc-product, mrc_images.count>0, sort=sort, sort=title');
            }
            if ($product && $product->id && $product->hasField('mrc_images') && $product->mrc_images->count()) {
                $imageUrl = $product->mrc_images->first()->url;
            }
            ?>
            <?php if ($imageUrl !== ''): ?>
                <img src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($page->title) ?>">
            <?php endif; ?>
        </div>
    </section>
    <section class="mrc-section">
        <div class="mrc-story-block">
            <div class="mrc-story-head">
                <span class="<?= $ui['kicker'] ?>"><?= $sanitizer->entities($story['kicker']) ?></span>
                <h2 class="mrc-display"><?= $sanitizer->entities($story['title']) ?></h2>
            </div>
            <div class="mrc-story-body">
                <p><?= $sanitizer->entities($story['intro']) ?></p>
                <div class="mrc-story-lines">
                    <?php foreach ($story['rows'] as $row): ?>
                        <article>
                            <h3><?= $sanitizer->entities($row[0]) ?></h3>
                            <p><?= $sanitizer->entities($row[1]) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php if ($isContact): ?>
    <section class="mrc-section">
        <div class="mrc-contact-console">
            <article>
                <span class="<?= $ui['kicker'] ?>">Customer routes</span>
                <h2 class="mrc-display">Order-aware contact.</h2>
                <div class="mrc-route-list">
                    <?php foreach ($board as $item): ?>
                        <div class="mrc-route-row">
                            <strong><?= $sanitizer->entities($item[0]) ?></strong>
                            <p><?= $sanitizer->entities($item[1]) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="mrc-message-panel">
                <span class="<?= $ui['kicker'] ?>">Message preview</span>
                <h2 class="mrc-display">Service request</h2>
                <form class="mrc-contact-form" action="<?= $sanitizer->entities($checkoutUrl) ?>">
                    <label><span class="<?= $ui['kicker'] ?>">Name</span><input type="text" value="" placeholder="Customer name"></label>
                    <label><span class="<?= $ui['kicker'] ?>">Email</span><input type="email" value="" placeholder="customer@example.com"></label>
                    <label><span class="<?= $ui['kicker'] ?>">Message</span><textarea placeholder="Question about an order, delivery, pickup, or product care"></textarea></label>
                    <button class="<?= $ui['button'] ?>" type="button">Prepare request</button>
                </form>
            </article>
        </div>
    </section>
    <?php else: ?>
    <section class="mrc-section">
        <div class="mrc-commerce-board">
            <div>
                <span class="<?= $ui['kicker'] ?>"><?= $isAbout ? 'Commerce layers' : ($isCare ? 'Digital product logic' : ($isShipping ? 'Delivery logic' : 'Policy logic')) ?></span>
                <h2 class="mrc-display"><?= $isAbout ? 'What the demo proves.' : ($isCare ? 'Care content with commerce behind it.' : ($isShipping ? 'Every fulfilment option has a job.' : 'Trust details belong in the order flow.')) ?></h2>
            </div>
            <div class="mrc-board-grid">
                <?php foreach ($board as $item): ?>
                    <article class="mrc-board-card">
                        <span><?= $sanitizer->entities($item[0]) ?></span>
                        <p><?= $sanitizer->entities($item[1]) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <section class="mrc-section">
        <div class="mrc-proof-strip">
            <?php foreach ($proofs as $proof): ?>
                <div class="mrc-proof-item">
                    <strong><?= $sanitizer->entities($proof[0]) ?></strong>
                    <span class="<?= $ui['kicker'] ?>"><?= $sanitizer->entities($proof[1]) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="mrc-section">
        <div class="mrc-page-cta">
            <div class="mrc-page-cta-copy">
                <span class="<?= $ui['kicker'] ?>">Arlberg Ceramics</span>
                <h2 class="mrc-display"><?= $sanitizer->entities($cta[0]) ?></h2>
            </div>
            <div class="mrc-page-cta-actions">
                <p><?= $sanitizer->entities($cta[1]) ?></p>
                <div>
                    <a class="<?= $ui['button'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Shop products</a>
                    <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($collectionsUrl) ?>">View collections</a>
                </div>
            </div>
        </div>
    </section>
    <?php else: ?>
    <section class="<?= $ui['panel'] ?>">
        <span class="<?= $ui['kicker'] ?>">Arlberg Ceramics</span>
        <h1><?= $sanitizer->entities($page->title) ?></h1>
        <?php if ($body !== ''): ?>
            <div><?= $body ?></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
</body>
</html>
