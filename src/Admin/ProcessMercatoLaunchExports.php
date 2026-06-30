<?php
namespace ProcessWire;

trait ProcessMercatoLaunchExports {

    protected function getLaunchChecklistItems(Mercato $commerce): array {
        $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Mercato';
        $products = $this->wire('pages')->find('template=mrc-product, include=all, limit=1');
        $checkoutPage = $this->wire('pages')->get('template=mrc-checkout, include=all');
        $productsParent = $this->wire('pages')->get('/products/');
        $ordersParent = $this->pageFromConfiguredPath((string) ($commerce->orders_parent ?: 'orders'));
        $successPage = $this->pageFromConfiguredPath((string) ($commerce->success_page ?: 'checkout/success'));
        $cancelPage = $this->pageFromConfiguredPath((string) ($commerce->cancel_page ?: 'checkout'));
        $policyPages = method_exists($commerce, 'getPolicyPages') ? $commerce->getPolicyPages() : new PageArray();
        $enabledMethods = $commerce->getEnabledPaymentMethods();
        $methodOptions = Mercato::getPaymentMethodOptions();
        $enabledFulfilmentMethods = $commerce->getEnabledFulfilmentMethods();
        $fulfilmentOptions = Mercato::getFulfilmentMethodOptions();
        $discountReadiness = $this->getDiscountReadiness($commerce);
        $expiredReservations = $commerce->orderRepository()->countExpiredReservations();
        $lowStockProducts = $this->getLowStockProducts($commerce, 1000);
        $oversellDebt = $this->getOversellDebtSummary();
        $staffAccess = $this->getStaffAccessReadiness();
        $recoveryUnsubscribeUrl = $commerce->getRecoveryUnsubscribeUrl('launch-check@example.test');
        parse_str((string) parse_url($recoveryUnsubscribeUrl, PHP_URL_QUERY), $recoveryUnsubscribeQuery);
        $recoveryUnsubscribeReady = str_contains($recoveryUnsubscribeUrl, '/api/mercato/recovery-unsubscribe')
            && $commerce->verifyRecoveryUnsubscribeToken(
                (string) ($recoveryUnsubscribeQuery['email'] ?? ''),
                (string) ($recoveryUnsubscribeQuery['token'] ?? '')
            );

        $checks = [
            [
                'area' => 'setup',
                'item' => 'Storefront pages',
                'ready' => $checkoutPage && $checkoutPage->id && $productsParent && $productsParent->id && $ordersParent && $ordersParent->id && $successPage && $successPage->id && $cancelPage && $cancelPage->id,
                'warning' => false,
                'detail' => 'Checkout, success, cancel, products, and orders pages are available.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'catalog',
                'item' => 'Products',
                'ready' => $products->count() > 0,
                'warning' => false,
                'detail' => $products->count() ? 'At least one product exists and can be sold.' : 'Add a first product or run the demo product installer.',
                'action' => $this->adminUrl('products/'),
            ],
            [
                'area' => 'payments',
                'item' => 'Payment methods',
                'ready' => count($enabledMethods) > 0,
                'warning' => false,
                'detail' => $enabledMethods ? implode(', ', array_map(fn($method) => (string) ($methodOptions[$method] ?? $method), $enabledMethods)) : 'No payment methods enabled.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'fulfilment',
                'item' => 'Receiving methods',
                'ready' => count($enabledFulfilmentMethods) > 0,
                'warning' => empty($commerce->production) && count($enabledFulfilmentMethods) < 3,
                'detail' => implode(', ', array_map(fn($method) => (string) ($fulfilmentOptions[$method] ?? $method), $enabledFulfilmentMethods)),
                'action' => $settingsUrl,
            ],
            [
                'area' => 'setup',
                'item' => 'Currency',
                'ready' => trim((string) $commerce->currency) !== '' && trim((string) $commerce->currency_symbol) !== '',
                'warning' => false,
                'detail' => strtoupper((string) $commerce->currency) . ' / ' . (string) $commerce->currency_symbol,
                'action' => $settingsUrl,
            ],
            [
                'area' => 'storefront',
                'item' => 'Frontend framework',
                'ready' => true,
                'warning' => false,
                'detail' => ucfirst((string) $commerce->getFrontendFramework()),
                'action' => $settingsUrl,
            ],
            [
                'area' => 'legal',
                'item' => 'Store policies',
                'ready' => $policyPages->count() > 0,
                'warning' => $policyPages->count() === 0,
                'detail' => $policyPages->count() > 0 ? implode(', ', array_map(fn(Page $page): string => (string) ($page->title ?: $page->name), iterator_to_array($policyPages))) : 'Add terms, privacy, refund, or shipping policy pages before launch.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'legal',
                'item' => 'Merchant receipt details',
                'ready' => trim($commerce->getMerchantLegalDetailsText()) !== '',
                'warning' => trim($commerce->getMerchantLegalDetailsText()) === '',
                'detail' => trim($commerce->getMerchantLegalDetailsText()) !== '' ? 'Seller/legal details are configured for receipts.' : 'Add seller/legal details so printable receipts identify the merchant.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'discounts',
                'item' => 'Discount test coupon',
                'ready' => (bool) $discountReadiness['ready'],
                'warning' => !(bool) $discountReadiness['ready'],
                'detail' => (string) $discountReadiness['detail'],
                'action' => $this->adminUrl('discounts/'),
            ],
            [
                'area' => 'email',
                'item' => 'Customer email sender',
                'ready' => trim((string) $commerce->notification_sender_email) !== '',
                'warning' => trim((string) $commerce->notification_sender_email) === '',
                'detail' => trim((string) $commerce->notification_sender_email) !== '' ? (string) $commerce->notification_sender_email : 'Configure a sender email before confirmations or shipping notifications can be sent.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'email',
                'item' => 'Recovery unsubscribe',
                'ready' => $recoveryUnsubscribeReady,
                'warning' => false,
                'detail' => $recoveryUnsubscribeReady ? $recoveryUnsubscribeUrl : 'Signed recovery unsubscribe endpoint is not available.',
                'action' => $settingsUrl,
            ],
            [
                'area' => 'access',
                'item' => 'Staff access',
                'ready' => (bool) $staffAccess['ready'],
                'warning' => !(bool) $staffAccess['ready'],
                'detail' => (string) $staffAccess['detail'],
                'action' => $this->wire('config')->urls->admin . 'access/roles/',
            ],
            [
                'area' => 'inventory',
                'item' => 'Inventory reservations',
                'ready' => $expiredReservations === 0,
                'warning' => $expiredReservations > 0,
                'detail' => $expiredReservations > 0 ? sprintf('%d expired reservation(s) should be released.', $expiredReservations) : 'No expired stock reservations.',
                'action' => $this->adminUrl('launch/'),
            ],
            [
                'area' => 'inventory',
                'item' => 'Low stock',
                'ready' => $lowStockProducts->count() === 0,
                'warning' => $lowStockProducts->count() > 0,
                'detail' => $lowStockProducts->count() > 0 ? sprintf('%d product(s) are at or below the configured stock threshold.', $lowStockProducts->count()) : 'No low-stock products.',
                'action' => $this->adminUrl('products/'),
            ],
            [
                'area' => 'inventory',
                'item' => 'Backorder/preorder debt',
                'ready' => true,
                'warning' => $oversellDebt['products'] > 0,
                'detail' => $oversellDebt['products'] > 0 ? sprintf('%d owed unit(s) across %d oversell product(s).', (int) $oversellDebt['units'], (int) $oversellDebt['products']) : 'No backorder or preorder debt.',
                'action' => $this->adminUrl('products/?stock=preorder'),
            ],
        ];

        foreach ($commerce->getGatewaySetupStatuses() as $status) {
            $statusData = method_exists($status, 'toArray') ? $status->toArray() : [];
            $details = (array) ($statusData['details'] ?? []);
            $errors = array_values(array_map('strval', (array) ($statusData['errors'] ?? [])));
            $warnings = array_values(array_map('strval', (array) ($statusData['warnings'] ?? [])));
            $detailParts = [];
            if (!empty($details['mode'])) $detailParts[] = 'Mode: ' . $details['mode'];
            if (!empty($details['webhook_url'])) $detailParts[] = 'Webhook: ' . $details['webhook_url'];
            if ($errors) $detailParts[] = implode(' ', $errors);
            elseif ($warnings) $detailParts[] = implode(' ', $warnings);
            $checks[] = [
                'area' => 'payments',
                'item' => $this->getGatewayChecklistLabel((string) ($statusData['gateway'] ?? 'gateway')) . ' gateway',
                'ready' => !empty($statusData['ready']),
                'warning' => empty($commerce->production) || (empty($errors) && !empty($warnings)),
                'detail' => implode(' | ', $detailParts),
                'action' => $settingsUrl,
            ];
        }

        $actionLabels = [
            'Storefront pages' => $this->_('Review settings'),
            'Products' => $this->_('Open products'),
            'Payment methods' => $this->_('Configure methods'),
            'Receiving methods' => $this->_('Configure fulfilment'),
            'Currency' => $this->_('Edit currency'),
            'Frontend framework' => $this->_('Change framework'),
            'Store policies' => $this->_('Select policies'),
            'Merchant receipt details' => $this->_('Receipt settings'),
            'Discount test coupon' => $this->_('Open discounts'),
            'Customer email sender' => $this->_('Email settings'),
            'Recovery unsubscribe' => $this->_('Email settings'),
            'Staff access' => $this->_('Review roles'),
            'Low stock' => $this->_('Review products'),
            'Backorder/preorder debt' => $this->_('Review oversell products'),
        ];
        foreach ($checks as &$check) {
            $check['title'] = $this->_((string) ($check['title'] ?? $check['item'] ?? ''));
            $check['item'] = (string) ($check['item'] ?? $check['title']);
            if ($check['item'] === 'Discount test coupon' && empty($check['ready'])) {
                $check['demo_discount'] = true;
            }
            if ($check['item'] === 'Inventory reservations') {
                $check['cleanup'] = true;
            }
            $defaultActionLabel = str_ends_with($check['item'], ' gateway')
                ? $this->_('Gateway settings')
                : ($actionLabels[$check['item']] ?? $this->_('Open'));
            $check['action_label'] = (string) ($check['action_label'] ?? $defaultActionLabel);
        }
        unset($check);

        return $checks;
    }

