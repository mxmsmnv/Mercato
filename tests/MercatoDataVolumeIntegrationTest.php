<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato data-volume integration test skipped (set MERCATO_TEST_SITE).\n";
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
if (!$superuser || !$superuser->id) throw new \RuntimeException('Data-volume integration test requires a superuser.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce instanceof Mercato || !empty($commerce->production)) {
    throw new \RuntimeException('Data-volume integration test is restricted to an installed non-production Mercato site.');
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$nonce = bin2hex(random_bytes(8));
$emailPrefix = 'volume-' . $nonce . '-';
$selectorPrefix = $wire->sanitizer->selectorValue($emailPrefix);
$orderTemplateName = (string) $commerce->order_template;
$createdIds = [];
$cleanupFailure = null;

$findFixtures = static function () use ($wire, $orderTemplateName, $selectorPrefix): PageArray {
    $template = $wire->sanitizer->selectorValue($orderTemplateName);
    return $wire->pages->find("template=$template, include=all, mrc_email^=$selectorPrefix, sort=id");
};
$cleanup = static function () use ($wire, $emailPrefix, $orderTemplateName, &$createdIds, $findFixtures, &$cleanupFailure): void {
    try {
        $candidateIds = $createdIds;
        foreach ($findFixtures() as $fixture) $candidateIds[] = (int) $fixture->id;
        foreach (array_values(array_unique(array_filter($candidateIds))) as $id) {
            $fixture = $wire->pages->get((int) $id);
            if (!$fixture || !$fixture->id) continue;
            if ($fixture->template->name !== $orderTemplateName || !str_starts_with((string) $fixture->mrc_email, $emailPrefix)) {
                throw new \RuntimeException("Refusing to delete non-owned data-volume fixture page $id.");
            }
            $fixture->of(false);
            $wire->pages->delete($fixture, true);
        }
        $createdIds = [];
        $wire->pages->uncacheAll();
        if ($findFixtures()->count() !== 0) throw new \RuntimeException('Data-volume fixture cleanup left matching order pages behind.');
    } catch (\Throwable $error) {
        $cleanupFailure = $error;
    }
};
register_shutdown_function($cleanup);

$failure = null;
try {
    $expect($findFixtures()->count() === 0, 'Unique data-volume fixture selector was not initially empty.');

    $emptyOrder = $commerce->orderRepository()->savePendingOrder([
        'first_name' => '',
        'last_name' => '',
        'email' => $emailPrefix . 'empty@example.test',
        'phone' => '',
        'address' => '',
        'address_2' => '',
        'city' => '',
        'zip' => '',
        'country' => '',
        'notes' => '',
        'payment_method' => '',
        'payment_status' => MercatoPaymentStatus::PENDING,
        'payment_complete' => 0,
        'mrc_items' => '[]',
        'mrc_currency' => 'USD',
        'mrc_subtotal_amount' => 0,
        'mrc_shipping_amount' => 0,
        'mrc_discount_total' => 0,
        'mrc_tax_amount' => 0,
        'mrc_total_amount' => 0,
        'fulfilment_method' => '',
    ]);
    $createdIds[] = (int) $emptyOrder->id;
    $emptyOrder = $wire->pages->getById((int) $emptyOrder->id, ['cache' => false])->first();
    $expect($emptyOrder instanceof Page && $emptyOrder->id, 'Empty-state order did not reload.');
    $emptyOrder->of(false);
    $emptyRoundTrip = $commerce->orderRepository()->pageToPendingData($emptyOrder);
    $expect(json_decode((string) $emptyOrder->mrc_items, true, 512, JSON_THROW_ON_ERROR) === [], 'Empty item snapshot did not persist as an empty array.');
    foreach (['mrc_first_name', 'mrc_last_name', 'mrc_phone', 'mrc_address', 'mrc_address_2', 'mrc_city', 'mrc_zip', 'mrc_country', 'mrc_notes', 'mrc_payment_method', 'mrc_fulfilment_method'] as $fieldName) {
        $expect((string) $emptyOrder->getUnformatted($fieldName) === '', "Empty optional field changed after reload: $fieldName");
    }
    $emptyOrder->of(true);
    $expect((string) $emptyOrder->mrc_items === '[]' && (string) $emptyOrder->mrc_notes === '', 'Formatted empty fields changed after reload.');
    $emptyOrder->of(false);
    $expect((float) $emptyOrder->mrc_total_amount === 0.0 && $commerce->orderRepository()->getTotalAmount($emptyOrder) === 0.0, 'Zero-value total changed after reload.');
    $expect(($emptyRoundTrip['mrc_items'] ?? null) === '[]' && ($emptyRoundTrip['mrc_total_amount'] ?? null) === 0.0, 'Empty order repository projection changed stored values.');
    $emptyAnalytics = $commerce->analyticsService()->orderPayload($emptyOrder, 'purchase');
    $expect(($emptyAnalytics['items'] ?? null) === [] && ($emptyAnalytics['value'] ?? null) === 0.0, 'Empty order analytics projection was not deterministic.');

    $largeItems = [];
    $expectedSubtotal = 0.0;
    for ($index = 0; $index < 1200; $index++) {
        $price = round(1.01 + (($index % 97) / 100), 2);
        $quantity = ($index % 3) + 1;
        $expectedSubtotal += $price * $quantity;
        $largeItems[] = [
            'id' => 'volume-' . $index,
            'product_id' => 900000 + $index,
            'title' => 'Чаша ' . $index . ' — 日本語 🏺 ' . str_repeat('é', 24),
            'sku' => sprintf('VOL-%04d', $index),
            'price' => $price,
            'quantity' => $quantity,
            'tax_rate' => 0,
            'tax_code' => '',
            'shipping_price' => 0,
            'product_type' => 'service',
            'variant_id' => 'finish-' . ($index % 8),
            'variant_label' => 'Глазурь ' . ($index % 8),
            'options' => ['finish' => '色-' . ($index % 8), 'edition' => 'Édition ' . ($index % 5)],
            'uid' => 'volume-' . $nonce . '-' . $index,
        ];
    }
    $expectedSubtotal = round($expectedSubtotal, 2);
    $largeJson = json_encode($largeItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $longNotes = str_repeat("Très longue note — 日本語 — 🏺 — line {$nonce}\n", 1500);
    $expect(strlen($largeJson) > 250000 && strlen($longNotes) > 65535, 'Large fixtures did not cross the intended storage boundaries.');
    $largeOrder = $commerce->orderRepository()->savePendingOrder([
        'first_name' => 'Volume',
        'last_name' => 'Boundary',
        'email' => $emailPrefix . 'large@example.test',
        'notes' => $longNotes,
        'payment_method' => 'demo',
        'payment_status' => MercatoPaymentStatus::PENDING,
        'payment_complete' => 0,
        'mrc_items' => $largeJson,
        'mrc_currency' => 'EUR',
        'mrc_subtotal_amount' => $expectedSubtotal,
        'mrc_shipping_amount' => 0,
        'mrc_discount_total' => 0,
        'mrc_tax_amount' => 0,
        'mrc_total_amount' => $expectedSubtotal,
        'fulfilment_method' => 'digital',
    ]);
    $createdIds[] = (int) $largeOrder->id;
    $largeOrder = $wire->pages->getById((int) $largeOrder->id, ['cache' => false])->first();
    $expect($largeOrder instanceof Page && $largeOrder->id, 'Large order did not reload.');
    $largeOrder->of(false);
    $storedJson = (string) $largeOrder->getUnformatted('mrc_items');
    $storedItems = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
    $expect(strlen($storedJson) === strlen($largeJson) && hash('sha256', $storedJson) === hash('sha256', $largeJson), 'Large item JSON was truncated or changed after reload.');
    $expect(count($storedItems) === 1200 && $storedItems[1199] === $largeItems[1199], 'Large item collection lost its final UTF-8 snapshot.');
    $expect((string) $largeOrder->getUnformatted('mrc_notes') === $longNotes, 'Long UTF-8 notes were truncated or changed after reload.');
    $largeOrder->of(true);
    $expect((string) $largeOrder->mrc_items === $largeJson && (string) $largeOrder->mrc_notes === $longNotes, 'Formatted large fields changed after reload.');
    $largeOrder->of(false);
    $expect(preg_match('//u', $storedJson) === 1 && preg_match('//u', (string) $largeOrder->mrc_notes) === 1, 'Large persisted content is not valid UTF-8.');
    $expect((float) $largeOrder->mrc_total_amount === $expectedSubtotal && $commerce->orderRepository()->getTotalAmount($largeOrder) === $expectedSubtotal, 'Large order total changed after reload.');
    $largeRoundTrip = $commerce->orderRepository()->pageToPendingData($largeOrder);
    $expect(($largeRoundTrip['mrc_items'] ?? '') === $largeJson && ($largeRoundTrip['notes'] ?? '') === $longNotes, 'Order repository projection corrupted large stored values.');
    $largeAnalytics = $commerce->analyticsService()->orderPayload($largeOrder, 'purchase');
    $expect(count($largeAnalytics['items'] ?? []) === 1200, 'Analytics projection dropped large-order line items.');
    $expect(($largeAnalytics['items'][0]['name'] ?? '') === $largeItems[0]['title'] && ($largeAnalytics['items'][1199]['sku'] ?? '') === $largeItems[1199]['sku'], 'Analytics projection corrupted large UTF-8 or terminal line-item data.');

    for ($index = 0; $index < 25; $index++) {
        $order = $commerce->orderRepository()->savePendingOrder([
            'email' => $emailPrefix . sprintf('page-%02d@example.test', $index),
            'payment_status' => MercatoPaymentStatus::PENDING,
            'payment_complete' => 0,
            'mrc_items' => '[]',
            'mrc_currency' => 'USD',
            'mrc_total_amount' => 0,
        ]);
        $createdIds[] = (int) $order->id;
    }

    $template = $wire->sanitizer->selectorValue((string) $commerce->order_template);
    $firstPage = $wire->pages->find("template=$template, include=all, mrc_email^=$selectorPrefix, sort=id, start=0, limit=25");
    $secondPage = $wire->pages->find("template=$template, include=all, mrc_email^=$selectorPrefix, sort=id, start=25, limit=25");
    $pagedIds = array_merge(array_map('intval', $firstPage->explode('id')), array_map('intval', $secondPage->explode('id')));
    $expectedIds = array_values(array_unique(array_map('intval', $createdIds)));
    sort($pagedIds);
    sort($expectedIds);
    $expect($firstPage->count() === 25 && $secondPage->count() === 2, 'Large fixture pagination did not cross the 25-row boundary as expected.');
    $expect($pagedIds === $expectedIds, 'Large fixture pagination duplicated or omitted persisted orders.');
} catch (\Throwable $error) {
    $failure = $error;
} finally {
    $cleanup();
}

if ($cleanupFailure instanceof \Throwable) throw $cleanupFailure;
if ($failure instanceof \Throwable) throw $failure;
$expect($findFixtures()->count() === 0, 'Data-volume cleanup residue check failed.');

printf(
    "Mercato empty/large data persistence tests passed: 27 orders, 1200 UTF-8 line items, %d JSON bytes, %d note bytes, exact pagination and cleanup.\n",
    strlen($largeJson),
    strlen($longNotes)
);
