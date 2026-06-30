<?php
namespace ProcessWire;

trait MercatoConfigReadiness {

    protected static function renderProductionReadinessNotice(array $data): string {
        $enabledMethods = self::normalizeEnabledPaymentMethods($data['enabled_payment_methods'] ?? []);
        $production = !empty($data['production']);
        $checks = [];
        $hasStripe = (bool) array_filter($enabledMethods, static fn(string $method): bool => str_starts_with($method, 'stripe-'));
        $hasMollie = in_array('mollie', $enabledMethods, true);
        $hasPayPal = in_array('paypal', $enabledMethods, true);

        if ($hasStripe) {
            $checks[] = [
                'label' => __('Stripe live publishable key'),
                'ok' => trim((string) ($data['stripe_live_pk'] ?? '')) !== '',
            ];
            $checks[] = [
                'label' => __('Stripe live secret key'),
                'ok' => trim((string) ($data['stripe_live_sk'] ?? '')) !== '',
            ];
            $checks[] = [
                'label' => __('Stripe webhook signing secret'),
                'ok' => trim((string) ($data['stripe_webhook_secret'] ?? '')) !== '',
            ];
        }

        if ($hasMollie) {
            $checks[] = [
                'label' => __('Mollie live API key'),
                'ok' => trim((string) ($data['mollie_live_key'] ?? '')) !== '',
            ];
        }

        if ($hasPayPal) {
            $checks[] = [
                'label' => __('PayPal live client ID'),
                'ok' => trim((string) ($data['paypal_live_client_id'] ?? '')) !== '',
            ];
            $checks[] = [
                'label' => __('PayPal live secret'),
                'ok' => trim((string) ($data['paypal_live_secret'] ?? '')) !== '',
            ];
            $checks[] = [
                'label' => __('PayPal live webhook ID'),
                'ok' => trim((string) ($data['paypal_live_webhook_id'] ?? '')) !== '',
            ];
        }

        $checks[] = [
            'label' => __('Customer email sender'),
            'ok' => trim((string) ($data['notification_sender_email'] ?? '')) !== '',
            'warning' => true,
        ];
        $checks[] = [
            'label' => __('Seller/legal receipt details'),
            'ok' => trim((string) ($data['merchant_legal_details'] ?? '')) !== '',
            'warning' => true,
        ];
        $checks[] = [
            'label' => __('Checkout policy pages'),
            'ok' => count(self::normalizePagePathListConfig($data['policy_pages'] ?? [])) > 0,
            'warning' => true,
        ];

        $missingCritical = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok']) && empty($check['warning'])));
        $missingRecommended = array_values(array_filter($checks, static fn(array $check): bool => empty($check['ok']) && !empty($check['warning'])));
        $readyLabel = count($missingCritical) === 0
            ? __('Critical live payment settings are present.')
            : sprintf(__('%d critical live payment setting(s) are missing.'), count($missingCritical));

        $class = count($missingCritical) > 0 ? 'uk-alert uk-alert-warning' : 'uk-alert uk-alert-success';
        if (!$production && count($missingCritical) === 0 && count($missingRecommended) > 0) {
            $class = 'uk-alert';
        }

        $out = '<div class="' . $class . ' mrc-production-readiness">';
        $out .= '<strong>' . ($production ? __('Production mode is enabled.') : __('Production mode is disabled.')) . '</strong> ';
        $out .= wire('sanitizer')->entities($readyLabel);
        if ($missingCritical || $missingRecommended) {
            $out .= '<ul>';
            foreach ($missingCritical as $check) {
                $out .= '<li><strong>' . wire('sanitizer')->entities((string) $check['label']) . '</strong> - ' . __('required before taking live payments') . '</li>';
            }
            foreach ($missingRecommended as $check) {
                $out .= '<li>' . wire('sanitizer')->entities((string) $check['label']) . ' - ' . __('recommended before launch') . '</li>';
            }
            $out .= '</ul>';
        }
        $out .= '</div>';

        return $out;
    }

    protected static function getConfigPageOptions(): array {
        $options = [];
        $pages = wire('pages')->find('include=all, status<' . Page::statusTrash . ', sort=path, limit=500');
        $skipTemplates = ['admin', 'permission', 'role', 'user', 'mrc-order', 'mrc-orders'];

        foreach ($pages as $p) {
            if (!$p->id) continue;
            if (in_array($p->template->name, $skipTemplates, true)) continue;
            if ($p->rootParent && $p->rootParent->id && $p->rootParent->template->name === 'admin') continue;
            if ($p->isHidden() || $p->isUnpublished()) continue;
            $path = trim($p->path, '/');
            if ($path === '') continue;
            $title = trim((string) $p->title);
            $options[$path] = $p->path . ($title !== '' ? ' — ' . $title : '');
        }

        foreach ([self::getDefaultConfig()['cancel_page'], self::getDefaultConfig()['success_page']] as $path) {
            $path = trim($path, '/');
            if ($path !== '' && !isset($options[$path])) {
                $options[$path] = '/' . $path . '/';
            }
        }

        return $options;
    }

    protected static function getPolicyPageOptions(array $selected = []): array {
        $options = [];
        $pages = wire('pages')->find('include=all, status<' . Page::statusTrash . ', sort=path, limit=500');
        $skipTemplates = ['admin', 'permission', 'role', 'user', 'mrc-order', 'mrc-orders', 'mrc-checkout', 'mrc-success', 'mrc-product'];

        foreach ($pages as $p) {
            if (!$p->id) continue;
            if (in_array($p->template->name, $skipTemplates, true)) continue;
            if ($p->rootParent && $p->rootParent->id && $p->rootParent->template->name === 'admin') continue;
            if ($p->isHidden() || $p->isUnpublished()) continue;
            $path = trim($p->path, '/');
            if ($path === '') continue;
            $title = trim((string) $p->title);
            $options[$path] = $p->path . ($title !== '' ? ' — ' . $title : '');
        }

        foreach (self::normalizePagePathListConfig($selected) as $path) {
            if (!isset($options[$path])) {
                $options[$path] = '/' . $path . '/';
            }
        }

        return $options;
    }
}
