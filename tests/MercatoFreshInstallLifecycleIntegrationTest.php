<?php
namespace ProcessWire;

$site = realpath((string) getenv('MERCATO_FRESH_SITE'));
$guardToken = (string) getenv('MERCATO_FRESH_GUARD_TOKEN');
$confirmation = (string) getenv('MERCATO_FRESH_CONFIRM');
$requiredConfirmation = 'I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE';
if (!$site || $guardToken === '' || $confirmation !== $requiredConfirmation) {
    echo "Mercato fresh install lifecycle integration test skipped (use scripts/run-fresh-install.php).\n";
    exit(0);
}
if (!preg_match('#/(?:private/)?tmp/mercato-fresh-install-[a-f0-9]{12}$#D', $site)) {
    throw new \RuntimeException('Fresh-install site path failed the disposable ownership guard.');
}
if (!preg_match('/^[a-f0-9]{32}$/D', $guardToken)) {
    throw new \RuntimeException('Fresh-install database token failed the ownership guard.');
}

$_SERVER['HTTP_HOST'] = 'mercato-fresh.invalid';
$_SERVER['SERVER_NAME'] = 'mercato-fresh.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
if (!preg_match('/^mercato_codex_fresh_[a-f0-9]{12}$/D', (string) $config->dbName)) {
    throw new \RuntimeException('Fresh-install database name failed the disposable ownership guard.');
}
$wire = new ProcessWire($config);
$wire->set('page', $wire->pages->get('/'));
$superuser = $wire->users->get((int) $wire->config->superUserPageID);
if (!$superuser || !$superuser->id) throw new \RuntimeException('Fresh ProcessWire superuser was not created.');
$wire->users->setCurrentUser($superuser);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$modules = $wire->modules;
$database = $wire->database;
$guard = $database->query('SELECT token FROM mercato_fresh_install_guard LIMIT 1')->fetchColumn();
$expect(is_string($guard) && hash_equals($guardToken, $guard), 'Fresh database ownership marker is missing or mismatched.');

$expect(!$modules->isInstalled('Mercato'), 'Mercato was unexpectedly installed in the fresh ProcessWire database.');
$expect(!$modules->isInstalled('ProcessMercato'), 'ProcessMercato was unexpectedly installed in the fresh ProcessWire database.');
$preinstallTemplate = $wire->templates->get('mrc-product');
$preinstallField = $wire->fields->get('mrc_price');
$expect(!$preinstallTemplate || !(bool) $preinstallTemplate->id, 'Mercato product template existed before module installation.');
$expect(!$preinstallField || !(bool) $preinstallField->id, 'Mercato price field existed before module installation.');
$expect(!(bool) $wire->pages->get('/products/')->id, 'Mercato products page existed before module installation.');

$modules->refresh();
$moduleInfo = $modules->getModuleInfo('Mercato');
$expect((int) ($moduleInfo['version'] ?? 0) > 0, 'Mercato runtime was not discovered by the module refresh.');
$expect((bool) $modules->install('Mercato'), 'Mercato installation returned false.');
$expect($modules->isInstalled('Mercato'), 'Mercato installation state was not persisted.');
$expect($modules->isInstalled('ProcessMercato'), 'Mercato did not install its ProcessMercato companion.');

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
$expect($commerce instanceof Mercato, 'Freshly installed Mercato could not be loaded.');
$expect($commerce->getInstalledSchemaVersion() === Mercato::SCHEMA_VERSION, 'Fresh install did not reach the current Mercato schema version.');

$safeConfig = (array) $modules->getConfig('Mercato');
$safeConfig['production'] = false;
$safeConfig['enabled_payment_methods'] = ['demo'];
$safeConfig['push_notifications_enabled'] = false;
$safeConfig['analytics_enabled'] = false;
$safeConfig['notification_sender_email'] = '';
$modules->saveConfig('Mercato', $safeConfig);
$storedConfig = (array) $modules->getConfig('Mercato');
$expect(empty($storedConfig['production']), 'Disposable fresh install unexpectedly enabled production mode.');
$expect(($storedConfig['enabled_payment_methods'] ?? []) === ['demo'], 'Disposable fresh install did not persist the safe demo gateway configuration.');
$expect(empty($storedConfig['push_notifications_enabled']) && empty($storedConfig['analytics_enabled']), 'Disposable fresh install enabled an external-effect integration.');

