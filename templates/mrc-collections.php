<?php
namespace ProcessWire;
/**
 * mrc-collections.php
 *
 * Public collection index page for Mercato storefronts.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-collections')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';

$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$collections = $pages->find('template=mrc-collection, parent=/collections/, sort=sort, sort=title');
$filterState = mrc_storefront_filter_state($input, true);
$filteredProducts = $pages->find(mrc_storefront_product_selector($filterState));
$productsUrl = mrc_storefront_page_url($pages, $config, 'products');
$collectionMeta = [];
$totalCollectionProducts = 0;
$featuredProducts = new PageArray();
foreach ($collections as $collection) {
    $collectionProducts = $pages->find('template=mrc-product, mrc_collections=' . (int) $collection->id . ', sort=sort, sort=title');
    $featureProduct = mrc_storefront_collection_feature_product($pages, $collection);
    if (!$featureProduct || !$featureProduct->id) {
        $featureProduct = $collectionProducts->count() ? $collectionProducts->first() : null;
    }
    $imageUrl = '';
    if ($featureProduct && $featureProduct->id && $featureProduct->hasField('mrc_images') && $featureProduct->mrc_images->count()) {
        $imageUrl = $featureProduct->mrc_images->first()->url;
        if (!$featuredProducts->has($featureProduct)) {
            $featuredProducts->add($featureProduct);
        }
    }
    $collectionMeta[(int) $collection->id] = [
        'count' => $collectionProducts->count(),
        'image' => $imageUrl,
        'feature' => $featureProduct,
    ];
    $totalCollectionProducts += $collectionProducts->count();
}
$heroProduct = $featuredProducts->count() ? $featuredProducts->first() : $pages->get('template=mrc-product, mrc_images.count>0, sort=sort, sort=title');
$heroImageUrl = '';
if ($heroProduct && $heroProduct->id && $heroProduct->hasField('mrc_images') && $heroProduct->mrc_images->count()) {
    $heroImageUrl = $heroProduct->mrc_images->first()->url;
}
$activeFilterCount = 0;
foreach (['collection', 'availability', 'sort', 'type', 'min_price', 'max_price'] as $filterKey) {
    if (!empty($filterState[$filterKey])) {
        $activeFilterCount++;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $sanitizer->entities($page->title ?: 'Collections') ?></title>
    <?= $frameworkAssets ?>
    <?= mrc_storefront_assets($isVanilla) ?>
    <?php if (!$isVanilla): ?>
    <style>
        .mrc-collections-shell {
            display: grid;
            gap: clamp(40px, 6vw, 86px);
            padding-top: clamp(14px, 2.5vw, 34px);
        }
        .mrc-collections-hero {
            align-items: start;
            display: grid;
            gap: clamp(26px, 5vw, 76px);
            grid-template-columns: minmax(0, .88fr) minmax(0, 1.12fr);
            min-height: 0;
            padding-bottom: clamp(32px, 6vw, 72px);
        }
        .mrc-collections-eyebrow {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }
        .mrc-collections-pill {
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            color: var(--mrc-muted);
            display: inline-flex;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            padding: 9px 11px;
            text-transform: uppercase;
        }
        .mrc-collections-copy {
            align-self: start;
            padding-top: clamp(76px, 9vw, 124px);
        }
        .mrc-collections-title {
            font-size: clamp(64px, 9vw, 132px);
            font-weight: 600;
            line-height: .88;
            margin: 0;
            max-width: 760px;
        }
        .mrc-collections-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 30px;
        }
        .mrc-collections-feature {
            background: var(--mrc-line);
            border-radius: var(--mrc-radius);
            display: grid;
            align-self: start;
            height: clamp(420px, 48vw, 600px);
            overflow: hidden;
            position: relative;
        }
        .mrc-collections-feature img {
            display: block;
            height: 100%;
            object-fit: cover;
            width: 100%;
        }
        .mrc-collections-feature:after {
            background: linear-gradient(to top, rgba(27, 46, 41, .68), rgba(27, 46, 41, .06) 58%, transparent);
            content: "";
            inset: 0;
            position: absolute;
        }
        .mrc-collections-feature-copy {
            align-self: end;
            color: var(--mrc-cream);
            display: grid;
            gap: 10px;
            padding: clamp(24px, 4vw, 42px);
            position: relative;
            z-index: 1;
        }
        .mrc-collections-feature-copy h2 {
            font-size: clamp(36px, 5vw, 70px);
            font-weight: 600;
            line-height: .92;
            margin: 0;
            max-width: 560px;
        }
        .mrc-collections-feature-copy p {
            color: rgba(255, 250, 242, .82);
            font-size: 15px;
            line-height: 1.7;
            margin: 0;
            max-width: 560px;
        }
        .mrc-collections-stat-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        #collection-edits,
        #filtered-catalog {
            scroll-margin-top: 112px;
        }
        .mrc-collections-stat {
            background: rgba(255, 250, 242, .58);
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            display: grid;
            gap: 8px;
            padding: clamp(16px, 2.5vw, 24px);
        }
        .mrc-collections-stat strong {
            color: var(--mrc-ink);
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(34px, 4vw, 52px);
            font-weight: 600;
            line-height: .92;
        }
        .mrc-collections-stat span {
            color: var(--mrc-muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .mrc-collections-section-head {
            align-items: end;
            border-bottom: 1px solid var(--mrc-line);
            display: flex;
            gap: 24px;
            justify-content: space-between;
            margin-bottom: clamp(22px, 4vw, 38px);
            padding-bottom: 22px;
        }
        .mrc-collections-section-head h2 {
            font-size: clamp(42px, 6vw, 84px);
            font-weight: 600;
            line-height: .92;
            margin: 0;
        }
        .mrc-collections-section-head p {
            color: var(--mrc-muted);
            font-size: 16px;
            line-height: 1.65;
            margin: 0;
            max-width: 460px;
        }
        .mrc-collections-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .mrc-collection-card {
            background: var(--mrc-cream);
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            color: var(--mrc-ink);
            display: grid;
            min-height: 100%;
            overflow: hidden;
            text-decoration: none;
        }
        .mrc-collection-card-media {
            aspect-ratio: 1 / 1;
            background: var(--mrc-line);
            overflow: hidden;
        }
        .mrc-collection-card-media img {
            display: block;
            height: 100%;
            object-fit: cover;
            width: 100%;
        }
        .mrc-collection-card-body {
            display: grid;
            gap: 14px;
            padding: clamp(18px, 2.5vw, 26px);
        }
        .mrc-collection-card-title {
            align-items: baseline;
            display: flex;
            gap: 14px;
            justify-content: space-between;
        }
        .mrc-collection-card-title strong {
            font-size: clamp(28px, 3vw, 42px);
            font-weight: 600;
            line-height: .95;
        }
        .mrc-collection-card-title span {
            color: var(--mrc-rust);
            flex: 0 0 auto;
        }
        .mrc-collection-card-body p {
            color: var(--mrc-muted);
            font-size: 15px;
            line-height: 1.65;
            margin: 0;
        }
        .mrc-collections-route {
            background: var(--mrc-pine);
            border-radius: var(--mrc-radius);
            color: var(--mrc-ivory);
            display: grid;
            gap: clamp(26px, 5vw, 72px);
            grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr);
            padding: clamp(24px, 5vw, 54px);
        }
        .mrc-collections-route h2 {
            font-size: clamp(40px, 6vw, 82px);
            font-weight: 600;
            line-height: .92;
            margin: 0;
        }
        .mrc-collections-route p {
            color: rgba(236, 233, 228, .72);
            line-height: 1.75;
            margin: 18px 0 0;
        }
        .mrc-collections-route-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .mrc-collections-route-card {
            border: 1px solid rgba(236, 233, 228, .22);
            border-radius: var(--mrc-radius);
            display: grid;
            gap: 10px;
            padding: 20px;
        }
        .mrc-collections-route-card strong {
            color: var(--mrc-cream);
            font-size: 18px;
            font-weight: 600;
        }
        .mrc-collections-route-card span {
            color: rgba(236, 233, 228, .68);
            font-size: 14px;
            line-height: 1.55;
        }
        .mrc-collections-catalog-head {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .mrc-collections-catalog-head h2 {
            font-size: clamp(40px, 5vw, 70px);
            font-weight: 600;
            line-height: .95;
            margin: 0;
        }
        .mrc-collections-result-count {
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            color: var(--mrc-muted);
            font-size: 13px;
            font-weight: 700;
            padding: 11px 13px;
            text-transform: uppercase;
        }
        .mrc-collections-products {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .mrc-collections-products .mrc-card {
            display: grid;
        }
        .mrc-collections-products .mrc-card-media {
            aspect-ratio: 4 / 5;
            display: block;
        }
        .mrc-collections-products .mrc-card-body {
            align-content: start;
        }
        .mrc-collections-products .mrc-card-title {
            font-size: clamp(24px, 2vw, 32px);
        }
        @media (max-width: 980px) {
            .mrc-collections-hero,
            .mrc-collections-route {
                grid-template-columns: 1fr;
            }
            .mrc-collections-hero {
                min-height: auto;
            }
            .mrc-collections-copy {
                padding-top: 0;
            }
            .mrc-collections-grid,
            .mrc-collections-products,
            .mrc-collections-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 680px) {
            .mrc-collections-section-head,
            .mrc-collections-catalog-head {
                align-items: start;
                display: grid;
            }
            .mrc-collections-grid,
            .mrc-collections-products,
            .mrc-collections-stat-grid,
            .mrc-collections-route-grid {
                grid-template-columns: 1fr;
            }
            .mrc-collections-feature {
                min-height: 360px;
            }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $ui['body'] ?>">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'collections') ?>
<?php endif; ?>
<main>
    <?php if (!$isVanilla): ?>
    <div class="mrc-section mrc-collections-shell">
    <section class="mrc-collections-hero">
        <div class="mrc-collections-copy">
            <div class="mrc-collections-eyebrow">
                <span class="<?= $ui['kicker'] ?>">Collection index</span>
                <span class="mrc-collections-pill"><?= $collections->count() ?> edits</span>
                <span class="mrc-collections-pill"><?= $filteredProducts->count() ?> products</span>
            </div>
            <h1 class="mrc-display mrc-collections-title"><?= $sanitizer->entities($page->title ?: 'Collections') ?></h1>
            <p class="mrc-lead">A complete storefront map for the Mercato demo: shop by room, gift moment, stock state, product type, and fulfilment scenario.</p>
            <div class="mrc-collections-actions">
                <a class="<?= $ui['button'] ?>" href="#collection-edits">Explore edits</a>
                <a class="<?= $ui['buttonSecondary'] ?>" href="#filtered-catalog">Use filters</a>
            </div>
        </div>
        <div class="mrc-collections-feature">
            <?php if ($heroImageUrl !== ''): ?>
                <img src="<?= $sanitizer->entities($heroImageUrl) ?>" alt="<?= $sanitizer->entities($page->title ?: 'Collections') ?>">
            <?php endif; ?>
            <div class="mrc-collections-feature-copy">
                <span class="mrc-small-caps">Featured route</span>
                <h2 class="mrc-display">From table setting to checkout test.</h2>
                <p>Collections connect real browsing with discounts, delivery logic, digital products, stock limits, preorder behavior, and cart validation.</p>
            </div>
        </div>
    </section>

    <section class="mrc-collections-stat-grid" aria-label="Collection summary">
        <div class="mrc-collections-stat"><strong><?= $collections->count() ?></strong><span>public collections for navigation and discount targeting</span></div>
        <div class="mrc-collections-stat"><strong><?= $totalCollectionProducts ?></strong><span>collection placements across the demo catalog</span></div>
        <div class="mrc-collections-stat"><strong><?= $filteredProducts->count() ?></strong><span>products available in the live filtered view</span></div>
        <div class="mrc-collections-stat"><strong><?= $activeFilterCount ?></strong><span>active filter<?= $activeFilterCount === 1 ? '' : 's' ?> on this page</span></div>
    </section>

    <section id="collection-edits">
        <div class="mrc-collections-section-head">
            <div>
                <span class="<?= $ui['kicker'] ?>">Curated edits</span>
                <h2 class="mrc-display">Shop by intent.</h2>
            </div>
            <p>Each collection is a real ProcessWire page, can be used in navigation, targeted discounts, product relations, and installable demo content.</p>
        </div>
        <div class="mrc-collections-grid">
            <?php foreach ($collections as $collection): ?>
                <?php
                $meta = $collectionMeta[(int) $collection->id] ?? ['count' => 0, 'image' => '', 'feature' => null];
                $collectionImageUrl = (string) $meta['image'];
                $collectionProductCount = (int) $meta['count'];
                ?>
                <a class="mrc-collection-card" href="<?= $sanitizer->entities($collection->url) ?>">
                    <span class="mrc-collection-card-media">
                        <?php if ($collectionImageUrl !== ''): ?>
                            <img src="<?= $sanitizer->entities($collectionImageUrl) ?>" alt="<?= $sanitizer->entities($collection->title) ?>">
                        <?php endif; ?>
                    </span>
                    <span class="mrc-collection-card-body">
                        <span class="mrc-small-caps" style="color:var(--mrc-gold)">Collection</span>
                        <span class="mrc-collection-card-title">
                            <strong class="mrc-display"><?= $sanitizer->entities($collection->title) ?></strong>
                            <span class="mrc-small-caps"><?= $collectionProductCount ?> item<?= $collectionProductCount === 1 ? '' : 's' ?></span>
                        </span>
                        <?php if ($collection->hasField('mrc_description') && $collection->mrc_description): ?>
                            <p><?= $sanitizer->entities(strip_tags((string) $collection->mrc_description)) ?></p>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mrc-collections-route">
        <div>
            <span class="<?= $ui['kicker'] ?>">Demo journeys</span>
            <h2 class="mrc-display">Four routes that prove the shop.</h2>
            <p>Collections should not be decoration. They help testers jump into the exact commerce behaviors they need to verify.</p>
        </div>
        <div class="mrc-collections-route-grid">
            <div class="mrc-collections-route-card"><strong>Discount targeting</strong><span>Use Tableware for collection-specific coupon tests and cart thresholds.</span></div>
            <div class="mrc-collections-route-card"><strong>Stock pressure</strong><span>Use Limited Stock to check low inventory, sold-out labels, and preorder copy.</span></div>
            <div class="mrc-collections-route-card"><strong>Mixed fulfillment</strong><span>Use Gifts for digital gift cards beside shippable ceramic pieces.</span></div>
            <div class="mrc-collections-route-card"><strong>Shipping logic</strong><span>Use Serveware to test larger basket values, delivery notes, pickup, and local delivery.</span></div>
        </div>
    </section>

    <section id="filtered-catalog">
        <div class="mrc-collections-catalog-head">
            <div>
                <span class="<?= $ui['kicker'] ?>">Filtered catalog</span>
                <h2 class="mrc-display">Find pieces across collections.</h2>
            </div>
            <span class="mrc-collections-result-count"><?= $filteredProducts->count() ?> result<?= $filteredProducts->count() === 1 ? '' : 's' ?></span>
        </div>
        <?= mrc_storefront_filter_form($filterState, $collections, $sanitizer, $page->url, true) ?>
        <?php if ($filteredProducts->count()): ?>
            <div class="mrc-collections-products">
                <?php foreach ($filteredProducts as $product): ?>
                    <?php
                    $imageUrl = '';
                    if ($product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                        $imageUrl = $product->mrc_images->first()->url;
                    }
                    ?>
                    <a class="mrc-card" href="<?= $sanitizer->entities($product->url) ?>">
                        <span class="mrc-card-media">
                            <?php if ($imageUrl !== ''): ?>
                                <img src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($product->title) ?>">
                            <?php endif; ?>
                        </span>
                        <span class="mrc-card-body">
                            <span class="mrc-card-title mrc-display"><?= $sanitizer->entities($product->title) ?></span>
                            <span class="mrc-small-caps" style="color:var(--mrc-gold)"><?= $commerce->formatPrice((float) $product->mrc_price) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="mrc-lead">No products match these filters yet.</p>
        <?php endif; ?>
    </section>
    </div>
    <?php else: ?>
    <section class="<?= $ui['panel'] ?>">
        <span class="<?= $ui['kicker'] ?>">Categories</span>
        <h1><?= $sanitizer->entities($page->title ?: 'Collections') ?></h1>
        <ul>
            <?php foreach ($collections as $collection): ?>
                <li><a href="<?= $sanitizer->entities($collection->url) ?>"><?= $sanitizer->entities($collection->title) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
</main>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
</body>
</html>
