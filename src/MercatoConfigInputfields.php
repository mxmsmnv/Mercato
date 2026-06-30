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
        $data['customer_data_retention_days'] = self::normalizeRetentionDays($data['customer_data_retention_days'] ?? 0, 0, 0);
        $data['low_stock_threshold'] = self::normalizeLowStockThreshold($data['low_stock_threshold'] ?? 5);
        $data['free_shipping_threshold'] = self::normalizeMoneyAmount($data['free_shipping_threshold'] ?? 0);
        $data['tax_shipping'] = !empty($data['tax_shipping']);
        $data['shipping_tax_rate'] = self::normalizeTaxRate($data['shipping_tax_rate'] ?? 20);
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
        $f->name = 'receipt_pdf_url_template';
        $f->label = __('Receipt PDF URL template');
        $f->description = __('Optional external PDF renderer URL. Variables: {order_id}, {invoice}, {token}, {receipt_link}. Leave blank to use printable HTML only.');
        $f->value = $data['receipt_pdf_url_template'];
        $f->columnWidth = 100;
        $fs->add($f);

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
