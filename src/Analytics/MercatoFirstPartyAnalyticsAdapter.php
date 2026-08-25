<?php
namespace ProcessWire;

final class MercatoFirstPartyAnalyticsAdapter extends Wire implements MercatoAnalyticsAdapterInterface {
    public function getAnalyticsAdapterKey(): string { return 'first_party'; }
    public function getConsentCategory(): string { return 'analytics'; }
    public function dispatch(array $event): array { $log = new MercatoEventLog('mercato-analytics'); $log->setWire($this->wire()); $log->record(['event' => 'analytics_dispatched', 'status' => 'sent', 'adapter' => 'first_party', 'analytics_event' => $event], 'analytics_dispatched'); return ['accepted' => true]; }
}