$schemaIds = [];
foreach (['mrc-product', 'mrc-order', 'mrc-discount', 'mrc-collection'] as $name) {
    $schemaIds['template:' . $name] = (int) $wire->templates->get($name)->id;
    $expect($schemaIds['template:' . $name] > 0, "Fresh install is missing template: $name");
}
foreach (['mrc_price', 'mrc_payment_status', 'mrc_items', 'mrc_stock'] as $name) {
    $schemaIds['field:' . $name] = (int) $wire->fields->get($name)->id;
    $expect($schemaIds['field:' . $name] > 0, "Fresh install is missing field: $name");
}

$permissionIds = [];
$permissionNames = [
    'mercato-admin', 'mercato-view-orders', 'mercato-edit-orders', 'mercato-refund-orders',
    'mercato-create-manual-orders', 'mercato-view-quotes', 'mercato-manage-quotes',
    'mercato-manage-products', 'mercato-manage-inventory', 'mercato-fulfil-orders',
    'mercato-manage-notifications', 'mercato-view-customers', 'mercato-manage-customers',
    'mercato-manage-privacy', 'mercato-manage-recovery', 'mercato-view-reports',
    'mercato-manage-discounts', 'mercato-manage-webhooks', 'mercato-launch-tools',
];
foreach ($permissionNames as $name) {
    $permissionIds[$name] = (int) $wire->permissions->get($name)->id;
    $expect($permissionIds[$name] > 0, "Fresh install is missing permission: $name");
}
$rolePermissions = [
    'mercato-customer' => [],
    'mercato-support' => [
        'page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-edit-orders',
        'mercato-view-quotes', 'mercato-manage-quotes', 'mercato-view-customers',
        'mercato-manage-customers', 'mercato-manage-recovery',
    ],
    'mercato-fulfilment' => [
        'page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-fulfil-orders',
        'mercato-manage-inventory',
    ],
    'mercato-catalog' => [
        'page-edit', 'mercato-admin', 'mercato-manage-products', 'mercato-manage-inventory',
        'mercato-manage-discounts', 'mercato-view-reports',
    ],
    'mercato-manager' => array_merge(['page-edit'], $permissionNames),
];
foreach ($rolePermissions as $name => $expectedPermissions) {
    $role = $wire->roles->get($name);
    $expect((int) $role->id > 0, "Fresh install is missing role: $name");
    foreach (array_merge(['page-edit'], $permissionNames) as $permissionName) {
        $expect(
            (bool) $role->hasPermission($permissionName) === in_array($permissionName, $expectedPermissions, true),
            "Fresh install role permission mismatch: $name / $permissionName"
        );
    }
}

$pageCounts = [];
foreach (['mrc-product', 'mrc-collection', 'mrc-discount', 'mrc-order'] as $templateName) {
    $pageCounts[$templateName] = $wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all');
}
$expect($pageCounts['mrc-product'] > 0, 'Fresh install did not create demo products.');
$expect($pageCounts['mrc-collection'] > 0, 'Fresh install did not create demo collections.');
$expect($pageCounts['mrc-discount'] > 0, 'Fresh install did not create demo discounts.');
$expect((int) $wire->pages->get('/products/')->id > 0, 'Fresh install did not create /products/.');
$expect((int) $wire->pages->get('/collections/')->id > 0, 'Fresh install did not create /collections/.');
$expect((int) $wire->pages->get('/checkout/')->id > 0, 'Fresh install did not create /checkout/.');
$expect((int) $wire->pages->get('/checkout/success/')->id > 0, 'Fresh install did not create /checkout/success/.');

$firstProduct = $wire->pages->get('template=mrc-product, include=all, sort=id');
$expect((bool) ($firstProduct && $firstProduct->id), 'Fresh demo product lookup failed.');
$expect(trim((string) $firstProduct->title) !== '', 'Fresh demo product has no title.');
$expect((float) $firstProduct->mrc_price > 0, 'Fresh demo product has no positive price.');

$templateHashes = [];
foreach (['mrc-storefront.php', 'mrc-products.php', 'mrc-product.php', 'mrc-checkout.php', 'mrc-success.php'] as $name) {
    $path = $wire->config->paths->templates . $name;
    $expect(is_file($path), "Fresh install did not copy storefront template: $name");
    $templateHashes[$name] = hash_file('sha256', $path);
}

foreach (['mercato_mcp_operations', 'mercato_push_devices', 'mercato_push_deliveries'] as $tableName) {
    $statement = $database->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table');
    $statement->execute([':schema' => (string) $wire->config->dbName, ':table' => $tableName]);
    $expect((int) $statement->fetchColumn() === 1, "Fresh install did not create required table: $tableName");
}

