<?php
namespace ProcessWire;

trait MercatoFrontendHooks {

    public function ___cartLoaded(MercatoCart $cart): void {
    }

    public function ___cartDeleted(MercatoCart $cart): void {
    }

    public function ___paymentInitialized(array $data, array $pendingOrder, string $redirect): void {
    }

    public function ___beforeAddToCart(array $item, MercatoCart $cart): array {
        return $item;
    }

    public function ___productBundleResolved(array $bundle, array $context = []): array {
        return $bundle;
    }

    public function ___readApiResponse(array $response, string $resource, array $params = []): array {
        return $response;
    }

    public function ___headlessCheckoutQuote(array $quote, array $context = []): array {
        return $quote;
    }

    public function ___beforeResolveDiscount(array $context): array {
        return $context;
    }

    public function ___afterResolveDiscount(array $result, array $context = []): array {
        return $result;
    }

    public function ___afterAddToCart(array $item, MercatoCart $cart): void {
    }

    public function ___beforeCreateOrder(array $pendingOrder): array {
        return $pendingOrder;
    }

    public function ___afterCreateOrder(Page $orderPage, array $pendingOrder, bool $created): void {
    }

    public function ___beforeCreateCheckout(array $data, MercatoCart $cart): array {
        return $data;
    }

    public function ___afterCreateCheckout(array $pendingOrder, Page $orderPage, string $redirect): void {
    }

    public function ___paymentAuthorized(Page $orderPage, string $status): void {
    }

    public function ___paymentPaid(Page $orderPage): void {
    }

    public function ___paymentCompleted(Page $orderPage, string $status): void {
        if ($status !== MercatoPaymentStatus::PAID) {
            return;
        }
        $service = new MercatoOrderConfirmationService($this);
        $service->setWire($this->wire());
        $service->send($orderPage);
    }

    public function ___paymentFailed(Page $orderPage, string $reason): void {
    }

    public function ___paymentRefunded(Page $orderPage, array $refund): void {
    }

    public function ___returnRequested(Page $orderPage, array $request): array {
        return $request;
    }

    public function ___orderStatusChanged(Page $orderPage, string $from, string $to, array $context = []): void {
    }

    public function ___fulfilmentUpdated(Page $orderPage, array $context = []): void {
    }

    public function ___partialFulfilmentRecorded(Page $orderPage, array $batch): array {
        return $batch;
    }

    public function ___shipmentRecorded(Page $orderPage, array $shipment): array {
        return $shipment;
    }

    public function ___businessTaxNumberValidated(array $result, array $context = []): array {
        return $result;
    }

    public function ___storeCreditIssued(array $credit): array {
        return $credit;
    }

    public function ___storeCreditRedeemed(array $redemption): array {
        return $redemption;
    }

    public function ___analyticsEvent(Page $orderPage, array $payload): array {
        return $payload;
    }

    public function ___productReviewSummary(Page $product): array {
        return [];
    }

    public function ___productRelatedProducts(Page $product, int $limit = 4): array {
        return [];
    }

    public function ___backgroundJobs(array $jobs): array {
        return $jobs;
    }

