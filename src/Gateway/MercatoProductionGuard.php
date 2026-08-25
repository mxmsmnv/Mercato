<?php
namespace ProcessWire;

final class MercatoProductionGuard {
    public static function validate(array $config, string $httpRoot): array {
        if (empty($config['production'])) return [];
        $errors = [];
        $methods = array_values(array_filter(array_map('strval', (array) ($config['enabled_payment_methods'] ?? []))));
        if (!$methods) $errors[] = 'Enable at least one production payment method.';
        if (in_array('demo', $methods, true)) $errors[] = 'Demo Payment must be disabled before production activation.';
        if (!str_starts_with(strtolower($httpRoot), 'https://')) $errors[] = 'Production checkout and webhook endpoints require HTTPS.';
        if ((array) ($config['enabled_notification_events'] ?? [])) {
            if (!filter_var((string) ($config['notification_sender_email'] ?? ''), FILTER_VALIDATE_EMAIL)) $errors[] = 'Transactional email sender must be a valid email address.';
            if (trim((string) ($config['notification_sender_name'] ?? '')) === '') $errors[] = 'Transactional email sender name is required.';
            if (trim((string) ($config['notification_transport'] ?? 'wiremail')) === '') $errors[] = 'Transactional email transport is required.';
        }
        if ((bool) array_filter($methods, static fn(string $method): bool => str_starts_with($method, 'stripe-'))) {
            $pk = trim((string) ($config['stripe_live_pk'] ?? '')); $sk = trim((string) ($config['stripe_live_sk'] ?? '')); $wh = trim((string) ($config['stripe_webhook_secret'] ?? ''));
            if (!str_starts_with($pk, 'pk_live_')) $errors[] = 'Stripe live publishable key must start with pk_live_.';
            if (!str_starts_with($sk, 'sk_live_')) $errors[] = 'Stripe live secret key must start with sk_live_.';
            if (!str_starts_with($wh, 'whsec_')) $errors[] = 'Stripe webhook signing secret must start with whsec_.';
            if ($pk !== '' && $pk === trim((string) ($config['stripe_test_pk'] ?? ''))) $errors[] = 'Stripe live and test publishable keys cannot match.';
            if ($sk !== '' && $sk === trim((string) ($config['stripe_test_sk'] ?? ''))) $errors[] = 'Stripe live and test secret keys cannot match.';
        }
        if (in_array('mollie', $methods, true)) {
            $live = trim((string) ($config['mollie_live_key'] ?? ''));
            if (!str_starts_with($live, 'live_')) $errors[] = 'Mollie production activation requires a live_ API key.';
            if ($live !== '' && $live === trim((string) ($config['mollie_test_key'] ?? ''))) $errors[] = 'Mollie live and test API keys cannot match.';
        }
        if (in_array('paypal', $methods, true)) {
            foreach (['paypal_live_client_id' => 'PayPal live client ID', 'paypal_live_secret' => 'PayPal live secret', 'paypal_live_webhook_id' => 'PayPal live webhook ID'] as $key => $label) if (trim((string) ($config[$key] ?? '')) === '') $errors[] = $label . ' is required.';
            $live = trim((string) ($config['paypal_live_client_id'] ?? '')); $test = trim((string) ($config['paypal_test_client_id'] ?? ''));
            if ($live !== '' && $live === $test) $errors[] = 'PayPal live and sandbox client IDs cannot match.';
        }
        return array_values(array_unique($errors));
    }
}
