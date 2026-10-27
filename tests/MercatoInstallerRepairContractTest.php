<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = (string) file_get_contents($root . '/src/MercatoConfigInputfields.php');
$installer = (string) file_get_contents($root . '/install/install.php');
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$expectedRequire = "require_once dirname(__DIR__) . '/install/install.php';";
$expect(str_contains($config, $expectedRequire), 'The module-config repair action must resolve install/install.php from the repository root.');
$expect(is_file($root . '/install/install.php'), 'The repair action target does not exist.');
$expect(!str_contains($config, "require_once __DIR__ . '/install/install.php';"), 'The broken src/install/install.php repair path is still present.');
$expect(str_contains($config, 'mercato_install($module, $overwrite, $data);'), 'The repair action must pass ProcessWire\'s authoritative normalized config data to the installer.');
$expect(str_contains($installer, "\$wire->modules->getConfig('Mercato')"), 'Installer repair must fall back to persisted module configuration instead of a possibly stale module instance.');
$expect(str_contains($installer, "array_key_exists('shipping_dimensions_enabled', \$effectiveConfig)"), 'Installer repair must use explicitly supplied normalized dimensions configuration.');
$expect(str_contains($installer, "(\$effectiveConfig['shipping_dimensions_field'] ?? '')"), 'Installer repair must use the explicitly supplied normalized dimensions field name.');
$expect(str_contains($installer, "post('shipping_dimensions_enabled')"), 'Installer repair must honor the submitted dimensions toggle before module config persistence.');
$expect(str_contains($installer, "post('shipping_dimensions_field')"), 'Installer repair must honor the submitted dimensions field name before module config persistence.');
$expect(str_contains($installer, "isInstalled('FieldtypeDimensions')"), 'Installer repair must feature-detect FieldtypeDimensions.');
$expect(str_contains($installer, 'FieldtypeDimensions is not installed or could not be loaded.'), 'An enabled but unavailable dimensions dependency must produce an explicit diagnostic.');
$expect(str_contains($installer, '$wire->fields->save($f);'), 'Installer repair no longer persists newly created fields.');
$expect(str_contains($installer, '$productTemplate->fieldgroup->add($dimensionsField);'), 'Installer repair no longer attaches the dimensions field to products.');

echo "Mercato installer repair contract passed ({$checks} checks).\n";
