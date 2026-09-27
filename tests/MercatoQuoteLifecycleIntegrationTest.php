<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato quote lifecycle integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the quote lifecycle profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Quote lifecycle fixtures are forbidden in production mode.');
$quotesParent = $wire->pages->get('/' . trim((string) $commerce->quotes_parent, '/') . '/');
$quoteTemplate = $wire->templates->get((string) $commerce->quote_template);
if (!$quotesParent || !$quotesParent->id || !$quoteTemplate) {
    echo "Mercato quote lifecycle integration test skipped (quote schema is not installed).\n";
    exit(0);
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$runId = 'quote-life-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$createdQuoteIds = [];
$createdUserIds = [];
$createdProductIds = [];
$logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';
$originalCart = $commerce->cart()->toArray();
$configKeys = [
    'quote_requests_enabled', 'quote_expiry_days', 'quote_inventory_policy',
    'quote_merchant_email', 'notification_sender_email', 'enabled_notification_events',
    'enabled_fulfilment_methods', 'default_fulfilment_method', 'allowed_delivery_countries',
    'shipping_provider', 'shipping_provider_include_manual_rates', 'free_shipping_threshold',
    'analytics_enabled', 'markets_json',
];
$originalConfig = [];
foreach ($configKeys as $key) $originalConfig[$key] = $commerce->get($key);
$cleaned = false;

$removeOwnedLogLines = static function () use ($logsRoot, $runId, &$createdQuoteIds): void {
    $ids = array_fill_keys(array_map('intval', $createdQuoteIds), true);
    $paths = array_merge(glob($logsRoot . 'mercato-*.txt') ?: [], [$logsRoot . 'errors.txt']);
    foreach (array_unique($paths) as $path) {
        if (!is_file($path)) continue;
        $lines = file($path);
        if ($lines === false) continue;
        $kept = [];
        foreach ($lines as $line) {
            $owned = str_contains($line, $runId)
                || ($path === $logsRoot . 'errors.txt' && str_contains($line, basename(__FILE__)));
            $json = strstr($line, '{');
            $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) {
                $quoteId = (int) ($payload['quote_id'] ?? 0);
                $owned = $owned || isset($ids[$quoteId]);
            }
            if (!$owned) $kept[] = $line;
        }
        if (count($kept) === count($lines)) continue;
        if ($kept === []) @unlink($path); else file_put_contents($path, implode('', $kept), LOCK_EX);
    }
};

