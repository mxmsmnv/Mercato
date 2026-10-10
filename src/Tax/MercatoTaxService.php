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
        $providerKey = (string) $context['provider'];
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
            try { $result = $this->invokeLifecycle($provider, 'commit', ['order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details, 'idempotency_key' => $key]); }
            catch (MercatoTaxProviderException $error) { if ($error->ambiguous) { $details['commit'] = ['status' => 'ambiguous', 'at' => date(DATE_ATOM), 'idempotency_key' => $key, 'reason' => $this->safeFailureReason($error)]; $this->saveDetails($fresh, $details); } throw $error; }
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
            $precision = MercatoCurrency::decimalPlaces((string) $fresh->mrc_currency);
            $amount = round($amount, $precision);
            $key = 'tax_refund_' . (int) $fresh->id . '_' . sha1($refundId . '|' . number_format($amount, $precision, '.', ''));
            $ambiguousIndex = null;
            foreach ((array) ($details['refunds'] ?? []) as $index => $existing) if (($existing['idempotency_key'] ?? '') === $key) {
                if (($existing['status'] ?? '') !== 'ambiguous') return $existing;
                $ambiguousIndex = $index;
                break;
            }
            try { $result = $this->invokeLifecycle($this->providerForDetails($details), 'refund', [
                'order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details,
                'transaction' => (array) ($details['commit'] ?? []),
                'amount' => $amount, 'refund_id' => $refundId, 'idempotency_key' => $key,
            ]); } catch (MercatoTaxProviderException $error) { if ($error->ambiguous) {
                $ambiguous = ['status' => 'ambiguous', 'amount' => $amount, 'refund_id' => $refundId, 'at' => date(DATE_ATOM), 'idempotency_key' => $key, 'reason' => $this->safeFailureReason($error)];
                if ($ambiguousIndex === null) $details['refunds'][] = $ambiguous;
                else $details['refunds'][$ambiguousIndex] = $ambiguous;
                $this->saveDetails($fresh, $details);
            } throw $error; }
            $entry = ['status' => 'refunded', 'amount' => $amount, 'refund_id' => $refundId, 'at' => date(DATE_ATOM), 'idempotency_key' => $key] + $result;
            if ($ambiguousIndex === null) $details['refunds'][] = $entry;
            else $details['refunds'][$ambiguousIndex] = $entry;
            $this->saveDetails($fresh, $details);
            return $entry;
        });
    }

    public function void(Page $order, string $reason = ''): array {
        if (!$order->hasField('mrc_tax_details')) return [];
        return $this->withOrderLock($order, function (Page $fresh) use ($reason): array {
            $details = $this->details($fresh);
            if (!$details || ($details['void']['status'] ?? '') === 'voided') return (array) ($details['void'] ?? []);
            $key = 'tax_void_' . (int) $fresh->id;
            try { $result = $this->invokeLifecycle($this->providerForDetails($details), 'void', [
                'order' => $this->orderContext($fresh), 'quote' => $details['quote'] ?? $details,
                'transaction' => (array) ($details['commit'] ?? []),
                'reason' => $reason, 'idempotency_key' => $key,
            ]); } catch (MercatoTaxProviderException $error) { if ($error->ambiguous) { $details['void'] = ['status' => 'ambiguous', 'reason' => $this->safeFailureReason($error), 'at' => date(DATE_ATOM), 'idempotency_key' => $key]; $this->saveDetails($fresh, $details); } throw $error; }
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
        $marketId = MercatoMarketService::normalizeId((string) ($customer['mrc_market_id'] ?? $customer['market_id'] ?? '')) ?: 'default';
        $market = $this->commerce->marketService()->resolve($marketId);
        $provider = trim((string) ($market['tax_provider'] ?? $this->commerce->tax_provider ?? 'manual')) ?: 'manual';
        $taxBehavior = (string) ($market['price_tax_behavior'] ?? $this->commerce->getTaxDisplayMode());
        if (!in_array($taxBehavior, ['included', 'excluded'], true)) $taxBehavior = 'included';
        $currencyCode = MercatoCurrency::normalizeCode($currency !== '' ? $currency : (string) $market['currency']);
        $items = [];
        foreach ($cart->toArray() as $item) {
            $items[] = [
                'line_id' => (string) ($item['key'] ?? $item['id'] ?? ''), 'product_id' => (int) ($item['product_id'] ?? 0),
                'variant_id' => (string) ($item['variant_id'] ?? ''), 'sku' => (string) ($item['sku'] ?? ''),
                'title' => substr((string) ($item['title'] ?? $item['name'] ?? $item['sku'] ?? ''), 0, 240),
                'product_type' => (string) ($item['product_type'] ?? 'physical'),
                'tax_code' => (string) ($item['tax_code'] ?? ''), 'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => round((float) ($item['price'] ?? 0), MercatoCurrency::decimalPlaces($currencyCode)), 'line_total' => round((float) ($item['sum'] ?? 0), MercatoCurrency::decimalPlaces($currencyCode)),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                'collection_ids' => array_values(array_map('intval', (array) ($item['collection_ids'] ?? []))),
            ];
        }
        $discountSnapshot = $this->buildDiscountSnapshot($items, $discount, (float) ($fulfilment['amount'] ?? 0), $currencyCode);
        $destination = [
            'address' => trim((string) ($customer['address'] ?? '')), 'city' => trim((string) ($customer['city'] ?? '')),
            'postal_code' => trim((string) ($customer['zip'] ?? '')), 'country' => strtoupper(trim((string) ($customer['country'] ?? ''))),
            'region' => strtoupper(trim((string) ($customer['region'] ?? ''))),
        ];
        $taxIdentity = trim((string) ($customer['tax_number'] ?? '')) !== ''
            ? $this->commerce->validateBusinessTaxNumber((string) $customer['tax_number'], ['country' => $destination['country'], 'company' => (string) ($customer['company'] ?? '')])
            : [];
        $requiresShipping = false;
        foreach ($items as $item) if ((string) ($item['product_type'] ?? 'physical') === 'physical') { $requiresShipping = true; break; }
        $context = [
            'operation' => 'estimate', 'provider' => $provider,
            'market_id' => (string) $market['id'],
            'currency' => $currencyCode,
            'display_mode' => $this->commerce->getTaxDisplayMode(), 'tax_behavior' => $taxBehavior,
            'seller_entity' => (string) ($market['seller_entity'] ?? ''),
            'ship_from' => (array) ($market['ship_from'] ?? []),
            'tax_date' => date('Y-m-d'), 'rule_version' => 'mercato-tax-v2',
            'items' => $items, 'customer' => [
                'email' => (string) ($customer['email'] ?? ''), 'tax_number' => (string) ($taxIdentity['tax_number'] ?? ''),
                'tax_number_type' => (string) ($taxIdentity['type'] ?? ''), 'tax_number_validated' => !empty($taxIdentity['validated']) && !empty($taxIdentity['valid']),
                'tax_number_format_valid' => !empty($taxIdentity['valid']),
                'tax_exempt' => !empty($taxIdentity['validated']) && !empty($taxIdentity['valid']) && !empty($taxIdentity['reverse_charge']),
                'taxability_override' => !empty($taxIdentity['validated']) && !empty($taxIdentity['valid']) && !empty($taxIdentity['reverse_charge']) ? 'reverse_charge' : 'none',
            ],
            'destination' => $destination, 'address_source' => $requiresShipping ? 'shipping' : 'billing',
            'shipping' => ['amount' => round((float) ($fulfilment['amount'] ?? 0), MercatoCurrency::decimalPlaces($currencyCode)), 'method' => (string) ($fulfilment['type'] ?? ''), 'product_type' => $requiresShipping ? 'good' : 'service', 'taxable' => $this->commerce->shouldTaxShipping(), 'tax_rate' => $this->commerce->getShippingTaxRate()],
            'discount' => $discountSnapshot,
            'registrations' => (array) ($market['tax_registrations'] ?? $this->decodeConfigJson((string) ($this->commerce->tax_registrations ?? ''))),
            'nexus_regions' => (array) ($market['tax_nexus_regions'] ?? (preg_split('/[\s,]+/', strtoupper((string) ($this->commerce->tax_nexus_regions ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [])),
            'failure_policy' => (string) ($market['tax_failure_policy'] ?? $this->commerce->tax_provider_failure_policy ?? 'fail_closed'),
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
            'tax_behavior' => (string) $context['tax_behavior'],
            'market_id' => (string) $context['market_id'],
            'address_source' => (string) $context['address_source'],
            'seller_entity' => (string) $context['seller_entity'],
            'ship_from' => (array) $context['ship_from'],
            'tax_date' => (string) $context['tax_date'],
            'rule_version' => (string) $context['rule_version'],
            'items' => (array) $context['items'],
            'customer' => [
                'tax_exempt' => !empty($context['customer']['tax_exempt']),
                'taxability_override' => (string) ($context['customer']['taxability_override'] ?? 'none'),
                'tax_number_type' => (string) ($context['customer']['tax_number_type'] ?? ''),
                'tax_number_validated' => !empty($context['customer']['tax_number_validated']),
                'tax_number_present' => trim((string) ($context['customer']['tax_number'] ?? '')) !== '',
            ],
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
        $expected = ['commit' => 'committed', 'refund' => 'refunded', 'void' => 'voided'][$operation] ?? '';
        if ($expected === '' || (string) ($result['status'] ?? '') !== $expected) throw new WireException('Tax provider returned an unsuccessful lifecycle response.', 502);
        $this->record('tax_' . $operation, ['provider' => $provider->getTaxProviderKey(), 'idempotency_key' => (string) ($context['idempotency_key'] ?? ''), 'status' => (string) ($result['status'] ?? '')]);
        return $result;
    }

    protected function retry(callable $operation): array {
        $attempts = max(1, min(4, (int) ($this->commerce->tax_provider_retries ?? 1) + 1));
        $last = null;
        for ($i = 0; $i < $attempts; $i++) try {
            $result = $operation();
            if (!is_array($result)) throw new WireException('Tax provider returned an invalid response.');
            return $result;
        } catch (\Throwable $e) {
            // Engine/programming errors are deterministic and retrying them can
            // repeat provider side effects without any chance of recovery.
            if (!$e instanceof \Exception) throw $e;
            if ($e instanceof MercatoTaxProviderException && !$e->retryable) throw $e;
            if ($e instanceof \InvalidArgumentException || $e instanceof \LogicException) throw $e;
            $last = $e;
        }
        if ($last instanceof MercatoTaxProviderException) throw $last;
        throw new WireException($last?->getMessage() ?: 'Tax provider failed.', 502, $last);
    }

    protected function handleFailure(\Throwable $error, array $context, MercatoTaxProviderInterface $manual): array {
        $policy = (string) ($context['failure_policy'] ?? $this->commerce->tax_provider_failure_policy ?? 'fail_closed');
        $reason = $this->safeFailureReason($error);
        $this->record('tax_provider_failed', ['provider' => (string) $context['provider'], 'policy' => $policy, 'error' => $reason]);
        if ($policy === 'manual_fallback') {
            $quote = $this->invokeEstimate($manual, $context);
            $quote['fallback'] = true; $quote['fallback_reason'] = $reason;
            $quote['requested_provider'] = (string) $context['provider'];
            return MercatoTaxQuote::normalize($quote, $context);
        }
        if ($policy === 'zero_tax') {
            return MercatoTaxQuote::normalize(['provider' => 'manual', 'requested_provider' => (string) $context['provider'], 'currency' => (string) $context['currency'], 'display_mode' => (string) $context['display_mode'], 'tax_behavior' => (string) $context['tax_behavior'], 'tax_added_to_total' => false, 'total_tax' => 0, 'taxable_amount' => 0, 'exempt_amount' => 0, 'fallback' => true, 'fallback_reason' => $reason], $context);
        }
        throw new WireException('Tax could not be calculated: ' . $reason, 503, $error);
    }

    /** Build exact provider-neutral discount allocations so adapters never guess which taxable base was reduced. */
    protected function buildDiscountSnapshot(array $items, array $discount, float $shipping, string $currency): array {
        $type = (string) ($discount['type'] ?? '');
        $amount = max(0.0, (float) ($discount['amount'] ?? 0));
        $snapshot = [
            'code' => (string) ($discount['code'] ?? ''), 'type' => $type,
            'amount' => round($amount, MercatoCurrency::decimalPlaces($currency)),
            'allocations' => [], 'shipping_discount' => 0.0,
            'product_ids' => array_values(array_map('intval', (array) ($discount['product_ids'] ?? []))),
            'collection_ids' => array_values(array_map('intval', (array) ($discount['collection_ids'] ?? []))),
        ];
        if ($amount <= 0) return $snapshot;
        if ($type === MercatoDiscountType::FREE_SHIPPING) {
            $snapshot['shipping_discount'] = min(max(0.0, $shipping), $amount);
            return $snapshot;
        }
        $eligible = [];
        foreach ($items as $index => $item) {
            $hasTargets = $snapshot['product_ids'] || $snapshot['collection_ids'];
            $matchesTarget = in_array((int) ($item['product_id'] ?? 0), $snapshot['product_ids'], true)
                || (bool) array_intersect((array) ($item['collection_ids'] ?? []), $snapshot['collection_ids']);
            if ((!$hasTargets || $matchesTarget) && (float) ($item['line_total'] ?? 0) > 0) $eligible[$index] = (float) $item['line_total'];
        }
        $eligibleTotal = array_sum($eligible);
        if ($eligibleTotal <= 0) return $snapshot;
        $factor = 10 ** MercatoCurrency::decimalPlaces($currency);
        $remaining = min((int) round($amount * $factor), (int) round($eligibleTotal * $factor));
        $remainders = [];
        foreach ($eligible as $index => $lineTotal) {
            $exact = $remaining * ($lineTotal / $eligibleTotal);
            $minor = (int) floor($exact);
            $snapshot['allocations'][(string) ($items[$index]['line_id'] ?? $index)] = $minor;
            $remainders[] = ['line_id' => (string) ($items[$index]['line_id'] ?? $index), 'fraction' => $exact - $minor];
        }
        $assigned = array_sum($snapshot['allocations']);
        usort($remainders, static fn(array $a, array $b): int => $b['fraction'] <=> $a['fraction'] ?: strcmp($a['line_id'], $b['line_id']));
        for ($i = 0; $i < $remaining - $assigned; $i++) $snapshot['allocations'][$remainders[$i % count($remainders)]['line_id']]++;
        foreach ($snapshot['allocations'] as $lineId => $minor) $snapshot['allocations'][$lineId] = round($minor / $factor, MercatoCurrency::decimalPlaces($currency));
        return $snapshot;
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
    protected function orderContext(Page $order): array {
        $payment = json_decode((string) ($order->mrc_payment_details ?? ''), true); $payment = is_array($payment) ? $payment : [];
        $processorId = '';
        foreach ([(string) ($order->mrc_stripe_payment_intent_id ?? ''), (string) ($order->mrc_mollie_payment_id ?? ''), (string) ($payment['id'] ?? '')] as $candidate) {
            if (trim($candidate) !== '') { $processorId = trim($candidate); break; }
        }
        return [
            'id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'created_at' => date(DATE_ATOM, (int) ($order->created ?: time())), 'currency' => (string) $order->mrc_currency,
            'subtotal' => (float) ($order->mrc_subtotal_amount ?? 0), 'shipping' => (float) ($order->mrc_shipping_amount ?? 0),
            'discount' => (float) ($order->mrc_discount_total ?? 0), 'tax' => (float) ($order->mrc_tax_amount ?? 0), 'total' => (float) $order->mrc_total_amount,
            'items' => json_decode((string) ($order->mrc_items ?? ''), true) ?: [],
            'customer' => ['first_name' => (string) ($order->mrc_first_name ?? ''), 'last_name' => (string) ($order->mrc_last_name ?? ''), 'email' => (string) ($order->mrc_email ?? '')],
            'billing_address' => json_decode((string) ($order->mrc_billing_address ?? ''), true) ?: [],
            'shipping_address' => json_decode((string) ($order->mrc_shipping_address ?? ''), true) ?: [],
            'payment' => ['method' => (string) ($order->mrc_payment_method ?? ''), 'processor_id' => $processorId],
            'store_namespace' => substr(hash('sha256', (string) $this->wire('config')->urls->httpRoot), 0, 16),
        ];
    }
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
    protected function safeFailureReason(\Throwable $error): string {
        $message = substr(trim($error->getMessage()), 0, 500);
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        $message = preg_replace('/\b(?:sk|rk|pk|whsec)_(?:test|live)?_[A-Za-z0-9_-]+\b/i', '[redacted-secret]', $message) ?? $message;
        return $message !== '' ? $message : 'Tax provider failed.';
    }
    protected function record(string $event, array $context): void { $this->wire('log')->save($this->logName, json_encode(['event' => $event, 'at' => date(DATE_ATOM)] + $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
