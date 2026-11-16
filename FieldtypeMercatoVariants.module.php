<?php namespace ProcessWire;

require_once __DIR__ . '/src/Product/MercatoVariantsValue.php';
require_once __DIR__ . '/src/Product/MercatoVariantDefinition.php';

/**
 * Stores one validated Mercato option/variant definition per product page.
 */
class FieldtypeMercatoVariants extends Fieldtype {

    public static function getModuleInfo(): array {
        return [
            'title' => 'Mercato Variants',
            'summary' => 'Stores product option groups and valid variant combinations.',
            'author' => 'Maxim Semenov',
            'href' => 'https://smnv.org',
            'version' => 190,
            'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0', 'InputfieldMercatoVariants'],
            'icon' => 'cubes',
        ];
    }

    public function getBlankValue(Page $page, Field $field): MercatoVariantsValue {
        return new MercatoVariantsValue();
    }

    public function sanitizeValue(Page $page, Field $field, $value): MercatoVariantsValue {
        if ($value instanceof MercatoVariantsValue) return $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        return new MercatoVariantsValue(is_array($value) ? $value : []);
    }

    public function ___wakeupValue(Page $page, Field $field, $value): MercatoVariantsValue {
        $stored = is_array($value) ? (string) ($value['data'] ?? '') : (string) $value;
        $decoded = $stored === '' ? [] : json_decode($stored, true);
        return new MercatoVariantsValue(is_array($decoded) ? $decoded : []);
    }

    public function ___sleepValue(Page $page, Field $field, $value): array {
        $value = $this->sanitizeValue($page, $field, $value);
        $submitted = $value->toArray();
        $result = MercatoVariantDefinition::validate($submitted['options'], $submitted['variants']);
        if (!$result['valid']) {
            throw new WireException('Invalid Mercato variant definition: ' . implode(' ', $result['errors']));
        }
        $definition = ['options' => $result['options'], 'variants' => $result['variants']];
        $active = 0;
        foreach ($definition['variants'] as $variant) {
            if (is_array($variant) && (string) ($variant['status'] ?? 'active') === 'active') $active++;
        }
        return [
            'data' => json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"options":[],"variants":[]}',
            'variant_count' => count($definition['variants']),
            'active_count' => $active,
            'definition_hash' => sha1(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    public function ___loadPageField(Page $page, Field $field): mixed {
        $table = $field->getTable();
        $stmt = $this->wire('database')->prepare("SELECT * FROM `$table` WHERE pages_id=:id");
        $stmt->execute([':id' => (int) $page->id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function ___savePageField(Page $page, Field $field): bool {
        $value = $this->sanitizeValue($page, $field, $page->get($field->name));
        $data = $this->sleepValue($page, $field, $value);
        $table = $field->getTable();
        $stmt = $this->wire('database')->prepare(
            "INSERT INTO `$table` (`pages_id`,`data`,`variant_count`,`active_count`,`definition_hash`)
             VALUES (:pages_id,:data,:variant_count,:active_count,:definition_hash)
             ON DUPLICATE KEY UPDATE `data`=VALUES(`data`),`variant_count`=VALUES(`variant_count`),
             `active_count`=VALUES(`active_count`),`definition_hash`=VALUES(`definition_hash`)"
        );
        return $stmt->execute([':pages_id' => (int) $page->id] + $data);
    }

    public function ___deletePageField(Page $page, Field $field): bool {
        $stmt = $this->wire('database')->prepare("DELETE FROM `{$field->getTable()}` WHERE pages_id=:id");
        return $stmt->execute([':id' => (int) $page->id]);
    }

    public function getDatabaseSchema(Field $field): array {
        $schema = parent::getDatabaseSchema($field);
        $schema['data'] = 'MEDIUMTEXT NOT NULL';
        $schema['variant_count'] = 'INT UNSIGNED NOT NULL DEFAULT 0';
        $schema['active_count'] = 'INT UNSIGNED NOT NULL DEFAULT 0';
        $schema['definition_hash'] = "CHAR(40) NOT NULL DEFAULT ''";
        unset($schema['keys']['data']);
        $schema['keys']['variant_count'] = 'KEY `variant_count` (`variant_count`)';
        $schema['keys']['definition_hash'] = 'KEY `definition_hash` (`definition_hash`)';
        return $schema;
    }

    public function getInputfield(Page $page, Field $field): InputfieldMercatoVariants {
        /** @var InputfieldMercatoVariants $input */
        $input = $this->wire('modules')->get('InputfieldMercatoVariants');
        $input->setPage($page);
        $input->setField($field);
        return $input;
    }

    public function getCompatibleFieldtypes(Field $field): ?Fieldtypes {
        return null;
    }
}
