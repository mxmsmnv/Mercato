<?php
namespace ProcessWire;

trait MercatoConfigSupport {

    protected static function normalizePagePathConfig(mixed $value, string $fallback = ''): string {
        if (is_array($value)) {
            $value = array_values(array_filter($value, fn($v) => is_scalar($v) && !str_starts_with((string) $v, '-')));
            $value = $value[0] ?? $fallback;
        }
        $value = trim((string) $value);
        return trim($value !== '' ? $value : $fallback, '/');
    }

    protected static function normalizePagePathListConfig(mixed $value): array {
        if (!is_array($value)) {
            $value = trim((string) $value) !== '' ? explode(',', (string) $value) : [];
        }

        $paths = [];
        foreach ($value as $path) {
            if (!is_scalar($path)) {
                continue;
            }
            $path = trim((string) $path);
            if ($path === '' || str_starts_with($path, '-')) {
                continue;
            }
            $path = trim($path, '/');
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    protected static function normalizeHttpRoot(string $root): string {
        $root = rtrim($root, '/');
        return preg_replace('~^([a-z][a-z0-9+\-.]*://[^/]+)\.(?=/|$)~i', '$1', $root) ?: $root;
    }

    protected static function normalizeEnabledPaymentMethods(mixed $value): array {
        $allowed = array_keys(self::getPaymentMethodOptions());
        if (!is_array($value)) {
            $value = trim((string) $value) !== '' ? explode(',', (string) $value) : [];
        }
        $value = array_values(array_unique(array_filter(array_map('strval', $value))));
        $value = array_values(array_intersect($value, $allowed));
        return $value ?: ['stripe-card'];
    }

    public static function getFulfilmentMethodOptions(): array {
        return [
            'carrier_delivery' => 'Delivery through carrier / provider',
            'store_pickup' => 'Pickup in store',
            'local_delivery' => 'Local delivery',
        ];
    }

    protected static function normalizeEnabledFulfilmentMethods(mixed $value): array {
        $allowed = array_keys(self::getFulfilmentMethodOptions());
        if (!is_array($value)) {
            $value = trim((string) $value) !== '' ? explode(',', (string) $value) : [];
        }
        $value = array_values(array_unique(array_filter(array_map('strval', $value))));
        $value = array_values(array_intersect($value, $allowed));
        return $value ?: ['carrier_delivery'];
    }

    protected static function normalizeDefaultFulfilmentMethod(mixed $value, array $enabled): string {
        $method = trim((string) $value);
        if ($method !== '' && in_array($method, $enabled, true)) {
            return $method;
        }
        return $enabled[0] ?? 'carrier_delivery';
    }

    protected static function normalizeFrontendFramework(mixed $value): string {
        $value = strtolower(trim((string) $value));
        return array_key_exists($value, self::getFrontendFrameworkOptions()) ? $value : 'vanilla';
    }

    protected static function normalizeReservationTtlMinutes(mixed $value): int {
        $minutes = (int) $value;
        if ($minutes < 1) return 30;
        if ($minutes > 1440) return 1440;
        return $minutes;
    }

    protected static function normalizeReservationCleanupSchedule(mixed $value): string {
        $value = trim((string) $value);
        return array_key_exists($value, self::getReservationCleanupScheduleOptions()) ? $value : 'every30Minutes';
    }

    protected static function normalizeRetentionDays(mixed $value, int $default, int $min = 0, int $max = 3650): int {
        $days = (int) $value;
        if ($days < $min) return $default;
        if ($days > $max) return $max;
        return $days;
    }

    protected static function normalizeInvoicePrefix(mixed $value): string {
        $prefix = strtoupper(trim((string) $value));
        $prefix = preg_replace('/[^A-Z0-9_.-]+/', '', $prefix) ?: '';
        return substr($prefix, 0, 24);
    }

    protected static function normalizeLowStockThreshold(mixed $value): int {
        $threshold = (int) $value;
        if ($threshold < 0) return 0;
        if ($threshold > 1000000) return 1000000;
        return $threshold;
    }

    protected static function normalizeMoneyAmount(mixed $value): float {
        $amount = round((float) $value, 2);
        if ($amount < 0) return 0.0;
        if ($amount > 100000000) return 100000000.0;
        return $amount;
    }

    protected static function normalizeTaxRate(mixed $value): float {
        $rate = round((float) $value, 4);
        if ($rate < 0) return 0.0;
        if ($rate > 100) return 100.0;
        return $rate;
    }

    protected static function normalizeTaxLabel(mixed $value): string {
        $label = trim(strip_tags((string) $value));
        $label = preg_replace('/\s+/', ' ', $label) ?: '';
        return $label !== '' ? substr($label, 0, 40) : 'VAT';
    }

    protected static function normalizeTaxRoundingMode(mixed $value): string {
        $value = strtolower(trim((string) $value));
        return array_key_exists($value, self::getTaxRoundingModeOptions()) ? $value : 'line';
    }

    protected static function normalizeTaxDisplayMode(mixed $value): string {
        $value = strtolower(trim((string) $value));
        return array_key_exists($value, self::getTaxDisplayModeOptions()) ? $value : 'included';
    }

    protected static function getDefaultFrontendAssetUrls(): array {
        return [
            'tailwind' => 'https://cdn.tailwindcss.com',
            'bootstrap' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'uikit' => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/css/uikit.min.css',
        ];
    }

    protected static function normalizeFrontendAssetUrl(mixed $value, string $framework): string {
        $defaults = self::getDefaultFrontendAssetUrls();
        $fallback = $defaults[$framework] ?? '';
        $url = trim(strip_tags((string) $value));
        $url = preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?: '';
        if ($url === '') return $fallback;
        if (!preg_match('~^https?://~i', $url)) return $fallback;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return $fallback;
        return substr($url, 0, 2000);
    }

    protected static function normalizeReceiptPdfUrlTemplate(mixed $value): string {
        $template = trim(strip_tags((string) $value));
        $template = preg_replace('/[\x00-\x1F\x7F]+/', '', $template) ?: '';
        if ($template === '') return '';
        if (!preg_match('~^(https?://|/)~i', $template)) return '';
        return substr($template, 0, 2000);
    }

    protected static function normalizeReceiptTemplateFile(mixed $value): string {
        $file = str_replace('\\', '/', trim(strip_tags((string) $value)));
        $file = ltrim(preg_replace('/[\x00-\x1F\x7F]+/', '', $file) ?: '', '/');
        if ($file === '' || str_contains($file, '..') || !str_ends_with($file, '.php')) {
            return '';
        }
        return substr($file, 0, 200);
    }

    protected static function normalizeCountryCodes(mixed $value): string {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
        $codes = [];
        foreach ($parts ?: [] as $part) {
            $code = strtoupper(preg_replace('/[^A-Z]/', '', (string) $part) ?: '');
            if (strlen($code) === 2) {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
        return implode("\n", $codes);
    }

    protected static function normalizeDeliveryRegions(mixed $value): string {
        $lines = is_array($value) ? $value : preg_split('/\R+/', (string) $value);
        $regions = [];
        foreach ($lines ?: [] as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $line) ?: '');
            if ($line === '') continue;
            if (!preg_match('/^([A-Za-z]{2})\s*[:|,\s]\s*([A-Za-z0-9_-]{1,12})(?:\s*[=:|-]\s*|\s+)(.+)$/', $line, $matches)) {
                continue;
            }
            $country = strtoupper($matches[1]);
            $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $matches[2]) ?: '');
            $label = trim((string) $matches[3]);
            if ($code === '' || $label === '') continue;
            $regions[$country . ':' . $code] = $country . ':' . $code . ':' . substr($label, 0, 120);
        }
        ksort($regions, SORT_STRING);
        return implode("\n", array_values($regions));
    }

