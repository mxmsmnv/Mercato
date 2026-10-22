<?php
require_once __DIR__ . '/../src/Api/MercatoPublicUrlResolver.php';

use ProcessWire\MercatoPublicUrlResolver;

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$server = [
    'HTTP_HOST' => 'mercato.test',
    'HTTP_X_FORWARDED_HOST' => 'shop.example.com',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTPS' => 'on',
];
$allowed = ['mercato.test', 'shop.example.com'];

$expect(
    MercatoPublicUrlResolver::origin('https://mercato.test', $server, $allowed) === 'https://shop.example.com',
    'Trusted forwarded origin was not resolved.'
);
$expect(
    MercatoPublicUrlResolver::resolve('https://mercato.test/privacy-policy/?source=app#terms', 'https://mercato.test', $server, $allowed) === 'https://shop.example.com/privacy-policy/?source=app#terms',
    'Local policy URL was not rewritten to the public tunnel origin.'
);
$expect(
    MercatoPublicUrlResolver::resolve('/api/mercato/v1/store', 'https://mercato.test', $server, $allowed) === 'https://shop.example.com/api/mercato/v1/store',
    'Relative discovery URL was not made absolute.'
);
$expect(
    MercatoPublicUrlResolver::resolve('https://payments.example.net/return', 'https://mercato.test', $server, $allowed) === 'https://payments.example.net/return',
    'Unrelated external provider URL was rewritten.'
);

$untrusted = $server;
$untrusted['HTTP_X_FORWARDED_HOST'] = 'attacker.example';
$expect(
    MercatoPublicUrlResolver::origin('https://mercato.test', $untrusted, $allowed) === '',
    'Untrusted forwarded host was accepted.'
);
$expect(
    MercatoPublicUrlResolver::resolve('https://mercato.test/privacy-policy/', 'https://mercato.test', $untrusted, $allowed) === 'https://mercato.test/privacy-policy/',
    'Untrusted forwarded host changed a public URL.'
);
$expect(
    MercatoPublicUrlResolver::origin('https://mercato.test', $untrusted, []) === '',
    'An empty httpHosts list accepted an arbitrary forwarded host.'
);
$localRequest = ['HTTP_HOST' => 'mercato.test', 'HTTPS' => 'on'];
$expect(
    MercatoPublicUrlResolver::origin('https://mercato.test', $localRequest, []) === 'https://mercato.test',
    'The configured root host was not retained as the safe empty-list fallback.'
);

$firstId = MercatoPublicUrlResolver::storeId('site-secret-one');
$expect((bool) preg_match('/^mrc_[A-Za-z0-9_-]{24}$/', $firstId), 'Store identity has an invalid public format.');
$expect($firstId === MercatoPublicUrlResolver::storeId('site-secret-one'), 'Store identity is not deterministic.');
$expect($firstId !== MercatoPublicUrlResolver::storeId('site-secret-two'), 'Different site secrets produced the same store identity.');

$module = file_get_contents(__DIR__ . '/../Mercato.module.php') ?: '';
$endpoints = file_get_contents(__DIR__ . '/../src/MercatoPublicEndpoints.php') ?: '';
$service = file_get_contents(__DIR__ . '/../src/Api/MercatoHeadlessApiService.php') ?: '';
$expect(str_contains($module, "'/.well-known/mercato/?'"), 'Extensionless mobile discovery route is not registered.');
$expect(str_contains($module, "'/.well-known/mercato.json/?'"), 'JSON mobile discovery compatibility route is not registered.');
$expect(str_contains($endpoints, 'handleMercatoDiscovery'), 'Mobile discovery HTTP handler is missing.');
$expect(str_contains($service, "resource==='discovery'"), 'Versioned API discovery route is missing.');
$expect(str_contains($service, "'store_id'=>"), 'Discovery payload omits store identity.');
$expect(str_contains($service, "'identity'=>"), 'Store payload omits additive identity metadata.');
$expect(str_contains($service, '$this->publicUrl((string)$page->httpUrl())'), 'Policy URLs do not use the public-origin resolver.');

echo "Mercato mobile discovery contract tests passed: {$checks} assertions.\n";
