<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site || getenv('MERCATO_TEST_INSTALL_LIFECYCLE') !== '1') {
    echo "Mercato install lifecycle integration test skipped (set MERCATO_TEST_SITE and MERCATO_TEST_INSTALL_LIFECYCLE=1).\n";
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
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));
$modules = $wire->modules;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};

$expect($modules->isInstalled('Mercato'), 'Mercato must be installed before the lifecycle test.');
$expect($modules->isInstalled('ProcessMercato'), 'ProcessMercato must be installed before the lifecycle test.');

$mercatoConfig = (array) $modules->getConfig('Mercato');
$processConfig = (array) $modules->getConfig('ProcessMercato');
$pageCounts = [];
foreach (['mrc-product', 'mrc-order', 'mrc-discount', 'mrc-collection'] as $templateName) {
    $pageCounts[$templateName] = $wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all');
}
$schemaIds = [];
foreach (['mrc-product', 'mrc-order', 'mrc_price', 'mrc_payment_status'] as $name) {
    $item = str_starts_with($name, 'mrc-') ? $wire->templates->get($name) : $wire->fields->get($name);
    $schemaIds[$name] = (int) ($item->id ?? 0);
    $expect($schemaIds[$name] > 0, "Required schema item is missing before uninstall: $name");
}
$permissionIds = [];
foreach (['mercato-admin', 'mercato-view-orders', 'mercato-edit-orders', 'mercato-manage-products', 'mercato-launch-tools'] as $name) {
    $permissionIds[$name] = (int) $wire->permissions->get($name)->id;
    $expect($permissionIds[$name] > 0, "Required permission is missing before uninstall: $name");
}
$roleMatrix = [
    'mercato-customer' => [[], ['mercato-admin', 'mercato-view-orders']],
    'mercato-support' => [['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-edit-orders', 'mercato-view-customers'], ['mercato-refund-orders', 'mercato-manage-products', 'mercato-launch-tools']],
    'mercato-fulfilment' => [['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-fulfil-orders', 'mercato-manage-inventory'], ['mercato-edit-orders', 'mercato-refund-orders']],
    'mercato-catalog' => [['page-edit', 'mercato-admin', 'mercato-manage-products', 'mercato-manage-inventory', 'mercato-manage-discounts', 'mercato-view-reports'], ['mercato-view-orders', 'mercato-refund-orders']],
    'mercato-manager' => [array_merge(['page-edit'], array_keys($permissionIds)), []],
];
foreach ($roleMatrix as $roleName => [$granted, $denied]) {
    $role = $wire->roles->get($roleName);
    $expect((bool) ($role && $role->id), "Required role is missing: $roleName");
    foreach ($granted as $permission) $expect($role->hasPermission($permission), "$roleName lacks expected permission: $permission");
    foreach ($denied as $permission) $expect(!$role->hasPermission($permission), "$roleName unexpectedly has permission: $permission");
}
$templateHashes = [];
foreach (['mrc-storefront.php', 'mrc-product.php', 'mrc-checkout.php', 'mrc-success.php'] as $name) {
    $path = $wire->config->paths->templates . $name;
    $expect(is_file($path), "Installed storefront template is missing before uninstall: $name");
    $templateHashes[$name] = hash_file('sha256', $path);
}

$failure = null;
try {
    $expect($modules->uninstall('ProcessMercato'), 'ProcessMercato uninstall returned false.');
    $expect($modules->uninstall('Mercato'), 'Mercato uninstall returned false.');
    $expect(!$modules->isInstalled('Mercato') && !$modules->isInstalled('ProcessMercato'), 'Module uninstall state was not persisted.');

    foreach ($pageCounts as $templateName => $count) {
        $expect($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') === $count, "Uninstall changed $templateName page count.");
    }
    foreach ($schemaIds as $name => $id) {
        $item = str_starts_with($name, 'mrc-') ? $wire->templates->get($name) : $wire->fields->get($name);
        $expect((int) ($item->id ?? 0) === $id, "Uninstall removed or replaced schema item: $name");
    }
    foreach ($permissionIds as $name => $id) {
        $expect((int) $wire->permissions->get($name)->id === $id, "Uninstall removed or replaced permission: $name");
    }
    foreach ($templateHashes as $name => $hash) {
        $path = $wire->config->paths->templates . $name;
        $expect(is_file($path) && hash_file('sha256', $path) === $hash, "Uninstall removed or overwrote storefront template: $name");
    }

    $expect((bool) $modules->install('Mercato'), 'Mercato reinstall returned false.');
    if (!$modules->isInstalled('ProcessMercato')) {
        $expect((bool) $modules->install('ProcessMercato'), 'ProcessMercato reinstall returned false.');
    }
    $expect($modules->isInstalled('Mercato') && $modules->isInstalled('ProcessMercato'), 'Module reinstall state was not persisted.');

    /** @var Mercato $commerce */
    $commerce = $modules->get('Mercato');
    $expect($commerce instanceof Mercato, 'Reinstalled Mercato module could not be loaded.');
    $expect($commerce->getInstalledSchemaVersion() === Mercato::SCHEMA_VERSION, 'Reinstall did not repair the current schema version.');
    foreach ($pageCounts as $templateName => $count) {
        $expect($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') === $count, "Reinstall changed $templateName page count.");
    }
} catch (\Throwable $error) {
    $failure = $error;
} finally {
    if (!$modules->isInstalled('Mercato')) $modules->install('Mercato');
    if (!$modules->isInstalled('ProcessMercato')) $modules->install('ProcessMercato');
    $modules->saveConfig('Mercato', $mercatoConfig);
    $modules->saveConfig('ProcessMercato', $processConfig);
}

if ($failure) throw $failure;
$expect((array) $modules->getConfig('Mercato') === $mercatoConfig, 'Mercato configuration was not restored exactly after reinstall.');
$expect((array) $modules->getConfig('ProcessMercato') === $processConfig, 'ProcessMercato configuration was not restored exactly after reinstall.');
echo "Mercato uninstall preservation and reinstall lifecycle tests passed.\n";
