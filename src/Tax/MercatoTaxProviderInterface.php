<?php
namespace ProcessWire;

/** Provider-neutral address tax lifecycle. Provider modules implement this interface. */
interface MercatoTaxProviderInterface {
    public function getTaxProviderKey(): string;
    public function estimate(array $context): array;
    public function commit(array $context): array;
    public function refund(array $context): array;
    public function void(array $context): array;
}
