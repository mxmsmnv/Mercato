<?php
namespace ProcessWire;

trait MercatoStoreServices {

    public function setConfigData(array $data): void {
        $this->requireArchitectureClasses();
        $productionActivationConfirmed = !empty($data['production_activation_confirmed']);
        $previousConfig = self::getDefaultConfig();
        foreach (array_keys($previousConfig) as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $previousConfig[$key] = $value;
            }
        }
        $data = array_merge(self::getDefaultConfig(), $data);
        unset($data['mrc_run_installer'], $data['mrc_overwrite_template_files'], $data['production_activation_confirmed']);
        $data['currency'] = MercatoCurrency::isIsoCode((string) ($data['currency'] ?? ''))
            ? MercatoCurrency::normalizeCode((string) $data['currency'])
            : self::getDefaultConfig()['currency'];
        $data['currency_symbol'] = trim((string) ($data['currency_symbol'] ?? '')) !== ''
            ? trim((string) $data['currency_symbol'])
            : self::getDefaultConfig()['currency_symbol'];
        $data['currency_symbol_position'] = in_array((string) ($data['currency_symbol_position'] ?? ''), ['before', 'after'], true)
            ? (string) $data['currency_symbol_position']
            : self::getDefaultConfig()['currency_symbol_position'];
        $data['markets_json'] = trim((string) ($data['markets_json'] ?? ''));
        if ($data['markets_json'] !== '') {
            $markets = json_decode($data['markets_json'], true);
            if (!is_array($markets) || !array_is_list($markets)) throw new WireException($this->_('Markets must be a valid JSON array.'));
        }
        $data['invoice_prefix'] = self::normalizeInvoicePrefix($data['invoice_prefix'] ?? '');
        $data['quotes_parent'] = self::normalizePagePathConfig($data['quotes_parent'] ?? 'quotes', 'quotes');
        $data['quote_requests_enabled'] = !empty($data['quote_requests_enabled']);
        $data['quote_expiry_days'] = max(1, min(365, (int) ($data['quote_expiry_days'] ?? 30)));
        $data['quote_inventory_policy'] = in_array((string) ($data['quote_inventory_policy'] ?? 'none'), ['none', 'on_acceptance'], true)
            ? (string) $data['quote_inventory_policy']
            : 'none';
        $data['success_page'] = self::normalizePagePathConfig($data['success_page'], self::getDefaultConfig()['success_page']);
        $data['cancel_page'] = self::normalizePagePathConfig($data['cancel_page'], self::getDefaultConfig()['cancel_page']);
        $data['policy_pages'] = self::normalizePagePathListConfig($data['policy_pages'] ?? []);
        $data['enabled_payment_methods'] = self::normalizeEnabledPaymentMethods($data['enabled_payment_methods'] ?? []);
        $data['stripe_automatic_payment_methods'] = !empty($data['stripe_automatic_payment_methods']);
        $data['enabled_fulfilment_methods'] = self::normalizeEnabledFulfilmentMethods($data['enabled_fulfilment_methods'] ?? []);
        $data['default_fulfilment_method'] = self::normalizeDefaultFulfilmentMethod($data['default_fulfilment_method'] ?? '', $data['enabled_fulfilment_methods']);
        $data['frontend_framework'] = self::normalizeFrontendFramework($data['frontend_framework'] ?? self::getDefaultConfig()['frontend_framework']);
        $data['frontend_auto_assets'] = !empty($data['frontend_auto_assets']);
        foreach (['tailwind', 'bootstrap', 'uikit'] as $frontendAssetFramework) {
            $key = 'frontend_' . $frontendAssetFramework . '_cdn_url';
            $data[$key] = self::normalizeFrontendAssetUrl($data[$key] ?? '', $frontendAssetFramework);
        }
        $data['bank_transfer_instructions'] = trim((string) ($data['bank_transfer_instructions'] ?? ''));
        $data['reservation_ttl_minutes'] = self::normalizeReservationTtlMinutes($data['reservation_ttl_minutes'] ?? 30);
        $data['reservation_cleanup_schedule'] = self::normalizeReservationCleanupSchedule($data['reservation_cleanup_schedule'] ?? 'every30Minutes');
        $data['cart_retention_days'] = self::normalizeRetentionDays($data['cart_retention_days'] ?? 30, 30, 1);
        $data['draft_order_retention_days'] = self::normalizeRetentionDays($data['draft_order_retention_days'] ?? 14, 14, 1);
        $data['webhook_payload_retention_days'] = self::normalizeRetentionDays($data['webhook_payload_retention_days'] ?? 90, 90, 1);
        $data['gateway_timeout_seconds'] = max(3, min(120, (int) ($data['gateway_timeout_seconds'] ?? 30)));
        $data['gateway_retries'] = max(0, min(3, (int) ($data['gateway_retries'] ?? 2)));
        $data['customer_data_retention_days'] = self::normalizeRetentionDays($data['customer_data_retention_days'] ?? 0, 0, 0);
        $data['email_log_retention_days'] = self::normalizeRetentionDays($data['email_log_retention_days'] ?? 180, 180, 1);
        $data['payment_attempt_retention_days'] = self::normalizeRetentionDays($data['payment_attempt_retention_days'] ?? 180, 180, 1);
        $data['operational_log_retention_days'] = self::normalizeRetentionDays($data['operational_log_retention_days'] ?? 365, 365, 1);
        $data['provider_reference_retention_days'] = self::normalizeRetentionDays($data['provider_reference_retention_days'] ?? 0, 0, 0);
        $data['signed_link_retention_days'] = self::normalizeRetentionDays($data['signed_link_retention_days'] ?? 3650, 3650, 0);
        $data['privacy_retention_schedule'] = self::normalizeReservationCleanupSchedule($data['privacy_retention_schedule'] ?? 'everyDay');
        $data['privacy_retention_batch_limit'] = max(1, min(500, (int) ($data['privacy_retention_batch_limit'] ?? 100)));
        $data['privacy_policy_version'] = substr(preg_replace('/[^a-zA-Z0-9._-]+/', '', trim((string) ($data['privacy_policy_version'] ?? '1.0'))) ?: '1.0', 0, 40);
        $data['privacy_backup_retention_note'] = trim((string) ($data['privacy_backup_retention_note'] ?? ''));
        $data['customer_accounts_mode'] = MercatoAccountPolicy::normalizeMode($data['customer_accounts_mode'] ?? 'disabled');
        $data['account_claim_guest_orders'] = !empty($data['account_claim_guest_orders']);
        $data['account_token_ttl_minutes'] = max(5, min(1440, (int) ($data['account_token_ttl_minutes'] ?? 60)));
        $data['account_login_attempts'] = max(1, min(20, (int) ($data['account_login_attempts'] ?? 5)));
        $data['account_login_window_seconds'] = max(60, min(86400, (int) ($data['account_login_window_seconds'] ?? 900)));
        $data['account_orders_per_page'] = max(1, min(100, (int) ($data['account_orders_per_page'] ?? 10)));
        $data['checkout_enabled'] = !empty($data['checkout_enabled']);
        $data['checkout_maintenance_message'] = substr(trim((string) ($data['checkout_maintenance_message'] ?? '')), 0, 500);
        $data['preupgrade_backup_required'] = !empty($data['preupgrade_backup_required']);
        $data['backup_max_age_hours'] = max(1, min(8760, (int) ($data['backup_max_age_hours'] ?? 24)));
        $data['backup_evidence'] = trim((string) ($data['backup_evidence'] ?? ''));
        $data['health_check_token'] = trim((string) ($data['health_check_token'] ?? ''));
        $data['health_storage_min_bytes'] = max(1048576, (int) ($data['health_storage_min_bytes'] ?? 104857600));
        $data['health_cron_max_age_seconds'] = max(3600, min(2592000, (int) ($data['health_cron_max_age_seconds'] ?? 172800)));
        $data['analytics_enabled'] = !empty($data['analytics_enabled']);
        $data['analytics_adapters'] = array_values(array_unique(array_filter(array_map(static fn($value) => preg_replace('/[^a-z0-9_-]+/', '', strtolower((string) $value)), (array) ($data['analytics_adapters'] ?? ['data_layer', 'first_party'])))));
        $data['analytics_default_consent'] = (string) ($data['analytics_default_consent'] ?? 'denied') === 'granted' ? 'granted' : 'denied';
        $data['analytics_order_identifier'] = in_array((string) ($data['analytics_order_identifier'] ?? 'invoice'), ['invoice', 'hash', 'omit'], true) ? (string) $data['analytics_order_identifier'] : 'invoice';
        $data['analytics_account_identifier'] = (string) ($data['analytics_account_identifier'] ?? 'omit') === 'hash' ? 'hash' : 'omit';
        $data['low_stock_threshold'] = self::normalizeLowStockThreshold($data['low_stock_threshold'] ?? 5);
        $data['notification_transport'] = preg_match('/^[a-z0-9_-]+$/', (string) ($data['notification_transport'] ?? 'wiremail')) ? (string) $data['notification_transport'] : 'wiremail';
        $data['notification_locale'] = MercatoEmailTemplateRenderer::normalizeLocale((string) ($data['notification_locale'] ?? 'en'));
        $data['notification_brand_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) ($data['notification_brand_color'] ?? ''))) ? strtolower(trim((string) $data['notification_brand_color'])) : '#6b4f3a';
        $logoUrl = trim((string) ($data['notification_logo_url'] ?? ''));
        $data['notification_logo_url'] = $logoUrl !== '' && filter_var($logoUrl, FILTER_VALIDATE_URL) && str_starts_with(strtolower($logoUrl), 'https://') ? $logoUrl : '';
        $data['notification_templates_json'] = self::normalizeNotificationTemplatesJson($data['notification_templates_json'] ?? '{}');
        $data['notification_header_html'] = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim((string) ($data['notification_header_html'] ?? ''))), 0, 100000);
        $data['notification_footer_html'] = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim((string) ($data['notification_footer_html'] ?? ''))), 0, 100000);
        $data['notification_retries'] = max(0, min(5, (int) ($data['notification_retries'] ?? 2)));
        $data['enabled_notification_events'] = array_values(array_intersect(MercatoEmailEventCatalog::EVENTS, array_map('strval', (array) ($data['enabled_notification_events'] ?? MercatoEmailEventCatalog::EVENTS))));
        $data['seo_site_name'] = MercatoSeoRules::safeText((string) ($data['seo_site_name'] ?? 'Mercato Store'), 80);
        $data['seo_default_description'] = MercatoSeoRules::safeText((string) ($data['seo_default_description'] ?? ''), 160);
        $data['seo_default_robots'] = MercatoSeoRules::normalizeRobots((string) ($data['seo_default_robots'] ?? 'index,follow,max-image-preview:large'));
        foreach (['seo_social_image_url', 'seo_organization_logo_url'] as $seoUrlKey) { $value = trim((string) ($data[$seoUrlKey] ?? '')); $data[$seoUrlKey] = $value !== '' && filter_var($value, FILTER_VALIDATE_URL) && str_starts_with(strtolower($value), 'https://') ? $value : ''; }
        $data['free_shipping_threshold'] = self::normalizeMoneyAmount($data['free_shipping_threshold'] ?? 0);
        $data['shipping_dimensions_enabled'] = !empty($data['shipping_dimensions_enabled']);
        $data['shipping_dimensions_field'] = self::normalizeShippingDimensionsField($data['shipping_dimensions_field'] ?? 'mrc_dimensions');
        $data['shipping_calculation_mode'] = self::normalizeShippingCalculationMode($data['shipping_calculation_mode'] ?? 'flat');
        $data['shipping_dimensional_divisor'] = max(1.0, min(1000000.0, (float) ($data['shipping_dimensional_divisor'] ?? 5000)));
        $data['shipping_missing_measurements'] = self::normalizeMissingMeasurementsPolicy($data['shipping_missing_measurements'] ?? 'flat');
        $data['shipping_rate_table'] = trim((string) ($data['shipping_rate_table'] ?? ''));
        $data['shipping_provider'] = trim((string) ($data['shipping_provider'] ?? 'manual')) ?: 'manual';
        $data['shipping_provider_failure_policy'] = in_array((string) ($data['shipping_provider_failure_policy'] ?? 'manual_fallback'), ['fail_closed', 'manual_fallback'], true) ? (string) $data['shipping_provider_failure_policy'] : 'manual_fallback';
        $data['shipping_provider_timeout_seconds'] = max(1, min(30, (int) ($data['shipping_provider_timeout_seconds'] ?? 5)));
        $data['shipping_provider_retries'] = max(0, min(3, (int) ($data['shipping_provider_retries'] ?? 1)));
        $data['shipping_provider_quote_ttl_seconds'] = max(60, min(86400, (int) ($data['shipping_provider_quote_ttl_seconds'] ?? 900)));
        $data['shipping_provider_origin'] = trim((string) ($data['shipping_provider_origin'] ?? ''));
        $data['shipping_provider_service_map'] = trim((string) ($data['shipping_provider_service_map'] ?? ''));
        $data['shipping_provider_handling_fixed'] = self::normalizeMoneyAmount($data['shipping_provider_handling_fixed'] ?? 0);
        $data['shipping_provider_handling_percent'] = max(-100, min(1000, (float) ($data['shipping_provider_handling_percent'] ?? 0)));
        $data['shipping_provider_allowed_regions'] = trim((string) ($data['shipping_provider_allowed_regions'] ?? ''));
        $data['shipping_provider_package_mode'] = in_array((string) ($data['shipping_provider_package_mode'] ?? 'combined'), ['combined', 'per_item'], true) ? (string) $data['shipping_provider_package_mode'] : 'combined';
        $data['shipping_provider_include_manual_rates'] = !empty($data['shipping_provider_include_manual_rates']);
        $data['shipping_provider_webhook_secret'] = trim((string) ($data['shipping_provider_webhook_secret'] ?? ''));
        $data['default_tax_rate'] = self::normalizeTaxRate($data['default_tax_rate'] ?? 20);
        $data['tax_display_mode'] = self::normalizeTaxDisplayMode($data['tax_display_mode'] ?? 'included');
        $data['tax_label'] = self::normalizeTaxLabel($data['tax_label'] ?? 'VAT');
        $data['tax_rounding_mode'] = self::normalizeTaxRoundingMode($data['tax_rounding_mode'] ?? 'line');
        $data['tax_shipping'] = !empty($data['tax_shipping']);
        $data['shipping_tax_rate'] = self::normalizeTaxRate($data['shipping_tax_rate'] ?? 20);
        $data['tax_provider'] = trim((string) ($data['tax_provider'] ?? 'manual')) ?: 'manual';
        $data['tax_provider_failure_policy'] = in_array((string) ($data['tax_provider_failure_policy'] ?? 'fail_closed'), ['fail_closed', 'manual_fallback', 'zero_tax'], true) ? (string) $data['tax_provider_failure_policy'] : 'fail_closed';
        $data['tax_provider_timeout_seconds'] = max(1, min(30, (int) ($data['tax_provider_timeout_seconds'] ?? 5)));
        $data['tax_provider_retries'] = max(0, min(3, (int) ($data['tax_provider_retries'] ?? 1)));
        $data['tax_registrations'] = trim((string) ($data['tax_registrations'] ?? ''));
        $data['tax_nexus_regions'] = trim((string) ($data['tax_nexus_regions'] ?? ''));
        $data['allowed_delivery_countries'] = self::normalizeCountryCodes($data['allowed_delivery_countries'] ?? '');
        $data['delivery_regions'] = self::normalizeDeliveryRegions($data['delivery_regions'] ?? '');
        $data['delivery_windows'] = self::normalizeDeliveryWindows($data['delivery_windows'] ?? '');
        $data['store_pickup_locations'] = self::normalizePickupLocations($data['store_pickup_locations'] ?? '');
        $data['local_delivery_minimum_order'] = self::normalizeMoneyAmount($data['local_delivery_minimum_order'] ?? 0);
        $data['recovery_email_cooldown_minutes'] = self::normalizeRecoveryEmailCooldownMinutes($data['recovery_email_cooldown_minutes'] ?? 1440);
        $data['recovery_automation_schedule'] = self::normalizeReservationCleanupSchedule($data['recovery_automation_schedule'] ?? 'disabled');
        $data['recovery_automation_min_age_minutes'] = self::normalizeRecoveryAutomationMinAgeMinutes($data['recovery_automation_min_age_minutes'] ?? 60);
        $data['recovery_automation_batch_limit'] = self::normalizeRecoveryAutomationBatchLimit($data['recovery_automation_batch_limit'] ?? 10);
        $data['recovery_discount_code'] = self::normalizeRecoveryDiscountCode($data['recovery_discount_code'] ?? '');
        $data['recovery_suppressed_emails'] = self::normalizeRecoverySuppressedEmails($data['recovery_suppressed_emails'] ?? '');
        $data['push_notifications_enabled'] = !empty($data['push_notifications_enabled']);
        $data['push_transport'] = trim((string) ($data['push_transport'] ?? 'apns')) ?: 'apns';
        $data['apns_environment'] = (string) ($data['apns_environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
        foreach (['apns_team_id', 'apns_key_id', 'apns_bundle_id', 'apns_private_key_path'] as $pushConfigKey) $data[$pushConfigKey] = trim((string) ($data[$pushConfigKey] ?? ''));
        $data['receipt_template_file'] = self::normalizeReceiptTemplateFile($data['receipt_template_file'] ?? '');
        $data['order_status_template_file'] = self::normalizeReceiptTemplateFile($data['order_status_template_file'] ?? '');
        $data['access_recovery_enabled'] = !empty($data['access_recovery_enabled']);
        $data['receipt_pdf_url_template'] = self::normalizeReceiptPdfUrlTemplate($data['receipt_pdf_url_template'] ?? '');
        if (empty($previousConfig['production']) && !empty($data['production'])) {
            if (!$productionActivationConfirmed) throw new WireException($this->_('Confirm the production activation checklist before enabling production mode.'));
            $productionErrors = MercatoProductionGuard::validate($data, $this->getHttpRoot());
            if ($productionErrors) throw new WireException($this->_('Production activation blocked: ') . implode(' ', $productionErrors));
        }
        $this->recordSettingsAuditEvents($previousConfig, $data);
        $this->setArray($data);
    }

    public function formatInvoiceNumber(int $sequence): string {
        $prefix = self::normalizeInvoicePrefix($this->invoice_prefix ?? '');
        return $prefix . str_pad((string) max(0, $sequence), 5, '0', STR_PAD_LEFT);
    }

    public function ___emailTransport(MercatoEmailTransportInterface $default): MercatoEmailTransportInterface {
        return $default;
    }

    public function notificationDeliveryService(): MercatoEmailDeliveryService {
        if (!$this->emailDeliveryService) {
            $transport = $this->emailTransport(new MercatoWireMailTransport());
            $this->emailDeliveryService = new MercatoEmailDeliveryService($this, $transport);
            $this->emailDeliveryService->setWire($this->wire());
        }
        return $this->emailDeliveryService;
    }

    public function notificationTemplates(): array {
        $stored = self::decodeNotificationTemplates($this->notification_templates_json ?? '{}');
        $metadata = MercatoEmailEventCatalog::metadata();
        $templates = [];
        foreach (MercatoEmailEventCatalog::EVENTS as $event) {
            $default = MercatoEmailEventCatalog::get($event);
            $legacy = $this->legacyNotificationTemplateOverride($event);
            $custom = (array) ($stored[$event] ?? []);
            $meta = (array) ($metadata[$event] ?? []);
            $subject = (string) ($custom['subject'] ?? $legacy['subject'] ?? $default['subject']);
            $text = (string) ($custom['text'] ?? $legacy['text'] ?? $default['text']);
            $html = (string) ($custom['html'] ?? self::defaultNotificationHtml($text));
            $templates[$event] = [
                'template_key' => $event,
                'label' => (string) ($meta['label'] ?? ucwords(str_replace('_', ' ', $event))),
                'recipient' => (string) ($meta['recipient'] ?? 'Customer'),
                'purpose' => (string) ($meta['purpose'] ?? 'Transactional commerce update.'),
                'subject' => $subject,
                'text' => $text,
                'html' => $html,
                'variables' => MercatoEmailEventCatalog::variables($event),
                'customized' => isset($stored[$event]),
            ];
        }
        return $templates;
    }

    public function notificationTemplate(string $event): array {
        if (!in_array($event, MercatoEmailEventCatalog::EVENTS, true)) throw new WireException('Unknown transactional email template.');
        return (array) ($this->notificationTemplates()[$event] ?? []);
    }

    public function notificationMailLayout(): array {
        return [
            'header' => (string) ($this->notification_header_html ?? ''),
            'footer' => (string) ($this->notification_footer_html ?? ''),
        ];
    }

    public function saveNotificationTemplate(string $event, string $subject, string $text, string $html, User $user): void {
        $this->requireNotificationTemplateAdmin($user);
        if (!in_array($event, MercatoEmailEventCatalog::EVENTS, true)) throw new WireException('Unknown transactional email template.');
        $subject = mb_substr(trim(str_replace(["\r", "\n"], ' ', $subject)), 0, 240);
        $text = mb_substr(trim($text), 0, 100000);
        $html = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim($html)), 0, 100000);
        if ($subject === '' || $text === '' || $html === '') throw new WireException('Subject, plain-text fallback, and HTML body are required.');
        $stored = self::decodeNotificationTemplates($this->notification_templates_json ?? '{}');
        $stored[$event] = ['subject' => $subject, 'text' => $text, 'html' => $html, 'updated_at' => date('c')];
        $this->persistNotificationTemplateConfig(['notification_templates_json' => self::encodeNotificationTemplates($stored)]);
    }

    public function resetNotificationTemplate(string $event, User $user): void {
        $this->requireNotificationTemplateAdmin($user);
        if (!in_array($event, MercatoEmailEventCatalog::EVENTS, true)) throw new WireException('Unknown transactional email template.');
        $stored = self::decodeNotificationTemplates($this->notification_templates_json ?? '{}');
        unset($stored[$event]);
        $this->persistNotificationTemplateConfig(['notification_templates_json' => self::encodeNotificationTemplates($stored)]);
    }

    public function saveNotificationMailLayout(string $header, string $footer, User $user): void {
        $this->requireNotificationTemplateAdmin($user);
        $header = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim($header)), 0, 100000);
        $footer = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim($footer)), 0, 100000);
        $this->persistNotificationTemplateConfig([
            'notification_header_html' => $header,
            'notification_footer_html' => $footer,
        ]);
    }

    protected function legacyNotificationTemplateOverride(string $event): array {
        return match ($event) {
            'order_confirmation' => ['subject' => (string) $this->confirmation_email_subject, 'text' => (string) $this->confirmation_email_body],
            'payment_recovery' => ['subject' => (string) $this->payment_link_email_subject, 'text' => (string) $this->payment_link_email_body],
            'shipment_tracking' => ['subject' => (string) $this->shipping_email_subject, 'text' => (string) $this->shipping_email_body],
            'pickup_ready' => ['subject' => (string) $this->pickup_ready_email_subject, 'text' => (string) $this->pickup_ready_email_body],
            'local_delivery' => ['subject' => (string) $this->local_delivery_email_subject, 'text' => (string) $this->local_delivery_email_body],
            default => [],
        };
    }

    protected static function normalizeNotificationTemplatesJson(mixed $value): string {
        return self::encodeNotificationTemplates(self::decodeNotificationTemplates($value));
    }

    protected static function decodeNotificationTemplates(mixed $value): array {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($decoded)) return [];
        $normalized = [];
        foreach (MercatoEmailEventCatalog::EVENTS as $event) {
            $row = (array) ($decoded[$event] ?? []);
            if (!$row) continue;
            $subject = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($row['subject'] ?? ''))), 0, 240);
            $text = mb_substr(trim((string) ($row['text'] ?? '')), 0, 100000);
            $html = mb_substr(MercatoEmailTemplateRenderer::sanitizeHtml(trim((string) ($row['html'] ?? ''))), 0, 100000);
            if ($subject === '' || $text === '' || $html === '') continue;
            $normalized[$event] = ['subject' => $subject, 'text' => $text, 'html' => $html, 'updated_at' => mb_substr(trim((string) ($row['updated_at'] ?? '')), 0, 40)];
        }
        return $normalized;
    }

    protected static function encodeNotificationTemplates(array $templates): string {
        return json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected static function defaultNotificationHtml(string $text): string {
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br(htmlspecialchars(trim((string) $paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }
        return $html !== '' ? $html : '<p>Transactional update</p>';
    }

    protected function requireNotificationTemplateAdmin(User $user): void {
        if (!$user->isSuperuser() && !$user->hasPermission('mercato-admin')) throw new WirePermissionException('You cannot edit transactional email templates.');
    }

    protected function persistNotificationTemplateConfig(array $changes): void {
        $modules = $this->wire('modules');
        $config = array_merge(self::getDefaultConfig(), (array) $modules->getConfig('Mercato'), $changes);
        if (!$modules->saveConfig('Mercato', $config)) throw new WireException('Transactional email settings could not be saved.');
        foreach ($changes as $key => $value) $this->set($key, $value);
    }

    public function emailWebhookService(): MercatoEmailWebhookService {
        if (!$this->emailWebhookService) {
            $this->emailWebhookService = new MercatoEmailWebhookService($this);
            $this->emailWebhookService->setWire($this->wire());
        }
        return $this->emailWebhookService;
    }

    public function ___pushTransport(MercatoPushTransportInterface $default): MercatoPushTransportInterface { return $default; }

    public function pushNotificationService(): MercatoPushNotificationService {
        if (!$this->pushNotificationService) {
            $transport = $this->pushTransport(new MercatoApnsTransport($this));
            $this->pushNotificationService = new MercatoPushNotificationService($this, $transport);
            $this->pushNotificationService->setWire($this->wire());
        }
        return $this->pushNotificationService;
    }

    public function seoService(): MercatoSeoService {
        if (!$this->seoService) { $this->seoService = new MercatoSeoService($this); $this->seoService->setWire($this->wire()); }
        return $this->seoService;
    }

    public function seoOwner(): string {
        $modules = $this->wire('modules');
        return MercatoSeoOwnership::resolve((bool) ($modules && $modules->isInstalled('Ichiban')));
    }

    public function usesBuiltInSeo(): bool {
        return $this->seoOwner() === MercatoSeoOwnership::MERCATO;
    }

    public function privacyService(): MercatoPrivacyService {
        if (!$this->privacyService) { $this->privacyService = new MercatoPrivacyService($this); $this->privacyService->setWire($this->wire()); }
        return $this->privacyService;
    }

    public function customerAccountService(): MercatoCustomerAccountService {
        if (!$this->customerAccountService) { $this->customerAccountService = new MercatoCustomerAccountService($this); $this->customerAccountService->setWire($this->wire()); }
        return $this->customerAccountService;
    }

    public function getRuntimeCompatibilityReport(): array {
        return MercatoRuntimeCompatibility::report($this->getEnabledPaymentMethods(), (string) ($this->wire('config')->version ?? ''));
    }

    public function operationalService(): MercatoOperationalService {
        if (!$this->operationalService) { $this->operationalService = new MercatoOperationalService($this); $this->operationalService->setWire($this->wire()); }
        return $this->operationalService;
    }

    public function analyticsService(): MercatoAnalyticsService {
        if (!$this->analyticsService) { $this->analyticsService = new MercatoAnalyticsService($this); $this->analyticsService->setWire($this->wire()); }
        return $this->analyticsService;
    }

    public function setAnalyticsConsent(array $categories): array { return $this->analyticsService()->setConsent($categories); }
    public function ___analyticsAdapters(array $adapters): array { return $adapters; }

    public function ___backupStatus(array $status): array { return $status; }

    public function ___customerAccountRegistrationData(array $profile, string $email): array { return $profile; }
    public function ___customerAccountCreated(User $user): void {}
    public function ___customerAccountVerified(User $user): void {}
    public function ___customerAccountProfileUpdated(User $user, array $profile): void {}
    public function ___customerAccountClaimed(User $user, Page $order): void {}

    public function areOrderSignedLinksExpired(Page $order, ?int $now = null): bool {
        $days = max(0, (int) ($this->signed_link_retention_days ?? 3650));
        return $days > 0 && (int) $order->created > 0 && (int) $order->created <= ($now ?? time()) - ($days * 86400);
    }

    public function ___deprecationNotices(array $notices): array {
        return $notices;
    }

    public function getDeprecationNotices(): array {
        $notices = $this->deprecationNotices([]);
        $normalized = [];
        foreach ((array) $notices as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $api = trim((string) ($notice['api'] ?? ''));
            $message = trim((string) ($notice['message'] ?? ''));
            if ($api === '' || $message === '') {
                continue;
            }
            $normalized[] = [
                'api' => substr($api, 0, 120),
                'replacement' => substr(trim((string) ($notice['replacement'] ?? '')), 0, 120),
                'message' => substr($message, 0, 240),
                'remove_after' => substr(trim((string) ($notice['remove_after'] ?? '')), 0, 40),
            ];
        }
        return $normalized;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Returns the current visitor cart (lazy-initialized, reset by passing $data).
     */
    public function cart(?array $data = null): MercatoCart {
        if ($data !== null) {
            return $this->_cart = new MercatoCart($data);
        }
        if ($this->_cart === null) {
            $this->_cart = new MercatoCart();
        }
        return $this->_cart;
    }

    /**
     * Create a standalone ProductList (e.g. for invoice display).
     */
    public function productList(array $data = []): MercatoProductList {
        return new MercatoProductList($data);
    }

    public function variantService(): MercatoVariantService {
        $this->requireArchitectureClasses();
        $service = new MercatoVariantService($this);
        $service->setWire($this->wire());
        return $service;
    }

    public function marketService(): MercatoMarketService {
        $this->requireArchitectureClasses();
        if (!$this->marketService) { $this->marketService = new MercatoMarketService($this); $this->marketService->setWire($this->wire()); }
        return $this->marketService;
    }

    public function getHeadlessCheckoutQuote(array $items, array $customerData = [], array $options = []): array {
        $market = $this->marketService()->resolve((string) ($options['market_id'] ?? ''));
        $customerData['mrc_market_id'] = $market['id'];
        foreach ($items as $key => $item) {
            if (!is_array($item)) continue;
            $reference = $item['product_id'] ?? $item['id'] ?? '';
            $product = $reference !== '' ? $this->wire('pages')->get($reference) : null;
            if ($product && $product->id && $product->template && $product->template->name === 'mrc-product') {
                $items[$key] = $this->marketService()->applyToItem($product, $this->variantService()->hydrateItem($product, $item), $market['id']);
            }
        }
        $cart = $this->productList($items);
        $discountCode = trim((string) ($options['discount_code'] ?? ''));
        $email = (string) ($customerData['email'] ?? $options['email'] ?? '');
        $discount = $discountCode !== ''
            ? $this->discountService()->resolveCartDiscount($discountCode, $cart, $email, true, ['source' => 'headless_quote'])
            : ['valid' => false, 'code' => '', 'amount' => 0.0];
        if (empty($discount['valid'])) {
            $discount = ['valid' => false, 'code' => '', 'amount' => 0.0];
        }
        $this->marketService()->assertDiscountSupported($discount, $market);

        $methods = $this->marketService()->applyToFulfilmentMethods($this->fulfilmentService()->getCheckoutMethods($cart, $customerData), $market);
        $selectedType = trim((string) ($options['fulfilment_method'] ?? ''));
        $selected = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            if ($selectedType !== '' && (string) ($method['selection_key'] ?? $method['type'] ?? '') === $selectedType) {
                $selected = $method;
                break;
            }
            if (!$selected && !empty($method['available'])) {
                $selected = $method;
            }
        }
        if (!$selected) {
            $selected = $methods[0] ?? ['type' => '', 'label' => '', 'amount' => 0.0, 'available' => false];
        }

        $subtotal = round((float) $cart->getSubtotal(), 2);
        $shipping = round(max(0.0, (float) ($selected['amount'] ?? 0)), 2);
        $discount = $this->discountService()->applyFinalShippingAmount($discount, $shipping);
        $discountAmount = round(max(0.0, (float) ($discount['amount'] ?? 0)), 2);
        $taxQuote = $this->taxService()->estimate($cart, $customerData, $selected, $discount, $market['currency']);
        $taxAmount = round(max(0.0, (float) ($taxQuote['total_tax'] ?? 0)), 2);
        $taxAddedToTotal = (string) ($taxQuote['provider'] ?? 'manual') !== 'manual'
            && (string) ($taxQuote['display_mode'] ?? 'included') === 'excluded';
        $total = round(max(0.0, $subtotal + $shipping - $discountAmount + ($taxAddedToTotal ? $taxAmount : 0.0)), 2);

        $quote = [
            'items' => $cart->toArray(),
            'item_count' => $cart->count(),
            'market_id' => $market['id'],
            'commerce_context' => $this->marketService()->context($market['id']),
            'currency' => $market['currency'],
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discountAmount,
            'tax' => $taxAmount,
            'tax_quote' => $taxQuote,
            'total' => $total,
            'discount_code' => (string) ($discount['code'] ?? ''),
            'discount_valid' => !empty($discount['valid']),
            'fulfilment_method' => (string) ($selected['selection_key'] ?? $selected['type'] ?? ''),
            'fulfilment_label' => (string) ($selected['label'] ?? ''),
            'fulfilment_methods' => $methods,
            'requires_payment' => $total > 0,
        ];

        $hooked = $this->headlessCheckoutQuote($quote, [
            'items' => $items,
            'customer_data' => $customerData,
            'options' => $options,
        ]);
        return is_array($hooked) ? $hooked + $quote : $quote;
    }

    public function headlessApiService(): MercatoHeadlessApiService {
        $this->requireArchitectureClasses();
        $service = new MercatoHeadlessApiService($this); $service->setWire($this->wire()); return $service;
    }

    public function createProductBundleItem(array $bundle, array $components = [], array $context = []): array {
        $sanitizer = $this->wire('sanitizer');
        $componentItems = [];
        $price = 0.0;
        $shipping = 0.0;
        $taxRates = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $quantity = max(1, (int) ($component['quantity'] ?? 1));
            $unit = round(max(0.0, (float) ($component['price'] ?? 0)), 2);
            $shippingUnit = round(max(0.0, (float) ($component['shipping_price'] ?? 0)), 2);
            $taxRate = round(max(0.0, (float) ($component['tax_rate'] ?? 0)), 4);
            $price += $unit * $quantity;
            $shipping += $shippingUnit * $quantity;
            $taxRates[] = $taxRate;
            $componentItems[] = [
                'product_id' => max(0, (int) ($component['product_id'] ?? $component['id'] ?? 0)),
                'title' => $sanitizer->text((string) ($component['title'] ?? $component['name'] ?? '')),
                'sku' => $sanitizer->text((string) ($component['sku'] ?? '')),
                'quantity' => $quantity,
                'price' => $unit,
                'shipping_price' => $shippingUnit,
                'tax_rate' => $taxRate,
            ];
        }

        $bundleId = trim((string) ($bundle['id'] ?? $bundle['bundle_id'] ?? ''));
        if ($bundleId === '') {
            $bundleId = 'bundle-' . strtolower(substr(hash('sha256', json_encode($componentItems)), 0, 12));
        }
        $taxRate = $taxRates ? max($taxRates) : round(max(0.0, (float) ($bundle['tax_rate'] ?? 0)), 4);

        $item = [
            'id' => $bundleId,
            'key' => $bundleId,
            'uid' => $bundleId,
            'title' => $sanitizer->text((string) ($bundle['title'] ?? $bundle['name'] ?? $this->_('Bundle'))),
            'sku' => $sanitizer->text((string) ($bundle['sku'] ?? '')),
            'quantity' => max(1, (int) ($bundle['quantity'] ?? 1)),
            'price' => round(max(0.0, (float) ($bundle['price'] ?? $price)), 2),
            'shipping_price' => round(max(0.0, (float) ($bundle['shipping_price'] ?? $shipping)), 2),
            'tax_rate' => $taxRate,
            'product_type' => 'bundle',
            'components' => $componentItems,
        ];

        $hooked = $this->productBundleResolved($item, $context + ['bundle' => $bundle, 'components' => $componentItems]);
        return is_array($hooked) ? $hooked + $item : $item;
    }

    public function orderRepository(): MercatoOrderRepository {
        if ($this->orderRepository === null) {
            $this->orderRepository = new MercatoOrderRepository($this);
        }
        return $this->orderRepository;
    }

    public function getPolicyPagePaths(): array {
        return self::normalizePagePathListConfig($this->policy_pages ?? []);
    }

    public function getPolicyPages(): PageArray {
        $result = new PageArray();
        foreach ($this->getPolicyPagePaths() as $path) {
            $page = $this->wire('pages')->get('/' . ltrim($path, '/') . '/');
            if ($page && $page->id && !$page->isHidden() && !$page->isUnpublished()) {
                $result->add($page);
            }
        }
        return $result;
    }

    public function getPolicyLinksText(string $heading = 'Store policies'): string {
        $pages = $this->getPolicyPages();
        if ($pages->count() < 1) {
            return '';
        }

        $lines = [$heading . ':'];
        foreach ($pages as $page) {
            $lines[] = '- ' . (string) $page->title . ': ' . (string) $page->httpUrl();
        }
        return implode("\n", $lines);
    }

    public function getMerchantLegalDetailsText(): string {
        $details = trim((string) ($this->merchant_legal_details ?? ''));
        if ($details === '') {
            return '';
        }
        $lines = array_filter(array_map(static fn(string $line): string => trim($line), preg_split('/\R/', $details) ?: []));
        return implode("\n", $lines);
    }

    public function getTaxLabel(?Page $order = null): string {
        if ($order && $order->hasField('mrc_receipt_details')) {
            $decoded = json_decode((string) $order->mrc_receipt_details, true);
            if (is_array($decoded) && trim((string) ($decoded['tax_label'] ?? '')) !== '') {
                return self::normalizeTaxLabel($decoded['tax_label']);
            }
        }
        return self::normalizeTaxLabel($this->tax_label ?? 'VAT');
    }

    public function getDefaultTaxRate(): float {
        return self::normalizeTaxRate($this->default_tax_rate ?? 20);
    }

    public function getTaxRoundingMode(): string {
        return self::normalizeTaxRoundingMode($this->tax_rounding_mode ?? 'line');
    }

    public function getTaxDisplayMode(): string {
        return self::normalizeTaxDisplayMode($this->tax_display_mode ?? 'included');
    }

    public function buildReceiptDetailsSnapshot(?MercatoProductList $cart = null, float $shippingAmount = 0.0): array {
        $snapshot = [
            'merchant_legal_details' => $this->getMerchantLegalDetailsText(),
            'tax_label' => $this->getTaxLabel(),
            'captured_at' => date('c'),
            'currency' => (string) ($this->currency ?? ''),
        ];
        if ($cart !== null) {
            $snapshot['tax_breakdown'] = $this->getTaxRatesForOrder($cart, $shippingAmount);
        }
        return $snapshot;
    }

    public function getReceiptMerchantLegalDetails(Page $order): string {
        if ($order->hasField('mrc_receipt_details')) {
            $decoded = json_decode((string) $order->mrc_receipt_details, true);
            if (is_array($decoded)) {
                $details = trim((string) ($decoded['merchant_legal_details'] ?? ''));
                if ($details !== '') {
                    $lines = array_filter(array_map(static fn(string $line): string => trim($line), preg_split('/\R/', $details) ?: []));
                    return implode("\n", $lines);
                }
            }
        }
        return $this->getMerchantLegalDetailsText();
    }

    public function getReceiptTaxRates(Page $order, array $items, float $shippingAmount = 0.0): array {
        $stored = $this->taxService()->getStoredBreakdown($order);
        if ($stored) {
            return $stored;
        }
        if ($order->hasField('mrc_receipt_details')) {
            $decoded = json_decode((string) $order->mrc_receipt_details, true);
            $taxBreakdown = is_array($decoded) ? ($decoded['tax_breakdown'] ?? null) : null;
            if (is_array($taxBreakdown)) {
                $rates = [];
                foreach ($taxBreakdown as $rate) {
                    if (!is_array($rate)) continue;
                    $taxRate = (float) ($rate['tax_rate'] ?? $rate['taxRate'] ?? 0);
                    $sum = round((float) ($rate['sum'] ?? 0), 2);
                    if ($taxRate <= 0 || $sum < 0) continue;
                    $rates[] = [
                        'tax_rate' => $taxRate,
                        'taxRate' => $taxRate,
                        'sum' => $sum,
                    ];
                }
                if ($rates) {
                    usort($rates, static fn(array $a, array $b): int => ((float) $a['tax_rate']) <=> ((float) $b['tax_rate']));
                    return $rates;
                }
            }
        }
        return $this->getTaxRatesForOrder($this->productList($items), $shippingAmount);
    }

    public function buildAddressSnapshots(array $data, array $fulfilment = []): array {
        $this->requireArchitectureClasses();
        $method = (string) ($fulfilment['type'] ?? $data['fulfilment_method'] ?? '');
        $base = [
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'company' => (string) ($data['company'] ?? ''),
            'tax_number' => strtoupper(trim((string) ($data['tax_number'] ?? ''))),
            'purchase_order_number' => trim((string) ($data['purchase_order_number'] ?? '')),
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'address' => (string) ($data['address'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'zip' => (string) ($data['zip'] ?? ''),
            'country' => strtoupper((string) ($data['country'] ?? '')),
            'region' => strtoupper(trim((string) ($data['region'] ?? ''))),
            'delivery_window' => (string) ($data['delivery_window'] ?? ''),
            'delivery_note' => (string) ($data['delivery_note'] ?? ''),
        ];

        $billing = $base + [
            'type' => 'billing',
        ];
        $shipping = $base + [
            'type' => 'shipping',
            'fulfilment_method' => $method,
            'fulfilment_label' => (string) ($fulfilment['label'] ?? ''),
        ];

        if ($method === MercatoFulfilmentMethodType::STORE_PICKUP) {
            $pickupLocations = $this->getStorePickupLocations();
            $pickupLocationKey = trim((string) ($data['pickup_location'] ?? ''));
            $pickupLocation = $pickupLocations[$pickupLocationKey] ?? reset($pickupLocations) ?: [];
            $shipping['type'] = 'pickup';
            $shipping['address'] = '';
            $shipping['city'] = '';
            $shipping['zip'] = '';
            $shipping['country'] = '';
            $shipping['region'] = '';
            $shipping['delivery_window'] = '';
            $shipping['delivery_note'] = '';
            $shipping['pickup_location'] = (string) ($pickupLocation['label'] ?? $fulfilment['pickup_location'] ?? '');
            $shipping['pickup_location_key'] = (string) ($pickupLocation['key'] ?? $fulfilment['pickup_location_key'] ?? '');
            $shipping['pickup_address'] = (string) ($pickupLocation['address'] ?? $fulfilment['pickup_address'] ?? $this->store_pickup_address ?? '');
            $shipping['pickup_instructions'] = (string) ($pickupLocation['instructions'] ?? $fulfilment['details'] ?? '');
            $shipping['pickup_hours'] = (string) ($pickupLocation['hours'] ?? $fulfilment['pickup_hours'] ?? '');
            $shipping['pickup_code'] = $this->normalizePickupCode((string) ($data['pickup_code'] ?? '')) ?: $this->generatePickupCode();
        } elseif ($method === MercatoFulfilmentMethodType::LOCAL_DELIVERY) {
            $shipping['type'] = 'local_delivery';
            $shipping['delivery_instructions'] = (string) ($fulfilment['details'] ?? '');
        } elseif ($method === MercatoFulfilmentMethodType::CARRIER_DELIVERY || $method === '') {
            $shipping['type'] = 'carrier_delivery';
        }

        return [
            'billing' => $billing,
            'shipping' => $shipping,
        ];
    }

    public function validateBusinessTaxNumber(string $taxNumber, array $context = []): array {
        $normalized = $this->normalizeBusinessTaxNumber($taxNumber);
        $country = strtoupper(trim((string) ($context['country'] ?? '')));
        $prefix = preg_match('/^[A-Z]{2}/', $normalized) ? substr($normalized, 0, 2) : '';
        $validFormat = $normalized !== '' && (bool) preg_match('/^[A-Z]{2}[A-Z0-9]{2,14}$/', $normalized);
        $countryMismatch = $validFormat && $country !== '' && $prefix !== '' && $prefix !== $country;

        $result = [
            'tax_number' => $normalized,
            'valid' => $validFormat && !$countryMismatch,
            'validated' => false,
            'status' => $validFormat ? ($countryMismatch ? 'country_mismatch' : 'valid_format') : 'invalid_format',
            'country' => $country,
            'country_prefix' => $prefix,
            'company' => trim((string) ($context['company'] ?? '')),
            'reverse_charge' => false,
            'message' => '',
            'source' => 'format',
        ];

        $hooked = $this->businessTaxNumberValidated($result, $context);
        if (is_array($hooked)) {
            $result = $hooked + $result;
        }

        $this->recordEvent('mercato-tax', [
            'event' => 'business_tax_number_validated',
            'tax_number' => (string) ($result['tax_number'] ?? $normalized),
            'valid' => (bool) ($result['valid'] ?? false),
            'validated' => (bool) ($result['validated'] ?? false),
            'status' => (string) ($result['status'] ?? ''),
            'country' => (string) ($result['country'] ?? $country),
            'country_prefix' => (string) ($result['country_prefix'] ?? $prefix),
            'company' => (string) ($result['company'] ?? ''),
            'reverse_charge' => (bool) ($result['reverse_charge'] ?? false),
            'source' => (string) ($result['source'] ?? 'format'),
        ], 'business_tax_number_validated');

        return $result;
    }

    protected function normalizeBusinessTaxNumber(string $taxNumber): string {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', trim($taxNumber)));
    }

    public function getCustomerFulfilmentDetails(Page $order): string {
        $details = [];
        if ($order->hasField('mrc_fulfilment_details')) {
            $decoded = json_decode((string) $order->mrc_fulfilment_details, true);
            if (is_array($decoded) && trim((string) ($decoded['details'] ?? '')) !== '') {
                $details[] = trim((string) $decoded['details']);
            }
        }

        $shipping = [];
        if ($order->hasField('mrc_shipping_address')) {
            $decoded = json_decode((string) $order->mrc_shipping_address, true);
            $shipping = is_array($decoded) ? $decoded : [];
        }
        if (trim((string) ($shipping['pickup_code'] ?? '')) !== '') {
            $details[] = 'Pickup code: ' . trim((string) $shipping['pickup_code']);
        }
        if (trim((string) ($shipping['delivery_window'] ?? '')) !== '') {
            $details[] = 'Preferred delivery window: ' . trim((string) $shipping['delivery_window']);
        }
        if (trim((string) ($shipping['delivery_note'] ?? '')) !== '') {
            $details[] = 'Delivery note: ' . trim((string) $shipping['delivery_note']);
        }

        return trim(implode("\n", array_values(array_unique($details))));
    }

    protected function normalizePickupCode(string $code): string {
        $code = strtoupper(preg_replace('/[^A-Z0-9-]/', '', trim($code)) ?: '');
        return substr($code, 0, 24);
    }

    protected function generatePickupCode(): string {
        return 'PU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public function paymentService(): MercatoPaymentService {
        if ($this->paymentService === null) {
            $this->paymentService = new MercatoPaymentService($this);
        }
        return $this->paymentService;
    }

    public function refundService(): MercatoRefundService {
        if ($this->refundService === null) {
            $this->requireArchitectureClasses();
            $this->refundService = new MercatoRefundService($this);
            $this->refundService->setWire($this->wire());
        }
        return $this->refundService;
    }

    public function paymentReconciliationAuditService(): MercatoPaymentReconciliationAuditService {
        if ($this->paymentReconciliationAuditService === null) {
            $this->requireArchitectureClasses();
            $this->paymentReconciliationAuditService = new MercatoPaymentReconciliationAuditService($this);
            $this->paymentReconciliationAuditService->setWire($this->wire());
        }
        return $this->paymentReconciliationAuditService;
    }

    public function discountService(): MercatoDiscountService {
        if ($this->discountService === null) {
            $this->discountService = new MercatoDiscountService($this);
        }
        return $this->discountService;
    }

    public function webhookService(): MercatoWebhookService {
        if ($this->webhookService === null) {
            $this->webhookService = new MercatoWebhookService($this);
        }
        return $this->webhookService;
    }

    public function fulfilmentService(): MercatoFulfilmentService {
        if ($this->fulfilmentService === null) {
            $this->requireArchitectureClasses();
            $this->fulfilmentService = new MercatoFulfilmentService($this);
        }
        return $this->fulfilmentService;
    }

    public function shippingProviderService(): MercatoShippingProviderService {
        if ($this->shippingProviderService === null) {
            $this->requireArchitectureClasses();
            $this->shippingProviderService = new MercatoShippingProviderService($this);
            $this->shippingProviderService->setWire($this->wire());
        }
        return $this->shippingProviderService;
    }

    public function purchasabilityService(): MercatoPurchasabilityService {
        if ($this->purchasabilityService === null) {
            $this->requireArchitectureClasses();
            $this->purchasabilityService = new MercatoPurchasabilityService($this);
        }
        return $this->purchasabilityService;
    }

    public function quoteService(): MercatoQuoteService {
        if ($this->quoteService === null) {
            $this->requireArchitectureClasses();
            $this->quoteService = new MercatoQuoteService($this);
        }
        return $this->quoteService;
    }

    public function taxService(): MercatoTaxService {
        if ($this->taxService === null) {
            $this->requireArchitectureClasses();
            $this->taxService = new MercatoTaxService($this);
            $this->taxService->setWire($this->wire());
        }
        return $this->taxService;
    }

    public function submitQuoteRequest(array $data, ?array $items = null): Page {
        return $this->quoteService()->submit($data, $items);
    }

    public function updateQuoteStatus(Page $quote, string $status, string $note = '', ?float $amount = null): Page {
        return $this->quoteService()->updateStatus($quote, $status, $note, $amount);
    }

    public function getEnabledFulfilmentMethods(): array {
        return self::normalizeEnabledFulfilmentMethods($this->enabled_fulfilment_methods ?? []);
    }

    public function getDefaultFulfilmentMethod(): string {
        $enabled = $this->getEnabledFulfilmentMethods();
        return self::normalizeDefaultFulfilmentMethod($this->default_fulfilment_method ?? '', $enabled);
    }

    public function getReservationTtlMinutes(): int {
        return self::normalizeReservationTtlMinutes($this->reservation_ttl_minutes ?? 30);
    }

    public function getReservationCleanupSchedule(): string {
        return self::normalizeReservationCleanupSchedule($this->reservation_cleanup_schedule ?? 'every30Minutes');
    }

    public function getCartRetentionDays(): int {
        return self::normalizeRetentionDays($this->cart_retention_days ?? 30, 30, 1);
    }

    public function getDraftOrderRetentionDays(): int {
        return self::normalizeRetentionDays($this->draft_order_retention_days ?? 14, 14, 1);
    }

    public function getWebhookPayloadRetentionDays(): int {
        return self::normalizeRetentionDays($this->webhook_payload_retention_days ?? 90, 90, 1);
    }

    public function getCustomerDataRetentionDays(): int {
        return self::normalizeRetentionDays($this->customer_data_retention_days ?? 0, 0, 0);
    }

    public function getInstalledSchemaVersion(): int {
        $version = max(0, (int) ($this->installed_schema_version ?? 0));
        if ($version > 0) {
            return $version;
        }
        $modules = $this->wire('modules');
        $config = is_object($modules) && method_exists($modules, 'getConfig')
            ? (array) $modules->getConfig('Mercato')
            : [];
        return max(0, (int) ($config['installed_schema_version'] ?? 0));
    }

    public function getSchemaStatus(): array {
        $current = max(0, (int) self::SCHEMA_VERSION);
        $installed = $this->getInstalledSchemaVersion();

        return [
            'current_version' => $current,
            'installed_version' => $installed,
            'installed' => $installed > 0,
            'up_to_date' => $installed >= $current,
            'needs_repair' => $installed <= 0 || $installed < $current,
            'newer_than_code' => $installed > $current,
        ];
    }

    public function getLowStockThreshold(): int {
        return self::normalizeLowStockThreshold($this->low_stock_threshold ?? 5);
    }

    public function getFreeShippingThreshold(): float {
        return self::normalizeMoneyAmount($this->free_shipping_threshold ?? 0);
    }

    public function shouldTaxShipping(): bool {
        return !empty($this->tax_shipping);
    }

    public function getShippingTaxRate(): float {
        return self::normalizeTaxRate($this->shipping_tax_rate ?? 20);
    }

    public function getAllowedDeliveryCountries(): array {
        $normalized = self::normalizeCountryCodes($this->allowed_delivery_countries ?? '');
        return $normalized === '' ? [] : explode("\n", $normalized);
    }

    public function isDeliveryCountryAllowed(string $country): bool {
        $allowed = $this->getAllowedDeliveryCountries();
        if (!$allowed) return true;
        $country = strtoupper(preg_replace('/[^A-Z]/', '', $country) ?: '');
        return strlen($country) === 2 && in_array($country, $allowed, true);
    }

    public function getDeliveryRegionsByCountry(): array {
        $lines = preg_split('/\R+/', self::normalizeDeliveryRegions($this->delivery_regions ?? ''));
        $regions = [];
        foreach ($lines ?: [] as $line) {
            [$country, $code, $label] = array_pad(explode(':', (string) $line, 3), 3, '');
            if ($country === '' || $code === '' || $label === '') {
                continue;
            }
            $regions[$country][$code] = $label;
        }
        return $regions;
    }

    public function getDeliveryRegionsForCountry(string $country): array {
        $country = strtoupper(preg_replace('/[^A-Z]/', '', $country) ?: '');
        $regions = $this->getDeliveryRegionsByCountry();
        return strlen($country) === 2 ? ($regions[$country] ?? []) : [];
    }

    public function isDeliveryRegionAllowed(string $country, string $region): bool {
        $regions = $this->getDeliveryRegionsForCountry($country);
        if (!$regions) {
            return true;
        }
        return array_key_exists(strtoupper(trim($region)), $regions);
    }

    public function getDeliveryWindowOptions(): array {
        $lines = preg_split('/\R+/', self::normalizeDeliveryWindows($this->delivery_windows ?? ''));
        $windows = [];
        foreach ($lines ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $windows[$line] = $line;
            }
        }
        return $windows;
    }

    public function isDeliveryWindowAllowed(string $window): bool {
        $windows = $this->getDeliveryWindowOptions();
        if (!$windows) {
            return true;
        }
        return array_key_exists(trim($window), $windows);
    }

    public function getStorePickupLocations(): array {
        $lines = preg_split('/\R+/', self::normalizePickupLocations($this->store_pickup_locations ?? ''));
        $locations = [];
        foreach ($lines ?: [] as $index => $line) {
            $parts = array_map('trim', explode('|', (string) $line, 4));
            $label = (string) ($parts[0] ?? '');
            if ($label === '') continue;
            $key = 'pickup_' . ($index + 1);
            $locations[$key] = [
                'key' => $key,
                'label' => $label,
                'address' => (string) ($parts[1] ?? ''),
                'instructions' => (string) ($parts[2] ?? ''),
                'hours' => (string) ($parts[3] ?? ''),
            ];
        }
        if (!$locations) {
            $address = trim((string) ($this->store_pickup_address ?? ''));
            $instructions = trim((string) ($this->store_pickup_instructions ?? ''));
            if ($address !== '' || $instructions !== '') {
                $locations['pickup_1'] = [
                    'key' => 'pickup_1',
                    'label' => trim((string) ($this->store_pickup_label ?? '')) ?: $this->_('Store pickup'),
                    'address' => $address,
                    'instructions' => $instructions,
                    'hours' => '',
                ];
            }
        }
        return $locations;
    }

    public function isStorePickupLocationAllowed(string $key): bool {
        $locations = $this->getStorePickupLocations();
        return !$locations || array_key_exists(trim($key), $locations);
    }

    public function getLocalDeliveryMinimumOrder(): float {
        return self::normalizeMoneyAmount($this->local_delivery_minimum_order ?? 0);
    }

    public function getRecoveryEmailCooldownMinutes(): int {
        return self::normalizeRecoveryEmailCooldownMinutes($this->recovery_email_cooldown_minutes ?? 1440);
    }

    public function getRecoveryAutomationSchedule(): string {
        return self::normalizeReservationCleanupSchedule($this->recovery_automation_schedule ?? 'disabled');
    }

    public function getRecoveryAutomationMinAgeMinutes(): int {
        return self::normalizeRecoveryAutomationMinAgeMinutes($this->recovery_automation_min_age_minutes ?? 60);
    }

    public function getRecoveryAutomationBatchLimit(): int {
        return self::normalizeRecoveryAutomationBatchLimit($this->recovery_automation_batch_limit ?? 10);
    }

    public function getRecoveryDiscountCode(): string {
        $config = (array) $this->wire('modules')->getConfig('Mercato');
        return self::normalizeRecoveryDiscountCode($config['recovery_discount_code'] ?? $this->get('recovery_discount_code') ?? '');
    }

    public function getRecoverySuppressedEmails(): array {
        $normalized = self::normalizeRecoverySuppressedEmails($this->recovery_suppressed_emails ?? '');
        return $normalized === '' ? [] : explode("\n", $normalized);
    }

    public function isRecoveryEmailSuppressed(string $email): bool {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        return $email !== '' && in_array($email, $this->getRecoverySuppressedEmails(), true);
    }

    public function getRecoveryUnsubscribeUrl(string $email): string {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        return $this->getHttpRoot() . '/api/mercato/recovery-unsubscribe?' . http_build_query([
            'email' => $email,
            'token' => $this->getRecoveryUnsubscribeToken($email),
        ]);
    }

    public function getRecoveryUnsubscribeToken(string $email): string {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        return hash_hmac('sha256', 'mercato-recovery-unsubscribe|' . $email, $secret);
    }

    public function verifyRecoveryUnsubscribeToken(string $email, string $token): bool {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        return $email !== '' && hash_equals($this->getRecoveryUnsubscribeToken($email), trim($token));
    }

    protected static function sendNotificationTestEmail(array $data): array {
        $input = wire('input');
        $sanitizer = wire('sanitizer');
        $recipient = (string) $sanitizer->email((string) $input->post->text('mrc_test_email_recipient'));
        $sender = (string) $sanitizer->email((string) ($input->post->text('notification_sender_email') ?: ($data['notification_sender_email'] ?? '')));
        $senderName = trim((string) ($input->post->text('notification_sender_name') ?: ($data['notification_sender_name'] ?? 'Mercato Store')));
        $replyTo = (string) $sanitizer->email((string) ($input->post->text('notification_reply_to') ?: ($data['notification_reply_to'] ?? '')));
        $event = (string) $input->post->text('mrc_test_email_event');
        if (!in_array($event, MercatoEmailEventCatalog::EVENTS, true)) $event = 'order_confirmation';
        if ($recipient === '') {
            return self::recordNotificationTestEmail('failed', 'Enter a valid recipient email.', '');
        }
        if ($sender === '') {
            return self::recordNotificationTestEmail('failed', 'Notification sender email is not configured.', $recipient);
        }

        try {
            $definition = MercatoEmailEventCatalog::get($event);
            $rendered = MercatoEmailTemplateRenderer::render((string) $definition['subject'], (string) $definition['text'], '', ['invoice' => 'MRC-TEST', 'customer' => 'Test Customer', 'items' => '1 x Test product', 'total' => '10.00', 'receipt_link' => 'https://example.test/receipt?signed=test', 'order_status_link' => 'https://example.test/order?signed=test', 'payment_link' => 'https://example.test/pay?signed=test', 'policy_links' => 'https://example.test/policies', 'reason' => 'Test payment failure', 'refund_amount' => '5.00', 'refund_status' => 'refunded', 'tracking' => 'TEST123', 'tracking_url' => 'https://example.test/tracking', 'fulfilment_details' => 'Test fulfilment details', 'recovery_discount_line' => '', 'recovery_unsubscribe_link' => 'https://example.test/unsubscribe?signed=test', 'store_name' => $senderName, 'account_link' => 'https://example.test/account', 'security_message' => 'Test security notice']);
            $mail = wireMail();
            $mail->to($recipient)
                ->from($sender, $senderName)
                ->subject('[TEST] ' . (string) $rendered['subject'])
                ->body((string) $rendered['text']);
            if (method_exists($mail, 'bodyHTML')) $mail->bodyHTML((string) $rendered['html']);
            if ($replyTo !== '') {
                $mail->header('Reply-To', $replyTo);
            }
            if ((int) $mail->send() < 1) {
                return self::recordNotificationTestEmail('failed', 'WireMail did not report a sent message.', $recipient);
            }
            return self::recordNotificationTestEmail('sent', 'Mercato ' . $event . ' test email sent.', $recipient);
        } catch (\Throwable $e) {
            return self::recordNotificationTestEmail('failed', $e->getMessage(), $recipient);
        }
    }

    protected static function recordNotificationTestEmail(string $status, string $message, string $recipient): array {
        if (!class_exists(MercatoEventLog::class, false)) {
            require_once __DIR__ . '/src/Logging/MercatoEventLog.php';
        }
        $at = strrpos($recipient, '@');
        $payload = [
            'event' => 'test_email',
            'status' => $status,
            'order_id' => 0,
            'invoice' => '',
            'recipient' => $at === false ? '' : substr($recipient, 0, 1) . '***' . substr($recipient, $at),
            'recipient_hash' => $recipient !== '' ? hash('sha256', strtolower($recipient)) : '',
            'message' => $message,
        ];
        $log = new MercatoEventLog('mercato-notifications');
        $log->setWire(wire());
        return $log->record($payload, $status);
    }

    public function suppressRecoveryEmail(string $email, string $source = 'system'): bool {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        if ($email === '') return false;

        $modules = $this->wire('modules');
        $config = array_merge(self::getDefaultConfig(), (array) $modules->getConfig('Mercato'));
        $suppressed = $this->getRecoverySuppressedEmails();
        if (!in_array($email, $suppressed, true)) {
            $suppressed[] = $email;
        }
        $suppressed = array_values(array_unique($suppressed));
        sort($suppressed, SORT_STRING);
        $config['recovery_suppressed_emails'] = implode("\n", $suppressed);

        if (!$modules->saveConfig('Mercato', $config)) {
            return false;
        }
        $this->set('recovery_suppressed_emails', $config['recovery_suppressed_emails']);
        $this->wire('log')->save('mercato-recovery', json_encode([
            'event' => 'recovery_email',
            'status' => 'suppressed',
            'order_id' => 0,
            'invoice' => '',
            'email' => $email,
            'recipient' => $email,
            'message' => 'Email suppressed from recovery.',
            'user' => 'public',
            'source' => $source,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'suppressed');
        return true;
    }

    public function recoveryService(): MercatoRecoveryService {
        $this->requireArchitectureClasses();
        $service = new MercatoRecoveryService($this);
        $service->setWire($this->wire());
        return $service;
    }

}