    public function ___runBackgroundJob(string $job, array $context = []): array {
        if ($job === 'reservation_cleanup') {
            $count = $this->orderRepository()->cleanupExpiredReservations();
            if ($count > 0) {
                $this->wire('log')->save('mercato-inventory', sprintf(
                    'Released %d expired inventory reservation(s).',
                    $count
                ));
            }
            return ['ok' => true, 'released' => $count];
        }

        if ($job === 'stale_draft_expiration') {
            $count = $this->orderRepository()->markStaleDraftOrdersExpired($this->getDraftOrderRetentionDays());
            if ($count > 0) {
                $this->wire('log')->save('mercato-events', sprintf(
                    'Marked %d stale draft order(s) expired.',
                    $count
                ));
            }
            return ['ok' => true, 'expired' => $count];
        }

        if ($job === 'webhook_payload_retention') {
            $this->requireArchitectureClasses();
            $log = new MercatoWebhookEventLog();
            $log->setWire($this->wire());
            $result = $log->redactPayloadsOlderThan($this->getWebhookPayloadRetentionDays());
            if ((int) ($result['redacted'] ?? 0) > 0) {
                $this->wire('log')->save('mercato-webhooks', sprintf(
                    'Redacted %d webhook payload context row(s) older than %d day(s).',
                    (int) $result['redacted'],
                    $this->getWebhookPayloadRetentionDays()
                ));
            }
            return $result;
        }

        if ($job === 'recovery_automation') {
            $result = $this->recoveryService()->run();
            $this->wire('log')->save('mercato-recovery', sprintf(
                'Recovery automation: checked=%d eligible=%d sent=%d failed=%d blocked=%d',
                (int) ($result['checked'] ?? 0),
                (int) ($result['eligible'] ?? 0),
                (int) ($result['sent'] ?? 0),
                (int) ($result['failed'] ?? 0),
                (int) ($result['blocked'] ?? 0)
            ));
            return ['ok' => true] + $result;
        }

        return [
            'ok' => false,
            'skipped' => true,
            'message' => sprintf('No background job handler registered for "%s".', $job),
        ];
    }

    public function ___beforeCalculateTax(array $context): array {
        return $context;
    }

    public function ___afterCalculateTax(float $taxAmount, array $context): float {
        return $taxAmount;
    }

    public function ___beforeCalculateShipping(string $type, MercatoProductList $cart, array $customerData, bool $validate): array {
        return [
            'type' => $type,
            'customer_data' => $customerData,
            'validate' => $validate,
        ];
    }

    public function ___afterCalculateShipping(array $method, MercatoProductList $cart, array $customerData, bool $validate): array {
        return $method;
    }

    /**
     * Format a price according to module currency settings.
     *
     * @param float $price
     * @param bool|null $symbolBefore null = use module config
     */
    public function formatPrice(float $price, ?bool $symbolBefore = null): string {
        $symbol   = $this->currency_symbol ?: '£';
        $before   = $symbolBefore ?? ($this->currency_symbol_position === 'before');
        $decimals = 2;
        $dec      = '.';
        $thou     = ',';

        // Respect PHP locale for decimal/thousands separators if set
        $lc = localeconv();
        if (isset($lc['decimal_point']) && $lc['decimal_point'] !== '') $dec  = $lc['decimal_point'];
        if (isset($lc['thousands_sep']))                                  $thou = $lc['thousands_sep'];

        $formatted = number_format($price, $decimals, $dec, $thou);

        return $before
            ? $symbol . "\u{00A0}" . $formatted
            : $formatted . "\u{00A0}" . $symbol;
    }

    /**
     * Calculate tax amount from gross price.
     *
     * @param float $grossPrice Price including tax
     * @param float $taxRate    Tax rate in percent, e.g. 19
     */
    public function calculateTax(float $grossPrice, float $taxRate): float {
        $context = $this->beforeCalculateTax([
            'gross_price' => $grossPrice,
            'tax_rate' => $taxRate,
        ]);
        if (!is_array($context)) {
            $context = ['gross_price' => $grossPrice, 'tax_rate' => $taxRate];
        }

        $grossPrice = max(0.0, (float) ($context['gross_price'] ?? $grossPrice));
        $taxRate = max(0.0, (float) ($context['tax_rate'] ?? $taxRate));
        $taxAmount = $taxRate > 0 ? $grossPrice / ($taxRate + 100) * $taxRate : 0.0;
        $hookTax = $this->afterCalculateTax($taxAmount, [
            'gross_price' => $grossPrice,
            'tax_rate' => $taxRate,
        ]);

        return max(0.0, (float) $hookTax);
    }

    /**
     * Calculate net price from gross price.
     */
    public function calculateNet(float $grossPrice, float $taxRate): float {
        return $grossPrice - $this->calculateTax($grossPrice, $taxRate);
    }

