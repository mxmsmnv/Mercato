<?php
namespace ProcessWire;

trait MercatoOrderApi {

    public function initializePayment(array $data): string {
        return $this->paymentService()->initializePayment($data);
    }

    public function createCheckout(array $data): string {
        return $this->initializePayment($data);
    }

    /**
     * Step 2: Complete payment — retrieve pending order from session,
     * run gateway completePayment, save order page, clear cart.
     *
     * @param array $data Extra data from gateway redirect (e.g. payment_intent)
     * @throws WireException
     * @return Page The saved order page
     */
    public function completePayment(array $data = []): Page {
        return $this->paymentService()->completePayment($data);
    }

    public function getOrder(Page|int|string $order): ?Page {
        if ($order instanceof Page) {
            return $order->id && $order->template->name === (string) $this->order_template ? $order : null;
        }

        $reference = trim((string) $order);
        if ($reference !== '' && ctype_digit($reference)) {
            $page = $this->wire('pages')->get((int) $reference);
            if ($page && $page->id && $page->template->name === (string) $this->order_template) {
                return $page;
            }
        }

        return $this->orderRepository()->findByOrderReference($reference);
    }

    public function failPayment(Page|int|string $order, string $reason = 'manual_failure'): Page {
        $orderPage = $this->getOrder($order);
        if (!$orderPage || !$orderPage->id) {
            throw new WireException($this->_('Order not found.'));
        }

        $status = strtolower(trim((string) ($orderPage->mrc_payment_status ?? '')));
        if ((int) ($orderPage->mrc_payment_complete ?? 0) === 1
            || in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true)) {
            throw new WireException($this->_('Paid or refunded orders cannot be marked as failed.'));
        }

        $details = json_decode((string) ($orderPage->mrc_payment_details ?? ''), true);
        $details = is_array($details) ? $details : [];
        $details['state'] = 'failed';
        $details['failure_reason'] = trim($reason) !== '' ? trim($reason) : 'manual_failure';
        $details['failed_at'] = date(DATE_ATOM);
        $details['failed_by'] = (string) ($this->wire('user')->name ?? 'system');

        $previousOrderStatus = $this->getDerivedOrderStatus($orderPage);
        $orderPage->of(false);
        if ($orderPage->hasField('mrc_payment_status')) $orderPage->mrc_payment_status = MercatoPaymentStatus::FAILED;
        if ($orderPage->hasField('mrc_payment_complete')) $orderPage->mrc_payment_complete = 0;
        if ($orderPage->hasField('mrc_payment_details')) $orderPage->mrc_payment_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('pages')->save($orderPage);
        $this->orderRepository()->releaseStockReservation($orderPage, 'payment_failed');
        $this->paymentFailed($orderPage, (string) $details['failure_reason']);
        $this->emitOrderStatusChanged($orderPage, $previousOrderStatus, [
            'source' => 'failPayment',
            'payment_status' => MercatoPaymentStatus::FAILED,
            'reason' => (string) $details['failure_reason'],
        ]);

