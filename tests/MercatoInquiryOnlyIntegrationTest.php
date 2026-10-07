<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato inquiry-only integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$product = null;
foreach ($wire->pages->find('template=mrc-product, mrc_product_status=active, mrc_product_type=physical, include=all, limit=250') as $candidate) {
    if (!$candidate->isHidden() && !$candidate->isUnpublished() && $candidate->hasField('mrc_inquiry_only')) {
        $product = $candidate;
        break;
    }
}
if (!$product || !$product->id) throw new \RuntimeException('A public physical product with mrc_inquiry_only is required.');

$original = (int) $product->getUnformatted('mrc_inquiry_only');
try {
    $product->of(false);
    $product->mrc_inquiry_only = 1;
    $wire->pages->saveField($product, 'mrc_inquiry_only');
    $variants = $commerce->variantService()->getDefinition($product)['variants'];
    $variantId = $variants ? (string) $variants[0]['id'] : null;
    $purchase = $commerce->getProductPurchasability($product, 1, 0, 0, $variantId);
    if ($purchase['ok'] || empty($purchase['inquiry_only']) || !str_contains((string) $purchase['first_error'], 'inquiry')) {
        throw new \RuntimeException('Inquiry-only product remained purchasable.');
    }
    $public = $commerce->headlessApiService()->product((int) $product->id);
    if (!array_key_exists('price', $public) || $public['price'] !== null || empty($public['price_on_request']) || !empty($public['purchasability']['purchasable'])) {
        throw new \RuntimeException('Headless API exposed price or purchase state.');
    }
    foreach ($public['variants'] as $variant) {
        if (!array_key_exists('price', $variant) || $variant['price'] !== null || isset($variant['stripe_price_id'])) {
            throw new \RuntimeException('Headless API exposed a variant price or Stripe price id.');
        }
    }
} finally {
    $product->mrc_inquiry_only = $original;
    $wire->pages->saveField($product, 'mrc_inquiry_only');
}

echo "Mercato inquiry-only integration test passed.\n";