    protected function getLaunchChecklistExportRows(Mercato $commerce): array {
        $rows = [['area', 'item', 'status', 'severity', 'ready', 'detail', 'action_url']];
        foreach ($this->getLaunchChecklistItems($commerce) as $check) {
            $ready = !empty($check['ready']);
            $warning = !empty($check['warning']);
            $status = $ready ? ($warning ? 'warning' : 'ready') : 'blocked';
            $rows[] = [
                (string) ($check['area'] ?? ''),
                (string) ($check['item'] ?? ''),
                $status,
                $warning ? 'recommended' : ($ready ? 'ok' : 'blocking'),
                $ready ? 'yes' : 'no',
                (string) ($check['detail'] ?? ''),
                (string) ($check['action'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getLaunchSummaryExportRows(Mercato $commerce): array {
        $checks = $this->getLaunchChecklistItems($commerce);
        $blockingTotal = 0;
        $blockingReady = 0;
        $recommendedTotal = 0;
        $recommendedReady = 0;
        $blockingItems = [];
        $recommendedItems = [];

        foreach ($checks as $check) {
            $ready = !empty($check['ready']);
            $warning = !empty($check['warning']);
            $item = (string) ($check['item'] ?? '');
            if ($warning) {
                $recommendedTotal++;
                if ($ready) {
                    $recommendedReady++;
                } elseif ($item !== '') {
                    $recommendedItems[] = $item;
                }
                continue;
            }

            $blockingTotal++;
            if ($ready) {
                $blockingReady++;
            } elseif ($item !== '') {
                $blockingItems[] = $item;
            }
        }

        $checkoutPage = $this->wire('pages')->get('template=mrc-checkout, include=all');
        $productsParent = $this->wire('pages')->get('/products/');
        $methodOptions = Mercato::getPaymentMethodOptions();
        $fulfilmentOptions = Mercato::getFulfilmentMethodOptions();
        $paymentMethods = array_map(
            fn($method): string => (string) ($methodOptions[$method] ?? $method),
            $commerce->getEnabledPaymentMethods()
        );
        $fulfilmentMethods = array_map(
            fn($method): string => (string) ($fulfilmentOptions[$method] ?? $method),
            $commerce->getEnabledFulfilmentMethods()
        );
        $gatewayStates = [];
        $gatewayIssues = [];
        foreach ($commerce->getGatewaySetupStatuses() as $status) {
            $data = method_exists($status, 'toArray') ? $status->toArray() : [];
            $gateway = (string) ($data['gateway'] ?? 'gateway');
            $errors = array_values(array_filter(array_map('strval', (array) ($data['errors'] ?? []))));
            $warnings = array_values(array_filter(array_map('strval', (array) ($data['warnings'] ?? []))));
            $gatewayStates[] = sprintf(
                '%s:%s',
                $gateway,
                !empty($data['ready']) ? 'ready' : 'needs_setup'
            );
            foreach (array_merge($errors, $warnings) as $message) {
                $gatewayIssues[] = $gateway . ': ' . $message;
            }
        }

        $score = $blockingTotal > 0 ? round(($blockingReady / $blockingTotal) * 100, 1) : 100.0;

        return [
            ['metric', 'value', 'detail'],
            ['launch_score_percent', (string) $score, sprintf('%d of %d blocking checks ready', $blockingReady, $blockingTotal)],
            ['blocking_remaining', (string) max(0, $blockingTotal - $blockingReady), implode(', ', $blockingItems)],
            ['recommended_remaining', (string) max(0, $recommendedTotal - $recommendedReady), implode(', ', $recommendedItems)],
            ['production_mode', !empty($commerce->production) ? 'enabled' : 'disabled', ''],
            ['currency', strtoupper((string) $commerce->currency), (string) $commerce->currency_symbol],
            ['payment_methods', implode(', ', $paymentMethods), ''],
            ['fulfilment_methods', implode(', ', $fulfilmentMethods), ''],
            ['gateway_readiness', implode(', ', $gatewayStates), ''],
            ['gateway_issues', (string) count($gatewayIssues), implode(' | ', $gatewayIssues)],
            ['checkout_url', ($checkoutPage && $checkoutPage->id) ? $checkoutPage->httpUrl() : '', ''],
            ['products_url', ($productsParent && $productsParent->id) ? $productsParent->httpUrl() : '', ''],
        ];
    }

}
