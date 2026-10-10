<?php
namespace ProcessWire;

require_once __DIR__ . '/src/Pricing/MercatoCurrency.php';
require_once __DIR__ . '/src/Tax/MercatoTaxProviderInterface.php';
require_once __DIR__ . '/src/Tax/MercatoTaxProviderException.php';
require_once __DIR__ . '/src/Tax/Provider/MercatoStripeTaxProvider.php';

/** Optional bundled Stripe Tax provider. Install and configure separately from Mercato payments. */
final class MercatoStripeTax extends WireData implements Module, ConfigurableModule {
    private const API_VERSION = '2026-02-25.clover';
    public static function getModuleInfo(): array {
        return ['title' => 'Mercato Stripe Tax', 'summary' => 'Stripe Tax calculations and transaction lifecycle for Mercato.', 'version' => 160, 'author' => 'Maxim Semenov', 'singular' => true, 'autoload' => true, 'requires' => ['Mercato']];
    }

    public static function getDefaultConfig(): array { return ['enabled' => false, 'secret_key' => '', 'tax_code_map_json' => '{}', 'shipping_tax_code' => 'txcd_92010001', 'test_api_base_url' => '']; }
    public function setConfigData(array $data): void {
        $this->enabled = !empty($data['enabled']); $secret = trim((string) ($data['secret_key'] ?? '')); if ($secret !== '' || trim((string) $this->secret_key) === '') $this->secret_key = $secret;
        $decoded = json_decode((string) ($data['tax_code_map_json'] ?? '{}'), true);
        if (!is_array($decoded)) throw new WireException('Stripe Tax code map must be a JSON object.');
        $this->tax_code_map_json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $shipping = trim((string) ($data['shipping_tax_code'] ?? ''));
        $this->shipping_tax_code = preg_match('/^txcd_[0-9]{8}$/', $shipping) ? $shipping : '';
        $this->test_api_base_url = $this->normalizeTestBaseUrl((string) ($data['test_api_base_url'] ?? ''));
    }

    public function init(): void { $this->addHookAfter('Mercato::taxProviders', $this, 'registerProvider'); }
    public function registerProvider(HookEvent $event): void {
        if (!$this->enabled || trim((string) $this->secret_key) === '') return;
        $providers = is_array($event->return) ? $event->return : [];
        $request = fn(string $operation, array $payload, string $idempotencyKey): array => $this->requestStripe($operation, $payload, $idempotencyKey);
        $providers['stripe_tax'] = new MercatoStripeTaxProvider($request, [
            'tax_code_map' => json_decode((string) $this->tax_code_map_json, true) ?: [],
            'shipping_tax_code' => (string) $this->shipping_tax_code,
            'expected_livemode' => str_contains((string) $this->secret_key, '_live_'),
        ]);
        $event->return = $providers;
    }

    private function requestStripe(string $operation, array $payload, string $idempotencyKey): array {
        if (!function_exists('curl_init')) throw new WireException('PHP cURL is required for Stripe Tax.');
        $commerce = $this->wire('modules')->get('Mercato');
        $key = trim((string) $this->secret_key);
        $live = str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
        $test = str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_');
        if ((!empty($commerce->production) && !$live) || (empty($commerce->production) && !$test)) throw new WireException('Stripe Tax key does not match Mercato live/test mode.');
        $path = ['calculate' => '/v1/tax/calculations', 'commit' => '/v1/tax/transactions/create_from_calculation', 'reverse' => '/v1/tax/transactions/create_reversal'][$operation] ?? '';
        if ($path === '') throw new WireException('Unsupported Stripe Tax operation.');
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'Stripe-Version: ' . self::API_VERSION];
        if ($idempotencyKey !== '') $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 255);
        $timeout = max(1, min(30, (int) ($commerce->tax_provider_timeout_seconds ?? 5)));
        $baseUrl = $this->testBaseUrl($commerce) ?: 'https://api.stripe.com';
        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986), CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0]);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $mutation = $operation !== 'calculate';
        if ($body === false) throw new MercatoTaxProviderException('Stripe Tax request failed: ' . substr($error, 0, 300), true, 502, null, $mutation);
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) throw new MercatoTaxProviderException('Stripe Tax returned an unreadable response.', true, 502, null, $mutation);
        if ($status < 200 || $status >= 300) throw new MercatoTaxProviderException('Stripe Tax API error ' . $status . ': ' . substr((string) ($decoded['error']['message'] ?? 'request failed'), 0, 300), $status === 429 || $status >= 500, $status, null, $mutation && $status >= 500);
        return $decoded;
    }

    private function normalizeTestBaseUrl(string $value): string {
        $value = rtrim(trim($value), '/');
        if ($value === '') return '';
        return preg_match('#^http://(?:127\.0\.0\.1|\[::1\]|localhost):[1-9][0-9]{0,4}$#i', $value) ? $value : '';
    }
    private function testBaseUrl(Mercato $commerce): string {
        $url = $this->normalizeTestBaseUrl((string) ($this->test_api_base_url ?? ''));
        if ($url !== '' && !empty($commerce->production)) throw new WireException('Stripe Tax loopback transport is forbidden in production mode.');
        return $url;
    }

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
        $data = array_merge(self::getDefaultConfig(), $data); $modules = wire('modules'); $wrapper = new InputfieldWrapper();
        $enabled = $modules->get('InputfieldCheckbox'); $enabled->name = 'enabled'; $enabled->label = __('Enable Stripe Tax provider'); $enabled->description = __('Use a Stripe test-mode key until the tax setup, registrations, calculations, commits, and reversals have been verified.'); $enabled->checked = !empty($data['enabled']); $wrapper->add($enabled);
        $key = $modules->get('InputfieldPassword'); $key->name = 'secret_key'; $key->label = __('Stripe secret key'); $key->description = __('Stored in ProcessWire module configuration and never written to Mercato tax logs. Leave blank to keep the saved key.'); $key->value = ''; $wrapper->add($key);
        $map = $modules->get('InputfieldTextarea'); $map->name = 'tax_code_map_json'; $map->label = __('Mercato to Stripe tax code map (JSON)'); $map->description = __('Map product tax codes to Stripe txcd_######## values. Unmapped non-Stripe codes use the Stripe account default.'); $map->value = (string) ($data['tax_code_map_json'] ?? '{}'); $wrapper->add($map);
        $shipping = $modules->get('InputfieldText'); $shipping->name = 'shipping_tax_code'; $shipping->label = __('Shipping tax code'); $shipping->value = (string) ($data['shipping_tax_code'] ?? 'txcd_92010001'); $wrapper->add($shipping);
        return $wrapper;
    }
}
