<?php
namespace ProcessWire;

interface MercatoAnalyticsAdapterInterface {
    public function getAnalyticsAdapterKey(): string;
    public function getConsentCategory(): string;
    /** @return array{accepted:bool,message?:string,external_id?:string} */
    public function dispatch(array $event): array;
}
