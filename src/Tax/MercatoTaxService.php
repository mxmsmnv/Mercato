<?php
namespace ProcessWire;

final class MercatoTaxService extends Wire {
    protected string $logName = 'mercato-tax';
    public function __construct(protected Mercato $commerce) { parent::__construct(); }

    public function getProviders(): array {
        $providers = ['manual' => new MercatoManualTaxProvider($this->commerce)];
        $hooked = $this->commerce->taxProviders($providers);
        foreach (is_array($hooked) ? $hooked : [] as $key => $provider) {
            if ($provider instanceof MercatoTaxProviderInterface) $providers[(string) $key] = $provider;
        }
        return $providers;
    }

    public function estimate(MercatoProductList $cart, array $customer, array $fulfilment, array $discount = [], string $currency = ''): array {
        $context = $this->buildContext($cart, $customer, $fulfilment, $discount, $currency);
        $providerKey = trim((string) ($this->commerce->tax_provider ?? 'manual')) ?: 'manual';
        $providers = $this->getProviders();
        if (!isset($providers[$providerKey])) return $this->handleFailure(new WireException(sprintf('Tax provider "%s" is unavailable.', $providerKey)), $context, $providers['manual']);
        try {
            return $this->invokeEstimate($providers[$providerKey], $context);
        } catch (\Throwable $e) {
            return $this->handleFailure($e, $context, $providers['manual']);
        }
    }

