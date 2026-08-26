<?php
namespace ProcessWire;

trait ProcessMercatoLaunchPanels {
    protected function renderLaunchChecklist(Mercato $commerce, array $cleanupResult = [], array $demoOrderResult = [], array $demoSetupResult = [], array $demoDiscountResult = [], array $privacyRetentionResult = []): string {
        $checkoutPage = $this->wire('pages')->get('template=mrc-checkout, include=all');
        $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Mercato';
        $checks = $this->getLaunchChecklistItems($commerce);

        $readyCount = 0;
        foreach ($checks as $check) {
            if (!empty($check['ready']) && empty($check['warning'])) {
                $readyCount++;
            }
        }

        $out = '<section class="pw-wrap mrc-admin-panel mrc-launch-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Launch Checklist')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Operational checks for going from install to first payment.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('launch-summary')) . '"><i class="fa fa-clipboard-list uk-margin-small-right"></i>' . $this->e($this->_('Export summary')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('launch-checklist')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export checklist')) . '</a>';
        $out .= '<div class="mrc-launch-score"><strong>' . $this->e((string) $readyCount) . '</strong><span>' . $this->e(sprintf($this->_('of %d ready'), count($checks))) . '</span></div>';
        $out .= '</div>';
        $out .= '</div>';

        $out .= $this->renderLaunchSummaryPanel($commerce);
        $out .= $this->renderLaunchSetupPath($checks);
        $out .= $this->renderLaunchQuickStart($checks);
        $out .= $this->renderLaunchPriorityPanel($checks);
        $out .= '<div class="mrc-checklist">';
        if ($cleanupResult) {
            $class = !empty($cleanupResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p>' . $this->e((string) ($cleanupResult['summary'] ?? '')) . '</p></div>';
        }
        if ($demoOrderResult) {
            $class = !empty($demoOrderResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p>' . $this->e((string) ($demoOrderResult['summary'] ?? '')) . '</p>';
            if (!empty($demoOrderResult['order']) && $demoOrderResult['order'] instanceof Page) {
                $out .= '<p><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('fulfilment/')) . '">' . $this->e($this->_('Open fulfilment queue')) . '</a> ';
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($demoOrderResult['order'])) . '">' . $this->e($this->_('Open timeline')) . '</a></p>';
            }
            $out .= '</div>';
        }
        if ($demoSetupResult) {
            $class = !empty($demoSetupResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p>' . $this->e((string) ($demoSetupResult['summary'] ?? '')) . '</p>';
            if (empty($demoSetupResult['errors'])) {
                $checkoutUrl = ($checkoutPage && $checkoutPage->id) ? $checkoutPage->url : '/checkout/';
                $out .= '<p><a class="uk-button uk-button-default" href="' . $this->e($checkoutUrl) . '" target="_blank" rel="noopener noreferrer">' . $this->e($this->_('Open checkout')) . '</a></p>';
            }
            $out .= '</div>';
        }
        if ($demoDiscountResult) {
            $class = !empty($demoDiscountResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p>' . $this->e((string) ($demoDiscountResult['summary'] ?? '')) . '</p>';
            if (empty($demoDiscountResult['errors'])) {
                $out .= '<p><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('discounts/')) . '">' . $this->e($this->_('Open discounts')) . '</a></p>';
            }
            $out .= '</div>';
        }
        foreach ($checks as $check) {
            $ready = !empty($check['ready']);
            $warning = !empty($check['warning']);
            $state = $ready ? ($warning ? 'warning' : 'ready') : 'blocked';
            $out .= '<div class="mrc-checklist-item is-' . $state . '">';
            $out .= '<div>';
            $out .= '<div class="mrc-checklist-title">';
            $out .= $this->renderLaunchStatusBadge($state);
            $out .= '<strong>' . $this->e((string) $check['title']) . '</strong>';
            $out .= '</div>';
            $out .= '<p>' . $this->e((string) ($check['detail'] ?: '-')) . '</p>';
            $out .= '</div>';
            if (!empty($check['cleanup'])) {
                $out .= '<form method="post" action="' . $this->e($this->adminUrl('launch/')) . '" class="mrc-inline-form">';
                $out .= $this->renderCsrfInput();
                $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_cleanup_reservations" value="1">' . $this->e($this->_('Release expired')) . '</button>';
                $out .= '</form>';
            } elseif (!empty($check['demo_discount'])) {
                $out .= '<form method="post" action="' . $this->e($this->adminUrl('launch/')) . '" class="mrc-inline-form">';
                $out .= $this->renderCsrfInput();
                $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_create_demo_discount" value="1"><i class="fa fa-ticket uk-margin-small-right"></i>' . $this->e($this->_('Create WELCOME10')) . '</button>';
                $out .= '</form>';
            } elseif (!empty($check['action'])) {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e((string) $check['action']) . '">' . $this->e((string) $check['action_label']) . '</a>';
            }
            $out .= '</div>';
        }
        $out .= '</div></section>';

        $out .= '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Test Workflow')) . '</h2>';
        if (empty($commerce->production)) {
            $out .= '<p class="uk-text-muted">' . $this->e($this->_('Enable a full test checkout with Demo Payment, or create paid workflow fixtures without contacting a gateway or changing stock. Demo checkout payments follow normal stock updates.')) . '</p></div>';
            $out .= '<form method="post" action="' . $this->e($this->adminUrl('launch/')) . '" class="mrc-inline-form">';
            $out .= $this->renderCsrfInput();
            $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_enable_demo_fulfilment" value="1"><i class="fa fa-sliders uk-margin-small-right"></i>' . $this->e($this->_('Enable demo checkout flow')) . '</button>';
            $out .= '</form>';
            $out .= '<form method="post" action="' . $this->e($this->adminUrl('launch/')) . '" class="mrc-inline-form">';
            $out .= $this->renderCsrfInput();
            $out .= '<select class="uk-select" name="demo_fulfilment_method" aria-label="' . $this->e($this->_('Demo fulfilment method')) . '">';
            foreach (Mercato::getFulfilmentMethodOptions() as $method => $label) {
                $out .= '<option value="' . $this->e((string) $method) . '">' . $this->e($this->_((string) $label)) . '</option>';
            }
            $out .= '</select>';
            $out .= '<button class="uk-button uk-button-primary" type="submit" name="mrc_create_demo_order" value="1"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Create demo order')) . '</button>';
            $out .= '</form>';
        } else {
            $out .= '<p class="uk-text-muted">' . $this->e($this->_('Demo order creation is disabled while production mode is active.')) . '</p></div>';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($settingsUrl) . '">' . $this->e($this->_('Review mode settings')) . '</a>';
        }
        $out .= '</div></section>';
        $out .= $this->renderSeoDiagnostics($commerce);
        $out .= $this->renderPrivacyRetentionPanel($commerce, $privacyRetentionResult);
        $out .= $this->renderOperationalStatusPanel($commerce);
        $out .= $this->renderAnalyticsDiagnostics($commerce);

        return $out;
    }

    protected function renderSeoDiagnostics(Mercato $commerce): string {
        if (!$commerce->usesBuiltInSeo()) {
            return '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Storefront SEO')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Ichiban is installed and is the authoritative SEO owner. Mercato metadata output, sitemap route, and native diagnostics are disabled to prevent duplicates.')) . '</p></div><strong>' . $this->e($this->_('ICHIBAN')) . '</strong></div></section>';
        }
        $rows = $commerce->seoService()->diagnostics(); $issueCount = 0;
        foreach ($rows as $row) $issueCount += count((array) ($row['issues'] ?? []));
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Storefront SEO diagnostics')) . '</h2><p class="uk-text-muted">' . $this->e(sprintf($this->_('%d catalog pages checked; %d metadata issue(s).'), count($rows), $issueCount)) . '</p></div><a class="uk-button uk-button-default" target="_blank" rel="noopener" href="' . $this->e(rtrim((string) $this->wire('config')->urls->root, '/') . '/sitemap-mercato.xml') . '">' . $this->e($this->_('Open sitemap')) . '</a></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small"><thead><tr><th>' . $this->e($this->_('Page')) . '</th><th>' . $this->e($this->_('Robots')) . '</th><th>' . $this->e($this->_('Sitemap')) . '</th><th>' . $this->e($this->_('Issues')) . '</th></tr></thead><tbody>';
        foreach ($rows as $row) { $issues = (array) ($row['issues'] ?? []); if (!$issues && $issueCount > 0) continue; $out .= '<tr><td><a href="' . $this->e((string) $row['url']) . '" target="_blank" rel="noopener">' . $this->e((string) $row['title']) . '</a></td><td>' . $this->e((string) $row['robots']) . '</td><td>' . (!empty($row['sitemap']) ? $this->e($this->_('Included')) : $this->e($this->_('Excluded'))) . '</td><td>' . $this->e($issues ? implode(', ', $issues) : $this->_('None')) . '</td></tr>'; }
        return $out . '</tbody></table></div></section>';
    }

    protected function renderPrivacyRetentionPanel(Mercato $commerce, array $result = []): string {
        $canManage = $this->hasCommercePermission(self::PERMISSION_MANAGE_PRIVACY); $report = (array) ($result['report'] ?? []);
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Privacy retention')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Bounded, retry-safe cleanup. Always inspect the dry-run before execution. Legal holds and active commerce workflows are blocked.')) . '</p></div></div>';
        if ($result) { $out .= '<div class="uk-alert ' . (!empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success') . '"><p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>'; foreach ((array) ($result['errors'] ?? []) as $error) $out .= '<p>' . $this->e((string) $error) . '</p>'; if ($report) $out .= '<pre style="white-space:pre-wrap">' . $this->e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') . '</pre>'; $out .= '</div>'; }
        if ($canManage) { $action = $this->adminUrl('launch/'); $out .= '<div class="mrc-panel-actions"><form method="post" action="' . $this->e($action) . '" class="mrc-inline-form">' . $this->renderCsrfInput() . '<button class="uk-button uk-button-default" name="mrc_privacy_retention_action" value="dry_run" type="submit">' . $this->e($this->_('Dry-run report')) . '</button></form><form method="post" action="' . $this->e($action) . '" class="mrc-inline-form">' . $this->renderCsrfInput() . '<input type="hidden" name="mrc_privacy_retention_action" value="run"><label><input class="uk-checkbox" type="checkbox" name="privacy_retention_confirmed" value="1" required> ' . $this->e($this->_('Execute reviewed batch')) . '</label><button class="uk-button uk-button-danger" type="submit">' . $this->e($this->_('Run retention')) . '</button></form></div>'; }
        return $out . '</section>';
    }

    protected function renderOperationalStatusPanel(Mercato $commerce): string {
        $health = $commerce->operationalService()->health(true); $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Operational health')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Permissioned, PII-free diagnostics for monitoring and recovery.')) . '</p></div><strong>' . $this->e(strtoupper((string) $health['status'])) . '</strong></div><div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small"><thead><tr><th>' . $this->e($this->_('Category')) . '</th><th>' . $this->e($this->_('Status')) . '</th><th>' . $this->e($this->_('Check')) . '</th></tr></thead><tbody>';
        foreach ((array) ($health['checks'] ?? []) as $check) $out .= '<tr><td>' . $this->e((string) ($check['category'] ?? '')) . '</td><td>' . $this->e(strtoupper((string) ($check['status'] ?? ''))) . '</td><td>' . $this->e((string) ($check['message'] ?? '')) . '</td></tr>';
        return $out . '</tbody></table></div><p><code>GET /api/mercato/health</code> ' . $this->e($this->_('is the minimal uptime endpoint. Add ?details=1 with the configured Authorization bearer token for category diagnostics.')) . '</p></section>';
    }

    protected function renderAnalyticsDiagnostics(Mercato $commerce): string {
        $diagnostics = $commerce->analyticsService()->diagnostics(20); $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Analytics diagnostics')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Consent-aware adapters and recent minimized delivery events.')) . '</p></div><strong>' . $this->e(!empty($diagnostics['enabled']) ? $this->_('ENABLED') : $this->_('DISABLED')) . '</strong></div>';
        $out .= '<p><strong>' . $this->e($this->_('Configured adapters')) . ':</strong> ' . $this->e(implode(', ', (array) $diagnostics['configured_adapters']) ?: $this->_('None')) . '<br><strong>' . $this->e($this->_('Registered adapters')) . ':</strong> ' . $this->e(implode(', ', (array) $diagnostics['registered_adapters']) ?: $this->_('None')) . '</p>';
        if (!empty($diagnostics['recent'])) $out .= '<pre style="white-space:pre-wrap;max-height:24rem;overflow:auto">' . $this->e(json_encode($diagnostics['recent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') . '</pre>'; else $out .= '<p class="uk-text-muted">' . $this->e($this->_('No analytics delivery events recorded.')) . '</p>'; return $out . '</section>';
    }

    protected function renderLaunchSetupPath(array $checks): string {
        $phases = [
            'storefront' => [
                'label' => $this->_('Storefront'),
                'items' => ['Storefront pages', 'Products', 'Frontend framework', 'Store policies'],
            ],
            'payments' => [
                'label' => $this->_('Payments'),
                'items' => ['Payment methods', 'Stripe gateway', 'Mollie gateway', 'PayPal gateway'],
            ],
            'operations' => [
                'label' => $this->_('Operations'),
                'items' => ['Receiving methods', 'Inventory reservations', 'Low stock', 'Backorder/preorder debt', 'Staff access'],
            ],
            'customer' => [
                'label' => $this->_('Customer comms'),
                'items' => ['Customer email sender', 'Recovery unsubscribe', 'Merchant receipt details', 'Discount test coupon'],
            ],
        ];

        $byItem = [];
        foreach ($checks as $check) {
            $item = (string) ($check['item'] ?? '');
            if ($item !== '') {
                $byItem[$item] = $check;
            }
        }
        foreach ($checks as $check) {
            $item = (string) ($check['item'] ?? '');
            if ($item !== '' && str_ends_with($item, ' gateway')) {
                $byItem[$item] = $check;
            }
        }

        $out = '<div class="mrc-launch-setup-path">';
        $out .= '<div class="mrc-launch-setup-head"><span class="ds-section-label">' . $this->e($this->_('Setup path')) . '</span>';
        $out .= '<h3>' . $this->e($this->_('Guided launch phases')) . '</h3>';
        $out .= '<p>' . $this->e($this->_('Work left to right: storefront, payments, operations, then customer communication.')) . '</p></div>';
        $out .= '<div class="mrc-launch-setup-grid">';
        foreach ($phases as $phase) {
            $items = [];
            foreach ((array) ($phase['items'] ?? []) as $item) {
                if (isset($byItem[$item])) {
                    $items[] = $byItem[$item];
                }
            }
            if (!$items) {
                continue;
            }
            $ready = 0;
            $warnings = 0;
            $blocked = 0;
            foreach ($items as $item) {
                if (!empty($item['ready']) && empty($item['warning'])) {
                    $ready++;
                } elseif (!empty($item['warning'])) {
                    $warnings++;
                } else {
                    $blocked++;
                }
            }
            $total = count($items);
            $percent = $total > 0 ? (int) round(($ready / $total) * 100) : 0;
            $state = $blocked > 0 ? 'blocked' : ($warnings > 0 ? 'warning' : 'ready');
            $primaryAction = '';
            $primaryLabel = '';
            foreach ($items as $item) {
                if ((!empty($item['ready']) && empty($item['warning'])) || empty($item['action'])) {
                    continue;
                }
                $primaryAction = (string) $item['action'];
                $primaryLabel = (string) ($item['action_label'] ?? $this->_('Open'));
                break;
            }
            $out .= '<div class="mrc-launch-phase is-' . $state . '">';
            $out .= '<div class="mrc-launch-phase-head"><div><span>' . $this->e((string) ($phase['label'] ?? '')) . '</span>';
            $out .= '<strong>' . $this->e((string) $percent) . '%</strong></div>' . $this->renderLaunchStatusBadge($state) . '</div>';
            $out .= '<div class="mrc-launch-phase-meter"><span style="width:' . max(0, min(100, $percent)) . '%"></span></div>';
            $out .= '<small>' . $this->e(sprintf($this->_('%d ready, %d recommended, %d blocking'), $ready, $warnings, $blocked)) . '</small>';
            $out .= '<ul>';
            foreach ($items as $item) {
                $itemState = !empty($item['ready']) ? (!empty($item['warning']) ? 'warning' : 'ready') : 'blocked';
                $out .= '<li class="is-' . $itemState . '">' . $this->renderLaunchStatusBadge($itemState) . '<span>' . $this->e((string) ($item['item'] ?? '')) . '</span></li>';
            }
            $out .= '</ul>';
            if ($primaryAction !== '') {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($primaryAction) . '">' . $this->e($primaryLabel) . '</a>';
            }
            $out .= '</div>';
        }
        return $out . '</div></div>';
    }

    protected function renderLaunchSummaryPanel(Mercato $commerce): string {
        $rows = $this->getLaunchSummaryExportRows($commerce);
        $summary = [];
        foreach (array_slice($rows, 1) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $metric = (string) ($row[0] ?? '');
            if ($metric !== '') {
                $summary[$metric] = [
                    'value' => (string) ($row[1] ?? ''),
                    'detail' => (string) ($row[2] ?? ''),
                ];
            }
        }

        $cards = [
            [$this->_('Launch score'), (string) ($summary['launch_score_percent']['value'] ?? '0') . '%', (string) ($summary['launch_score_percent']['detail'] ?? '')],
            [$this->_('Blocking remaining'), (string) ($summary['blocking_remaining']['value'] ?? '0'), (string) ($summary['blocking_remaining']['detail'] ?? '')],
            [$this->_('Recommended remaining'), (string) ($summary['recommended_remaining']['value'] ?? '0'), (string) ($summary['recommended_remaining']['detail'] ?? '')],
            [$this->_('Gateways'), (string) ($summary['gateway_readiness']['value'] ?? '-'), ''],
            [$this->_('Gateway issues'), (string) ($summary['gateway_issues']['value'] ?? '0'), (string) ($summary['gateway_issues']['detail'] ?? '')],
        ];

        $out = '<div class="mrc-launch-summary-panel">';
        $out .= '<div class="mrc-launch-summary-grid">';
        foreach ($cards as [$label, $value, $detail]) {
            $out .= '<div class="mrc-launch-summary-card">';
            $out .= '<span class="ds-section-label">' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e($value !== '' ? $value : '-') . '</strong>';
            $out .= '<small>' . $this->e($detail !== '' ? $detail : '-') . '</small>';
            $out .= '</div>';
        }
        $out .= '</div>';

        $links = [
            [$this->_('Checkout'), (string) ($summary['checkout_url']['value'] ?? '')],
            [$this->_('Products'), (string) ($summary['products_url']['value'] ?? '')],
        ];
        $out .= '<div class="mrc-launch-summary-links">';
        foreach ($links as [$label, $url]) {
            if ($url === '') {
                continue;
            }
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($url) . '" target="_blank" rel="noopener noreferrer">' . $this->e((string) $label) . '</a>';
        }
        $out .= '</div></div>';

        return $out;
    }

    protected function renderLaunchPriorityPanel(array $checks): string {
        $blocking = [];
        $recommended = [];
        foreach ($checks as $check) {
            if (!empty($check['ready'])) {
                continue;
            }
            if (!empty($check['warning'])) {
                $recommended[] = $check;
            } else {
                $blocking[] = $check;
            }
        }

        $out = '<div class="mrc-launch-priority">';
        $out .= '<div class="mrc-launch-priority-head">';
        $out .= '<div><span class="ds-section-label">' . $this->e($this->_('Next actions')) . '</span>';
        if ($blocking) {
            $out .= '<h3>' . $this->e(sprintf($this->_('%d launch blocker(s)'), count($blocking))) . '</h3>';
            $out .= '<p>' . $this->e($this->_('Clear these before accepting real payments.')) . '</p></div>';
        } else {
            $out .= '<h3>' . $this->e($this->_('No launch blockers')) . '</h3>';
            $out .= '<p>' . $this->e($this->_('Critical setup is ready. Review recommended tasks before going live.')) . '</p></div>';
        }
        $out .= '</div>';

        $out .= '<div class="mrc-launch-priority-grid">';
        $out .= $this->renderLaunchPriorityList($this->_('Blocking'), $blocking, 'blocked');
        $out .= $this->renderLaunchPriorityList($this->_('Recommended'), $recommended, 'warning');
        $out .= '</div></div>';

        return $out;
    }

    protected function renderLaunchPriorityList(string $label, array $items, string $state): string {
        $out = '<div class="mrc-launch-priority-list is-' . $state . '">';
        $out .= '<h4>' . $this->e($label) . '</h4>';
        if (!$items) {
            $out .= '<p class="uk-text-muted">' . $this->e($state === 'blocked' ? $this->_('No blocking tasks remain.') : $this->_('No recommended gaps remain.')) . '</p></div>';
            return $out;
        }

        $out .= '<ul>';
        foreach (array_slice($items, 0, 5) as $check) {
            $action = (string) ($check['action'] ?? '');
            $actionLabel = (string) ($check['action_label'] ?? $this->_('Open'));
            $out .= '<li>';
            $out .= '<div><strong>' . $this->e((string) ($check['title'] ?? $check['item'] ?? '')) . '</strong>';
            $out .= '<span>' . $this->e((string) ($check['detail'] ?? '')) . '</span></div>';
            if ($action !== '') {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($action) . '">' . $this->e($actionLabel) . '</a>';
            }
            $out .= '</li>';
        }
        if (count($items) > 5) {
            $out .= '<li class="mrc-launch-more">' . $this->e(sprintf($this->_('%d more item(s) in the full checklist below.'), count($items) - 5)) . '</li>';
        }
        $out .= '</ul></div>';

        return $out;
    }

    protected function renderLaunchQuickStart(array $checks): string {
        $steps = [
            'Storefront pages' => [
                'label' => $this->_('Create storefront pages'),
                'note' => $this->_('Checkout, products, success, cancel, and orders pages.'),
            ],
            'Products' => [
                'label' => $this->_('Add products'),
                'note' => $this->_('At least one sellable product with price and stock.'),
            ],
            'Payment methods' => [
                'label' => $this->_('Enable payments'),
                'note' => $this->_('Demo, Stripe, Mollie, or PayPal method selected for checkout.'),
            ],
            'Gateway setup' => [
                'label' => $this->_('Clear gateway setup'),
                'note' => $this->_('Live keys, webhook secrets, and provider setup for enabled gateways.'),
            ],
            'Receiving methods' => [
                'label' => $this->_('Choose fulfilment'),
                'note' => $this->_('Delivery, pickup, or local delivery available at checkout.'),
            ],
            'Customer email sender' => [
                'label' => $this->_('Configure emails'),
                'note' => $this->_('Sender address for confirmations and fulfilment updates.'),
            ],
        ];

        $byItem = [];
        foreach ($checks as $check) {
            $item = (string) ($check['item'] ?? '');
            if ($item !== '') {
                $byItem[$item] = $check;
            }
        }
        $gatewayChecks = array_values(array_filter($checks, static fn(array $check): bool => str_ends_with((string) ($check['item'] ?? ''), ' gateway')));
        if ($gatewayChecks) {
            $blockedGatewayChecks = array_values(array_filter($gatewayChecks, static fn(array $check): bool => empty($check['ready'])));
            $warningGatewayChecks = array_values(array_filter($gatewayChecks, static fn(array $check): bool => !empty($check['warning'])));
            $gatewayNames = array_map(static fn(array $check): string => (string) ($check['item'] ?? ''), $blockedGatewayChecks ?: $warningGatewayChecks);
            $byItem['Gateway setup'] = [
                'ready' => $blockedGatewayChecks === [],
                'warning' => $blockedGatewayChecks === [] && $warningGatewayChecks !== [],
                'detail' => $gatewayNames ? implode(', ', $gatewayNames) : $this->_('Enabled gateways have required setup data.'),
                'action' => $this->wire('config')->urls->admin . 'module/edit?name=Mercato',
                'action_label' => $this->_('Gateway settings'),
            ];
        }

        $out = '<div class="mrc-launch-quickstart">';
        $out .= '<div class="mrc-launch-quickstart-head">';
        $out .= '<div><span class="ds-section-label">' . $this->e($this->_('Quick start')) . '</span>';
        $out .= '<h3>' . $this->e($this->_('15-minute shop setup')) . '</h3>';
        $out .= '<p>' . $this->e($this->_('Follow these steps to move from install to a safe first test payment.')) . '</p></div>';
        $out .= '</div>';
        $out .= '<ol class="mrc-launch-steps">';
        $position = 1;
        foreach ($steps as $item => $copy) {
            $check = (array) ($byItem[$item] ?? []);
            $ready = !empty($check['ready']);
            $warning = !empty($check['warning']);
            $state = $ready ? ($warning ? 'warning' : 'ready') : 'blocked';
            $action = (string) ($check['action'] ?? '');
            $actionLabel = (string) ($check['action_label'] ?? $this->_('Open'));
            $out .= '<li class="mrc-launch-step is-' . $state . '">';
            $out .= '<span class="mrc-launch-step-number">' . $this->e((string) $position) . '</span>';
            $out .= '<div><div class="mrc-launch-step-title">' . $this->renderLaunchStatusBadge($state) . '<strong>' . $this->e((string) $copy['label']) . '</strong></div>';
            $out .= '<p>' . $this->e((string) $copy['note']) . '</p></div>';
            if ($action !== '') {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($action) . '">' . $this->e($actionLabel) . '</a>';
            }
            $out .= '</li>';
            $position++;
        }
        $out .= '</ol></div>';

        return $out;
    }

    protected function getDiscountReadiness(Mercato $commerce): array {
        $template = $this->wire('templates')->get('mrc-discount');
        if (!$template) {
            return [
                'ready' => false,
                'detail' => $this->_('Discount template is missing. Run installer/repair first.'),
            ];
        }

        $discounts = $commerce->discountService()->listDiscounts(100);
        $activeCodes = [];
        foreach ($discounts as $discount) {
            if (!method_exists($discount, 'isCurrentlyActive') || !$discount->isCurrentlyActive()) {
                continue;
            }
            $data = method_exists($discount, 'toArray') ? $discount->toArray() : [];
            $activeCodes[] = (string) ($data['code'] ?? '');
        }

        $activeCodes = array_values(array_filter($activeCodes));
        if ($activeCodes) {
            return [
                'ready' => true,
                'detail' => sprintf($this->_('%d active coupon(s): %s'), count($activeCodes), implode(', ', array_slice($activeCodes, 0, 5))),
            ];
        }

        return [
            'ready' => false,
            'detail' => $this->_('Create a demo coupon such as WELCOME10 to validate checkout discounts.'),
        ];
    }

    protected function getStaffAccessReadiness(): array {
        $permissionNames = $this->getExpectedCommercePermissions();
        $roleDefinitions = $this->getExpectedCommerceRoles();
        $missingPermissions = [];
        $missingRoles = [];
        $rolePermissionMissing = [];

        foreach ($permissionNames as $permissionName) {
            $permission = $this->wire('permissions')->get($permissionName);
            if (!$permission || !$permission->id) {
                $missingPermissions[] = $permissionName;
            }
        }

        foreach ($roleDefinitions as $roleName => $rolePermissions) {
            $role = $this->wire('roles')->get($roleName);
            if (!$role || !$role->id) {
                $missingRoles[] = $roleName;
                continue;
            }
            foreach ($rolePermissions as $permissionName) {
                if (!$role->hasPermission($permissionName)) {
                    $rolePermissionMissing[$roleName][] = $permissionName;
                }
            }
        }

        $ready = !$missingPermissions && !$missingRoles && !$rolePermissionMissing;
        if ($ready) {
            return [
                'ready' => true,
                'detail' => sprintf(
                    $this->_('%d commerce permission(s) and %d role preset(s) are ready.'),
                    count($permissionNames),
                    count($roleDefinitions)
                ),
            ];
        }

        $details = [];
        if ($missingPermissions) {
            $details[] = sprintf($this->_('Missing permissions: %s'), implode(', ', $missingPermissions));
        }
        if ($missingRoles) {
            $details[] = sprintf($this->_('Missing roles: %s'), implode(', ', $missingRoles));
        }
        foreach ($rolePermissionMissing as $roleName => $missing) {
            $details[] = sprintf($this->_('%s lacks: %s'), $roleName, implode(', ', (array) $missing));
        }

        return [
            'ready' => false,
            'detail' => implode(' ', $details),
        ];
    }

    protected function getOversellDebtSummary(): array {
        $products = 0;
        $units = 0;
        foreach ($this->wire('pages')->find('template=mrc-product, include=all, mrc_stock<0, limit=1000') as $product) {
            if (!in_array($this->getProductStockPolicy($product), ['backorder', 'preorder'], true)) {
                continue;
            }
            $products++;
            $units += abs((int) $product->mrc_stock);
        }
        return ['products' => $products, 'units' => $units];
    }

    protected function getExpectedCommercePermissions(): array {
        return [
            self::PERMISSION_ADMIN,
            self::PERMISSION_VIEW_ORDERS,
            self::PERMISSION_EDIT_ORDERS,
            self::PERMISSION_REFUND_ORDERS,
            self::PERMISSION_MANUAL_ORDERS,
            self::PERMISSION_MANAGE_PRODUCTS,
            self::PERMISSION_MANAGE_INVENTORY,
            self::PERMISSION_FULFIL_ORDERS,
            self::PERMISSION_VIEW_CUSTOMERS,
            self::PERMISSION_MANAGE_CUSTOMERS,
            self::PERMISSION_MANAGE_RECOVERY,
            self::PERMISSION_MANAGE_PRIVACY,
            self::PERMISSION_VIEW_REPORTS,
            self::PERMISSION_MANAGE_DISCOUNTS,
            self::PERMISSION_MANAGE_WEBHOOKS,
            self::PERMISSION_LAUNCH_TOOLS,
        ];
    }

    protected function getExpectedCommerceRoles(): array {
        return [
            'mercato-support' => [
                self::PERMISSION_ADMIN,
                self::PERMISSION_VIEW_ORDERS,
                self::PERMISSION_EDIT_ORDERS,
                self::PERMISSION_VIEW_CUSTOMERS,
                self::PERMISSION_MANAGE_CUSTOMERS,
                self::PERMISSION_MANAGE_RECOVERY,
            ],
            'mercato-fulfilment' => [
                self::PERMISSION_ADMIN,
                self::PERMISSION_VIEW_ORDERS,
                self::PERMISSION_FULFIL_ORDERS,
                self::PERMISSION_MANAGE_INVENTORY,
            ],
            'mercato-catalog' => [
                self::PERMISSION_ADMIN,
                self::PERMISSION_MANAGE_PRODUCTS,
                self::PERMISSION_MANAGE_INVENTORY,
                self::PERMISSION_MANAGE_DISCOUNTS,
                self::PERMISSION_VIEW_REPORTS,
            ],
            'mercato-manager' => $this->getExpectedCommercePermissions(),
        ];
    }

    protected function renderLaunchStatusBadge(string $state): string {
        $label = match ($state) {
            'ready' => $this->_('Ready'),
            'warning' => $this->_('Warning'),
            default => $this->_('Needed'),
        };
        $class = match ($state) {
            'ready' => 'is-paid',
            'warning' => 'is-pending',
            default => 'is-failed',
        };
        return '<span class="uk-label mrc-admin-status ' . $class . '">' . $this->e($label) . '</span>';
    }

    protected function pageFromConfiguredPath(string $path): ?Page {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        $page = $this->wire('pages')->get('/' . $path . '/');
        return $page && $page->id ? $page : null;
    }
}
