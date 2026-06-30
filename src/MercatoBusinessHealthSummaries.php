<?php
namespace ProcessWire;

trait MercatoBusinessHealthSummaries {

    public function getDiscountHealthSummary(int $limit = 250): array {
        $this->requireArchitectureClasses();

        $rules = $this->discountService()->listDiscounts(10000);
        $now = time();
        $states = [
            'active' => 0,
            'inactive' => 0,
            'scheduled' => 0,
            'expired' => 0,
        ];
        $types = [];
        $usage = [
            'limited' => 0,
            'exhausted' => 0,
            'near_limit' => 0,
            'per_customer_limited' => 0,
        ];
        $targeting = [
            'all_products' => 0,
            'product_targeted' => 0,
            'collection_targeted' => 0,
            'customer_targeted' => 0,
            'minimum_order' => 0,
        ];

        foreach ($rules as $rule) {
            if (!$rule instanceof MercatoDiscountRule) {
                continue;
            }

            $type = (string) $rule->type;
            if ($type !== '') {
                $types[$type] = (int) ($types[$type] ?? 0) + 1;
            }

            if (!$rule->active) {
                $states['inactive']++;
            } elseif ($rule->startsAt && $rule->startsAt > $now) {
                $states['scheduled']++;
            } elseif ($rule->endsAt && $rule->endsAt < $now) {
                $states['expired']++;
            } elseif ($rule->isCurrentlyActive($now)) {
                $states['active']++;
            } else {
                $states['inactive']++;
            }

            if ($rule->usageLimit > 0) {
                $usage['limited']++;
                if ($rule->usedCount >= $rule->usageLimit) {
                    $usage['exhausted']++;
                } elseif ($rule->usedCount >= max(1, (int) floor($rule->usageLimit * 0.8))) {
                    $usage['near_limit']++;
                }
            }
            if ($rule->perCustomerLimit > 0) {
                $usage['per_customer_limited']++;
            }

            $hasProductTargets = count($rule->productIds) > 0;
            $hasCollectionTargets = count($rule->collectionIds) > 0;
            if (!$hasProductTargets && !$hasCollectionTargets) {
                $targeting['all_products']++;
            }
            if ($hasProductTargets) {
                $targeting['product_targeted']++;
            }
            if ($hasCollectionTargets) {
                $targeting['collection_targeted']++;
            }
            if (count($rule->customerTargets) > 0) {
                $targeting['customer_targeted']++;
            }
            if ($rule->minimumOrderTotal > 0) {
                $targeting['minimum_order']++;
            }
        }

        $events = $this->readJsonLogEvents('mercato-discounts', max(1, $limit));
        $eventTypes = [];
        $eventCodes = [];
        $accepted = 0;
        $rejected = 0;
        $latestRejection = null;
        foreach ($events as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            $code = strtoupper(trim((string) ($event['code'] ?? '')));
            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
            if ($code !== '') {
                $eventCodes[$code] = (int) ($eventCodes[$code] ?? 0) + 1;
            }
            if (!empty($event['valid']) || $eventName === 'accepted') {
                $accepted++;
            } elseif ($eventName === 'rejected' || array_key_exists('valid', $event)) {
                $rejected++;
                if ($latestRejection === null) {
                    $latestRejection = $this->summarizeDiscountOperation($event);
                }
            }
        }

        ksort($types);
        ksort($eventTypes);
        arsort($eventCodes);
        $eventCodes = array_slice($eventCodes, 0, 10, true);

        $action = count($rules) === 0
            ? 'create_discount'
            : ((int) $usage['exhausted'] > 0 ? 'review_exhausted_discounts' : ($rejected > $accepted && $rejected > 0 ? 'review_rejected_discounts' : 'none'));

        return [
            'total' => count($rules),
            'states' => $states,
            'types' => $types,
            'usage' => $usage,
            'targeting' => $targeting,
            'recovery_discount_configured' => $this->getRecoveryDiscountCode() !== '',
            'events' => [
                'total' => count($events),
                'types' => $eventTypes,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'top_codes' => $eventCodes,
                'latest' => isset($events[0]) ? $this->summarizeDiscountOperation($events[0]) : null,
                'latest_rejection' => $latestRejection,
            ],
            'action' => $action,
        ];
    }