    protected static function normalizeDeliveryWindows(mixed $value): string {
        $lines = is_array($value) ? $value : preg_split('/\R+/', (string) $value);
        $windows = [];
        foreach ($lines ?: [] as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $line) ?: '');
            if ($line === '') continue;
            $windows[$line] = substr($line, 0, 120);
        }
        return implode("\n", array_values($windows));
    }

    protected static function normalizePickupLocations(mixed $value): string {
        $lines = is_array($value) ? $value : preg_split('/\R+/', (string) $value);
        $locations = [];
        foreach ($lines ?: [] as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $line) ?: '');
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line, 4));
            $label = substr((string) ($parts[0] ?? ''), 0, 80);
            if ($label === '') continue;
            $address = substr((string) ($parts[1] ?? ''), 0, 180);
            $instructions = substr((string) ($parts[2] ?? ''), 0, 180);
            $hours = substr((string) ($parts[3] ?? ''), 0, 120);
            $locations[$label] = trim(implode(' | ', array_filter([$label, $address, $instructions, $hours], static fn(string $part): bool => $part !== '')));
        }
        ksort($locations, SORT_STRING);
        return implode("\n", array_values($locations));
    }

    protected static function normalizeRecoveryEmailCooldownMinutes(mixed $value): int {
        $minutes = (int) $value;
        if ($minutes < 0) return 0;
        if ($minutes > 10080) return 10080;
        return $minutes;
    }

    protected static function normalizeRecoveryAutomationMinAgeMinutes(mixed $value): int {
        $minutes = (int) $value;
        if ($minutes < 15) return 15;
        if ($minutes > 43200) return 43200;
        return $minutes;
    }

    protected static function normalizeRecoveryAutomationBatchLimit(mixed $value): int {
        $limit = (int) $value;
        if ($limit < 1) return 1;
        if ($limit > 100) return 100;
        return $limit;
    }

    protected static function normalizeRecoveryDiscountCode(mixed $value): string {
        $code = strtoupper(trim((string) $value));
        $code = preg_replace('/[^A-Z0-9_-]+/', '', $code) ?: '';
        return substr($code, 0, 64);
    }

    protected static function normalizeRecoverySuppressedEmails(mixed $value): string {
        $emails = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
        $sanitizer = wire('sanitizer');
        $normalized = [];
        foreach ((array) $emails as $email) {
            $email = strtolower((string) $sanitizer->email(trim((string) $email)));
            if ($email !== '') {
                $normalized[$email] = $email;
            }
        }
        return implode("\n", array_values($normalized));
    }

    public static function getPaymentMethodOptions(): array {
        return [
            'stripe-card' => 'Stripe Card',
            'stripe-ideal' => 'Stripe iDEAL',
            'stripe-sepa' => 'Stripe SEPA Debit',
            'stripe-klarna' => 'Stripe Klarna',
            'mollie' => 'Mollie Checkout',
            'paypal' => 'PayPal Checkout',
            'bank-transfer' => 'Bank transfer / invoice',
            'demo' => 'Demo Payment (test mode only)',
        ];
    }

    public static function getFrontendFrameworkOptions(): array {
        return [
            'vanilla' => 'Vanilla',
            'tailwind' => 'Tailwind CSS',
            'bootstrap' => 'Bootstrap',
            'uikit' => 'UIkit',
        ];
    }

    public static function getReservationCleanupScheduleOptions(): array {
        return [
            'disabled' => 'Disabled',
            'every5Minutes' => 'Every 5 minutes',
            'every15Minutes' => 'Every 15 minutes',
            'every30Minutes' => 'Every 30 minutes',
            'everyHour' => 'Every hour',
            'every2Hours' => 'Every 2 hours',
        ];
    }

    public static function getTaxRoundingModeOptions(): array {
        return [
            'line' => 'Round each line',
            'tax_rate' => 'Round each tax-rate group',
            'total' => 'Round final tax total',
        ];
    }

    public static function getTaxDisplayModeOptions(): array {
        return [
            'included' => 'Prices include tax',
            'excluded' => 'Prices exclude tax',
            'none' => 'No tax displayed',
        ];
    }

}
