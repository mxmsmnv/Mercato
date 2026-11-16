<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException("Could not read {$path}.");
    return $content;
};

$fieldtype = $read('FieldtypeMercatoVariants.module.php');
$inputfield = $read('InputfieldMercatoVariants.module.php');
$installer = $read('install/install.php');
$service = $read('src/Product/MercatoVariantService.php');
$panel = $read('src/Admin/ProcessMercatoProductPanels.php');
$actions = $read('src/Admin/ProcessMercatoProductActions.php');
$script = $read('assets/js/mercato-variants.js');

$expect(str_contains($fieldtype, 'class FieldtypeMercatoVariants extends Fieldtype'), 'Variant fieldtype module is missing.');
$expect(str_contains($fieldtype, "'data'] = 'MEDIUMTEXT NOT NULL'"), 'Variant fieldtype must use non-truncating storage.');
$expect(str_contains($fieldtype, 'variant_count') && str_contains($fieldtype, 'definition_hash'), 'Variant fieldtype index columns are incomplete.');
$expect(str_contains($fieldtype, 'MercatoVariantDefinition::validate'), 'Variant fieldtype must enforce the definition invariant on every persistence path.');
$expect(str_contains($inputfield, 'class InputfieldMercatoVariants extends Inputfield'), 'Variant inputfield module is missing.');
$expect(str_contains($inputfield, 'MercatoVariantDefinition::validate'), 'Inputfield must validate submitted definitions server-side.');
$expect(str_contains($installer, "'mrc_variant_definition', 'type' => 'FieldtypeMercatoVariants'"), 'Installer does not create the atomic variant field.');
$expect(str_contains($installer, 'mercato_migrate_variant_definitions'), 'Installer does not migrate legacy variant JSON.');
$expect(str_contains($installer, "fieldgroup->remove(\$legacy)"), 'Legacy editor fields are not detached after migration.');
$expect(str_contains($service, "hasField('mrc_variant_definition')"), 'Variant service does not prefer the atomic field.');
$expect(str_contains($service, "hasField('mrc_variant_options')"), 'Variant service lost the upgrade fallback.');
$expect(!str_contains($panel, 'name="variant_options_json"') && !str_contains($panel, 'name="variants_json"'), 'Product manager still exposes raw JSON textareas.');
$expect(str_contains($actions, "post('variant_definition_json')"), 'Product manager does not accept the structured definition.');
foreach (['mrc-add-option', 'mrc-add-value', 'mrc-generate-variants', 'mrc-add-variant', 'mrc-toggle-advanced'] as $control) {
    $expect(str_contains($script, $control), "Structured editor control {$control} is missing.");
}

echo "Mercato variant fieldtype contract tests passed.\n";
