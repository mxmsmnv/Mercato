<?php
namespace ProcessWire;

trait MercatoConfigInputfields {

    public static function getModuleConfigInputfields(array $data) {
        $data    = array_merge(self::getDefaultConfig(), $data);
        $data['success_page'] = self::normalizePagePathConfig($data['success_page'], self::getDefaultConfig()['success_page']);
        $data['cancel_page'] = self::normalizePagePathConfig($data['cancel_page'], self::getDefaultConfig()['cancel_page']);
        $data['policy_pages'] = self::normalizePagePathListConfig($data['policy_pages'] ?? []);
        $data['enabled_payment_methods'] = self::normalizeEnabledPaymentMethods($data['enabled_payment_methods'] ?? []);
        $data['enabled_fulfilment_methods'] = self::normalizeEnabledFulfilmentMethods($data['enabled_fulfilment_methods'] ?? []);
        $data['default_fulfilment_method'] = self::normalizeDefaultFulfilmentMethod($data['default_fulfilment_method'] ?? '', $data['enabled_fulfilment_methods']);
        $data['invoice_prefix'] = self::normalizeInvoicePrefix($data['invoice_prefix'] ?? '');
        $data['quotes_parent'] = self::normalizePagePathConfig($data['quotes_parent'] ?? 'quotes', 'quotes');
        $data['quote_requests_enabled'] = !empty($data['quote_requests_enabled']);
        $data['quote_expiry_days'] = max(1, min(365, (int) ($data['quote_expiry_days'] ?? 30)));
        $data['quote_inventory_policy'] = in_array((string) ($data['quote_inventory_policy'] ?? 'none'), ['none', 'on_acceptance'], true)
            ? (string) $data['quote_inventory_policy']
            : 'none';
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
        $data['customer_accounts_mode'] = MercatoAccountPolicy::normalizeMode($data['customer_accounts_mode'] ?? 'disabled');
        $data['account_claim_guest_orders'] = !empty($data['account_claim_guest_orders']);
        $data['account_token_ttl_minutes'] = max(5, min(1440, (int) ($data['account_token_ttl_minutes'] ?? 60)));
        $data['account_login_attempts'] = max(1, min(20, (int) ($data['account_login_attempts'] ?? 5)));
        $data['account_login_window_seconds'] = max(60, min(86400, (int) ($data['account_login_window_seconds'] ?? 900)));
        $data['account_orders_per_page'] = max(1, min(100, (int) ($data['account_orders_per_page'] ?? 10)));
        $data['checkout_enabled'] = !empty($data['checkout_enabled']);
        $data['checkout_maintenance_message'] = substr(trim((string) ($data['checkout_maintenance_message'] ?? '')), 0, 500);
        $data['headless_api_enabled'] = !empty($data['headless_api_enabled']);
        $data['headless_api_token_ttl_minutes'] = max(5, min(10080, (int) ($data['headless_api_token_ttl_minutes'] ?? 60)));
        $data['headless_api_rate_limit_per_minute'] = max(1, min(1000, (int) ($data['headless_api_rate_limit_per_minute'] ?? 60)));
        $data['headless_api_max_body_bytes'] = max(1024, min(1048576, (int) ($data['headless_api_max_body_bytes'] ?? 65536)));
        $data['headless_api_allowed_origins'] = trim((string) ($data['headless_api_allowed_origins'] ?? ''));
        $data['preupgrade_backup_required'] = !empty($data['preupgrade_backup_required']);
        $data['backup_max_age_hours'] = max(1, min(8760, (int) ($data['backup_max_age_hours'] ?? 24)));
        $data['health_storage_min_bytes'] = max(1048576, (int) ($data['health_storage_min_bytes'] ?? 104857600));
        $data['health_cron_max_age_seconds'] = max(3600, min(2592000, (int) ($data['health_cron_max_age_seconds'] ?? 172800)));
        $data['analytics_enabled'] = !empty($data['analytics_enabled']);
        $data['analytics_adapters'] = array_values(array_unique(array_filter(array_map(static fn($value) => preg_replace('/[^a-z0-9_-]+/', '', strtolower((string) $value)), (array) ($data['analytics_adapters'] ?? ['data_layer', 'first_party'])))));
        $data['analytics_default_consent'] = (string) ($data['analytics_default_consent'] ?? 'denied') === 'granted' ? 'granted' : 'denied';
        $data['analytics_order_identifier'] = in_array((string) ($data['analytics_order_identifier'] ?? 'invoice'), ['invoice', 'hash', 'omit'], true) ? (string) $data['analytics_order_identifier'] : 'invoice';
        $data['analytics_account_identifier'] = (string) ($data['analytics_account_identifier'] ?? 'omit') === 'hash' ? 'hash' : 'omit';
        $data['low_stock_threshold'] = self::normalizeLowStockThreshold($data['low_stock_threshold'] ?? 5);
        $data['notification_locale'] = MercatoEmailTemplateRenderer::normalizeLocale((string) ($data['notification_locale'] ?? 'en'));
        $data['notification_brand_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) ($data['notification_brand_color'] ?? ''))) ? strtolower(trim((string) $data['notification_brand_color'])) : '#6b4f3a';
        $data['notification_logo_url'] = trim((string) ($data['notification_logo_url'] ?? ''));
        $data['notification_retries'] = max(0, min(5, (int) ($data['notification_retries'] ?? 2)));
        $data['enabled_notification_events'] = array_values(array_intersect(MercatoEmailEventCatalog::EVENTS, array_map('strval', (array) ($data['enabled_notification_events'] ?? MercatoEmailEventCatalog::EVENTS))));
        $data['seo_site_name'] = MercatoSeoRules::safeText((string) ($data['seo_site_name'] ?? 'Mercato Store'), 80);
        $data['seo_default_description'] = MercatoSeoRules::safeText((string) ($data['seo_default_description'] ?? ''), 160);
        $data['seo_default_robots'] = MercatoSeoRules::normalizeRobots((string) ($data['seo_default_robots'] ?? 'index,follow,max-image-preview:large'));
        $data['free_shipping_threshold'] = self::normalizeMoneyAmount($data['free_shipping_threshold'] ?? 0);
        $data['shipping_dimensions_enabled'] = !empty($data['shipping_dimensions_enabled']);
        $data['shipping_dimensions_field'] = self::normalizeShippingDimensionsField($data['shipping_dimensions_field'] ?? 'mrc_dimensions');
        $data['shipping_calculation_mode'] = self::normalizeShippingCalculationMode($data['shipping_calculation_mode'] ?? 'flat');
        $data['shipping_missing_measurements'] = self::normalizeMissingMeasurementsPolicy($data['shipping_missing_measurements'] ?? 'flat');
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
        $data['tax_shipping'] = !empty($data['tax_shipping']);
        $data['shipping_tax_rate'] = self::normalizeTaxRate($data['shipping_tax_rate'] ?? 20);
        $data['tax_provider'] = trim((string) ($data['tax_provider'] ?? 'manual')) ?: 'manual';
        $data['tax_provider_failure_policy'] = in_array((string) ($data['tax_provider_failure_policy'] ?? 'fail_closed'), ['fail_closed', 'manual_fallback', 'zero_tax'], true) ? (string) $data['tax_provider_failure_policy'] : 'fail_closed';
        $data['tax_provider_timeout_seconds'] = max(1, min(30, (int) ($data['tax_provider_timeout_seconds'] ?? 5)));
        $data['tax_provider_retries'] = max(0, min(3, (int) ($data['tax_provider_retries'] ?? 1)));
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
        $modules = wire('modules');
        $wrapper = new InputfieldWrapper();
        $module  = $modules->get('Mercato');
        $pageOptions = self::getConfigPageOptions();
        $policyPageOptions = self::getPolicyPageOptions($data['policy_pages']);

        if (wire('input')->post('mrc_run_installer')) {
            try {
                require_once __DIR__ . '/install/install.php';
                $overwrite = (bool) wire('input')->post('mrc_overwrite_template_files');
                mercato_install($module, $overwrite);
                if (!wire('modules')->isInstalled('ProcessMercato')) {
                    wire('modules')->install('ProcessMercato');
                }
                wire('session')->message(sprintf(
                    __('Mercato installer finished. Template files were %s.'),
                    $overwrite ? __('copied with overwrite enabled') : __('copied only when missing')
                ));
            } catch (\Throwable $e) {
                wire('session')->error(__('Mercato installer failed: ') . $e->getMessage());
            }
        }
        if (wire('input')->post('mrc_send_test_email')) {
            $result = self::sendNotificationTestEmail($data);
            if (($result['status'] ?? '') === 'sent') {
                wire('session')->message(__('Mercato test email sent to ') . (string) ($result['recipient'] ?? ''));
            } else {
                wire('session')->error(__('Mercato test email failed: ') . (string) ($result['message'] ?? 'Unknown error'));
            }
        }

        // --- General ---
        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('General Settings');
        $fs->collapsed = Inputfield::collapsedNo;

        $f = $modules->get('InputfieldText');
        $f->name  = 'orders_parent';
        $f->label = __('Orders parent page path');
        $f->description = __('Path relative to root, e.g. "orders". This page will hold all order child pages.');
        $f->value = $data['orders_parent'];
        $f->required = true;
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'quotes_parent';
        $f->label = __('Quote requests parent page path');
        $f->description = __('Hidden storage path for dedicated quote records. Quote requests are not orders and are excluded from revenue and fulfilment.');
        $f->value = $data['quotes_parent'];
        $f->required = true;
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'quote_requests_enabled';
        $f->label = __('Enable request-for-quote checkout');
        $f->description = __('Customers can submit the cart for merchant review without initializing payment or reserving inventory.');
        $f->attr('value', 1);
        if (!empty($data['quote_requests_enabled'])) $f->attr('checked', 'checked');
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'quote_inventory_policy';
        $f->label = __('Quote inventory policy');
        $f->description = __('Default is no reservation. Optionally reserve stock only after staff/customer acceptance and release it when the quote expires or converts.');
        $f->addOptions([
            'none' => __('Do not reserve inventory'),
            'on_acceptance' => __('Reserve when accepted'),
        ]);
        $f->value = $data['quote_inventory_policy'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'quote_expiry_days';
        $f->label = __('Quote expiry days');
        $f->value = $data['quote_expiry_days'];
        $f->min = 1;
        $f->max = 365;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldAsmSelect');
        $f->name  = 'success_page';
        $f->label = __('Success page');
        $f->description = __('Page shown after successful payment.');
        $f->addOptions($pageOptions);
        $f->value = [$data['success_page']];
        $f->columnWidth = 33;
        $f->set('sortable', false);
        $f->set('addable', true);
        $fs->add($f);

        $f = $modules->get('InputfieldAsmSelect');
        $f->name  = 'cancel_page';
        $f->label = __('Cancel / back page');
        $f->description = __('Page used when payment is canceled or the customer goes back.');
        $f->addOptions($pageOptions);
        $f->value = [$data['cancel_page']];
        $f->columnWidth = 33;
        $f->set('sortable', false);
        $f->set('addable', true);
        $fs->add($f);

        $f = $modules->get('InputfieldAsmSelect');
        $f->name  = 'policy_pages';
        $f->label = __('Checkout policy pages');
        $f->description = __('Optional public pages linked below checkout. Use this for terms, privacy, refund, and shipping policies.');
        $f->addOptions($policyPageOptions);
        $f->value = $data['policy_pages'];
        $f->columnWidth = 100;
        $f->set('sortable', true);
        $f->set('addable', true);
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name  = 'currency';
        $f->label = __('Currency code (ISO 4217)');
        $f->value = $data['currency'];
        $f->columnWidth = 25;
        $fs->add($f);

        foreach (['email_log_retention_days' => __('Email log retention'), 'payment_attempt_retention_days' => __('Payment-attempt log retention'), 'operational_log_retention_days' => __('Operational log retention')] as $name => $label) { $f = $modules->get('InputfieldInteger'); $f->name = $name; $f->label = $label; $f->description = __('Rows older than this are redacted while event status and financial linkage remain.'); $f->value = $data[$name]; $f->min = 1; $f->max = 3650; $f->columnWidth = 25; $fs->add($f); }
        foreach (['provider_reference_retention_days' => __('Failed provider-reference retention'), 'signed_link_retention_days' => __('Signed customer-link lifetime')] as $name => $label) { $f = $modules->get('InputfieldInteger'); $f->name = $name; $f->label = $label; $f->description = __('Days; use 0 to retain without automatic expiry. Paid financial references are not automatically removed.'); $f->value = $data[$name]; $f->min = 0; $f->max = 3650; $f->columnWidth = 25; $fs->add($f); }
        $f = $modules->get('InputfieldSelect'); $f->name = 'privacy_retention_schedule'; $f->label = __('Privacy retention schedule'); foreach (self::getReservationCleanupScheduleOptions() as $value => $label) $f->addOption($value, __($label)); $f->value = $data['privacy_retention_schedule']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldInteger'); $f->name = 'privacy_retention_batch_limit'; $f->label = __('Privacy batch limit'); $f->value = $data['privacy_retention_batch_limit']; $f->min = 1; $f->max = 500; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldText'); $f->name = 'privacy_policy_version'; $f->label = __('Privacy policy version'); $f->value = $data['privacy_policy_version']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldTextarea'); $f->name = 'privacy_backup_retention_note'; $f->label = __('Backup/export retention policy note'); $f->description = __('Document backup deletion windows, processor copies, cached exports, and restoration procedures. Technical cleanup cannot erase external backups.'); $f->value = (string) ($data['privacy_backup_retention_note'] ?? ''); $f->rows = 3; $f->columnWidth = 75; $fs->add($f);

        $f = $modules->get('InputfieldSelect'); $f->name = 'customer_accounts_mode'; $f->label = __('Customer accounts'); $f->addOption('disabled', __('Disabled')); $f->addOption('optional', __('Optional; guest checkout remains available')); $f->addOption('required_verified', __('Verified account required at checkout')); $f->value = $data['customer_accounts_mode']; $f->columnWidth = 33; $fs->add($f);
        $f = $modules->get('InputfieldCheckbox'); $f->name = 'account_claim_guest_orders'; $f->label = __('Allow verified guest-order claims'); $f->description = __('Requires a one-time confirmation sent to the exact order email address.'); $f->checked = $data['account_claim_guest_orders']; $f->columnWidth = 33; $fs->add($f);
        foreach (['account_token_ttl_minutes' => __('Account link lifetime (minutes)'), 'account_login_attempts' => __('Login attempts per window'), 'account_login_window_seconds' => __('Login rate-limit window (seconds)'), 'account_orders_per_page' => __('Orders per account page')] as $name => $label) { $f = $modules->get('InputfieldInteger'); $f->name = $name; $f->label = $label; $f->value = $data[$name]; $f->min = 1; $f->columnWidth = 25; $fs->add($f); }
        $f = $modules->get('InputfieldCheckbox'); $f->name = 'checkout_enabled'; $f->label = __('Checkout enabled'); $f->description = __('Disable checkout independently while keeping catalog, signed order pages, webhooks, and admin recovery available.'); $f->checked = $data['checkout_enabled']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldText'); $f->name = 'checkout_maintenance_message'; $f->label = __('Checkout maintenance message'); $f->value = $data['checkout_maintenance_message']; $f->columnWidth = 75; $fs->add($f);
        $f = $modules->get('InputfieldCheckbox'); $f->name = 'headless_api_enabled'; $f->label = __('Headless API v1 enabled'); $f->description = __('Versioned JSON catalog, quote, guest checkout, payment and order-status resources.'); $f->checked = $data['headless_api_enabled']; $f->columnWidth = 25; $fs->add($f);
        foreach (['headless_api_token_ttl_minutes'=>__('API token lifetime (minutes)'), 'headless_api_rate_limit_per_minute'=>__('API requests per minute'), 'headless_api_max_body_bytes'=>__('API maximum JSON bytes')] as $name=>$label) { $f=$modules->get('InputfieldInteger'); $f->name=$name; $f->label=$label; $f->value=$data[$name]; $f->min=1; $f->columnWidth=25; $fs->add($f); }
        $f=$modules->get('InputfieldTextarea'); $f->name='headless_api_allowed_origins'; $f->label=__('Headless API browser origins'); $f->description=__('One exact HTTPS origin per line. Leave blank for native/server clients only; wildcard origins are rejected.'); $f->value=$data['headless_api_allowed_origins']; $f->rows=3; $f->columnWidth=100; $fs->add($f);
        $f = $modules->get('InputfieldCheckbox'); $f->name = 'preupgrade_backup_required'; $f->label = __('Require fresh evidence before schema upgrades'); $f->checked = $data['preupgrade_backup_required']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldInteger'); $f->name = 'backup_max_age_hours'; $f->label = __('Maximum backup age (hours)'); $f->value = $data['backup_max_age_hours']; $f->min = 1; $f->max = 8760; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldText'); $f->name = 'health_check_token'; $f->label = __('Detailed health bearer token'); $f->description = __('Send as Authorization: Bearer; never put it in the URL or monitoring output.'); $f->value = (string) ($data['health_check_token'] ?? ''); $f->attr('type', 'password'); $f->columnWidth = 50; $fs->add($f);
        foreach (['health_storage_min_bytes' => __('Minimum free storage bytes'), 'health_cron_max_age_seconds' => __('Maximum scheduler silence (seconds)')] as $name => $label) { $f = $modules->get('InputfieldInteger'); $f->name = $name; $f->label = $label; $f->value = $data[$name]; $f->min = 1; $f->columnWidth = 25; $fs->add($f); }
        $f = $modules->get('InputfieldCheckbox'); $f->name = 'analytics_enabled'; $f->label = __('Consent-aware analytics'); $f->description = __('Commerce continues when analytics is disabled, denied, or an adapter fails.'); $f->checked = $data['analytics_enabled']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldCheckboxes'); $f->name = 'analytics_adapters'; $f->label = __('Analytics adapters'); $f->addOption('data_layer', __('Browser dataLayer')); $f->addOption('first_party', __('Redacted first-party log')); $f->value = $data['analytics_adapters']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldSelect'); $f->name = 'analytics_default_consent'; $f->label = __('Default analytics consent'); $f->addOption('denied', __('Denied until customer choice')); $f->addOption('granted', __('Granted (only where lawful)')); $f->value = $data['analytics_default_consent']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldSelect'); $f->name = 'analytics_order_identifier'; $f->label = __('Analytics order identifier'); foreach (['invoice' => __('Invoice/public reference'), 'hash' => __('SHA-256 hash'), 'omit' => __('Omit')] as $value => $label) $f->addOption($value, $label); $f->value = $data['analytics_order_identifier']; $f->columnWidth = 25; $fs->add($f);
        $f = $modules->get('InputfieldSelect'); $f->name = 'analytics_account_identifier'; $f->label = __('Analytics account identifier'); $f->addOption('omit', __('Omit')); $f->addOption('hash', __('Salted SHA-256 hash')); $f->value = $data['analytics_account_identifier']; $f->columnWidth = 25; $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name  = 'currency_symbol';
        $f->label = __('Currency symbol');
        $f->value = $data['currency_symbol'];
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name  = 'currency_symbol_position';
        $f->label = __('Symbol position');
        $f->addOption('before', __('Before amount (€ 12.99)'));
        $f->addOption('after',  __('After amount (12.99 €)'));
        $f->value = $data['currency_symbol_position'];
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'invoice_prefix';
        $f->label = __('Invoice prefix');
        $f->description = __('Optional prefix for newly issued invoice numbers, e.g. INV-. Existing invoice numbers are preserved.');
        $f->value = $data['invoice_prefix'];
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name  = 'production';
        $f->label = __('Production mode');
        $f->description = __('When enabled, live gateway keys are used instead of test keys.');
        $f->attr('value', 1);
        if (!empty($data['production'])) $f->attr('checked', 'checked');
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'production_activation_confirmed';
        $f->label = __('Production activation confirmation');
        $f->label2 = __('I reviewed live credentials, HTTPS webhooks, required events, smoke tests, reconciliation, and rollback.');
        $f->description = __('Required only when changing production mode from off to on. This confirmation is not stored.');
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'gateway_timeout_seconds'; $f->label = __('Gateway request timeout (seconds)'); $f->value = $data['gateway_timeout_seconds']; $f->min = 3; $f->max = 120; $f->columnWidth = 50; $fs->add($f);
        $f = $modules->get('InputfieldInteger');
        $f->name = 'gateway_retries'; $f->label = __('Gateway transient retries'); $f->value = $data['gateway_retries']; $f->min = 0; $f->max = 3; $f->columnWidth = 50; $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'reservation_ttl_minutes';
        $f->label = __('Reservation TTL');
        $f->description = __('How long pending checkout orders reserve stock before payment confirmation. Range: 1-1440 minutes.');
        $f->value = $data['reservation_ttl_minutes'];
        $f->min = 1;
        $f->max = 1440;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'reservation_cleanup_schedule';
        $f->label = __('Reservation cleanup');
        foreach (self::getReservationCleanupScheduleOptions() as $value => $label) {
            $f->addOption($value, __($label));
        }
        $f->value = $data['reservation_cleanup_schedule'];
        $f->description = __('Automatic cleanup uses ProcessWire LazyCron. Manual cleanup remains available in the Launch tab.');
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'cart_retention_days';
        $f->label = __('Cart retention');
        $f->description = __('How many days abandoned cart/session policy should retain cart context. Range: 1-3650 days. This setting documents policy; it does not delete paid orders.');
        $f->value = $data['cart_retention_days'];
        $f->min = 1;
        $f->max = 3650;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'draft_order_retention_days';
        $f->label = __('Draft order retention');
        $f->description = __('How many days unpaid draft/pending order data may remain before manual or LazyCron cleanup. Range: 1-3650 days.');
        $f->value = $data['draft_order_retention_days'];
        $f->min = 1;
        $f->max = 3650;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'webhook_payload_retention_days';
        $f->label = __('Webhook payload retention');
        $f->description = __('How many days redacted webhook/event payload context should remain available for diagnostics. Range: 1-3650 days.');
        $f->value = $data['webhook_payload_retention_days'];
        $f->min = 1;
        $f->max = 3650;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'customer_data_retention_days';
        $f->label = __('Customer data retention');
        $f->description = __('Optional policy horizon for customer data review. Use 0 to retain until an explicit admin policy/manual cleanup exists. Paid orders are never silently deleted.');
        $f->value = $data['customer_data_retention_days'];
        $f->min = 0;
        $f->max = 3650;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'low_stock_threshold';
        $f->label = __('Default low-stock threshold');
        $f->description = __('Products at or below this stock level are flagged in the dashboard. Per-product thresholds override this value.');
        $f->value = $data['low_stock_threshold'];
        $f->min = 0;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'frontend_framework';
        $f->label = __('Frontend framework');
        foreach (self::getFrontendFrameworkOptions() as $value => $label) {
            $f->addOption($value, __($label));
        }
        $f->value = $data['frontend_framework'];
        $f->description = __('Controls the default Mercato frontend templates: product, checkout, and success.');
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckboxes');
        $f->name = 'enabled_payment_methods';
        $f->label = __('Enabled payment methods');
        $f->description = __('Only selected methods are shown on checkout and accepted by the payment flow. Demo Payment is always unavailable in production mode.');
        $paymentMethodOptions = [];
        foreach (self::getPaymentMethodOptions() as $value => $label) {
            $paymentMethodOptions[$value] = __($label);
        }
        $f->addOptions($paymentMethodOptions);
        $f->value = $data['enabled_payment_methods'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'bank_transfer_instructions';
        $f->label = __('Bank transfer instructions');
        $f->description = __('Shown after checkout when Bank transfer / invoice is selected. Include bank details, reference rules, and expected processing time.');
        $f->value = $data['bank_transfer_instructions'];
        $f->rows = 3;
        $f->columnWidth = 100;
        $fs->add($f);

        $wrapper->add($fs);

        // --- Advanced / Debug ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Advanced / Debug');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Production readiness');
        $f->value = self::renderProductionReadinessNotice($data);
        $f->description = __('Review this before enabling production mode. Launch checklist repeats these checks with live site context.');
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'shipping_provider'; $f->label = __('Live shipping provider key');
        $f->description = __('Use manual to keep flat/weight-band shipping, reference for the credential-free development adapter, or a key registered through shippingProviders.');
        $f->value = $data['shipping_provider']; $f->columnWidth = 34; $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'shipping_provider_failure_policy'; $f->label = __('Live-rate failure policy');
        $f->addOptions(['manual_fallback' => __('Use configured manual carrier rate'), 'fail_closed' => __('Make checkout unavailable')]);
        $f->value = $data['shipping_provider_failure_policy']; $f->columnWidth = 33; $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'shipping_provider_include_manual_rates'; $f->label = __('Manual carrier option'); $f->label2 = __('Show manual rates alongside successful live rates');
        $f->checked = !empty($data['shipping_provider_include_manual_rates']); $f->columnWidth = 33; $fs->add($f);

        foreach ([['shipping_provider_timeout_seconds', __('Provider timeout (seconds)'), 1, 30], ['shipping_provider_retries', __('Provider retries'), 0, 3], ['shipping_provider_quote_ttl_seconds', __('Quote TTL (seconds)'), 60, 86400]] as [$name, $label, $min, $max]) {
            $f = $modules->get('InputfieldInteger'); $f->name = $name; $f->label = $label; $f->value = $data[$name]; $f->min = $min; $f->max = $max; $f->columnWidth = 33; $fs->add($f);
        }

        $f = $modules->get('InputfieldSelect'); $f->name = 'shipping_provider_package_mode'; $f->label = __('Parcel strategy');
        $f->addOptions(['combined' => __('Combine measured items'), 'per_item' => __('One parcel per item quantity')]); $f->value = $data['shipping_provider_package_mode']; $f->columnWidth = 33; $fs->add($f);
        $f = $modules->get('InputfieldFloat'); $f->name = 'shipping_provider_handling_fixed'; $f->label = __('Fixed handling adjustment'); $f->value = $data['shipping_provider_handling_fixed']; $f->columnWidth = 33; $fs->add($f);
        $f = $modules->get('InputfieldFloat'); $f->name = 'shipping_provider_handling_percent'; $f->label = __('Handling adjustment (%)'); $f->value = $data['shipping_provider_handling_percent']; $f->columnWidth = 34; $fs->add($f);
        $f = $modules->get('InputfieldTextarea'); $f->name = 'shipping_provider_origin'; $f->label = __('Shipping origin (JSON)'); $f->description = __('Provider-neutral origin address; do not put credentials here.'); $f->value = $data['shipping_provider_origin']; $f->rows = 4; $f->columnWidth = 50; $fs->add($f);
        $f = $modules->get('InputfieldTextarea'); $f->name = 'shipping_provider_service_map'; $f->label = __('Service mapping (JSON)'); $f->description = __('Map provider service codes to enabled and label values.'); $f->value = $data['shipping_provider_service_map']; $f->rows = 4; $f->columnWidth = 50; $fs->add($f);
        $f = $modules->get('InputfieldTextarea'); $f->name = 'shipping_provider_allowed_regions'; $f->label = __('Live-rate countries / regions'); $f->description = __('Optional comma/newline codes such as US or US:NY.'); $f->value = $data['shipping_provider_allowed_regions']; $f->rows = 3; $f->columnWidth = 100; $fs->add($f);
        $f = $modules->get('InputfieldPassword'); $f->name = 'shipping_provider_webhook_secret'; $f->label = __('Reference tracking webhook secret'); $f->description = __('Used only by the reference adapter fixture. Production adapters should store credentials in their own module configuration.'); $f->value = $data['shipping_provider_webhook_secret']; $f->columnWidth = 100; $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'frontend_auto_assets';
        $f->label = __('Automatic asset loading');
        $f->label2 = __('Load CDN assets for the selected frontend framework.');
        $f->description = __('Disable this when your site theme already provides Tailwind, Bootstrap, or UIkit.');
        $f->checked = !empty($data['frontend_auto_assets']);
        $f->columnWidth = 25;
        $fs->add($f);

        foreach ([
            'frontend_tailwind_cdn_url' => __('Tailwind CDN URL'),
            'frontend_bootstrap_cdn_url' => __('Bootstrap CSS CDN URL'),
            'frontend_uikit_cdn_url' => __('UIkit CSS CDN URL'),
        ] as $name => $label) {
            $f = $modules->get('InputfieldText');
            $f->name = $name;
            $f->label = $label;
            $f->description = __('Used when automatic asset loading is enabled for the matching framework. Leave blank to use the Mercato default CDN URL.');
            $f->value = $data[$name];
            $f->columnWidth = 33;
            $fs->add($f);
        }

        $wrapper->add($fs);

        // --- Fulfilment ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Fulfilment Methods');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldCheckboxes');
        $f->name = 'enabled_fulfilment_methods';
        $f->label = __('Available checkout methods');
        $f->description = __('Select how customers can receive products. At least carrier delivery remains enabled as a fallback.');
        $fulfilmentOptions = [];
        foreach (self::getFulfilmentMethodOptions() as $value => $label) {
            $fulfilmentOptions[$value] = __($label);
        }
        $f->addOptions($fulfilmentOptions);
        $f->value = $data['enabled_fulfilment_methods'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'default_fulfilment_method';
        $f->label = __('Default checkout method');
        $f->description = __('Shown first and preselected when the customer has not chosen a fulfilment method.');
        $f->addOptions($fulfilmentOptions);
        $f->value = $data['default_fulfilment_method'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'carrier_delivery_label';
        $f->label = __('Carrier delivery label');
        $f->description = __('Uses the shipping price configured on products, unless the free-shipping threshold is met.');
        $f->value = $data['carrier_delivery_label'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'free_shipping_threshold';
        $f->label = __('Free shipping threshold');
        $f->description = __('Set to 0 to disable. Applies to carrier delivery when the cart subtotal reaches this amount.');
        $f->value = (float) $data['free_shipping_threshold'];
        $f->min = 0;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'shipping_dimensions_enabled';
        $f->label = __('Weight and dimensions');
        $f->label2 = __('Use optional FieldtypeDimensions shipping rates');
        $f->description = wire('modules')->isInstalled('FieldtypeDimensions')
            ? __('Run the installer after enabling this option to create or attach the configured field to Mercato products.')
            : __('FieldtypeDimensions is not installed. Flat product shipping remains available.');
        $f->checked = !empty($data['shipping_dimensions_enabled']);
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'shipping_dimensions_field';
        $f->label = __('Dimensions field');
        $f->value = $data['shipping_dimensions_field'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'shipping_calculation_mode';
        $f->label = __('Carrier rate calculation');
        $f->addOptions([
            'flat' => __('Product flat rates'),
            'actual_weight' => __('Total actual weight'),
            'dimensional_weight' => __('Dimensional weight'),
            'max_weight' => __('Greater of actual and dimensional weight'),
        ]);
        $f->value = $data['shipping_calculation_mode'];
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'shipping_dimensional_divisor';
        $f->label = __('Dimensional divisor (cm³/kg)');
        $f->description = __('Common carrier values are 5000 or 6000.');
        $f->value = (float) $data['shipping_dimensional_divisor'];
        $f->min = 1;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'shipping_missing_measurements';
        $f->label = __('Products without measurements');
        $f->addOptions([
            'flat' => __('Use existing flat shipping for the cart'),
            'ignore' => __('Ignore unmeasured products'),
            'unavailable' => __('Make carrier delivery unavailable'),
        ]);
        $f->value = $data['shipping_missing_measurements'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'shipping_rate_table';
        $f->label = __('Weight rate table');
        $f->description = __('One band per line: scope|min kg|max kg|rate|label. Scope is *, ISO country, or country:region. Example: GB|0|1|4.95|Small parcel');
        $f->value = $data['shipping_rate_table'];
        $f->rows = 6;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'tax_shipping';
        $f->label = __('Tax shipping');
        $f->label2 = __('Include carrier/local delivery fees in the tax breakdown');
        $f->description = __('Mercato keeps prices gross; this controls the displayed tax breakdown, not the payable total.');
        $f->checked = !empty($data['tax_shipping']);
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'default_tax_rate';
        $f->label = __('Default product tax rate (%)');
        $f->description = __('Used for manual custom lines and imported products when no tax_rate is provided. Existing product rates and explicit CSV values are preserved.');
        $f->value = (float) $data['default_tax_rate'];
        $f->min = 0;
        $f->max = 100;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'tax_provider';
        $f->label = __('Tax provider key');
        $f->description = __('Use manual to preserve product tax rates, or the key registered by a module implementing MercatoTaxProviderInterface. Tax configuration is not accounting or legal advice.');
        $f->value = $data['tax_provider'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'tax_provider_failure_policy';
        $f->label = __('Tax provider failure policy');
        $f->addOptions(['fail_closed' => __('Block checkout'), 'manual_fallback' => __('Use manual rates and record fallback'), 'zero_tax' => __('Use explicit zero-tax fallback')]);
        $f->value = $data['tax_provider_failure_policy'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'tax_provider_timeout_seconds';
        $f->label = __('Tax timeout (seconds)');
        $f->value = $data['tax_provider_timeout_seconds'];
        $f->min = 1; $f->max = 30; $f->columnWidth = 17;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'tax_provider_retries';
        $f->label = __('Tax retries');
        $f->value = $data['tax_provider_retries'];
        $f->min = 0; $f->max = 3; $f->columnWidth = 17;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'tax_registrations';
        $f->label = __('Tax registrations (JSON)');
        $f->description = __('Provider-neutral merchant registration records. Do not store provider secrets here.');
        $f->value = $data['tax_registrations'];
        $f->rows = 4; $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'tax_nexus_regions';
        $f->label = __('Tax nexus / regions');
        $f->description = __('Comma or newline separated provider-neutral jurisdiction codes.');
        $f->value = $data['tax_nexus_regions'];
        $f->rows = 4; $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'tax_display_mode';
        $f->label = __('Tax display mode');
        $f->description = __('Controls tax breakdown display. Included keeps the current gross-price model; No tax hides tax rows without changing payable totals.');
        $displayOptions = [];
        foreach (self::getTaxDisplayModeOptions() as $value => $label) {
            $displayOptions[$value] = __($label);
        }
        $f->addOptions($displayOptions);
        $f->value = $data['tax_display_mode'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'tax_label';
        $f->label = __('Tax label');
        $f->description = __('Shown in checkout, success, receipts, and receipt snapshots. Examples: VAT, GST, Sales tax.');
        $f->value = $data['tax_label'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'tax_rounding_mode';
        $f->label = __('Tax rounding mode');
        $f->description = __('Controls how displayed tax totals are rounded. Stored order totals remain frozen after checkout.');
        $roundingOptions = [];
        foreach (self::getTaxRoundingModeOptions() as $value => $label) {
            $roundingOptions[$value] = __($label);
        }
        $f->addOptions($roundingOptions);
        $f->value = $data['tax_rounding_mode'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'shipping_tax_rate';
        $f->label = __('Shipping tax rate (%)');
        $f->value = (float) $data['shipping_tax_rate'];
        $f->min = 0;
        $f->max = 100;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'allowed_delivery_countries';
        $f->label = __('Allowed delivery countries');
        $f->description = __('Optional ISO-2 country allowlist for carrier and local delivery, e.g. GB, FR, DE. Leave blank to allow all countries.');
        $f->value = $data['allowed_delivery_countries'];
        $f->rows = 3;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'delivery_regions';
        $f->label = __('Delivery regions by country');
        $f->description = __('Optional checkout region/state list, one per line: US: CA California, US: NY New York, CA: QC Quebec. When a country has entries, checkout requires one of those region codes.');
        $f->value = $data['delivery_regions'];
        $f->rows = 5;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'store_pickup_label';
        $f->label = __('Pickup label');
        $f->value = $data['store_pickup_label'];
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'local_delivery_label';
        $f->label = __('Local delivery label');
        $f->value = $data['local_delivery_label'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'store_pickup_address';
        $f->label = __('Pickup location');
        $f->description = __('Address shown to customers choosing pickup.');
        $f->value = $data['store_pickup_address'];
        $f->rows = 3;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'store_pickup_instructions';
        $f->label = __('Pickup instructions');
        $f->value = $data['store_pickup_instructions'];
        $f->rows = 3;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'store_pickup_locations';
        $f->label = __('Pickup locations');
        $f->description = __('Optional multiple pickup locations, one per line: Name | Address | Instructions | Hours. When empty, the single pickup location and instructions above are used.');
        $f->value = $data['store_pickup_locations'];
        $f->rows = 4;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'delivery_windows';
        $f->label = __('Checkout delivery windows');
        $f->description = __('Optional managed checkout time windows, one per line. When configured, checkout shows a select field and rejects values outside this list.');
        $f->value = $data['delivery_windows'];
        $f->rows = 4;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'local_delivery_fee';
        $f->label = __('Local delivery fee');
        $f->value = (float) $data['local_delivery_fee'];
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldFloat');
        $f->name = 'local_delivery_minimum_order';
        $f->label = __('Local delivery minimum order');
        $f->description = __('Set to 0 to disable. Local delivery is unavailable below this cart subtotal.');
        $f->value = (float) $data['local_delivery_minimum_order'];
        $f->min = 0;
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'local_delivery_postcodes';
        $f->label = __('Local postal-code prefixes');
        $f->description = __('Comma-separated prefixes. Leave blank to allow all postcodes.');
        $f->value = $data['local_delivery_postcodes'];
        $f->columnWidth = 25;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'local_delivery_instructions';
        $f->label = __('Local delivery instructions');
        $f->value = $data['local_delivery_instructions'];
        $f->rows = 3;
        $f->columnWidth = 25;
        $fs->add($f);

        $wrapper->add($fs);

        // --- Merchant details ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Merchant Details');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'merchant_legal_details';
        $f->label = __('Receipt merchant details');
        $f->description = __('Optional seller/legal details shown on printable receipts, e.g. company name, address, registration number, VAT number.');
        $f->value = $data['merchant_legal_details'];
        $f->rows = 5;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'receipt_template_file';
        $f->label = __('Receipt template file');
        $f->description = __('Optional PHP template relative to /site/templates/, e.g. mercato-receipt.php. Leave blank to use the built-in printable receipt.');
        $f->value = $data['receipt_template_file'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'order_status_template_file';
        $f->label = __('Order status template file');
        $f->description = __('Optional PHP template relative to /site/templates/, e.g. mercato-order-status.php. Leave blank to use the built-in signed order-status page.');
        $f->value = $data['order_status_template_file'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'receipt_pdf_url_template';
        $f->label = __('Receipt PDF URL template');
        $f->description = __('Optional external PDF renderer URL. Variables: {order_id}, {invoice}, {token}, {receipt_link}. Leave blank to use printable HTML only.');
        $f->value = $data['receipt_pdf_url_template'];
        $f->columnWidth = 100;
        $fs->add($f);

        $wrapper->add($fs);

        // --- Storefront SEO ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Storefront SEO');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldText'); $f->name = 'seo_site_name'; $f->label = __('Site/organization name'); $f->value = $data['seo_site_name']; $f->columnWidth = 50; $fs->add($f);
        $f = $modules->get('InputfieldText'); $f->name = 'seo_default_robots'; $f->label = __('Default robots directive'); $f->value = $data['seo_default_robots']; $f->description = __('Private and tokenized commerce pages are always noindex regardless of this value.'); $f->columnWidth = 50; $fs->add($f);
        $f = $modules->get('InputfieldTextarea'); $f->name = 'seo_default_description'; $f->label = __('Fallback meta description'); $f->value = $data['seo_default_description']; $f->maxlength = 160; $f->rows = 3; $f->columnWidth = 100; $fs->add($f);
        foreach (['seo_social_image_url' => __('Default social image URL'), 'seo_organization_logo_url' => __('Organization logo URL')] as $name => $label) { $f = $modules->get('InputfieldURL'); if (!$f) $f = $modules->get('InputfieldText'); $f->name = $name; $f->label = $label; $f->description = __('Use a public HTTPS URL.'); $f->value = (string) ($data[$name] ?? ''); $f->columnWidth = 50; $fs->add($f); }
        $f = $modules->get('InputfieldMarkup'); $f->label = __('Sitemap and overrides'); $f->value = '<p><a href="' . htmlspecialchars(rtrim((string) wire('config')->urls->root, '/') . '/sitemap-mercato.xml', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">/sitemap-mercato.xml</a></p><p>Project modules can hook <code>storefrontSeoMetadata</code>, <code>storefrontSeoAlternates</code>, and <code>storefrontSitemapEntries</code>.</p>'; $fs->add($f);
        $wrapper->add($fs);

        // --- Notifications ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Email Notifications');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldText');
        $f->name = 'notification_sender_name';
        $f->label = __('Sender name');
        $f->value = $data['notification_sender_name'];
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldEmail');
        if (!$f) $f = $modules->get('InputfieldText');
        $f->name = 'notification_sender_email';
        $f->label = __('Sender email');
        $f->description = __('Required before order confirmations or fulfilment emails can be sent.');
        $f->value = $data['notification_sender_email'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldEmail');
        if (!$f) $f = $modules->get('InputfieldText');
        $f->name = 'notification_reply_to';
        $f->label = __('Reply-to email');
        $f->value = $data['notification_reply_to'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'notification_transport';
        $f->label = __('Email transport');
        $f->addOption('wiremail', 'ProcessWire WireMail');
        $f->value = (string) ($data['notification_transport'] ?? 'wiremail');
        $f->description = __('External providers can replace the transport through the Mercato::emailTransport hook.');
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'notification_locale';
        $f->label = __('Email locale');
        $f->value = (string) $data['notification_locale'];
        $f->description = __('Locale used for safe template overrides, for example en or de_de.');
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'notification_brand_color';
        $f->label = __('Email brand color');
        $f->value = (string) $data['notification_brand_color'];
        $f->description = __('Six-digit hex color, for example #6b4f3a.');
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldURL');
        if (!$f) $f = $modules->get('InputfieldText');
        $f->name = 'notification_logo_url';
        $f->label = __('Email logo URL');
        $f->value = (string) $data['notification_logo_url'];
        $f->description = __('Optional public HTTPS logo URL.');
        $f->columnWidth = 67;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'notification_retries';
        $f->label = __('Delivery retries');
        $f->min = 0; $f->max = 5;
        $f->value = (int) $data['notification_retries'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckboxes');
        $f->name = 'enabled_notification_events';
        $f->label = __('Enabled transactional events');
        foreach (MercatoEmailEventCatalog::EVENTS as $emailEvent) $f->addOption($emailEvent, ucwords(str_replace('_', ' ', $emailEvent)));
        $f->value = $data['enabled_notification_events'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldEmail');
        if (!$f) $f = $modules->get('InputfieldText');
        $f->name = 'quote_merchant_email';
        $f->label = __('Quote request recipient');
        $f->description = __('Merchant address notified when a customer submits a quote request.');
        $f->value = $data['quote_merchant_email'];
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'quote_customer_email_subject';
        $f->label = __('Quote submission subject');
        $f->description = __('Variables: {quote}, {customer}, {total}, {status_link}.');
        $f->value = $data['quote_customer_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'quote_customer_email_body';
        $f->label = __('Quote submission body');
        $f->description = __('Variables: {quote}, {customer}, {total}, {status}, {status_link}.');
        $f->value = $data['quote_customer_email_body'];
        $f->rows = 5;
        $f->columnWidth = 100;
        $fs->add($f);

        $previewEvent = (string) wire('input')->post->text('mrc_test_email_event');
        if (!in_array($previewEvent, MercatoEmailEventCatalog::EVENTS, true)) $previewEvent = 'order_confirmation';
        $f = $modules->get('InputfieldSelect');
        $f->name = 'mrc_test_email_event';
        $f->label = __('Preview/test template');
        foreach (MercatoEmailEventCatalog::EVENTS as $emailEvent) $f->addOption($emailEvent, ucwords(str_replace('_', ' ', $emailEvent)));
        $f->value = $previewEvent;
        $f->columnWidth = 34;
        $fs->add($f);

        $sample = ['invoice' => 'MRC-00123', 'customer' => 'Alex Customer', 'items' => '1 x Sample product', 'total' => '£49.00', 'receipt_link' => 'https://store.example/receipt?signed=preview', 'order_status_link' => 'https://store.example/status?signed=preview', 'payment_link' => 'https://store.example/pay?signed=preview', 'policy_links' => 'https://store.example/policies', 'reason' => 'The payment provider declined the attempt.', 'refund_amount' => '£10.00', 'refund_status' => 'partially refunded', 'tracking' => 'TRACK123', 'tracking_url' => 'https://carrier.example/TRACK123', 'fulfilment_details' => 'Pickup at the selected store.', 'recovery_discount_line' => '', 'recovery_unsubscribe_link' => 'https://store.example/unsubscribe?signed=preview', 'store_name' => (string) ($data['notification_sender_name'] ?: 'Mercato Store'), 'account_link' => 'https://store.example/account', 'security_message' => 'Your password was changed.'];
        $previewOverrides = ['locale' => (string) $data['notification_locale']];
        if ($previewEvent === 'order_confirmation') $previewOverrides += ['subject' => (string) $data['confirmation_email_subject'], 'text' => (string) $data['confirmation_email_body']];
        if ($previewEvent === 'payment_recovery') $previewOverrides += ['subject' => (string) $data['payment_link_email_subject'], 'text' => (string) $data['payment_link_email_body']];
        if ($previewEvent === 'shipment_tracking') $previewOverrides += ['subject' => (string) $data['shipping_email_subject'], 'text' => (string) $data['shipping_email_body']];
        if ($previewEvent === 'pickup_ready') $previewOverrides += ['subject' => (string) $data['pickup_ready_email_subject'], 'text' => (string) $data['pickup_ready_email_body']];
        if ($previewEvent === 'local_delivery') $previewOverrides += ['subject' => (string) $data['local_delivery_email_subject'], 'text' => (string) $data['local_delivery_email_body']];
        $preview = $module->notificationDeliveryService()->preview($previewEvent, $sample, $previewOverrides);
        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Template preview');
        $f->value = '<p><strong>' . htmlspecialchars((string) $preview['subject'], ENT_QUOTES, 'UTF-8') . '</strong></p><div style="max-width:720px;border:1px solid #ddd;padding:16px;background:#fff">' . (string) $preview['html'] . '</div><details><summary>Plain text</summary><pre style="white-space:pre-wrap">' . htmlspecialchars((string) $preview['text'], ENT_QUOTES, 'UTF-8') . '</pre></details>';
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldEmail');
        if (!$f) $f = $modules->get('InputfieldText');
        $f->name = 'mrc_test_email_recipient';
        $f->label = __('Test email recipient');
        $f->description = __('Send a plain-text test email using the configured sender and reply-to values. Test attempts are logged in Customer Emails.');
        $f->value = (string) wire('input')->post->text('mrc_test_email_recipient');
        $f->columnWidth = 66;
        $fs->add($f);

        $f = $modules->get('InputfieldSubmit');
        $f->name = 'mrc_send_test_email';
        $f->value = __('Send test email');
        $f->icon = 'envelope';
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'confirmation_email_subject';
        $f->label = __('Order confirmation subject');
        $f->description = __('Variables: {invoice}, {customer}, {total}, {currency}.');
        $f->value = $data['confirmation_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'confirmation_email_body';
        $f->label = __('Order confirmation body');
        $f->description = __('Plain-text message. Variables: {invoice}, {customer}, {items}, {subtotal}, {shipping}, {fulfilment}, {fulfilment_details}, {discount}, {total}, {currency}, {receipt_link}, {order_status_link}, {policy_links}.');
        $f->value = $data['confirmation_email_body'];
        $f->rows = 7;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'payment_link_email_subject';
        $f->label = __('Payment link email subject');
        $f->description = __('Variables: {invoice}, {customer}, {total}, {payment_link}.');
        $f->value = $data['payment_link_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'payment_link_email_body';
        $f->label = __('Payment link email body');
        $f->description = __('Plain-text message. Variables: {invoice}, {customer}, {total}, {payment_link}, {recovery_unsubscribe_link}. If the unsubscribe variable is missing, Mercato appends the unsubscribe link automatically.');
        $f->value = $data['payment_link_email_body'];
        $f->rows = 6;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'recovery_email_cooldown_minutes';
        $f->label = __('Recovery email cooldown');
        $f->description = __('Minimum minutes before another recovery payment-link email can be sent for the same unpaid order. Use 0 to disable the cooldown. Range: 0-10080 minutes.');
        $f->value = $data['recovery_email_cooldown_minutes'];
        $f->min = 0;
        $f->max = 10080;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'recovery_automation_enabled';
        $f->label = __('Enable automated recovery emails');
        $f->description = __('Uses ProcessWire LazyCron to send payment-link emails to eligible abandoned checkouts.');
        $f->attr('value', 1);
        if (!empty($data['recovery_automation_enabled'])) $f->attr('checked', 'checked');
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldSelect');
        $f->name = 'recovery_automation_schedule';
        $f->label = __('Recovery automation schedule');
        $recoveryScheduleOptions = [];
        foreach (self::getReservationCleanupScheduleOptions() as $value => $label) {
            $recoveryScheduleOptions[$value] = __($label);
        }
        $f->addOptions($recoveryScheduleOptions);
        $f->value = $data['recovery_automation_schedule'];
        $f->description = __('How often LazyCron should check abandoned checkout recovery candidates.');
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'recovery_automation_min_age_minutes';
        $f->label = __('Minimum checkout age');
        $f->description = __('Unpaid orders must be at least this many minutes old before automated recovery can email them. Range: 15-43200 minutes.');
        $f->value = $data['recovery_automation_min_age_minutes'];
        $f->min = 15;
        $f->max = 43200;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'recovery_automation_batch_limit';
        $f->label = __('Recovery batch limit');
        $f->description = __('Maximum payment-link emails per automation run. Range: 1-100.');
        $f->value = $data['recovery_automation_batch_limit'];
        $f->min = 1;
        $f->max = 100;
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'recovery_discount_code';
        $f->label = __('Recovery discount code');
        $f->description = __('Optional coupon code promoted in recovery payment-link emails. Variables: {recovery_discount_code}, {recovery_discount_line}.');
        $f->value = $data['recovery_discount_code'];
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'recovery_suppressed_emails';
        $f->label = __('Recovery suppressed emails');
        $f->description = __('One email per line. These addresses are excluded from manual and automated recovery payment-link emails.');
        $f->value = $data['recovery_suppressed_emails'];
        $f->rows = 4;
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'shipping_email_subject';
        $f->label = __('Shipping email subject');
        $f->description = __('Variables: {invoice}, {customer}, {tracking}, {tracking_url}.');
        $f->value = $data['shipping_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'shipping_email_body';
        $f->label = __('Shipping email body');
        $f->description = __('Plain-text message. Variables: {invoice}, {customer}, {tracking}, {tracking_url}, {order_status_link}, {policy_links}.');
        $f->value = $data['shipping_email_body'];
        $f->rows = 7;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'pickup_ready_email_subject';
        $f->label = __('Pickup ready email subject');
        $f->description = __('Variables: {invoice}, {customer}, {fulfilment_details}, {order_status_link}, {policy_links}.');
        $f->value = $data['pickup_ready_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'pickup_ready_email_body';
        $f->label = __('Pickup ready email body');
        $f->description = __('Plain-text message sent when an order is ready for collection. Variables: {invoice}, {customer}, {fulfilment_details}, {order_status_link}, {policy_links}.');
        $f->value = $data['pickup_ready_email_body'];
        $f->rows = 6;
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'local_delivery_email_subject';
        $f->label = __('Local delivery email subject');
        $f->description = __('Variables: {invoice}, {customer}, {fulfilment_details}, {order_status_link}, {policy_links}.');
        $f->value = $data['local_delivery_email_subject'];
        $f->columnWidth = 100;
        $fs->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'local_delivery_email_body';
        $f->label = __('Local delivery email body');
        $f->description = __('Plain-text message sent when an order is out for local delivery. Variables: {invoice}, {customer}, {fulfilment_details}, {order_status_link}, {policy_links}.');
        $f->value = $data['local_delivery_email_body'];
        $f->rows = 6;
        $f->columnWidth = 50;
        $fs->add($f);

        $wrapper->add($fs);

        // --- Installer ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Installer');
        $fs->collapsed = Inputfield::collapsedBlank;

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Install / repair generated assets');
        $f->value = '<p>' . __('Run this after updating Mercato if you want the module to create missing fields, templates, pages, and template files.') . '</p>';
        $f->description = __('Template files are used from /site/templates/. Files in the module templates folder are only source copies.');
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'mrc_overwrite_template_files';
        $f->label = __('Overwrite existing template files in /site/templates/');
        $f->description = __('Enable only if you want Mercato to replace mrc-order.php, mrc-orders.php, mrc-products.php, mrc-product.php, mrc-checkout.php, and mrc-success.php in /site/templates/. This can overwrite manual edits.');
        $f->attr('value', 1);
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldSubmit');
        $f->name = 'mrc_run_installer';
        $f->value = __('Run Mercato installer');
        $f->icon = 'magic';
        $f->columnWidth = 50;
        $fs->add($f);

        $wrapper->add($fs);

        // --- Stripe ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Stripe Settings');
        $fs->collapsed = Inputfield::collapsedBlank;

        $stripeWebhookUrl = self::normalizeHttpRoot((string) wire('config')->urls->httpRoot) . '/api/mercato/stripe-webhook/';

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Setup guide');
        $f->value =
            '<div class="mrc-config-note">' .
            '<ol>' .
            '<li>' . __('Open Stripe Dashboard → Developers → API keys and copy the Publishable key and Secret key for test/live mode.') . '</li>' .
            '<li>' . __('Open Developers → Webhooks → Add endpoint and use this endpoint URL:') . ' <code>' . $stripeWebhookUrl . '</code></li>' .
            '<li>' . __('Select events: payment_intent.succeeded, payment_intent.payment_failed, payment_intent.processing, payment_intent.canceled, checkout.session.completed, checkout.session.expired, charge.refunded, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed, refund.created, refund.updated, refund.failed.') . '</li>' .
            '<li>' . __('For subscription self-service links, configure Stripe Billing → Customer portal in the Stripe Dashboard.') . '</li>' .
            '<li>' . __('After saving the webhook, reveal and copy the Signing secret (whsec_...) into the field below.') . '</li>' .
            '<li>' . __('Use Stripe test keys with Stripe test cards, for example 4242 4242 4242 4242 with any future expiry and CVC, before switching to live mode.') . '</li>' .
            '</ol>' .
            '</div>';
        $fs->add($f);

        foreach ([
            'stripe_test_pk' => __('Test Publishable Key (pk_test_...)'),
            'stripe_test_sk' => __('Test Secret Key (sk_test_...)'),
            'stripe_live_pk' => __('Live Publishable Key (pk_live_...)'),
            'stripe_live_sk' => __('Live Secret Key (sk_live_...)'),
            'stripe_webhook_secret' => __('Webhook Signing Secret (whsec_...)'),
        ] as $name => $label) {
            $f = $modules->get('InputfieldText');
            $f->name  = $name;
            $f->label = $label;
            $f->value = $data[$name] ?? '';
            if (str_contains($name, 'sk') || str_contains($name, 'secret')) {
                $f->attr('type', 'password');
            }
            $f->columnWidth = $name === 'stripe_webhook_secret' ? 100 : 50;
            $fs->add($f);
        }

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'stripe_automatic_payment_methods';
        $f->label = __('Stripe automatic payment methods');
        $f->label2 = __('Let Stripe show compatible Payment Element methods for Stripe Card checkout.');
        $f->description = __('Redirect-specific methods such as Klarna, iDEAL, and SEPA Debit continue to use their explicit configured flows.');
        $f->checked = !empty($data['stripe_automatic_payment_methods']);
        $fs->add($f);

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Stripe Webhook Endpoint');
        $f->value = '<code>' . $stripeWebhookUrl . '</code>';
        $f->description = __('GET opens a diagnostic response. Stripe must POST signed webhook events to this URL.');
        $fs->add($f);

        $wrapper->add($fs);

        // --- Mollie ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Mollie Settings');
        $fs->collapsed = Inputfield::collapsedBlank;

        $mollieWebhookUrl = self::normalizeHttpRoot((string) wire('config')->urls->httpRoot) . '/api/mercato/mollie-webhook/';
        $mollieSuccessUrl = self::normalizeHttpRoot((string) wire('config')->urls->httpRoot) . '/' . trim((string) ($data['success_page'] ?? self::getDefaultConfig()['success_page']), '/') . '/';
        $mollieCancelUrl = self::normalizeHttpRoot((string) wire('config')->urls->httpRoot) . '/' . trim((string) ($data['cancel_page'] ?? self::getDefaultConfig()['cancel_page']), '/') . '/';

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Setup guide');
        $f->value =
            '<div class="mrc-config-note">' .
            '<ol>' .
            '<li>' . __('Open Mollie Dashboard → Developers → API keys and copy the Test API key and Live API key.') . '</li>' .
            '<li>' . __('Enable customer-facing payment methods in the Mollie Dashboard. Mollie Checkout will show only methods available for the merchant profile, currency, amount, and shopper country.') . '</li>' .
            '<li>' . __('While Mercato production mode is off, use the Mollie Test API key, complete a test checkout, and verify the Webhooks tab before switching to live mode.') . '</li>' .
            '<li>' . __('Mercato sends the webhook URL automatically when it creates each Mollie payment:') . ' <code>' . $mollieWebhookUrl . '</code></li>' .
            '<li>' . __('Customer redirects return to the configured Mercato pages:') . ' <code>' . $mollieSuccessUrl . '</code> / <code>' . $mollieCancelUrl . '</code></li>' .
            '<li>' . __('Mollie also calls this payment webhook when a refund reaches processing, failed, or refunded status. Queued or pending refunds can be checked from Order Detail.') . '</li>' .
            '<li>' . __('For Mollie, no signing secret is stored here. The webhook POST contains a payment id; Mercato verifies it by fetching the payment through the Mollie API.') . '</li>' .
            '</ol>' .
            '</div>';
        $fs->add($f);

        foreach ([
            'mollie_test_key' => __('Test API Key (test_...)'),
            'mollie_live_key' => __('Live API Key (live_...)'),
        ] as $name => $label) {
            $f = $modules->get('InputfieldText');
            $f->name = $name;
            $f->label = $label;
            $f->value = $data[$name] ?? '';
            $f->attr('type', 'password');
            $f->columnWidth = 50;
            $fs->add($f);
        }

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Mollie Webhook Endpoint');
        $f->value = '<code>' . $mollieWebhookUrl . '</code>';
        $f->description = __('GET opens a diagnostic response. Mollie will POST payment status updates to this URL.');
        $fs->add($f);

        $wrapper->add($fs);

        // --- PayPal ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('PayPal Settings');
        $fs->collapsed = Inputfield::collapsedBlank;

        $paypalWebhookUrl = self::normalizeHttpRoot((string) wire('config')->urls->httpRoot) . '/api/mercato/paypal-webhook/';

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('Setup guide');
        $f->value =
            '<div class="mrc-config-note">' .
            '<ol>' .
            '<li>' . __('Open PayPal Developer Dashboard → Apps & Credentials and create or select a REST app.') . '</li>' .
            '<li>' . __('Copy the Sandbox Client ID and Secret for testing. Copy Live credentials only when you are ready for production review.') . '</li>' .
            '<li>' . __('Create a webhook for this endpoint and copy its Webhook ID into the matching Sandbox or Live field:') . ' <code>' . $paypalWebhookUrl . '</code></li>' .
            '<li>' . __('Sandbox checkout can create and capture PayPal Orders v2 payments after the customer approves the redirect.') . '</li>' .
            '<li>' . __('Mercato verifies real PayPal webhooks with the PayPal verify-webhook-signature API before processing production events.') . '</li>' .
            '<li>' . __('Required webhook events: CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED.') . '</li>' .
            '</ol>' .
            '</div>';
        $fs->add($f);

        foreach ([
            'paypal_test_client_id' => __('Sandbox Client ID'),
            'paypal_test_secret' => __('Sandbox Secret'),
            'paypal_test_webhook_id' => __('Sandbox Webhook ID'),
            'paypal_live_client_id' => __('Live Client ID'),
            'paypal_live_secret' => __('Live Secret'),
            'paypal_live_webhook_id' => __('Live Webhook ID'),
        ] as $name => $label) {
            $f = $modules->get('InputfieldText');
            $f->name = $name;
            $f->label = $label;
            $f->value = $data[$name] ?? '';
            if (str_contains($name, 'secret')) {
                $f->attr('type', 'password');
            }
            $f->columnWidth = 50;
            $fs->add($f);
        }

        $f = $modules->get('InputfieldMarkup');
        $f->label = __('PayPal Webhook Endpoint');
        $f->value = '<code>' . $paypalWebhookUrl . '</code>';
        $f->description = __('GET opens a diagnostic response. PayPal will POST signed webhook events to this URL.');
        $fs->add($f);

        $wrapper->add($fs);

        return $wrapper;
    }


}
