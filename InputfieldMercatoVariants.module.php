<?php namespace ProcessWire;

require_once __DIR__ . '/src/Product/MercatoVariantDefinition.php';
require_once __DIR__ . '/src/Product/MercatoVariantsValue.php';

/**
 * Structured editor for Mercato option groups and valid combinations.
 *
 * JavaScript owns presentation only. The complete definition is submitted as
 * one JSON value and is normalized and validated again on the server.
 */
class InputfieldMercatoVariants extends Inputfield {

    protected ?Page $page = null;
    protected ?Field $field = null;

    public static function getModuleInfo(): array {
        return [
            'title' => 'Mercato Variants Inputfield',
            'summary' => 'Structured product option and variant editor for Mercato.',
            'author' => 'Maxim Semenov',
            'href' => 'https://smnv.org',
            'version' => 190,
            'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0'],
            'icon' => 'cubes',
        ];
    }

    public function setPage(Page $page): void { $this->page = $page; }
    public function setField(Field $field): void { $this->field = $field; }

    public function init(): void {
        parent::init();
        $base = $this->wire('config')->urls->Mercato;
        $this->wire('config')->styles->add($base . 'assets/css/mercato-variants.css');
        $this->wire('config')->scripts->add($base . 'assets/js/mercato-variants.js');
    }

    public function ___render(): string {
        $value = $this->value;
        if (!$value instanceof MercatoVariantsValue) {
            $value = new MercatoVariantsValue(is_array($value) ? $value : []);
        }
        $stored = $value->toArray();
        $definition = MercatoVariantDefinition::normalize($stored['options'], $stored['variants']);
        $name = $this->wire('sanitizer')->entities((string) $this->attr('name'));
        $id = $this->wire('sanitizer')->entities((string) ($this->attr('id') ?: 'mrc-variants-' . mt_rand()));
        $json = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"options":[],"variants":[]}';
        $encoded = $this->wire('sanitizer')->entities($json);
        $baseSku = $this->page && $this->page->hasField('mrc_sku') ? (string) $this->page->mrc_sku : '';
        $basePrice = $this->page && $this->page->hasField('mrc_price') ? (string) $this->page->mrc_price : '';

        return '<div class="mrc-variant-editor" id="' . $id . '" data-base-sku="' . $this->wire('sanitizer')->entities($baseSku) . '" data-base-price="' . $this->wire('sanitizer')->entities($basePrice) . '">'
            . '<input type="hidden" class="mrc-variant-value" name="' . $name . '" value="' . $encoded . '">'
            . '<div class="mrc-variant-intro"><div><strong>' . $this->_('Product options and variants') . '</strong><p>'
            . $this->_('Create customer-facing option groups first, then generate or add only the combinations that can actually be purchased.')
            . '</p></div><div class="mrc-variant-summary" aria-live="polite"></div></div>'
            . '<section class="mrc-variant-section" aria-labelledby="' . $id . '-options"><div class="mrc-variant-heading"><div><h3 id="' . $id . '-options">'
            . $this->_('Option groups') . '</h3><p>' . $this->_('Identifiers are stable API keys. Labels are customer-facing and may be changed later.')
            . '</p></div><button type="button" class="ui-button ui-button-secondary mrc-add-option"><i class="fa fa-plus" aria-hidden="true"></i> '
            . $this->_('Add option group') . '</button></div><div class="mrc-option-list"></div></section>'
            . '<section class="mrc-variant-section" aria-labelledby="' . $id . '-variants"><div class="mrc-variant-heading"><div><h3 id="' . $id . '-variants">'
            . $this->_('Purchasable combinations') . '</h3><p>' . $this->_('Each row has its own SKU, price, stock policy, availability, measurements, and images.')
            . '</p></div><div class="mrc-variant-actions"><button type="button" class="ui-button ui-button-secondary mrc-generate-variants"><i class="fa fa-magic" aria-hidden="true"></i> '
            . $this->_('Generate combinations') . '</button><button type="button" class="ui-button ui-button-secondary mrc-add-variant"><i class="fa fa-plus" aria-hidden="true"></i> '
            . $this->_('Add variant') . '</button></div></div><div class="mrc-variant-table-wrap" tabindex="0"><table class="AdminDataTable mrc-variant-table"><thead></thead><tbody></tbody></table></div></section>'
            . '<div class="mrc-variant-errors" role="alert" hidden></div>'
            . '<noscript><p class="error">' . $this->_('JavaScript is required to edit product variants.') . '</p></noscript></div>';
    }

    public function ___processInput(WireInputData $input): self {
        $name = (string) $this->attr('name');
        if (!isset($input[$name])) return $this;
        $raw = (string) $input[$name];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->error($this->_('The variant definition could not be read. Reload the page and try again.'));
            return $this;
        }
        $result = MercatoVariantDefinition::validate(
            is_array($decoded['options'] ?? null) ? $decoded['options'] : [],
            is_array($decoded['variants'] ?? null) ? $decoded['variants'] : []
        );
        if (!$result['valid']) {
            $this->setAttribute('value', new MercatoVariantsValue([
                'options' => $result['options'],
                'variants' => $result['variants'],
            ]));
            foreach ($result['errors'] as $error) $this->error((string) $error);
            return $this;
        }
        $value = new MercatoVariantsValue(['options' => $result['options'], 'variants' => $result['variants']]);
        $this->setAttribute('value', $value);
        if ($this->page && $this->field && $this->page->hasField($this->field->name)) {
            $this->page->set($this->field->name, $value);
            $this->page->trackChange($this->field->name);
        }
        $this->trackChange('value');
        return $this;
    }

    public function isEmpty(): bool {
        return $this->value instanceof MercatoVariantsValue ? $this->value->isEmpty() : true;
    }
}
