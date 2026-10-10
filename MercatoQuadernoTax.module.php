<?php
namespace ProcessWire;

require_once __DIR__ . '/src/Pricing/MercatoCurrency.php';
require_once __DIR__ . '/src/Tax/MercatoTaxProviderInterface.php';
require_once __DIR__ . '/src/Tax/MercatoTaxProviderException.php';
require_once __DIR__ . '/src/Tax/Provider/MercatoQuadernoTaxProvider.php';

/** Optional bundled Quaderno tax provider with explicit sandbox/live isolation. */
final class MercatoQuadernoTax extends WireData implements Module, ConfigurableModule {
    private const API_VERSION = '20241028';
    public static function getModuleInfo(): array {
        return ['title' => 'Mercato Quaderno Tax', 'summary' => 'Global Quaderno tax calculations and document lifecycle for Mercato.', 'version' => 160, 'author' => 'Maxim Semenov', 'singular' => true, 'autoload' => true, 'requires' => ['Mercato']];
    }
    public static function getDefaultConfig(): array { return ['enabled' => false, 'environment' => 'sandbox', 'account_slug' => '', 'sandbox_api_key' => '', 'live_api_key' => '', 'tax_code_map_json' => '{"general":"standard"}', 'product_type_map_json' => '{"physical":"good","digital":"service","service":"service","recurring":"service"}', 'shipping_tax_code' => 'standard', 'test_api_base_url' => '']; }
    public function setConfigData(array $data): void {
        $this->enabled = !empty($data['enabled']); $this->environment = (string) ($data['environment'] ?? '') === 'live' ? 'live' : 'sandbox';
        $slug = strtolower(trim((string) ($data['account_slug'] ?? ''))); $this->account_slug = preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) ? $slug : '';
        foreach (['sandbox_api_key', 'live_api_key'] as $keyField) { $incoming = trim((string) ($data[$keyField] ?? '')); if ($incoming !== '' || trim((string) $this->$keyField) === '') $this->$keyField = $incoming; }
        foreach (['tax_code_map_json', 'product_type_map_json'] as $field) { $decoded = json_decode((string) ($data[$field] ?? '{}'), true); if (!is_array($decoded)) throw new WireException('Quaderno mappings must be valid JSON objects.'); $this->$field = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        $this->shipping_tax_code = in_array((string) ($data['shipping_tax_code'] ?? ''), ['standard', 'reduced', 'exempt', 'eservice', 'ebook', 'saas', 'consulting'], true) ? (string) $data['shipping_tax_code'] : 'standard';
        $this->test_api_base_url = $this->normalizeTestBaseUrl((string) ($data['test_api_base_url'] ?? ''));
    }
    public function init(): void { $this->addHookAfter('Mercato::taxProviders', $this, 'registerProvider'); }
    public function registerProvider(HookEvent $event): void {
        if (!$this->enabled || $this->account_slug === '' || $this->apiKey() === '') return;
        $providers = is_array($event->return) ? $event->return : [];
        $providers['quaderno_tax'] = new MercatoQuadernoTaxProvider(fn(string $operation, array $payload, string $key): array => $this->requestQuaderno($operation, $payload, $key), [
            'tax_code_map' => json_decode((string) $this->tax_code_map_json, true) ?: [], 'product_type_map' => json_decode((string) $this->product_type_map_json, true) ?: [], 'shipping_tax_code' => (string) $this->shipping_tax_code,
        ]);
        $event->return = $providers;
    }
    private function requestQuaderno(string $operation, array $payload, string $idempotencyKey): array {
        if (!function_exists('curl_init')) throw new WireException('PHP cURL is required for Quaderno Tax.');
        $method = 'GET'; $path = '/api/tax_rates/calculate'; $body = null;
        if ($operation === 'transaction') { $method = 'POST'; $path = '/api/transactions'; $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        elseif (str_starts_with($operation, 'void:')) { $parts = explode(':', $operation, 3); $document = $parts[1] === 'receipt' ? 'receipts' : 'invoices'; $method = 'PUT'; $path = '/api/' . $document . '/' . rawurlencode($parts[2]) . '/void'; $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        elseif ($operation !== 'calculate') throw new WireException('Unsupported Quaderno Tax operation.');
        $commerce = $this->wire('modules')->get('Mercato');
        $host = $this->testBaseUrl($commerce) ?: 'https://' . $this->account_slug . ($this->environment === 'live' ? '.quadernoapp.com' : '.sandbox-quadernoapp.com');
        $url = $host . $path . ($method === 'GET' ? '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : '');
        $timeout = max(1, min(30, (int) ($commerce->tax_provider_timeout_seconds ?? 5)));
        $headers = ['Accept: application/json; api_version: ' . self::API_VERSION, 'Content-Type: application/json'];
        if ($idempotencyKey !== '') $headers[] = 'X-Mercato-Idempotency-Key: ' . substr($idempotencyKey, 0, 191);
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $this->apiKey() . ':', CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0]); if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch); $error = curl_error($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($response === false) throw new MercatoTaxProviderException('Quaderno request failed: ' . substr($error, 0, 300), $method === 'GET', 502, null, $method !== 'GET');
        if (strlen((string) $response) > 1048576) throw new MercatoTaxProviderException('Quaderno response exceeded the 1 MiB safety limit.', false, 502);
        $decoded = json_decode((string) $response, true); if (!is_array($decoded)) throw new MercatoTaxProviderException('Quaderno returned an unreadable response.', $method === 'GET', 502, null, $method !== 'GET' && $status >= 200 && $status < 300);
        if ($status < 200 || $status >= 300) throw new MercatoTaxProviderException('Quaderno API error ' . $status . ': ' . substr((string) ($decoded['message'] ?? $decoded['error'] ?? 'request failed'), 0, 300), $method === 'GET' && ($status === 429 || $status >= 500), $status, null, $method !== 'GET' && $status >= 500);
        return $decoded;
    }
    private function apiKey(): string { return $this->environment === 'live' ? trim((string) $this->live_api_key) : trim((string) $this->sandbox_api_key); }
    private function normalizeTestBaseUrl(string $value): string {
        $value = rtrim(trim($value), '/');
        if ($value === '') return '';
        return preg_match('#^http://(?:127\.0\.0\.1|\[::1\]|localhost):[1-9][0-9]{0,4}$#i', $value) ? $value : '';
    }
    private function testBaseUrl(Mercato $commerce): string {
        $url = $this->normalizeTestBaseUrl((string) ($this->test_api_base_url ?? ''));
        if ($url !== '' && !empty($commerce->production)) throw new WireException('Quaderno loopback transport is forbidden in production mode.');
        return $url;
    }

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
        $data = array_merge(self::getDefaultConfig(), $data); $modules = wire('modules'); $wrapper = new InputfieldWrapper();
        $enabled = $modules->get('InputfieldCheckbox'); $enabled->name = 'enabled'; $enabled->label = __('Enable Quaderno Tax provider'); $enabled->description = __('Start in the isolated Quaderno sandbox. Direct Mercato transaction recording must not be combined with Quaderno payment connectors, or duplicate documents may be created.'); $enabled->checked = !empty($data['enabled']); $wrapper->add($enabled);
        $environment = $modules->get('InputfieldSelect'); $environment->name = 'environment'; $environment->label = __('Environment'); $environment->addOptions(['sandbox' => __('Sandbox'), 'live' => __('Live')]); $environment->value = $data['environment']; $wrapper->add($environment);
        $slug = $modules->get('InputfieldText'); $slug->name = 'account_slug'; $slug->label = __('Quaderno account slug'); $slug->description = __('Hostname is generated from this slug; arbitrary API hosts are not accepted.'); $slug->value = $data['account_slug']; $wrapper->add($slug);
        foreach (['sandbox_api_key' => __('Sandbox API key'), 'live_api_key' => __('Live API key')] as $name => $label) { $field = $modules->get('InputfieldPassword'); $field->name = $name; $field->label = $label; $field->description = __('Leave blank to keep the saved key.'); $field->value = ''; $wrapper->add($field); }
        foreach (['tax_code_map_json' => __('Mercato to Quaderno tax-code map (JSON)'), 'product_type_map_json' => __('Mercato to Quaderno product-type map (JSON)')] as $name => $label) { $field = $modules->get('InputfieldTextarea'); $field->name = $name; $field->label = $label; $field->value = (string) $data[$name]; $wrapper->add($field); }
        $shipping = $modules->get('InputfieldSelect'); $shipping->name = 'shipping_tax_code'; $shipping->label = __('Shipping tax code'); foreach (['standard','reduced','exempt','eservice','ebook','saas','consulting'] as $option) $shipping->addOption($option, $option); $shipping->value = $data['shipping_tax_code']; $wrapper->add($shipping);
        return $wrapper;
    }
}