    public function commit(Page $order): array {
        if (!$order->hasField('mrc_tax_details')) return [];
        return $this->withOrderLock($order, function (Page $fresh): array {
            $details = $this->details($fresh);
            if (!empty($details['commit']['status']) && $details['commit']['status'] === 'committed') return $details['commit'];
            $provider = $this->providerForDetails($details);
            $key = 'tax_commit_' . (int) $fresh->id;
            $result = $this->invokeLifecycle($provider, 'commit', ['order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details, 'idempotency_key' => $key]);
            $details['commit'] = ['status' => 'committed', 'at' => date(DATE_ATOM), 'idempotency_key' => $key] + $result;
            $this->saveDetails($fresh, $details, true, (string) ($result['provider_reference'] ?? $details['provider_reference'] ?? ''));
            return $details['commit'];
        });
    }

    public function refund(Page $order, float $amount, string $refundId): array {
        if (!$order->hasField('mrc_tax_details')) return [];
        return $this->withOrderLock($order, function (Page $fresh) use ($amount, $refundId): array {
            $details = $this->details($fresh);
            if (!$details || empty($details['commit']['status'])) return [];
            $key = 'tax_refund_' . (int) $fresh->id . '_' . sha1($refundId . '|' . number_format($amount, 2, '.', ''));
            foreach ((array) ($details['refunds'] ?? []) as $existing) if (($existing['idempotency_key'] ?? '') === $key) return $existing;
            $result = $this->invokeLifecycle($this->providerForDetails($details), 'refund', [
                'order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details,
                'amount' => round($amount, 2), 'refund_id' => $refundId, 'idempotency_key' => $key,
            ]);
            $entry = ['status' => 'refunded', 'amount' => round($amount, 2), 'refund_id' => $refundId, 'at' => date(DATE_ATOM), 'idempotency_key' => $key] + $result;
            $details['refunds'][] = $entry;
            $this->saveDetails($fresh, $details);
            return $entry;
        });
    }

    public function void(Page $order, string $reason = ''): array {
        if (!$order->hasField('mrc_tax_details')) return [];
        return $this->withOrderLock($order, function (Page $fresh) use ($reason): array {
            $details = $this->details($fresh);
            if (!$details || !empty($details['void']['status'])) return (array) ($details['void'] ?? []);
            $key = 'tax_void_' . (int) $fresh->id;
            $result = $this->invokeLifecycle($this->providerForDetails($details), 'void', ['order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details, 'reason' => $reason, 'idempotency_key' => $key]);
            $details['void'] = ['status' => 'voided', 'reason' => $reason, 'at' => date(DATE_ATOM), 'idempotency_key' => $key] + $result;
            $this->saveDetails($fresh, $details);
            return $details['void'];
        });
    }

    public function getStoredBreakdown(Page $order): array {
        $details = $this->details($order);
        $quote = (array) ($details['quote'] ?? $details);
        $groups = [];
        foreach ((array) ($quote['lines'] ?? []) as $line) {
            $rate = (float) ($line['rate'] ?? 0);
            $key = (string) $rate;
            if (!isset($groups[$key])) $groups[$key] = ['tax_rate' => $rate, 'taxRate' => $rate, 'sum' => 0.0, 'jurisdiction' => (string) ($line['jurisdiction'] ?? '')];
            $groups[$key]['sum'] = round($groups[$key]['sum'] + (float) ($line['tax'] ?? 0), 2);
        }
        $shipping = (array) ($quote['shipping'] ?? []);
        if ((float) ($shipping['tax'] ?? 0) > 0) {
            $rate = (float) ($shipping['rate'] ?? 0); $key = (string) $rate;
            if (!isset($groups[$key])) $groups[$key] = ['tax_rate' => $rate, 'taxRate' => $rate, 'sum' => 0.0, 'jurisdiction' => 'shipping'];
            $groups[$key]['sum'] = round($groups[$key]['sum'] + (float) $shipping['tax'], 2);
        }
        ksort($groups, SORT_NUMERIC);
        return array_values($groups);
    }

    protected function buildContext(MercatoProductList $cart, array $customer, array $fulfilment, array $discount, string $currency = ''): array {
        $items = [];
        foreach ($cart->toArray() as $item) {
            $items[] = [
                'line_id' => (string) ($item['key'] ?? $item['id'] ?? ''), 'product_id' => (int) ($item['product_id'] ?? 0),
                'variant_id' => (string) ($item['variant_id'] ?? ''), 'sku' => (string) ($item['sku'] ?? ''),
                'tax_code' => (string) ($item['tax_code'] ?? ''), 'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => round((float) ($item['price'] ?? 0), 2), 'line_total' => round((float) ($item['sum'] ?? 0), 2),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
            ];
        }
        $destination = [
            'address' => trim((string) ($customer['address'] ?? '')), 'city' => trim((string) ($customer['city'] ?? '')),
            'postal_code' => trim((string) ($customer['zip'] ?? '')), 'country' => strtoupper(trim((string) ($customer['country'] ?? ''))),
            'region' => strtoupper(trim((string) ($customer['region'] ?? ''))),
        ];
        $context = [
            'operation' => 'estimate', 'provider' => (string) ($this->commerce->tax_provider ?? 'manual'),
            'currency' => MercatoCurrency::normalizeCode($currency !== '' ? $currency : (string) $this->commerce->currency), 'display_mode' => $this->commerce->getTaxDisplayMode(),
            'items' => $items, 'customer' => ['email' => (string) ($customer['email'] ?? ''), 'tax_number' => (string) ($customer['tax_number'] ?? ''), 'tax_exempt' => !empty($customer['tax_exempt'])],
            'destination' => $destination, 'shipping' => ['amount' => round((float) ($fulfilment['amount'] ?? 0), 2), 'method' => (string) ($fulfilment['type'] ?? ''), 'taxable' => $this->commerce->shouldTaxShipping(), 'tax_rate' => $this->commerce->getShippingTaxRate()],
            'discount' => ['code' => (string) ($discount['code'] ?? ''), 'amount' => round((float) ($discount['amount'] ?? 0), 2)],
            'registrations' => $this->decodeConfigJson((string) ($this->commerce->tax_registrations ?? '')), 'nexus_regions' => preg_split('/[\s,]+/', strtoupper((string) ($this->commerce->tax_nexus_regions ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'timeout_seconds' => max(1, (int) ($this->commerce->tax_provider_timeout_seconds ?? 5)),
        ];
        $context['idempotency_key'] = 'tax_est_' . hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $context;
    }

    protected function invokeEstimate(MercatoTaxProviderInterface $provider, array $context): array {
        $started = microtime(true);
        $quote = $this->retry(fn() => $provider->estimate($context));
        if ((microtime(true) - $started) > (float) $context['timeout_seconds']) throw new WireException('Tax provider timed out.');
        $quote['provider'] = $provider->getTaxProviderKey();
        $quote['input_snapshot'] = [
            'currency' => (string) $context['currency'],
            'display_mode' => (string) $context['display_mode'],
            'items' => (array) $context['items'],
            'customer' => (array) $context['customer'],
            'destination' => (array) $context['destination'],
            'shipping' => (array) $context['shipping'],
            'discount' => (array) $context['discount'],
            'registrations' => (array) $context['registrations'],
            'nexus_regions' => (array) $context['nexus_regions'],
        ];
        $normalized = MercatoTaxQuote::normalize($quote, $context);
        $this->record('tax_estimated', ['provider' => $normalized['provider'], 'tax' => $normalized['total_tax'], 'idempotency_key' => $normalized['idempotency_key']]);
        return $normalized;
    }

    protected function invokeLifecycle(MercatoTaxProviderInterface $provider, string $operation, array $context): array {
        $result = $this->retry(fn() => $provider->$operation($context));
        if (!is_array($result)) throw new WireException('Tax provider returned an invalid lifecycle response.');
        $this->record('tax_' . $operation, ['provider' => $provider->getTaxProviderKey(), 'idempotency_key' => (string) ($context['idempotency_key'] ?? ''), 'status' => (string) ($result['status'] ?? '')]);
        return $result;
    }

    protected function retry(callable $operation): array {
        $attempts = max(1, min(4, (int) ($this->commerce->tax_provider_retries ?? 1) + 1));
        $last = null;
        for ($i = 0; $i < $attempts; $i++) try { $result = $operation(); if (!is_array($result)) throw new WireException('Tax provider returned an invalid response.'); return $result; } catch (\Throwable $e) { $last = $e; }
        throw new WireException($last?->getMessage() ?: 'Tax provider failed.', 502, $last);
    }

    protected function handleFailure(\Throwable $error, array $context, MercatoTaxProviderInterface $manual): array {
        $policy = (string) ($this->commerce->tax_provider_failure_policy ?? 'fail_closed');
        $this->record('tax_provider_failed', ['provider' => (string) $context['provider'], 'policy' => $policy, 'error' => $error->getMessage()]);
        if ($policy === 'manual_fallback') {
            $quote = $this->invokeEstimate($manual, $context);
            $quote['fallback'] = true; $quote['fallback_reason'] = $error->getMessage();
            return $quote;
        }
        if ($policy === 'zero_tax') {
            return MercatoTaxQuote::normalize(['provider' => (string) $context['provider'], 'currency' => (string) $context['currency'], 'display_mode' => (string) $context['display_mode'], 'total_tax' => 0, 'taxable_amount' => 0, 'exempt_amount' => 0, 'fallback' => true, 'fallback_reason' => $error->getMessage()], $context);
        }
        throw new WireException('Tax could not be calculated: ' . $error->getMessage(), 503, $error);
    }

    protected function providerForDetails(array $details): MercatoTaxProviderInterface {
        $key = (string) ($details['quote']['provider'] ?? $details['provider'] ?? 'manual');
        $providers = $this->getProviders();
        if (!isset($providers[$key])) throw new WireException(sprintf('Tax provider "%s" is unavailable.', $key), 503);
        return $providers[$key];
    }
    protected function details(Page $order): array { $value = json_decode((string) $order->mrc_tax_details, true); return is_array($value) ? $value : []; }
    protected function saveDetails(Page $order, array $details, bool $committed = false, string $reference = ''): void {
        $order->of(false); $order->mrc_tax_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($committed && $order->hasField('mrc_tax_committed')) $order->mrc_tax_committed = 1;
        if ($reference !== '' && $order->hasField('mrc_tax_provider_reference')) $order->mrc_tax_provider_reference = $reference;
        $this->wire('pages')->save($order);
    }
    protected function orderContext(Page $order): array { return ['id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title), 'currency' => (string) $order->mrc_currency, 'total' => (float) $order->mrc_total_amount]; }
    protected function withOrderLock(Page $order, callable $operation): array {
        $database = $this->wire('database');
        $name = 'mercato_tax_' . (int) $order->id;
        $lock = $database->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute([':name' => $name]);
        if ((int) $lock->fetchColumn() !== 1) throw new WireException('Could not acquire the tax transaction lock.', 503);
        try {
            $fresh = $this->wire('pages')->getById((int) $order->id, ['cache' => false])->first();
            if (!$fresh || !$fresh->id) throw new WireException('Order no longer exists.', 404);
            return $operation($fresh);
        } finally {
            $release = $database->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name' => $name]);
        }
    }
    protected function decodeConfigJson(string $json): array { $value = json_decode(trim($json), true); return is_array($value) ? $value : []; }
    protected function record(string $event, array $context): void { $this->wire('log')->save($this->logName, json_encode(['event' => $event, 'at' => date(DATE_ATOM)] + $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