$cleanup = static function () use (
    $wire, $commerce, $superuser, &$createdQuoteIds, &$createdUserIds, &$createdProductIds,
    $originalConfig, $originalCart, $runId, $removeOwnedLogLines, &$cleaned
): void {
    if ($cleaned) return;
    $cleaned = true;
    $wire->users->setCurrentUser($superuser);
    foreach ($originalConfig as $key => $value) $commerce->set($key, $value);
    $commerce->cart($originalCart);
    foreach (array_reverse(array_unique($createdQuoteIds)) as $id) {
        $quote = $wire->pages->get((int) $id);
        if (!$quote || !$quote->id) continue;
        if (!str_contains(strtolower((string) $quote->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected quote {$quote->id}.");
        $wire->pages->delete($quote, true);
    }
    foreach (array_reverse(array_unique($createdProductIds)) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, 'e2e-' . $runId . '-')) throw new WireException("Refusing to delete unexpected product {$product->id}.");
        $wire->pages->delete($product, true);
    }
    foreach (array_reverse(array_unique($createdUserIds)) as $id) {
        $user = $wire->users->get((int) $id);
        if (!$user || !$user->id) continue;
        if (!str_starts_with((string) $user->name, 'e2e-' . $runId . '-')) throw new WireException("Refusing to delete unexpected user {$user->id}.");
        $wire->users->delete($user);
    }
    $removeOwnedLogLines();
};
register_shutdown_function($cleanup);

$productsParent = $wire->pages->get('template=mrc-products,include=all');
if (!$productsParent || !$productsParent->id) $productsParent = $wire->pages->get('/products/');
if (!$productsParent || !$productsParent->id) throw new WireException('Mercato products parent is missing.');
$customerRole = $wire->roles->get('name=mercato-customer');
if (!$customerRole || !$customerRole->id) throw new WireException('Mercato customer role is missing.');

$createUser = static function (string $suffix) use ($wire, $customerRole, $runId, &$createdUserIds): User {
    $user = new User();
    $user->of(false);
    $user->name = 'e2e-' . $runId . '-' . $suffix;
    $user->email = $runId . '-' . $suffix . '@example.test';
    $user->pass = 'Quote-fixture-42!';
    $user->addRole($customerRole);
    if ($user->hasField('mrc_customer_verified')) $user->mrc_customer_verified = 1;
    if ($user->hasField('mrc_first_name')) $user->mrc_first_name = ucfirst($suffix);
    if ($user->hasField('mrc_last_name')) $user->mrc_last_name = 'Quote';
    $wire->users->save($user);
    $createdUserIds[] = (int) $user->id;
    return $user;
};

$product = new Page();
$product->template = 'mrc-product';
$product->parent = $productsParent;
$product->name = 'e2e-' . $runId . '-physical';
$product->of(false);
$product->title = 'E2E ' . $runId . ' quoted physical product';
$product->mrc_sku = strtoupper(substr(hash('sha256', $runId), 0, 16));
$product->mrc_price = 40.0;
$product->mrc_shipping_price = 5.0;
$product->mrc_tax_rate = 20.0;
$product->mrc_product_type = 'physical';
$product->mrc_product_status = 'active';
$product->mrc_stock = 2;
$product->mrc_stock_policy = 'deny';
$wire->pages->save($product);
$createdProductIds[] = (int) $product->id;

$owner = $createUser('owner');
$other = $createUser('other');
$service = $commerce->quoteService();
$repository = $commerce->orderRepository();
$fresh = static fn(int $id): Page => $wire->pages->getById($id, ['cache' => false])->first();
$rejects = static function (callable $operation, int $code, string $message) use ($expect): void {
    $rejected = false;
    try {
        $operation();
    } catch (WireException $error) {
        $rejected = $code === 0 || $error->getCode() === $code;
    }
    $expect($rejected, $message);
};

try {
    $commerce->set('quote_requests_enabled', true);
    $commerce->set('quote_expiry_days', 7);
    $commerce->set('quote_merchant_email', '');
    $commerce->set('notification_sender_email', '');
    $commerce->set('enabled_notification_events', []);
    $commerce->set('enabled_fulfilment_methods', ['carrier_delivery']);
    $commerce->set('default_fulfilment_method', 'carrier_delivery');
    $commerce->set('allowed_delivery_countries', 'US');
    $commerce->set('shipping_provider', 'manual');
    $commerce->set('shipping_provider_include_manual_rates', true);
    $commerce->set('free_shipping_threshold', 0.0);
    $commerce->set('analytics_enabled', false);
    $commerce->set('markets_json', '');

    $ordersBefore = (int) $wire->pages->count('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ',include=all');
    $dataFor = static fn(User $user, string $suffix): array => [
        'first_name' => ucfirst($suffix),
        'last_name' => 'Quote',
        'email' => (string) $user->email,
        'address' => '10 Quote Street',
        'city' => 'New York',
        'zip' => '10001',
        'country' => 'US',
        'fulfilment_method' => 'carrier_delivery',
        'mrc_policy_accepted' => 1,
        'notes' => $runId . '-' . $suffix,
    ];

    $wire->users->setCurrentUser($owner);
    $commerce->set('quote_inventory_policy', 'on_acceptance');
    $reservedPolicyQuote = $commerce->submitQuoteRequest($dataFor($owner, 'reserved'), [['product_id' => (int) $product->id, 'quantity' => 2]]);
    $createdQuoteIds[] = (int) $reservedPolicyQuote->id;
    $wire->users->setCurrentUser($other);
    $commerce->set('quote_inventory_policy', 'none');
    $nonePolicyQuote = $commerce->submitQuoteRequest($dataFor($other, 'none'), [['product_id' => (int) $product->id, 'quantity' => 1]]);
    $createdQuoteIds[] = (int) $nonePolicyQuote->id;

    $reservedPolicyQuote = $fresh((int) $reservedPolicyQuote->id);
    $nonePolicyQuote = $fresh((int) $nonePolicyQuote->id);
    $reservedDetails = json_decode((string) $reservedPolicyQuote->mrc_quote_details, true);
    $noneDetails = json_decode((string) $nonePolicyQuote->mrc_quote_details, true);
    $reservedItems = json_decode((string) $reservedPolicyQuote->mrc_items, true);
    $expect(($reservedDetails['inventory_reservation'] ?? '') === 'on_acceptance', 'Quote lost its on-acceptance inventory-policy snapshot.');
    $expect(($noneDetails['inventory_reservation'] ?? '') === 'none', 'Quote lost its no-reservation inventory-policy snapshot.');
    $expect((int) ($reservedItems[0]['product_id'] ?? 0) === (int) $product->id && (int) ($reservedItems[0]['quantity'] ?? 0) === 2, 'Quote lost its authoritative item snapshot.');
    $expect((float) $reservedPolicyQuote->mrc_subtotal_amount === 80.0 && (float) $reservedPolicyQuote->mrc_shipping_amount === 5.0 && (float) $reservedPolicyQuote->mrc_total_amount === 85.0, 'Quote pricing/shipping snapshot is incorrect.');
    $expect((string) $reservedPolicyQuote->mrc_fulfilment_method === 'carrier_delivery', 'Quote lost its fulfilment snapshot.');
    $expect((int) $reservedPolicyQuote->mrc_quote_customer_user_id === (int) $owner->id && (int) $nonePolicyQuote->mrc_quote_customer_user_id === (int) $other->id, 'Quote ownership was not persisted exactly.');
    $expect(count($service->findForCustomer($owner)) === 1 && count($service->findForCustomer($other)) === 1, 'Customer quote history did not isolate owners.');

    $token = $service->getToken($reservedPolicyQuote);
    $public = $service->serializePublic($reservedPolicyQuote);
    $expect($service->verifyToken($reservedPolicyQuote, $token), 'Fresh signed quote token was rejected.');
    $expect(!isset($public['email'], $public['customer_user_id'], $public['token_seed']) && ($public['number'] ?? '') === (string) $reservedPolicyQuote->mrc_quote_number, 'Public quote serialization exposed identity or lost its number.');
    $expect(!$service->verifyToken($reservedPolicyQuote, 'malformed-' . $token), 'Malformed signed quote token was accepted.');

    $rejects(
        fn() => $commerce->updateQuoteStatus($nonePolicyQuote, MercatoQuoteStatus::ACCEPTED, 'Invalid direct acceptance.'),
        409,
        'Quote skipped required lifecycle states.'
    );

    $wire->users->setCurrentUser($superuser);
    $commerce->updateQuoteStatus($reservedPolicyQuote, MercatoQuoteStatus::UNDER_REVIEW, 'Review reserved-policy quote.');
    $commerce->updateQuoteStatus($reservedPolicyQuote, MercatoQuoteStatus::QUOTED, 'Price reserved-policy quote.', 82.50);
    $commerce->updateQuoteStatus($nonePolicyQuote, MercatoQuoteStatus::UNDER_REVIEW, 'Review none-policy quote.');
    $commerce->updateQuoteStatus($nonePolicyQuote, MercatoQuoteStatus::QUOTED, 'Price none-policy quote.', 43.00);

    // A quote's submitted policy is immutable: later module configuration must not change it.
    $commerce->set('quote_inventory_policy', 'none');
    $commerce->updateQuoteStatus($reservedPolicyQuote, MercatoQuoteStatus::ACCEPTED, 'Accept snapshotted reservation quote.');
    $reservedPolicyQuote = $fresh((int) $reservedPolicyQuote->id);
    $expect((int) $reservedPolicyQuote->mrc_inventory_reserved === 1, 'Accepted quote ignored its snapshotted on-acceptance policy.');
    $expect($repository->getReservedQuantityForProduct((int) $product->id) === 2, 'Accepted quote reservation was not counted after configuration changed.');
    $acceptedHistory = json_decode((string) $reservedPolicyQuote->mrc_quote_details, true)['history'] ?? [];
    $commerce->updateQuoteStatus($reservedPolicyQuote, MercatoQuoteStatus::ACCEPTED, 'Accept snapshotted reservation quote.');
    $reservedPolicyQuote = $fresh((int) $reservedPolicyQuote->id);
    $replayedHistory = json_decode((string) $reservedPolicyQuote->mrc_quote_details, true)['history'] ?? [];
    $expect(count($replayedHistory) === count($acceptedHistory), 'Accepted quote transition replay duplicated lifecycle history.');
    $expect((int) $reservedPolicyQuote->mrc_inventory_reserved === 1 && $repository->getReservedQuantityForProduct((int) $product->id) === 2, 'Accepted quote transition replay changed its reservation.');

    $commerce->updateQuoteStatus($reservedPolicyQuote, MercatoQuoteStatus::EXPIRED, 'Expire accepted quote.');
    $reservedPolicyQuote = $fresh((int) $reservedPolicyQuote->id);
    $expect((int) $reservedPolicyQuote->mrc_inventory_reserved === 0 && $repository->getReservedQuantityForProduct((int) $product->id) === 0, 'Quote expiry did not release the reservation.');
    $expect(!$service->verifyToken($reservedPolicyQuote, $token), 'Expired quote token remained valid.');

    $commerce->set('quote_inventory_policy', 'on_acceptance');
    $commerce->updateQuoteStatus($nonePolicyQuote, MercatoQuoteStatus::ACCEPTED, 'Accept snapshotted no-reservation quote.');
    $nonePolicyQuote = $fresh((int) $nonePolicyQuote->id);
    $expect((int) $nonePolicyQuote->mrc_inventory_reserved === 0 && $repository->getReservedQuantityForProduct((int) $product->id) === 0, 'No-reservation quote adopted a later on-acceptance configuration.');
    $commerce->updateQuoteStatus($nonePolicyQuote, MercatoQuoteStatus::CONVERTED, 'Convert quote without creating an order.');
    $nonePolicyQuote = $fresh((int) $nonePolicyQuote->id);
    $expect((string) $nonePolicyQuote->mrc_quote_status === MercatoQuoteStatus::CONVERTED && (int) $nonePolicyQuote->mrc_inventory_reserved === 0, 'Converted quote retained inventory or the wrong state.');

    $history = json_decode((string) $nonePolicyQuote->mrc_quote_details, true)['history'] ?? [];
    $expect(count($history) === 5, 'Quote lifecycle history cardinality is incorrect.');
    $expect(array_column($history, 'to') === [MercatoQuoteStatus::SUBMITTED, MercatoQuoteStatus::UNDER_REVIEW, MercatoQuoteStatus::QUOTED, MercatoQuoteStatus::ACCEPTED, MercatoQuoteStatus::CONVERTED], 'Quote lifecycle history order is incorrect.');
    $ordersAfter = (int) $wire->pages->count('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ',include=all');
    $expect($ordersAfter === $ordersBefore, 'Quote lifecycle unexpectedly initialized payment or created an order.');

    $rawLogs = '';
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) $rawLogs .= (string) file_get_contents($path);
    $expect(!str_contains($rawLogs, (string) $owner->email) && !str_contains($rawLogs, (string) $other->email), 'Quote logs exposed a raw customer email.');

    $quoteCount = count(array_unique($createdQuoteIds));
    $userCount = count(array_unique($createdUserIds));
    $productCount = count(array_unique($createdProductIds));
    $cleanup();
    $pageExists = static function (int $id) use ($wire): bool {
        $statement = $wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');
        $statement->execute([':id' => $id]);
        return (int) $statement->fetchColumn() > 0;
    };
    foreach ($createdQuoteIds as $id) $expect(!$pageExists((int) $id), "Quote {$id} remained after cleanup.");
    foreach ($createdProductIds as $id) $expect(!$pageExists((int) $id), "Product {$id} remained after cleanup.");
    foreach ($createdUserIds as $id) $expect(!$pageExists((int) $id), "User {$id} remained after cleanup.");
    $residualLogs = '';
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) $residualLogs .= (string) file_get_contents($path);
    $expect(!str_contains($residualLogs, $runId), 'Run-owned quote log rows remained after cleanup.');
    echo "Mercato quote lifecycle integration tests passed: {$quoteCount} quotes, {$userCount} users, {$productCount} product, immutable reservation policy and exact cleanup.\n";
} finally {
    $cleanup();
}