        return $orderPage;
    }

    public function refundPayment(Page|int|string $order, float $amount, string $reason, string $userName = ''): array {
        $orderPage = $this->getOrder($order);
        if (!$orderPage || !$orderPage->id) {
            throw new WireException($this->_('Order not found.'));
        }
        $userName = trim($userName) !== '' ? trim($userName) : (string) ($this->wire('user')->name ?? 'system');
        return $this->refundService()->refund($orderPage, $amount, $reason, $userName);
    }

    public function clearPendingCheckoutSession(bool $releaseReservation = true): void {
        $this->paymentService()->clearPendingCheckoutSession($releaseReservation);
    }

    public function getPaymentLinkUrl(Page $order): string {
        $checkoutPage = $this->wire('pages')->get('/' . ltrim((string) ($this->cancel_page ?: 'checkout'), '/') . '/');
        $baseUrl = ($checkoutPage && $checkoutPage->id)
            ? $checkoutPage->httpUrl()
            : $this->getHttpRoot() . '/' . ltrim((string) ($this->cancel_page ?: 'checkout'), '/') . '/';

        $query = [
            'mrc_order' => (int) $order->id,
            'mrc_token' => $this->getPaymentLinkToken($order),
        ];
        $recoveryDiscountCode = $this->getRecoveryDiscountCode();
        if ($recoveryDiscountCode !== '') {
            $query['mrc_discount'] = $recoveryDiscountCode;
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
    }

    public function getPaymentLinkToken(Page $order): string {
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        $payload = implode('|', [
            (int) $order->id,
            (string) ($order->mrc_invoice_number ?: $order->title),
            (string) $order->mrc_email,
            (int) $order->created,
        ]);
        return hash_hmac('sha256', $payload, $secret);
    }

    public function verifyPaymentLinkToken(Page $order, string $token): bool {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }
        if ((int) $order->mrc_payment_complete === 1 || (string) $order->mrc_payment_status === self::PAYMENT_STATUS_PAID) {
            return false;
        }
        if ($this->areOrderSignedLinksExpired($order)) return false;
        return hash_equals($this->getPaymentLinkToken($order), trim($token));
    }

    public function getOrderSubscriptionStatus(Page $order): string {
        if (!$order || !$order->id || !$order->hasField('mrc_subscription_status')) {
            return self::SUBSCRIPTION_STATUS_NONE;
        }
        return self::normalizeSubscriptionStatus((string) $order->mrc_subscription_status);
    }

    public function getCustomerOrders(string $email, bool $paidOnly = false, int $limit = 100): PageArray {
        $email = strtolower(trim($email));
        $limit = max(1, min(10000, $limit));
        if ($email === '') {
            return new PageArray();
        }

        $sanitizer = $this->wire('sanitizer');
        $safeEmail = is_object($sanitizer) ? $sanitizer->selectorValue($email) : addslashes($email);
        $selector = 'template=' . $this->order_template
            . ', include=all, mrc_email=' . $safeEmail
            . ', sort=-created, limit=' . $limit;
        if ($paidOnly) {
            $selector .= ', mrc_payment_complete=1';
        }
        return $this->wire('pages')->find($selector);
    }

    public function getUserOrders(?User $user = null, bool $paidOnly = false, int $limit = 100): PageArray {
        $user = $user ?: $this->wire('user');
        if (!$user || !$user->id || (method_exists($user, 'isGuest') && $user->isGuest())) {
            return new PageArray();
        }
        return $this->getCustomerOrders((string) ($user->email ?? ''), $paidOnly, $limit);
    }

    public function getCustomerSummary(string $email): array {
        $email = strtolower(trim($email));
        $orders = $this->getCustomerOrders($email, false, 10000);
        $paidCount = 0;
        $totalSpent = 0.0;
        $latest = null;

        foreach ($orders as $order) {
            if (!$order instanceof Page) {
                continue;
            }
            if ($latest === null) {
                $latest = $order;
            }
            if ((int) ($order->mrc_payment_complete ?? 0) !== 1) {
                continue;
            }
            $paidCount++;
            $totalSpent += (float) ($order->mrc_total_amount ?? 0);
        }

        return [
            'email' => $email,
            'order_count' => count($orders),
            'paid_order_count' => $paidCount,
            'total_spent' => round($totalSpent, 2),
            'latest_order' => $latest instanceof Page ? [
                'id' => (int) $latest->id,
                'invoice' => (string) ($latest->mrc_invoice_number ?? ''),
                'payment_status' => (string) ($latest->mrc_payment_status ?? ''),
                'payment_complete' => (int) ($latest->mrc_payment_complete ?? 0) === 1,
                'created' => (int) $latest->created,
            ] : null,
        ];
    }

    public function getUserCustomerSummary(?User $user = null): array {
        $user = $user ?: $this->wire('user');
        if (!$user || !$user->id || (method_exists($user, 'isGuest') && $user->isGuest())) {
            return $this->getCustomerSummary('');
        }
        return $this->getCustomerSummary((string) ($user->email ?? ''));
    }

    public function userHasPurchasedProduct(?User $user, int|Page $product): bool {
        $user = $user ?: $this->wire('user');
        if (!$user || !$user->id || (method_exists($user, 'isGuest') && $user->isGuest())) {
            return false;
        }
        return $this->customerHasPurchasedProduct((string) ($user->email ?? ''), $product);
    }

    public function customerHasPurchasedProduct(string $email, int|Page $product): bool {
        $email = strtolower(trim($email));
        $productId = $product instanceof Page ? (int) $product->id : (int) $product;
        if ($email === '' || $productId <= 0) {
            return false;
        }

        $orders = $this->getCustomerOrders($email, true, 10000);
        foreach ($orders as $order) {
            if ($order instanceof Page && $this->orderContainsProduct($order, $productId)) {
                return true;
            }
        }
        return false;
    }

    public function getOrderSubscriptionPortalUrl(Page $order, string $returnUrl = ''): string {
        if (!$order || !$order->id || !$order->hasField('mrc_stripe_customer_id')) {
            return '';
        }
        $customerId = trim((string) $order->mrc_stripe_customer_id);
        if ($customerId === '') {
            return '';
        }
        if ($returnUrl === '') {
            $returnUrl = $this->getOrderStatusUrl($order);
        }

        /** @var StripeGateway $gateway */
        $gateway = $this->getGateway('stripe');
        return $gateway instanceof StripeGateway ? $gateway->createCustomerPortalUrl($customerId, $returnUrl) : '';
    }

    protected static function normalizeSubscriptionStatus(string $status): string {
        $status = strtolower(trim($status));
        $allowed = [
            self::SUBSCRIPTION_STATUS_NONE,
            'incomplete',
            'incomplete_expired',
            'trialing',
            'active',
            'past_due',
            'canceled',
            'unpaid',
            'paused',
        ];
        return in_array($status, $allowed, true) ? $status : self::SUBSCRIPTION_STATUS_NONE;
    }

    public function getOrderStatusUrl(Page $order): string {
        return $this->getHttpRoot() . '/api/mercato/order-status?' . http_build_query([
            'order' => (int) $order->id,
            'token' => $this->getOrderStatusToken($order),
        ]);
    }

    public function getOrderLookupUrl(): string {
        return $this->getHttpRoot() . '/api/mercato/order-lookup';
    }

    public function findOrderForPublicLookup(string $reference, string $email): ?Page {
        $email = strtolower((string) $this->wire('sanitizer')->email(trim($email)));
        if ($email === '') {
            return null;
        }

        $order = $this->orderRepository()->findByOrderReference($reference);
        if (!$order || !$order->id) {
            return null;
        }

        return strtolower(trim((string) $order->mrc_email)) === $email ? $order : null;
    }

    public function getOrderStatusToken(Page $order): string {
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        $seed = $order->hasField('mrc_status_token_seed') ? trim((string) $order->mrc_status_token_seed) : '';
        $parts = [
            'mercato-order-status',
            (int) $order->id,
            (string) ($order->mrc_invoice_number ?: $order->title),
            strtolower((string) $order->mrc_email),
            (int) $order->created,
        ];
        if ($seed !== '') {
            $parts[] = $seed;
        }
        return hash_hmac('sha256', implode('|', $parts), $secret);
    }

    public function regenerateOrderStatusTokenSeed(Page $order): string {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            throw new WireException($this->_('Order not found.'));
        }
        if (!$order->hasField('mrc_status_token_seed')) {
            throw new WireException($this->_('Run Mercato installer/repair before regenerating order status links.'));
        }

        $seed = bin2hex(random_bytes(16));
        $order->of(false);
        $order->mrc_status_token_seed = $seed;
        $this->wire('pages')->save($order);
        return $seed;
    }

    public function verifyOrderStatusToken(Page $order, string $token): bool {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }
        return !$this->areOrderSignedLinksExpired($order) && hash_equals($this->getOrderStatusToken($order), trim($token));
    }

    public function getOrderReceiptUrl(Page $order): string {
        return $this->getHttpRoot() . '/api/mercato/order-receipt?' . http_build_query([
            'order' => (int) $order->id,
            'token' => $this->getOrderReceiptToken($order),
        ]);
    }

    public function getOrderPackingSlipPdfUrl(Page $order): string {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return '';
        }
        return $this->getHttpRoot() . '/api/mercato/order-packing-slip-pdf?' . http_build_query([
            'order' => (int) $order->id,
            'token' => $this->getOrderStatusToken($order),
        ]);
    }

    public function getBackgroundJobs(): array {
        $jobs = [
            'reservation_cleanup' => [
                'label' => 'Reservation cleanup',
                'enabled' => $this->getReservationCleanupSchedule() !== 'disabled',
                'schedule' => $this->getReservationCleanupSchedule(),
            ],
            'stale_draft_expiration' => [
                'label' => 'Stale draft expiration',
                'enabled' => $this->getReservationCleanupSchedule() !== 'disabled',
                'schedule' => $this->getReservationCleanupSchedule(),
            ],
            'webhook_payload_retention' => [
                'label' => 'Webhook payload retention',
                'enabled' => false,
                'schedule' => 'manual',
            ],
            'privacy_retention' => [
                'label' => 'Privacy retention',
                'enabled' => (string) ($this->privacy_retention_schedule ?? 'everyDay') !== 'disabled',
                'schedule' => (string) ($this->privacy_retention_schedule ?? 'everyDay'),
            ],
            'recovery_automation' => [
                'label' => 'Abandoned checkout recovery',
                'enabled' => (bool) ($this->recovery_automation_enabled ?? false) && $this->getRecoveryAutomationSchedule() !== 'disabled',
                'schedule' => $this->getRecoveryAutomationSchedule(),
            ],
        ];

        $hooked = $this->backgroundJobs($jobs);
        return is_array($hooked) ? $hooked : $jobs;
    }

    public function runBackgroundJobs(array $jobNames = [], array $context = []): array {
        $jobs = $this->getBackgroundJobs();
        $names = $jobNames ?: array_keys(array_filter($jobs, static fn($job): bool => is_array($job) && !empty($job['enabled'])));
        $results = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (!isset($jobs[$name])) {
                $results[$name] = ['ok' => false, 'skipped' => true, 'message' => 'Unknown background job.'];
                continue;
            }
            $job = is_array($jobs[$name]) ? $jobs[$name] : [];
            $startedAt = microtime(true);
            try {
                $result = $this->runBackgroundJob($name, $context + [
                    'job' => $name,
                    'label' => (string) ($job['label'] ?? $name),
                    'schedule' => (string) ($job['schedule'] ?? ''),
                ]);
                $results[$name] = is_array($result) ? $result : ['ok' => true];
            } catch (\Throwable $e) {
                $this->wire('log')->save('mercato-background-jobs', sprintf('%s failed: %s', $name, $e->getMessage()));
                $results[$name] = ['ok' => false, 'error' => $e->getMessage()];
            }
            $this->recordEvent('mercato-background-jobs', ['event' => 'background_job', 'job' => $name, 'status' => !empty($results[$name]['ok']) ? 'completed' : (!empty($results[$name]['skipped']) ? 'skipped' : 'failed'), 'source' => substr((string) ($context['source'] ?? 'manual'), 0, 40), 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)], 'background_job');
        }
        return $results;
    }

}
