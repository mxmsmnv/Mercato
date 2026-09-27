<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$inventory = require __DIR__ . '/fixtures/MercatoVisualStateInventory.php';
$fixture = (string) file_get_contents(__DIR__ . '/e2e/admin-visual-breadth-fixtures.php');
$spec = (string) file_get_contents(__DIR__ . '/e2e/admin-visual-breadth.spec.js');
$lifecycle = (string) file_get_contents(__DIR__ . '/e2e/admin-visual-disposable-lifecycle.php');
$router = (string) file_get_contents(__DIR__ . '/e2e/admin-visual-disposable-router.php');
$freshRunner = (string) file_get_contents($root . '/scripts/run-fresh-install.php');
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$htmlRoutes = [];
foreach ($inventory['screens'] as $id => $screen) {
    if (($screen['surface'] ?? '') !== 'admin') continue;
    $route = str_replace('_', '-', substr($id, strlen('admin.')));
    $htmlRoutes[$route] = $screen;
}
$expect(count($htmlRoutes) === 23, 'Expected 22 ProcessMercato HTML routes plus module settings.');
foreach ($htmlRoutes as $route => $screen) {
    $states = $screen['states'] ?? [];
    $expect(($states['loading']['status'] ?? '') === 'not_applicable', "{$route} must explicitly classify its synchronous loading state as N/A.");
    if ($route === 'module-settings') {
        $expect(str_contains($fixture, "\$state['settings_url']"), 'The fixture does not expose the module settings URL.');
        $expect(str_contains($spec, 'module-settings'), 'The spec does not capture module settings.');
        continue;
    }
    $literal = "'id' => '" . $route . "'";
    $expect(str_contains($fixture, $literal), "The fixture does not expose admin route {$route}.");
    $expect(str_contains($spec, "'" . $route . "'"), "The spec does not inventory admin route {$route}.");
}

foreach ([
    "MERCATO_ADMIN_VISUAL_EMPTY_ISOLATED",
    "MERCATO_FRESH_CONFIRM",
    "mercato_codex_fresh_",
    "mercato_fresh_install_guard",
    "MERCATO_ADMIN_VISUAL_SHARED_SITE",
    "forbidden in production mode",
    "Refusing admin visual cleanup without a valid run id",
    "register_shutdown_function",
    "admin visual fixture state file",
] as $guard) $expect(str_contains($fixture, $guard), "Admin visual fixture lost safety guard: {$guard}.");
foreach ([
    'preflight', 'teardown', 'DROP_ADMIN_VISUAL_DISPOSABLE_SITE', 'mercato_fresh_install_guard',
    'MERCATO_ADMIN_VISUAL_SHARED_SITE', 'Fixture state still exists', 'DROP DATABASE',
    'Disposable admin visual site root still exists after teardown',
] as $guard) $expect(str_contains($lifecycle, $guard), "Admin visual disposable lifecycle lost safety guard: {$guard}.");
foreach (['mercato-fresh-install-', 'ownership guard failed', "return false", "require \$site . '/index.php'"] as $guard) {
    $expect(str_contains($router, $guard), "Admin visual disposable router lost safety guard: {$guard}.");
}
foreach (['MERCATO_FRESH_ADMIN_VISUAL', 'runFreshAdminVisualProfile', 'admin-visual-disposable-lifecycle.php', 'admin-visual-breadth-fixtures.php', 'admin-visual-disposable-router.php'] as $guard) {
    $expect(str_contains($freshRunner, $guard), "Fresh-install runner lost empty-admin visual integration: {$guard}.");
}
foreach (['assertAccessible', 'assertResponsive', 'aria-live', 'long_content', 'translated', 'large_data'] as $contract) {
    $haystack = in_array($contract, ['long_content', 'translated', 'large_data'], true) ? serialize($inventory) : $spec;
    $expect(str_contains($haystack, $contract), "Admin visual evidence support lost {$contract}.");
}

echo "Mercato admin visual breadth contract passed: 22 ProcessMercato HTML routes + module settings, synchronous loading N/A, isolated empty guard, normal/error/long/translated/large/responsive support.\n";
