<?php
namespace ProcessWire;

trait MercatoStoreServices {

    public function setConfigData(array $data): void {
        $this->requireArchitectureClasses();
        $previousConfig = self::getDefaultConfig();
        foreach (array_keys($previousConfig) as $key) {
            $value = $this->get($key);
            if ($value !== null) {
                $previousConfig[$key] = $value;
            }
        }
        $data = array_merge(self::getDefaultConfig(), $data);
        unset($data['mrc_run_installer'], $data['mrc_overwrite_template_files']);
        $data['currency'] = MercatoCurrency::isIsoCode((string) ($data['currency'] ?? ''))
            ? MercatoCurrency::normalizeCode((string) $data['currency'])
            : self::getDefaultConfig()['currency'];
        $data['currency_symbol'] = trim((string) ($data['currency_symbol'] ?? '')) !== ''
            ? trim((string) $data['currency_symbol'])
            : self::getDefaultConfig()['currency_symbol'];
        $data['currency_symbol_position'] = in_array((string) ($data['currency_symbol_position'] ?? ''), ['before', 'after'], true)
            ? (string) $data['currency_symbol_position']
            : self::getDefaultConfig()['currency_symbol_position'];
        $data['invoice_prefix'] = self::normalizeInvoicePrefix($data['invoice_prefix'] ?? '');
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
        $data['customer_data_retention_days'] = self::normalizeRetentionDays($data['customer_data_retention_days'] ?? 0, 0, 0);
        $data['low_stock_threshold'] = self::normalizeLowStockThreshold($data['low_stock_threshold'] ?? 5);
        $data['free_shipping_threshold'] = self::normalizeMoneyAmount($data['free_shipping_threshold'] ?? 0);
        $data['default_tax_rate'] = self::normalizeTaxRate($data['default_tax_rate'] ?? 20);
        $data['tax_display_mode'] = self::normalizeTaxDisplayMode($data['tax_display_mode'] ?? 'included');
        $data['tax_label'] = self::normalizeTaxLabel($data['tax_label'] ?? 'VAT');
        $data['tax_rounding_mode'] = self::normalizeTaxRoundingMode($data['tax_rounding_mode'] ?? 'line');
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
        $data['receipt_template_file'] = self::normalizeReceiptTemplateFile($data['receipt_template_file'] ?? '');
        $data['receipt_pdf_url_template'] = self::normalizeReceiptPdfUrlTemplate($data['receipt_pdf_url_template'] ?? '');
        $this->recordSettingsAuditEvents($previousConfig, $data);
        $this->setArray($data);
    }

    public function formatInvoiceNumber(int $sequence): string {
        $prefix = self::normalizeInvoicePrefix($this->invoice_prefix ?? '');
        return $prefix . str_pad((string) max(0, $sequence), 5, '0', STR_PAD_LEFT);
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

    public function getHeadlessCheckoutQuote(array $items, array $customerData = [], array $options = []): array {
        $cart = $this->productList($items);
        $discountCode = trim((string) ($options['discount_code'] ?? ''));
        $email = (string) ($customerData['email'] ?? $options['email'] ?? '');
        $discount = $discountCode !== ''
            ? $this->discountService()->resolveCartDiscount($discountCode, $cart, $email, true, ['source' => 'headless_quote'])
            : ['valid' => false, 'code' => '', 'amount' => 0.0];
        if (empty($discount['valid'])) {
            $discount = ['valid' => false, 'code' => '', 'amount' => 0.0];
        }

        $methods = $this->fulfilmentService()->getCheckoutMethods($cart, $customerData);
        $selectedType = trim((string) ($options['fulfilment_method'] ?? ''));
        $selected = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            if ($selectedType !== '' && (string) ($method['type'] ?? '') === $selectedType) {
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
        $discountAmount = round(max(0.0, (float) ($discount['amount'] ?? 0)), 2);
        $total = round(max(0.0, $subtotal + $shipping - $discountAmount), 2);

        $quote = [
            'items' => $cart->toArray(),
            'item_count' => $cart->count(),
            'currency' => MercatoCurrency::normalizeCode((string) ($options['currency'] ?? $this->currency ?? 'GBP')),
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discountAmount,
            'total' => $total,
            'discount_code' => (string) ($discount['code'] ?? ''),
            'discount_valid' => !empty($discount['valid']),
            'fulfilment_method' => (string) ($selected['type'] ?? ''),
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

    public function purchasabilityService(): MercatoPurchasabilityService {
        if ($this->purchasabilityService === null) {
            $this->requireArchitectureClasses();
            $this->purchasabilityService = new MercatoPurchasabilityService($this);
        }
        return $this->purchasabilityService;
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
        if ($recipient === '') {
            return self::recordNotificationTestEmail('failed', 'Enter a valid recipient email.', '');
        }
        if ($sender === '') {
            return self::recordNotificationTestEmail('failed', 'Notification sender email is not configured.', $recipient);
        }

        try {
            $mail = wireMail();
            $mail->to($recipient)
                ->from($sender, $senderName)
                ->subject('Mercato test email')
                ->body("This is a Mercato test email.\n\nIf you received it, your ProcessWire mail transport accepted the configured sender.");
            if ($replyTo !== '') {
                $mail->header('Reply-To', $replyTo);
            }
            if ((int) $mail->send() < 1) {
                return self::recordNotificationTestEmail('failed', 'WireMail did not report a sent message.', $recipient);
            }
            return self::recordNotificationTestEmail('sent', 'Mercato test email sent.', $recipient);
        } catch (\Throwable $e) {
            return self::recordNotificationTestEmail('failed', $e->getMessage(), $recipient);
        }
    }

    protected static function recordNotificationTestEmail(string $status, string $message, string $recipient): array {
        if (!class_exists(MercatoEventLog::class, false)) {
            require_once __DIR__ . '/src/Logging/MercatoEventLog.php';
        }
        $payload = [
            'event' => 'test_email',
            'status' => $status,
            'order_id' => 0,
            'invoice' => '',
            'recipient' => $recipient,
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
