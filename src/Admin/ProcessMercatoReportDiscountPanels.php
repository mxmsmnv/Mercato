<?php
namespace ProcessWire;

trait ProcessMercatoReportDiscountPanels {

    protected function renderReports(array $reports, Mercato $commerce): string {
        $summary = $reports['summary'] ?? [];
        $cards = [
            [$this->_('Paid revenue'), $commerce->formatPrice((float) ($summary['revenue'] ?? 0)), $this->_('Completed paid orders.')],
            [$this->_('Average order'), $commerce->formatPrice((float) ($summary['average_order_value'] ?? 0)), $this->_('Revenue divided by paid orders.')],
            [$this->_('Open value'), $commerce->formatPrice((float) ($summary['open_value'] ?? 0)), $this->_('Pending + processing.')],
            [$this->_('Pending'), (string) (int) ($summary['pending_orders'] ?? 0), $this->_('Awaiting payment.')],
            [$this->_('Processing'), (string) (int) ($summary['processing_orders'] ?? 0), $this->_('Awaiting gateway.')],
            [$this->_('Failed'), (string) (int) ($summary['failed_orders'] ?? 0), $this->_('Failed or expired.')],
            [$this->_('Canceled'), (string) (int) ($summary['canceled_orders'] ?? 0), $this->_('Canceled before payment.')],
            [$this->_('Items sold'), (string) (float) ($summary['items_sold'] ?? 0), $this->_('Quantity from paid orders.')],
            [$this->_('Shipping'), $commerce->formatPrice((float) ($summary['shipping_revenue'] ?? 0)), $this->_('Shipping charged on paid orders.')],
            [$this->_('Expired reservations'), (string) (int) ($summary['expired_reservations'] ?? 0), $this->_('Pending holds past TTL.')],
            [$this->_('Low stock'), (string) (int) ($summary['low_stock_products'] ?? 0), $this->_('Products at or below threshold.')],
            [$this->_('Oversell debt'), (string) (int) ($summary['oversell_debt_units'] ?? 0), sprintf($this->_('Across %d backorder/preorder product(s).'), (int) ($summary['oversell_debt_products'] ?? 0))],
        ];

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Reports')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Sales and operational reporting from stored ProcessWire order pages.')) . '</p>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="mrc-admin-stats uk-child-width-1-5@l uk-child-width-1-3@m uk-child-width-1-2@s" uk-grid>';
        foreach ($cards as [$label, $value, $note]) {
            $out .= '<div><div class="uk-card uk-card-default uk-card-body uk-card-small mrc-admin-card">';
            $out .= '<span class="ds-section-label">' . $this->e($label) . '</span>';
            $out .= '<strong class="uk-display-block">' . $this->e($value) . '</strong>';
            $out .= '<small class="uk-text-muted">' . $this->e($note) . '</small>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
        $out .= '</section>';

        $out .= $this->renderTaxShippingReadinessReport($reports['shipping_tax'] ?? [], $commerce);
        $out .= $this->renderOrderStatusReport($reports['order_statuses'] ?? []);
        $out .= $this->renderPaymentStatusReport($reports['statuses'] ?? []);
        $out .= $this->renderProductSalesReport($reports['products'] ?? [], $commerce);

        return $out;
    }

    protected function renderTaxShippingReadinessReport(array $data, Mercato $commerce): string {
        $rows = $this->getTaxShippingReadinessRows($data, $commerce);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Tax and Shipping Readiness')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Current checkout calculation settings before country-aware tax zones are added.')) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('tax-shipping-readiness')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e((string) ($data['settings_url'] ?? '')) . '"><i class="fa fa-sliders uk-margin-small-right"></i>' . $this->e($this->_('Configure')) . '</a>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Area'), $this->_('Current setting'), $this->_('Operational note')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach ($rows as [$area, $value, $note]) {
            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e($area) . '</strong></td>';
            $out .= '<td>' . $this->e($value) . '</td>';
            $out .= '<td><small>' . $this->e($note) . '</small></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function getTaxShippingReadinessRows(array $data, Mercato $commerce): array {
        $taxRates = array_map(
            static fn($rate): string => rtrim(rtrim(number_format((float) $rate, 3, '.', ''), '0'), '.') . '%',
            (array) ($data['tax_rates'] ?? [])
        );
        $allowedCountries = (array) ($data['allowed_countries'] ?? []);
        $localZones = (array) ($data['local_delivery_zones'] ?? []);
        $enabledMethods = (array) ($data['enabled_methods'] ?? []);

        return [
            [
                $this->_('Enabled fulfilment'),
                $enabledMethods ? implode(', ', $enabledMethods) : $this->_('Delivery fallback'),
                $this->_('Methods customers can choose during checkout.'),
            ],
            [
                $this->_('Product tax rates'),
                $taxRates ? implode(', ', $taxRates) : $this->_('No product VAT rates'),
                sprintf($this->_('%d product(s) scanned.'), (int) ($data['product_count'] ?? 0)),
            ],
            [
                $this->_('Product shipping'),
                sprintf(
                    $this->_('%d paid / %d free'),
                    (int) ($data['shipping_products'] ?? 0),
                    (int) ($data['free_shipping_products'] ?? 0)
                ),
                $this->_('Based on product-level shipping prices.'),
            ],
            [
                $this->_('Free shipping threshold'),
                (float) ($data['free_shipping_threshold'] ?? 0) > 0
                    ? $commerce->formatPrice((float) $data['free_shipping_threshold'])
                    : $this->_('Disabled'),
                $this->_('Applies to carrier delivery subtotal.'),
            ],
            [
                $this->_('Tax shipping'),
                !empty($data['tax_shipping'])
                    ? sprintf($this->_('Enabled at %s%%'), rtrim(rtrim(number_format((float) ($data['shipping_tax_rate'] ?? 0), 3, '.', ''), '0'), '.'))
                    : $this->_('Disabled'),
                $this->_('Controls whether delivery fees appear in VAT breakdowns.'),
            ],
            [
                $this->_('Allowed delivery countries'),
                $allowedCountries ? implode(', ', $allowedCountries) : $this->_('All countries'),
                $this->_('Carrier and local delivery country allowlist.'),
            ],
            [
                $this->_('Local delivery'),
                sprintf(
                    $this->_('Minimum %s / zones %s'),
                    $commerce->formatPrice((float) ($data['local_delivery_minimum'] ?? 0)),
                    $localZones ? implode(', ', array_slice($localZones, 0, 8)) : $this->_('all')
                ),
                count($localZones) > 8 ? sprintf($this->_('%d more zone(s) configured.'), count($localZones) - 8) : $this->_('Postcode prefixes checked during checkout.'),
            ],
        ];
    }

    protected function renderOrderStatusReport(array $statuses): string {
        $total = array_sum(array_map('intval', $statuses));
        arsort($statuses);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Order Status Breakdown')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Derived merchant workflow status, separate from payment and fulfilment state.')) . '</p>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Status'), $this->_('Orders'), $this->_('Share')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$statuses) {
            $out .= $this->renderSkeletonRows(4, 3);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No order statuses yet.')) . '</p></section>';
        }

        foreach ($statuses as $status => $count) {
            $share = $total > 0 ? round(((int) $count / $total) * 100, 1) : 0;
            $out .= '<tr>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->e(MercatoOrderStatus::statusClass((string) $status)) . '">' . $this->e(MercatoOrderStatus::label((string) $status)) . '</span></td>';
            $out .= '<td>' . $this->e((string) $count) . '</td>';
            $out .= '<td><div class="mrc-report-meter"><span style="width:' . $this->e((string) $share) . '%"></span></div><small>' . $this->e((string) $share) . '%</small></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderPaymentStatusReport(array $statuses): string {
        $total = array_sum(array_map('intval', $statuses));
        arsort($statuses);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Payment Status Breakdown')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Distribution of current order payment states.')) . '</p>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Status'), $this->_('Orders'), $this->_('Share')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$statuses) {
            $out .= $this->renderSkeletonRows(4, 3);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No order statuses yet.')) . '</p></section>';
        }

        foreach ($statuses as $status => $count) {
            $share = $total > 0 ? round(((int) $count / $total) * 100, 1) : 0;
            $out .= '<tr>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->getReportStatusClass((string) $status) . '">' . $this->e(ucfirst(str_replace('_', ' ', (string) $status))) . '</span></td>';
            $out .= '<td>' . $this->e((string) $count) . '</td>';
            $out .= '<td><div class="mrc-report-meter"><span style="width:' . $this->e((string) $share) . '%"></span></div><small>' . $this->e((string) $share) . '%</small></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderProductSalesReport(array $products, Mercato $commerce): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Product Sales')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Top products by paid order revenue.')) . '</p>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Product'), $this->_('Quantity'), $this->_('Orders'), $this->_('Revenue')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$products) {
            $out .= $this->renderSkeletonRows(4, 4);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No paid product sales yet.')) . '</p></section>';
        }

        foreach (array_slice($products, 0, 25) as $product) {
            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e((string) ($product['title'] ?? '-')) . '</strong><br><small>' . $this->e((string) ($product['sku'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e((string) (float) ($product['quantity'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e((string) (int) ($product['orders'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e($commerce->formatPrice((float) ($product['revenue'] ?? 0))) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderDiscounts(array $discounts, Mercato $commerce): string {
        $template = $this->wire('templates')->get('mrc-discount');
        $parent = $this->wire('pages')->get('/discounts/');
        $addUrl = ($template && $parent && $parent->id)
            ? $this->wire('config')->urls->admin . 'page/add/?parent_id=' . (int) $parent->id . '&template_id=' . (int) $template->id
            : '';

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Discounts')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Coupon and discount rules available to checkout.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('discounts')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a>';
        if ($addUrl) {
            $out .= '<a class="uk-button uk-button-primary" href="' . $this->e($addUrl) . '"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Add Discount')) . '</a>';
        }
        $out .= '</div>';
        $out .= '</div>';

        $headings = [$this->_('Code'), $this->_('Type'), $this->_('Value'), $this->_('Active'), $this->_('Targets'), $this->_('Schedule'), $this->_('Usage'), $this->_('Customer limit'), ''];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$template) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('Discount template is not installed yet. Run the Mercato installer from module settings.')) . '</p></section>';
        }

        if (!$discounts) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No discounts yet. Add a first coupon such as WELCOME10.')) . '</p></section>';
        }

        foreach ($discounts as $discount) {
            $data = method_exists($discount, 'toArray') ? $discount->toArray() : [];
            $type = (string) ($data['type'] ?? '');
            $value = match ($type) {
                MercatoDiscountType::PERCENTAGE => ((float) ($data['percent'] ?? 0)) . '%',
                MercatoDiscountType::FIXED => $commerce->formatPrice((float) ($data['amount'] ?? 0)),
                MercatoDiscountType::FREE_SHIPPING => $this->_('Free shipping'),
                default => '-',
            };
            $active = !empty($data['active']) && method_exists($discount, 'isCurrentlyActive') && $discount->isCurrentlyActive();
            $statusClass = $active ? 'is-paid' : 'is-pending';
            $schedule = [];
            if (!empty($data['starts_at'])) $schedule[] = $this->_('From') . ' ' . date('Y-m-d', (int) $data['starts_at']);
            if (!empty($data['ends_at'])) $schedule[] = $this->_('Until') . ' ' . date('Y-m-d', (int) $data['ends_at']);
            $usageLimit = (int) ($data['usage_limit'] ?? 0);
            $customerLimit = (int) ($data['per_customer_limit'] ?? 0);
            $minimumOrderTotal = (float) ($data['minimum_order_total'] ?? 0);
            $usedCount = (int) ($data['used_count'] ?? 0);
            $usageLabel = $usageLimit > 0
                ? $usedCount . ' / ' . $usageLimit
                : $usedCount . ' / ' . $this->_('Unlimited');
            $customerLimitLabel = $customerLimit > 0 ? (string) $customerLimit : $this->_('Unlimited');
            $productTargetCount = count((array) ($data['product_ids'] ?? []));
            $collectionTargetCount = count((array) ($data['collection_ids'] ?? []));
            $customerTargetCount = count((array) ($data['customer_targets'] ?? []));
            $targets = [];
            if ($productTargetCount > 0) {
                $targets[] = sprintf($this->_('%d product(s)'), $productTargetCount);
            }
            if ($collectionTargetCount > 0) {
                $targets[] = sprintf($this->_('%d collection(s)'), $collectionTargetCount);
            }
            if (!$targets) {
                $targets[] = $this->_('All products');
            }
            if ($minimumOrderTotal > 0) {
                $targets[] = sprintf($this->_('Minimum %s'), $commerce->formatPrice($minimumOrderTotal));
            }
            if ($customerTargetCount > 0) {
                $targets[] = sprintf($this->_('%d customer target(s)'), $customerTargetCount);
            }
            $page = $this->wire('pages')->get((int) ($data['page_id'] ?? 0));

            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e((string) ($data['code'] ?: $data['title'] ?? '-')) . '</strong><br><small>' . $this->e((string) ($data['title'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e((string) (MercatoDiscountType::labels()[$type] ?? $type ?: '-')) . '</td>';
            $out .= '<td>' . $this->e($value) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($active ? $this->_('Active') : $this->_('Inactive')) . '</span></td>';
            $out .= '<td>' . $this->e(implode(' / ', $targets)) . '</td>';
            $out .= '<td>' . $this->e($schedule ? implode(' / ', $schedule) : $this->_('Always')) . '</td>';
            $out .= '<td>' . $this->e($usageLabel) . '</td>';
            $out .= '<td>' . $this->e($customerLimitLabel) . '</td>';
            $out .= '<td class="mrc-table-actions">';
            if ($page && $page->id) {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($page)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit')) . '</a>';
            }
            $out .= '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div>';
        $discountEventFilters = $this->getRequestedDiscountEventFilters();
        $out .= $this->renderDiscountAudit($this->getDiscountAuditEvents(12, $discountEventFilters), $commerce, $discountEventFilters);
        $out .= '</section>';
        return $out;
    }

    protected function getReportStatusClass(string $status): string {
        if ($status === MercatoPaymentStatus::REFUNDED) {
            return 'is-failed';
        }
        if (in_array($status, [MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
            return 'is-pending';
        }

        return match ($this->getPaymentStatusBucketForRaw($status)) {
            'paid' => 'is-paid',
            'failed', 'canceled' => 'is-failed',
            default => 'is-pending',
        };
    }
}
