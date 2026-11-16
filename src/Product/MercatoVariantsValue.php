<?php
namespace ProcessWire;

/**
 * Typed page-field value for a complete product variant definition.
 *
 * Option groups and their valid combinations deliberately travel together so
 * an editor cannot save one half of the definition independently of the other.
 */
final class MercatoVariantsValue extends WireData implements \Countable, \JsonSerializable {

    public function __construct(array $definition = []) {
        parent::__construct();
        $this->setDefinition($definition);
    }

    public function setDefinition(array $definition): self {
        $this->set('options', array_values(is_array($definition['options'] ?? null) ? $definition['options'] : []));
        $this->set('variants', array_values(is_array($definition['variants'] ?? null) ? $definition['variants'] : []));
        return $this;
    }

    public function toArray(): array {
        return [
            'options' => array_values((array) $this->get('options')),
            'variants' => array_values((array) $this->get('variants')),
        ];
    }

    public function isEmpty(): bool {
        $definition = $this->toArray();
        return $definition['options'] === [] && $definition['variants'] === [];
    }

    public function count(): int {
        return count((array) $this->get('variants'));
    }

    public function jsonSerialize(): array {
        return $this->toArray();
    }

    public function __toString(): string {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"options":[],"variants":[]}';
    }
}