    protected function summarizeDiscountOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'code' => (string) ($event['code'] ?? ''),
            'valid' => !empty($event['valid']),
            'amount' => (float) ($event['amount'] ?? 0),
            'email' => (string) ($event['email'] ?? ''),
            'source' => (string) ($event['source'] ?? ''),
            'order_page_id' => (int) ($event['order_page_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'message' => (string) ($event['message'] ?? ''),
        ];
    }

    public function getStoreCreditHealthSummary(int $limit = 250): array {
        $events = $this->readJsonLogEvents('mercato-store-credit', max(1, $limit), ['store_credit_issued', 'store_credit_redeemed']);
        $eventTypes = [];
        $issuedByCurrency = [];
        $redeemedByCurrency = [];
        $codes = [];
        $expiredIssued = 0;
        $expiringSoon = 0;
        $now = time();
        $soon = $now + 30 * 86400;

        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $currency = strtoupper(trim((string) ($event['currency'] ?? $this->currency ?? 'GBP'))) ?: 'GBP';
            $amount = round(max(0.0, (float) ($event['amount'] ?? 0)), 2);
            $code = strtoupper(trim((string) ($event['code'] ?? '')));

            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
            if ($code !== '') {
                if (!isset($codes[$code])) {
                    $codes[$code] = ['issued' => 0.0, 'redeemed' => 0.0, 'events' => 0];
                }
                $codes[$code]['events']++;
            }

            if ($eventName === 'store_credit_issued') {
                $issuedByCurrency[$currency] = round((float) ($issuedByCurrency[$currency] ?? 0) + $amount, 2);
                if ($code !== '') {
                    $codes[$code]['issued'] = round((float) ($codes[$code]['issued'] ?? 0) + $amount, 2);
                }
                $expiresAt = trim((string) ($event['expires_at'] ?? ''));
                $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
                if ($expiresTs !== false && $expiresTs > 0) {
                    if ($expiresTs < $now) {
                        $expiredIssued++;
                    } elseif ($expiresTs <= $soon) {
                        $expiringSoon++;
                    }
                }
            } elseif ($eventName === 'store_credit_redeemed') {
                $redeemedByCurrency[$currency] = round((float) ($redeemedByCurrency[$currency] ?? 0) + $amount, 2);
                if ($code !== '') {
                    $codes[$code]['redeemed'] = round((float) ($codes[$code]['redeemed'] ?? 0) + $amount, 2);
                }
            }
        }

        $netByCurrency = [];
        foreach (array_unique(array_merge(array_keys($issuedByCurrency), array_keys($redeemedByCurrency))) as $currency) {
            $netByCurrency[$currency] = round((float) ($issuedByCurrency[$currency] ?? 0) - (float) ($redeemedByCurrency[$currency] ?? 0), 2);
        }

        foreach ($codes as $code => $totals) {
            $codes[$code]['net'] = round((float) ($totals['issued'] ?? 0) - (float) ($totals['redeemed'] ?? 0), 2);
        }

        ksort($eventTypes);
        ksort($issuedByCurrency);
        ksort($redeemedByCurrency);
        ksort($netByCurrency);
        uasort($codes, static fn(array $a, array $b): int => (int) ($b['events'] ?? 0) <=> (int) ($a['events'] ?? 0));
        $codes = array_slice($codes, 0, 10, true);

        $overRedeemed = array_filter($codes, static fn(array $totals): bool => (float) ($totals['net'] ?? 0) < 0);
        $action = count($overRedeemed) > 0
            ? 'review_store_credit_redemptions'
            : ($expiringSoon > 0 ? 'review_expiring_store_credit' : 'none');