$commerce->install();
$expect($commerce->getInstalledSchemaVersion() === Mercato::SCHEMA_VERSION, 'Idempotent installer repair changed the schema version.');
foreach ($pageCounts as $templateName => $count) {
    $expect($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') === $count, "Installer repair duplicated $templateName pages.");
}
foreach ($schemaIds as $key => $id) {
    [$type, $name] = explode(':', $key, 2);
    $item = $type === 'template' ? $wire->templates->get($name) : $wire->fields->get($name);
    $expect((int) $item->id === $id, "Installer repair replaced schema item: $name");
}

$expect((bool) $modules->uninstall('ProcessMercato'), 'ProcessMercato uninstall returned false on the disposable fresh site.');
$expect((bool) $modules->uninstall('Mercato'), 'Mercato uninstall returned false on the disposable fresh site.');
$installedModuleRows = $database->query("SELECT class FROM modules WHERE class IN ('Mercato', 'ProcessMercato') ORDER BY class")->fetchAll(\PDO::FETCH_COLUMN);
$expect($installedModuleRows === [], 'Fresh-site uninstall state was not persisted in the modules table: ' . json_encode($installedModuleRows));

// ProcessWire 3.0.200 removes namespaced module rows correctly, but can retain the
// module object in this request's WireArray because its object key includes the
// namespace. Clear only an already-uninstalled stale entry so reinstall is tested
// without weakening the authoritative persistence assertion above.
$runtimeInstalledModules = array_values(array_filter(
    ['Mercato', 'ProcessMercato'],
    static fn(string $class): bool => $modules->isInstalled($class)
));
foreach ($runtimeInstalledModules as $class) $modules->remove($class);
$expect(!$modules->isInstalled('Mercato') && !$modules->isInstalled('ProcessMercato'), 'Could not clear a stale post-uninstall module instance: ' . json_encode($runtimeInstalledModules));
foreach ($pageCounts as $templateName => $count) {
    $expect($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') === $count, "Uninstall changed preserved $templateName page count.");
}
foreach ($schemaIds as $key => $id) {
    [$type, $name] = explode(':', $key, 2);
    $item = $type === 'template' ? $wire->templates->get($name) : $wire->fields->get($name);
    $expect((int) $item->id === $id, "Uninstall removed or replaced preserved schema item: $name");
}
foreach ($permissionIds as $name => $id) {
    $expect((int) $wire->permissions->get($name)->id === $id, "Uninstall removed or replaced preserved permission: $name");
}
foreach ($templateHashes as $name => $hash) {
    $path = $wire->config->paths->templates . $name;
    $expect(is_file($path) && hash_file('sha256', $path) === $hash, "Uninstall removed or changed preserved storefront template: $name");
}

$expect((bool) $modules->install('Mercato'), 'Mercato reinstall returned false on the disposable fresh site.');
$expect($modules->isInstalled('Mercato') && $modules->isInstalled('ProcessMercato'), 'Mercato companion modules were not both installed after reinstall.');
$commerce = $modules->get('Mercato');
$expect($commerce instanceof Mercato, 'Reinstalled Mercato could not be loaded.');
$expect($commerce->getInstalledSchemaVersion() === Mercato::SCHEMA_VERSION, 'Reinstall did not restore the current schema version.');
foreach ($pageCounts as $templateName => $count) {
    $expect($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') === $count, "Reinstall duplicated or removed $templateName pages.");
}
foreach ($schemaIds as $key => $id) {
    [$type, $name] = explode(':', $key, 2);
    $item = $type === 'template' ? $wire->templates->get($name) : $wire->fields->get($name);
    $expect((int) $item->id === $id, "Reinstall replaced preserved schema item: $name");
}
$expect($database->query('SELECT token FROM mercato_fresh_install_guard LIMIT 1')->fetchColumn() === $guardToken, 'Lifecycle operations changed the database ownership marker.');

echo sprintf(
    "Mercato fresh empty-database install, demo, repair, uninstall preservation, and reinstall tests passed (module %d, schema %d, products %d, collections %d, discounts %d).\n",
    (int) ($moduleInfo['version'] ?? 0),
    Mercato::SCHEMA_VERSION,
    $pageCounts['mrc-product'],
    $pageCounts['mrc-collection'],
    $pageCounts['mrc-discount']
);