    /**
     * Return tax-rate groups for product taxes plus optional gross shipping tax.
     *
     * @return array<int, array{tax_rate:float,taxRate:float,sum:float}>
     */
    public function getTaxRatesForOrder(MercatoProductList $cart, float $shippingAmount = 0.0): array {
        if ($this->getTaxDisplayMode() === 'none') {
            return [];
        }

        $rates = [];
        foreach ($cart->getTaxRates() as $rate) {
            $taxRate = (float) ($rate['tax_rate'] ?? $rate['taxRate'] ?? 0);
            if ($taxRate <= 0) continue;
            $rates[(string) $taxRate] = [
                'tax_rate' => $taxRate,
                'taxRate' => $taxRate,
                'sum' => round((float) ($rate['sum'] ?? 0), 2),
            ];
        }

        $shippingAmount = round(max(0, $shippingAmount), 2);
        $shippingTaxRate = $this->getShippingTaxRate();
        if ($this->shouldTaxShipping() && $shippingAmount > 0 && $shippingTaxRate > 0) {
            $key = (string) $shippingTaxRate;
            if (!isset($rates[$key])) {
                $rates[$key] = ['tax_rate' => $shippingTaxRate, 'taxRate' => $shippingTaxRate, 'sum' => 0.0];
            }
            $rates[$key]['sum'] = round($rates[$key]['sum'] + $this->calculateTax($shippingAmount, $shippingTaxRate), 2);
        }

        ksort($rates, SORT_NUMERIC);
        return array_values($rates);
    }

    public function getHttpRoot(): string {
        return self::normalizeHttpRoot((string) $this->wire('config')->urls->httpRoot);
    }

    public function getEnabledPaymentMethods(): array {
        $methods = self::normalizeEnabledPaymentMethods($this->enabled_payment_methods ?? []);
        return !empty($this->production)
            ? array_values(array_diff($methods, ['demo']))
            : $methods;
    }

    public function getFrontendFramework(): string {
        return self::normalizeFrontendFramework($this->frontend_framework ?? self::getDefaultConfig()['frontend_framework']);
    }

    public function getFrontendAssetUrl(string $framework = ''): string {
        $framework = self::normalizeFrontendFramework($framework !== '' ? $framework : $this->getFrontendFramework());
        $key = match ($framework) {
            'tailwind' => 'frontend_tailwind_cdn_url',
            'bootstrap' => 'frontend_bootstrap_cdn_url',
            'uikit' => 'frontend_uikit_cdn_url',
            default => '',
        };
        return $key !== '' ? self::normalizeFrontendAssetUrl($this->{$key} ?? '', $framework) : '';
    }