        return [
            'total' => count($events),
            'events' => $eventTypes,
            'issued_by_currency' => $issuedByCurrency,
            'redeemed_by_currency' => $redeemedByCurrency,
            'net_by_currency' => $netByCurrency,
            'codes' => $codes,
            'expired_issued_events' => $expiredIssued,
            'expiring_soon_issued_events' => $expiringSoon,
            'latest' => isset($events[0]) ? $this->summarizeStoreCreditOperation($events[0]) : null,
            'action' => $action,
        ];
    }

    protected function summarizeStoreCreditOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'code' => (string) ($event['code'] ?? ''),
            'amount' => (float) ($event['amount'] ?? 0),
            'currency' => (string) ($event['currency'] ?? ''),
            'email' => (string) ($event['email'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'source' => (string) ($event['source'] ?? ''),
            'expires_at' => (string) ($event['expires_at'] ?? ''),
        ];
    }

    public function getReturnsHealthSummary(int $limit = 250): array {
        $events = $this->readJsonLogEvents('mercato-returns', max(1, $limit), ['return_requested']);
        $statuses = [];
        $sources = [];
        $orders = [];
        $itemLines = 0;
        $requested = 0;

        foreach ($events as $event) {
            $status = strtolower(trim((string) ($event['status'] ?? 'requested'))) ?: 'requested';
            $source = strtolower(trim((string) ($event['source'] ?? 'unknown'))) ?: 'unknown';
            $orderId = (int) ($event['order_id'] ?? 0);
            $items = is_array($event['items'] ?? null) ? $event['items'] : [];

            $statuses[$status] = (int) ($statuses[$status] ?? 0) + 1;
            $sources[$source] = (int) ($sources[$source] ?? 0) + 1;
            if ($orderId > 0) {
                $orders[$orderId] = true;
            }
            $itemLines += count($items);
            if ($status === 'requested') {
                $requested++;
            }
        }

        ksort($statuses);
        ksort($sources);

        return [
            'total' => count($events),
            'statuses' => $statuses,
            'sources' => $sources,
            'orders' => count($orders),
            'item_lines' => $itemLines,
            'latest' => isset($events[0]) ? $this->summarizeReturnOperation($events[0]) : null,
            'action' => $requested > 0 ? 'review_return_requests' : 'none',
        ];
    }

    protected function summarizeReturnOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'request_id' => (string) ($event['request_id'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'email' => (string) ($event['email'] ?? ''),
            'source' => (string) ($event['source'] ?? ''),
            'item_lines' => is_array($event['items'] ?? null) ? count($event['items']) : 0,
            'reason' => (string) ($event['reason'] ?? ''),
        ];
    }

    public function getProductActivityHealthSummary(int $limit = 250): array {
        $events = $this->readJsonLogEvents('mercato-products', max(1, $limit));
        $eventTypes = [];
        $users = [];
        $products = [];
        $changedFields = [];
        $stockAdjustments = 0;
        $imported = 0;
        $bulkChanges = 0;

        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $user = (string) ($event['user'] ?? 'system');
            $productId = (int) ($event['product_id'] ?? 0);

            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
            if ($user !== '') {
                $users[$user] = (int) ($users[$user] ?? 0) + 1;
            }
            if ($productId > 0) {
                $products[$productId] = true;
            }

            if ($eventName === 'product_stock_adjusted' || array_key_exists('delta', $event)) {
                $stockAdjustments++;
            }
            if ($eventName === 'product_imported') {
                $imported++;
            }
            if (str_starts_with($eventName, 'product_bulk_')) {
                $bulkChanges++;
            }

            foreach ($this->extractProductActivityChangedFields($event) as $field) {
                $changedFields[$field] = (int) ($changedFields[$field] ?? 0) + 1;
            }
        }

        ksort($eventTypes);
        arsort($users);
        arsort($changedFields);

        return [
            'total' => count($events),
            'events' => $eventTypes,
            'products' => count($products),
            'users' => array_slice($users, 0, 10, true),
            'changed_fields' => array_slice($changedFields, 0, 15, true),
            'stock_adjustments' => $stockAdjustments,
            'imports' => $imported,
            'bulk_changes' => $bulkChanges,
            'latest' => isset($events[0]) ? $this->summarizeProductActivityOperation($events[0]) : null,
            'action' => 'none',
        ];
    }

    protected function extractProductActivityChangedFields(array $event): array {
        $fields = [];
        $changed = (string) ($event['changed_fields'] ?? '');
        if ($changed !== '') {
            foreach (preg_split('/[|,]/', $changed) ?: [] as $field) {
                $field = trim((string) $field);
                if ($field !== '') {
                    $fields[] = $field;
                }
            }
        }

        foreach (['changes', 'before', 'after'] as $key) {
            if (is_array($event[$key] ?? null)) {
                foreach (array_keys((array) $event[$key]) as $field) {
                    if (is_string($field) && $field !== '') {
                        $fields[] = $field;
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }

    protected function summarizeProductActivityOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'product_id' => (int) ($event['product_id'] ?? 0),
            'title' => (string) ($event['title'] ?? ''),
            'sku' => (string) ($event['sku'] ?? ''),
            'user' => (string) ($event['user'] ?? ''),
            'source' => (string) ($event['source'] ?? ''),
            'changed_fields' => (string) ($event['changed_fields'] ?? ''),
            'delta' => array_key_exists('delta', $event) ? (int) $event['delta'] : null,
        ];
    }

    public function getSupportActivityHealthSummary(int $limit = 250): array {
        $orderEdits = $this->readJsonLogEvents('mercato-order-edits', max(1, $limit), ['order_edited']);
        $orderNotes = $this->readJsonLogEvents('mercato-order-notes', max(1, $limit), ['order_note']);
        $customerNotes = $this->readJsonLogEvents('mercato-customer-notes', max(1, $limit), ['customer_note']);
        $events = $this->readJsonLogEvents('mercato-events', max(1, $limit));

        $orders = [];
        $customers = [];
        $users = [];
        foreach ([$orderEdits, $orderNotes, $customerNotes, $events] as $stream) {
            foreach ($stream as $event) {
                $orderId = (int) ($event['order_id'] ?? 0);
                $customerKey = (string) ($event['customer_key'] ?? ($event['email'] ?? ''));
                $user = (string) ($event['user'] ?? '');
                if ($orderId > 0) {
                    $orders[$orderId] = true;
                }
                if ($customerKey !== '') {
                    $customers[$customerKey] = true;
                }
                if ($user !== '') {
                    $users[$user] = (int) ($users[$user] ?? 0) + 1;
                }
            }
        }

        $eventTypes = [];
        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
        }

        arsort($users);
        ksort($eventTypes);

        return [
            'order_edits' => count($orderEdits),
            'order_notes' => count($orderNotes),
            'customer_notes' => count($customerNotes),
            'events' => [
                'total' => count($events),
                'types' => $eventTypes,
            ],
            'orders' => count($orders),
            'customers' => count($customers),
            'users' => array_slice($users, 0, 10, true),
            'latest_order_edit' => isset($orderEdits[0]) ? $this->summarizeSupportActivityOperation($orderEdits[0]) : null,
            'latest_order_note' => isset($orderNotes[0]) ? $this->summarizeSupportActivityOperation($orderNotes[0]) : null,
            'latest_customer_note' => isset($customerNotes[0]) ? $this->summarizeSupportActivityOperation($customerNotes[0]) : null,
            'latest_event' => isset($events[0]) ? $this->summarizeSupportActivityOperation($events[0]) : null,
            'action' => 'none',
        ];
    }

    protected function summarizeSupportActivityOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'customer_key' => (string) ($event['customer_key'] ?? ''),
            'email' => (string) ($event['email'] ?? ''),
            'user' => (string) ($event['user'] ?? ''),
            'gateway' => (string) ($event['gateway'] ?? ''),
            'total' => array_key_exists('total', $event) ? (float) $event['total'] : null,
        ];
    }

    protected function parseJsonLogLine(string $line): ?array {
        $jsonStart = strpos($line, '{');
        if ($jsonStart === false) {
            return null;
        }

        $event = json_decode(substr($line, $jsonStart), true);
        if (!is_array($event)) {
            return null;
        }

        $event['_time'] = trim(substr($line, 0, $jsonStart)) ?: '';
        return $event;
    }

}
