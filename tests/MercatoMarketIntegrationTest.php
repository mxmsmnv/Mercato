<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato market integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser')); $wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$wire->fields->get('mrc_market_prices')) { echo "Mercato market integration test skipped (install schema 11).\n"; exit(0); }

$product = null;
foreach ($wire->pages->find('template=mrc-product,mrc_product_status=active,mrc_product_type=physical,include=all') as $candidate) {
    if ($commerce->variantService()->getDefinition($candidate)['variants'] === []) { $product = $candidate; break; }
}
if (!$product instanceof Page || !$product->id) throw new \RuntimeException('Market fixture product missing.');

$originalMarkets = (string) ($commerce->markets_json ?? '');
$originalMethods = $commerce->enabled_payment_methods;
$originalPrices = (string) $product->mrc_market_prices;
$originalCart = $commerce->cart()->toArray();
$marketPrice = round((float) $product->mrc_price + 13.25, 2);
$nonce = bin2hex(random_bytes(6)); $created = null; $cartResource = null;

try {
    $commerce->set('markets_json', json_encode([[
        'id' => 'us', 'label' => 'United States', 'currency' => 'USD', 'countries' => ['US'], 'language' => 'en',
        'fulfilment_prices' => ['carrier_delivery' => 7.50],
    ]], JSON_UNESCAPED_SLASHES));
    $commerce->set('enabled_payment_methods', ['demo']);
    $product->of(false); $product->mrc_market_prices = json_encode(['us' => ['price' => $marketPrice, 'shipping_price' => 4.25]], JSON_UNESCAPED_SLASHES); $wire->pages->save($product);

    $service = $commerce->headlessApiService();
    $store = $service->store();
    if (count($store['markets'] ?? []) !== 2 || ($store['markets'][1]['currency'] ?? '') !== 'USD') throw new \RuntimeException('Store market discovery failed.');
    $marketProduct = $service->product((int) $product->id, ['market_id' => 'us']);
    if ((float) $marketProduct['price'] !== $marketPrice || $marketProduct['currency'] !== 'USD' || $marketProduct['market_id'] !== 'us') throw new \RuntimeException('Catalog did not use the explicit market price.');

    $items = [['product_id' => (int) $product->id, 'quantity' => 1]];
    $cartResource = $service->createCart(['market_id' => 'us', 'items' => $items], 'market-cart-' . $nonce);
    if (($cartResource['commerce_context']['market_id'] ?? '') !== 'us' || ($cartResource['commerce_context']['currency_code'] ?? '') !== 'USD') throw new \RuntimeException('Server cart lost its market context.');

    $customer = ['first_name' => 'Market', 'last_name' => 'Fixture', 'email' => 'market-' . $nonce . '@example.test', 'address' => 'Market Street', 'city' => 'New York', 'zip' => '10001', 'country' => 'US'];
    $options = ['market_id' => 'us', 'fulfilment_method' => 'carrier_delivery', 'payment_method' => 'demo', 'policy_accepted' => true];
    $body = ['items' => $items, 'customer' => $customer, 'options' => $options];
    $quote = $service->quote($body);
    if ($quote['currency'] !== 'USD' || $quote['market_id'] !== 'us' || (float) $quote['subtotal'] !== $marketPrice || (float) $quote['shipping'] !== 7.50) throw new \RuntimeException('Market quote was not authoritative.');

    $created = $service->createCheckout($body, 'market-checkout-' . $nonce);
    $order = $wire->pages->get('template=mrc-order,include=all,mrc_api_checkout_id=' . $wire->sanitizer->selectorValue((string) $created['id']));
    $snapshots = $order && $order->id ? json_decode((string) $order->mrc_items, true) : null;
    if (!$order || !$order->id || (string) $order->mrc_currency !== 'USD' || (float) $order->mrc_subtotal_amount !== $marketPrice || (float) $order->mrc_shipping_amount !== 7.50 || ($snapshots[0]['market_id'] ?? '') !== 'us') throw new \RuntimeException('Checkout order lost its market price snapshot.');
} finally {
    $commerce->set('markets_json', $originalMarkets); $commerce->set('enabled_payment_methods', $originalMethods); $commerce->cart($originalCart);
    $product->of(false); $product->mrc_market_prices = $originalPrices; $wire->pages->save($product);
    if (is_array($created ?? null) && !empty($created['id'])) {
        $order = $wire->pages->get('template=mrc-order,include=all,mrc_api_checkout_id=' . $wire->sanitizer->selectorValue((string) $created['id']));
        if ($order && $order->id) { $commerce->orderRepository()->releaseStockReservation($order, 'market_integration_cleanup'); $wire->pages->delete($order, true); }
    }
    if (is_array($cartResource ?? null) && !empty($cartResource['id'])) {
        $secret = (string) ($wire->config->userAuthSalt ?: __FILE__); $wire->cache->delete('Mercato.api.cart.' . hash_hmac('sha256', (string) $cartResource['id'], $secret));
    }
}
echo "Mercato market integration tests passed.\n";