    public function getStorefrontTemplateOverridePath(string $template): string {
        $template = trim(str_replace('\\', '/', $template));
        $template = preg_replace('/[^A-Za-z0-9_.-]+/', '', $template) ?: '';
        $template = preg_replace('/\.php$/i', '', $template) ?: '';
        if ($template === '' || str_contains($template, '..')) {
            return '';
        }

        $templatesRoot = realpath((string) $this->wire('config')->paths->templates);
        if (!$templatesRoot) {
            return '';
        }

        $framework = $this->getFrontendFramework();
        $candidates = [
            $templatesRoot . DIRECTORY_SEPARATOR . 'mercato' . DIRECTORY_SEPARATOR . $framework . DIRECTORY_SEPARATOR . $template . '.php',
            $templatesRoot . DIRECTORY_SEPARATOR . 'mercato' . DIRECTORY_SEPARATOR . $template . '.php',
        ];
        foreach ($candidates as $candidate) {
            $path = realpath($candidate);
            if ($path && is_file($path) && str_starts_with($path, $templatesRoot . DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        return '';
    }

    public function renderFrontendFrameworkAssets(): string {
        if (empty($this->frontend_auto_assets)) {
            return '';
        }

        $framework = $this->getFrontendFramework();
        $url = htmlspecialchars($this->getFrontendAssetUrl($framework), ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return '';
        }

        return match ($framework) {
            'tailwind' => '<script src="' . $url . '"></script>',
            'bootstrap' => '<link href="' . $url . '" rel="stylesheet">',
            'uikit' => '<link rel="stylesheet" href="' . $url . '">',
            default => '',
        };
    }

    public function getFrontendUiClasses(): array {
        $base = [
            'body' => 'mrc-page',
            'shell' => 'mrc-shell',
            'panel' => 'mrc-wrap',
            'kicker' => 'mrc-kicker',
            'grid' => 'mrc-grid',
            'field' => 'mrc-field',
            'fieldWide' => 'mrc-field is-wide',
            'input' => 'mrc-input',
            'select' => 'mrc-select',
            'textarea' => 'mrc-textarea',
            'button' => 'mrc-button',
            'buttonSecondary' => 'mrc-button mrc-button-secondary',
            'message' => 'mrc-message',
            'error' => 'mrc-error',
            'table' => 'mrc-table',
            'total' => 'mrc-total',
            'price' => 'mrc-price',
            'imageWrap' => 'mrc-product-media',
            'image' => 'mrc-product-image',
            'shipping' => 'mrc-shipping',
            'description' => 'mrc-description',
            'form' => 'mrc-form',
            'actions' => 'mrc-actions',
            'paymentPanel' => 'mrc-payment-panel',
            'paymentElement' => '',
            'statusNote' => 'mrc-status-note',
            'statusPaid' => 'is-paid',
            'statusProcessing' => 'is-processing',
            'statusFailed' => 'is-failed',
        ];

        return match ($this->getFrontendFramework()) {
            'tailwind' => [
                'body' => 'mrc-luxury-theme min-h-screen bg-[#f5f0e8] text-[#33251f]',
                'shell' => 'mx-auto grid w-full max-w-7xl gap-8 px-4 py-8 md:px-8 lg:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]',
                'panel' => 'rounded-md border border-[#d8cdbc] bg-[#fffaf2] p-5 shadow-[0_22px_70px_rgba(62,43,31,0.08)] md:p-8',
                'kicker' => 'block text-[11px] font-semibold uppercase tracking-[0.28em] text-[#8a4b3e] mb-3',
                'grid' => 'grid gap-4 md:grid-cols-2',
                'field' => 'grid gap-1',
                'fieldWide' => 'grid gap-1 md:col-span-2',
                'input' => 'w-full min-h-11 rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-3 py-2 text-[#33251f] outline-none focus:border-[#8a4b3e] focus:bg-white',
                'select' => 'w-full min-h-11 rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-3 py-2 text-[#33251f] outline-none focus:border-[#8a4b3e] focus:bg-white',
                'textarea' => 'w-full min-h-28 rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-3 py-2 text-[#33251f] outline-none focus:border-[#8a4b3e] focus:bg-white',
                'button' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-[#5b241f] px-6 text-xs font-semibold uppercase tracking-[0.18em] text-[#fffaf2]',
                'buttonSecondary' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[#5b241f] px-6 text-xs font-semibold uppercase tracking-[0.18em] text-[#5b241f] no-underline',
                'message' => 'mb-5 rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-4 py-3 text-[#5a4639]',
                'error' => 'mb-5 rounded-md border border-[#b77a6c] bg-[#fff1ee] px-4 py-3 text-[#5b241f]',
                'table' => 'w-full border-collapse',
                'total' => 'text-2xl font-semibold text-[#5b241f]',
                'price' => 'text-2xl font-semibold text-[#5b241f]',
                'imageWrap' => 'mb-6 overflow-hidden rounded-md border border-[#d8cdbc] bg-[#fbf6ed]',
                'image' => 'block aspect-[4/3] w-full object-cover',
                'shipping' => 'mb-4 text-sm text-[#6e5b4d]',
                'description' => 'mb-6 text-[#4d3d33]',
                'form' => 'space-y-4',
                'actions' => 'mt-5 flex flex-wrap items-center gap-3',
                'paymentPanel' => 'mt-6',
                'paymentElement' => 'rounded-md border border-[#d8cdbc] bg-[#fbf6ed] p-4',
                'statusNote' => 'mb-5 rounded-md border border-[#d8cdbc] bg-[#fbf6ed] px-4 py-3',
                'statusPaid' => 'bg-[#eef4e7]',
                'statusProcessing' => 'bg-[#fff6d8]',
                'statusFailed' => 'bg-[#fff1ee]',
            ],
            'bootstrap' => [
                'body' => 'bg-light text-dark min-vh-100 p-4',
                'shell' => 'container-fluid row g-4',
                'panel' => 'bg-white border p-4',
                'kicker' => 'd-block text-uppercase text-secondary fw-bold small mb-2',
                'grid' => 'row g-3',
                'field' => 'col-md-6',
                'fieldWide' => 'col-12',
                'input' => 'form-control',
                'select' => 'form-select',
                'textarea' => 'form-control',
                'button' => 'btn btn-dark rounded-pill fw-bold',
                'buttonSecondary' => 'btn btn-outline-dark rounded-pill fw-bold',
                'message' => 'alert alert-secondary',
                'error' => 'alert alert-danger',
                'table' => 'table',
                'total' => 'fs-4 fw-bold',
                'price' => 'fs-2 fw-bold text-danger',
                'imageWrap' => 'mb-4 border bg-light',
                'image' => 'img-fluid d-block w-100',
                'shipping' => 'text-secondary mb-3',
                'description' => 'mb-4',
                'form' => 'row g-3',
                'actions' => 'd-flex flex-wrap gap-2 mt-3',
                'paymentPanel' => 'mt-4',
                'paymentElement' => 'border p-3',
                'statusNote' => 'alert',
                'statusPaid' => 'alert-success',
                'statusProcessing' => 'alert-warning',
                'statusFailed' => 'alert-danger',
            ],
            'uikit' => [
                'body' => 'uk-background-muted uk-padding',
                'shell' => 'uk-grid-large uk-child-width-expand@s',
                'panel' => 'uk-card uk-card-default uk-card-body',
                'kicker' => 'uk-text-meta uk-text-uppercase uk-text-bold',
                'grid' => 'uk-grid-small uk-child-width-1-2@m',
                'field' => '',
                'fieldWide' => 'uk-width-1-1',
                'input' => 'uk-input',
                'select' => 'uk-select',
                'textarea' => 'uk-textarea',
                'button' => 'uk-button uk-button-primary',
                'buttonSecondary' => 'uk-button uk-button-default',
                'message' => 'uk-alert-primary',
                'error' => 'uk-alert-danger',
                'table' => 'uk-table uk-table-divider',
                'total' => 'uk-text-large uk-text-bold',
                'price' => 'uk-text-large uk-text-bold uk-text-danger',
                'imageWrap' => 'uk-margin uk-background-muted',
                'image' => 'uk-width-1-1',
                'shipping' => 'uk-text-meta uk-margin-small',
                'description' => 'uk-margin',
                'form' => 'uk-form-stacked',
                'actions' => 'uk-flex uk-flex-wrap uk-flex-middle uk-grid-small',
                'paymentPanel' => 'uk-margin-top',
                'paymentElement' => 'uk-padding-small uk-border-rounded',
                'statusNote' => 'uk-alert',
                'statusPaid' => 'uk-alert-success',
                'statusProcessing' => 'uk-alert-warning',
                'statusFailed' => 'uk-alert-danger',
            ],
            default => $base,
        };
    }

    // -----------------------------------------------------------------------
    // Payment flow
    // -----------------------------------------------------------------------

    /**
     * Step 1: Validate cart and form data, initialize gateway, store pending
     * order in session, return redirect URL.
     *
     * @param array $data Must contain 'payment_method'. All fields are sanitized.
     * @throws WireException on validation failure
     * @return string Redirect URL (success page or gateway redirect)
     */
}
