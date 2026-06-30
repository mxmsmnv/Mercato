<?php
namespace ProcessWire;

trait ProcessMercatoRecoveryCustomerPanels {

    protected function renderRecovery(array $rows, Mercato $commerce, array $filters, array $result = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Abandoned Checkouts')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Unpaid checkout orders that can be recovered with a secure payment link.')) . '</p>';
        $cooldown = $this->getRecoveryEmailCooldownMinutes();
        $cooldownLabel = $cooldown > 0 ? sprintf($this->_('Recovery email cooldown: %s.'), $this->formatAgeMinutes($cooldown)) : $this->_('Recovery email cooldown is disabled.');
        $out .= '<p class="uk-text-muted mrc-admin-note">' . $this->e($cooldownLabel) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('recovery', $this->getRecoveryExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div>';
        $out .= '</div>';

        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        $out .= $this->renderRecoveryAutomationPanel($commerce, (array) ($result['automation_preview'] ?? []));
        $out .= $this->renderRecoverySummary($rows, $commerce);
        $out .= $this->renderRecoveryFilters($filters);
        $out .= $this->renderRecoveryBulkActions($rows, $filters);

        $headings = [$this->_('Order'), $this->_('Customer'), $this->_('Payment'), $this->_('Attempt'), $this->_('Age'), $this->_('Recovery'), ''];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$rows) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            $out .= '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No abandoned checkouts match the current filters.')) . '</p></section>';
            return $out . $this->renderRecoveryActivity($this->getRecoveryEvents(30));
        }

        foreach ($rows as $row) {
            $order = $row['order'] ?? null;
            if (!$order instanceof Page || !$order->id) {
                continue;
            }
            $payment = (array) ($row['payment'] ?? []);
            $attempt = (array) ($row['latest_attempt'] ?? []);
            $recoveryEmail = (array) ($row['recovery_email'] ?? []);
            $attemptStatus = (string) ($attempt['status'] ?? ($payment['raw'] ?? ''));
            $attemptLabel = trim((string) ($attempt['event'] ?? '')) !== ''
                ? ucfirst(str_replace('_', ' ', (string) $attempt['event']))
                : $this->_('No attempt log');
            $recoveryLabel = !empty($recoveryEmail)
                ? sprintf($this->_('Last sent %s'), (string) ($recoveryEmail['_time'] ?? '-'))
                : $this->_('Not sent yet');
            $recoveryReason = (string) ($row['recovery_reason'] ?? '');

            $out .= '<tr>';
            $out .= '<td><a href="' . $this->e($this->orderDetailUrl($order)) . '"><strong>' . $this->e((string) ($order->mrc_invoice_number ?: $order->title)) . '</strong></a><br><small>' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</small></td>';
            $out .= '<td>' . $this->e($this->getOrderCustomer($order) ?: '-') . '<br><small>' . $this->e((string) $order->mrc_email) . '</small></td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->e((string) ($payment['class'] ?? 'is-pending')) . '">' . $this->e((string) ($payment['label'] ?? '-')) . '</span><br><small>' . $this->e($this->getOrderGatewayLabel($order)) . '</small></td>';
            $out .= '<td><strong>' . $this->e($attemptLabel) . '</strong><br><small>' . $this->e((string) ($row['gateway'] ?? 'unknown')) . ' / ' . $this->e((string) ($row['attempt_count'] ?? 0)) . ' ' . $this->e($this->_('attempt(s)')) . '</small><br><span class="uk-label mrc-admin-status ' . $this->getPaymentAttemptStatusClass($attemptStatus) . '">' . $this->e(ucfirst(str_replace('_', ' ', $attemptStatus ?: 'pending'))) . '</span><br>' . $this->renderPaymentAttemptContext($attempt) . '</td>';
            $out .= '<td>' . $this->e($this->formatAgeMinutes((int) ($row['age_minutes'] ?? 0))) . '<br><small>' . $this->e(!empty($row['last_activity']) ? date('Y-m-d H:i', (int) $row['last_activity']) : '-') . '</small></td>';
            $out .= '<td>' . $this->e($recoveryLabel);
            if ($recoveryReason !== '') {
                $out .= '<br><small>' . $this->e($recoveryReason) . '</small>';
            }
            $out .= '</td>';
            $out .= '<td class="mrc-table-actions">';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Detail')) . '</a>';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('payment-attempts/') . '?' . http_build_query(['order' => (int) $order->id])) . '"><i class="fa fa-credit-card uk-margin-small-right"></i>' . $this->e($this->_('Attempts')) . '</a>';
            $recoveryQuery = http_build_query($this->getRecoveryExportQuery($filters));
            $recoveryAction = $this->adminUrl('recovery/') . ($recoveryQuery !== '' ? '?' . $recoveryQuery : '');
            if ((string) $order->mrc_email !== '' && !$commerce->isRecoveryEmailSuppressed((string) $order->mrc_email)) {
                $out .= '<form method="post" class="mrc-inline-form" action="' . $this->e($recoveryAction) . '">';
                $out .= $this->renderCsrfInput();
                $out .= '<input type="hidden" name="mrc_suppress_recovery_email" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
                $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-ban uk-margin-small-right"></i>' . $this->e($this->_('Suppress')) . '</button></form>';
            } else {
                $out .= '<button class="uk-button uk-button-default" type="button" disabled><i class="fa fa-ban uk-margin-small-right"></i>' . $this->e($this->_('Suppress')) . '</button>';
            }
            if (!empty($row['recovery_allowed'])) {
                $out .= '<form method="post" class="mrc-inline-form" action="' . $this->e($recoveryAction) . '">';
                $out .= $this->renderCsrfInput();
                $out .= '<input type="hidden" name="mrc_send_recovery_link" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
                $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Email link')) . '</button></form>';
            } else {
                $out .= '<button class="uk-button uk-button-default" type="button" disabled><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Email link')) . '</button>';
            }
            $isCancelable = $this->isUnpaidOrderCancelable($order);
            if ($isCancelable) {
                $out .= '<form method="post" class="mrc-inline-form" action="' . $this->e($recoveryAction) . '">';
                $out .= $this->renderCsrfInput();
                $out .= '<input type="hidden" name="mrc_cancel_recovery_order" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
                $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-times uk-margin-small-right"></i>' . $this->e($this->_('Cancel')) . '</button></form>';
            } else {
                $out .= '<button class="uk-button uk-button-default" type="button" disabled><i class="fa fa-times uk-margin-small-right"></i>' . $this->e($this->_('Cancel')) . '</button>';
            }
            $out .= '</td></tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out . $this->renderRecoveryActivity($this->getRecoveryEvents(30));
    }

    protected function getCancelableRecoveryOrders(array $rows): array {
        $orders = [];
        foreach ($rows as $row) {
            $order = $row['order'] ?? null;
            if ($order instanceof Page && $this->isUnpaidOrderCancelable($order)) {
                $orders[] = $order;
            }
        }
        return $orders;
    }

    protected function renderRecoveryBulkActions(array $rows, array $filters): string {
        $count = count($this->getCancelableRecoveryOrders($rows));
        $query = http_build_query($this->getRecoveryExportQuery($filters));
        $action = $this->adminUrl('recovery/') . ($query !== '' ? '?' . $query : '');

        $out = '<section class="pw-wrap mrc-admin-panel mrc-recovery-bulk-actions">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Bulk Recovery Actions')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Apply guarded actions to the current filtered abandoned-checkout set. Bulk cancellation is limited to 25 orders per run.')) . '</p></div></div>';
        $out .= '<form method="post" class="mrc-payment-reconcile-form" action="' . $this->e($action) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_bulk_cancel_recovery_orders" value="1">';
        $out .= '<div><strong>' . $this->e(sprintf($this->_('%d cancelable abandoned order(s) in current filters.'), $count)) . '</strong>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Canceled orders leave Recovery, release reservations, and remain available in Orders and audit exports.')) . '</p></div>';
        $out .= '<div class="mrc-payment-reconcile-action"><label class="mrc-confirm-action"><input class="uk-checkbox" type="checkbox" name="bulk_cancel_confirmed" value="1" ' . ($count > 0 ? 'required' : 'disabled') . '> ' . $this->e($this->_('Cancel the filtered unpaid orders')) . '</label>';
        $out .= '<button class="uk-button uk-button-default" type="submit" ' . ($count > 0 ? '' : 'disabled') . '><i class="fa fa-times uk-margin-small-right"></i>' . $this->e($this->_('Cancel filtered orders')) . '</button></div>';
        $out .= '</form></section>';
        return $out;
    }

    protected function renderRecoveryAutomationPanel(Mercato $commerce, array $preview = []): string {
        $enabled = !empty($commerce->recovery_automation_enabled);
        $schedule = method_exists($commerce, 'getRecoveryAutomationSchedule') ? $commerce->getRecoveryAutomationSchedule() : 'disabled';
        $minAge = method_exists($commerce, 'getRecoveryAutomationMinAgeMinutes') ? $commerce->getRecoveryAutomationMinAgeMinutes() : 60;
        $batchLimit = method_exists($commerce, 'getRecoveryAutomationBatchLimit') ? $commerce->getRecoveryAutomationBatchLimit() : 10;
        $suppressedEmails = method_exists($commerce, 'getRecoverySuppressedEmails') ? $commerce->getRecoverySuppressedEmails() : [];
        $suppressedCount = count($suppressedEmails);
        $discountCode = strtoupper(trim((string) ($commerce->recovery_discount_code ?? '')));
        $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Mercato';

        $out = '<section class="pw-wrap mrc-admin-panel mrc-recovery-automation">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Recovery Automation')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Preview eligible abandoned checkouts before LazyCron sends payment-link emails.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<form method="post" class="mrc-inline-form" action="' . $this->e($this->adminUrl('recovery/')) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_preview_recovery_automation" value="1">';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-search uk-margin-small-right"></i>' . $this->e($this->_('Preview automation')) . '</button>';
        $out .= '</form>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($settingsUrl) . '"><i class="fa fa-cog uk-margin-small-right"></i>' . $this->e($this->_('Settings')) . '</a>';
        $out .= '</div></div>';

        $cards = [
            [$this->_('Automation'), $enabled ? $this->_('On') : $this->_('Off'), $enabled ? $this->_('LazyCron sends eligible links.') : $this->_('Enable it in module settings.')],
            [$this->_('Schedule'), ucfirst(str_replace(['every', 'Minutes'], ['Every ', ' min'], $schedule)), $this->_('LazyCron interval.')],
            [$this->_('Minimum age'), $this->formatAgeMinutes($minAge), $this->_('Order age before recovery.')],
            [$this->_('Batch limit'), (string) $batchLimit, $this->_('Maximum emails per run.')],
            [$this->_('Discount'), $discountCode !== '' ? $discountCode : $this->_('None'), $discountCode !== '' ? $this->_('Promoted in recovery emails.') : $this->_('No recovery coupon configured.')],
        ];
        $out .= '<div class="mrc-admin-stats uk-child-width-1-6@l uk-child-width-1-3@m uk-child-width-1-2@s" uk-grid>';
        $cards[] = [$this->_('Suppressed'), (string) $suppressedCount, $this->_('Emails excluded from recovery.')];
        foreach ($cards as [$label, $value, $note]) {
            $out .= '<div><div class="uk-card uk-card-default uk-card-body uk-card-small mrc-admin-card">';
            $out .= '<span class="ds-section-label">' . $this->e($label) . '</span>';
            $out .= '<strong class="uk-display-block">' . $this->e($value) . '</strong>';
            $out .= '<small class="uk-text-muted">' . $this->e($note) . '</small>';
            $out .= '</div></div>';
        }
        $out .= '</div>';

        if ($preview) {
            $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
            $out .= '<thead><tr><th>' . $this->e($this->_('Preview order')) . '</th><th>' . $this->e($this->_('Email')) . '</th><th>' . $this->e($this->_('Age')) . '</th><th>' . $this->e($this->_('Discount')) . '</th></tr></thead><tbody>';
            $orders = (array) ($preview['orders'] ?? []);
            if (!$orders) {
                $out .= '<tr><td colspan="4" class="uk-text-muted">' . $this->e($this->_('No eligible orders in the current automation preview.')) . '</td></tr>';
            } else {
                foreach ($orders as $order) {
                    $order = (array) $order;
                    $out .= '<tr>';
                    $out .= '<td>#' . (int) ($order['order_id'] ?? 0) . ' ' . $this->e((string) ($order['invoice'] ?? '')) . '</td>';
                    $out .= '<td>' . $this->e((string) ($order['email'] ?? '')) . '</td>';
                    $out .= '<td>' . $this->e($this->formatAgeMinutes((int) ($order['age_minutes'] ?? 0))) . '</td>';
                    $out .= '<td>' . $this->e((string) (($order['recovery_discount_code'] ?? '') ?: '-')) . '</td>';
                    $out .= '</tr>';
                }
            }
            $out .= '</tbody></table></div>';
        }

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Suppressed recovery email')) . '</th><th></th></tr></thead><tbody>';
        if (!$suppressedEmails) {
            $out .= '<tr><td colspan="2" class="uk-text-muted">' . $this->e($this->_('No suppressed recovery emails.')) . '</td></tr>';
        } else {
            foreach ($suppressedEmails as $email) {
                $out .= '<tr><td>' . $this->e((string) $email) . '</td><td class="mrc-table-actions">';
                $out .= '<form method="post" class="mrc-inline-form" action="' . $this->e($this->adminUrl('recovery/')) . '">';
                $out .= $this->renderCsrfInput();
                $out .= '<input type="hidden" name="mrc_unsuppress_recovery_email" value="1"><input type="hidden" name="email" value="' . $this->e((string) $email) . '">';
                $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-undo uk-margin-small-right"></i>' . $this->e($this->_('Unsuppress')) . '</button></form>';
                $out .= '</td></tr>';
            }
        }
        $out .= '</tbody></table></div>';

        return $out . '</section>';
    }

    protected function renderRecoveryActivity(array $events): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Recovery Activity')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Manual recovery actions, automation runs, cooldown blocks, and suppression changes.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('recovery-events')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export activity')) . '</a></div></div>';

        $headings = [$this->_('Time'), $this->_('Status'), $this->_('Order'), $this->_('Email'), $this->_('Discount'), $this->_('Message'), $this->_('User')];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        if (!$events) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No recovery actions logged yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? '');
            $class = match ($status) {
                'sent', 'completed' => 'is-paid',
                'failed', 'blocked' => 'is-failed',
                default => 'is-pending',
            };
            $eventName = (string) ($event['event'] ?? '');
            $message = (string) ($event['message'] ?? '');
            if ($message === '' && $eventName === 'recovery_automation_run') {
                $message = sprintf(
                    $this->_('Automation run checked %d order(s), found %d eligible, sent %d, failed %d, blocked %d.'),
                    (int) ($event['checked'] ?? 0),
                    (int) ($event['eligible'] ?? 0),
                    (int) ($event['sent'] ?? 0),
                    (int) ($event['failed'] ?? 0),
                    (int) ($event['blocked'] ?? 0)
                );
            }
            $orderId = (int) ($event['order_id'] ?? 0);
            $orderHtml = $this->e((string) ($event['invoice'] ?? ($orderId > 0 ? '#' . $orderId : '-')));
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                if ($order && $order->id) {
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e((string) ($order->mrc_invoice_number ?: $order->title)) . '</a>';
                }
            }
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $class . '">' . $this->e(ucfirst($status ?: '-')) . '</span></td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->e((string) ($event['email'] ?? $event['recipient'] ?? '')) . '</td>';
            $out .= '<td>' . $this->e((string) (($event['recovery_discount_code'] ?? '') ?: '-')) . '</td>';
            $out .= '<td>' . $this->e($message) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['user'] ?? 'system')) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderRecoverySummary(array $rows, Mercato $commerce): string {
        $recoverable = 0;
        $ready = 0;
        $cooldown = 0;
        $sent = 0;
        $total = 0.0;
        foreach ($rows as $row) {
            $order = $row['order'] ?? null;
            if ($order instanceof Page && $order->id) {
                $total += $this->getOrderTotal($order, $commerce);
            }
            if (!empty($row['recoverable'])) {
                $recoverable++;
            }
            if (!empty($row['recovery_allowed'])) {
                $ready++;
            } elseif ((int) ($row['recovery_cooldown_minutes'] ?? 0) > 0) {
                $cooldown++;
            }
            if (!empty($row['recovery_email'])) {
                $sent++;
            }
        }

        $cards = [
            [$this->_('Checkouts'), (string) count($rows), $this->_('Current filtered set'), 'is-neutral'],
            [$this->_('Recoverable'), (string) $recoverable, $this->_('Customer email available'), 'is-pending'],
            [$this->_('Ready'), (string) $ready, $this->_('Eligible to email now'), 'is-paid'],
            [$this->_('Cooldown'), (string) $cooldown, $this->_('Recently emailed'), 'is-pending'],
            [$this->_('Links sent'), (string) $sent, $this->_('Payment link emails'), 'is-paid'],
            [$this->_('Open value'), $commerce->formatPrice($total), $this->_('Unpaid order total'), 'is-neutral'],
        ];

        $out = '<div class="mrc-webhook-summary">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-webhook-summary-card ' . $class . '"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><small>' . $this->e($caption) . '</small></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderRecoveryFilters(array $filters): string {
        $age = (string) ($filters['age'] ?? '60');
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $ages = [
            '15' => $this->_('Older than 15 minutes'),
            '60' => $this->_('Older than 1 hour'),
            '240' => $this->_('Older than 4 hours'),
            '1440' => $this->_('Older than 1 day'),
            'all' => $this->_('Any age'),
        ];
        $gateways = $this->getGatewayFilterOptions(true, true);
        $statuses = [
            'all' => $this->_('All statuses'),
            'pending' => $this->_('Pending'),
            'processing' => $this->_('Processing'),
            'failed' => $this->_('Failed'),
            'canceled' => $this->_('Canceled'),
        ];

        $out = '<form method="get" class="mrc-webhook-filters">';
        $out .= '<label><span>' . $this->e($this->_('Age')) . '</span><select name="age">';
        foreach ($ages as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($age === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Gateway')) . '</span><select name="gateway">';
        foreach ($gateways as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($gateway === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Status')) . '</span><select name="status">';
        foreach ($statuses as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($age !== '60' || $gateway !== 'all' || $status !== 'all') {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('recovery/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        return $out;
    }

    protected function renderCustomers(array $customers, Mercato $commerce, array $filters = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Customers')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Customer summary aggregated from order email addresses.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('customers', $this->getCustomerExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a>';
        $out .= $this->renderCustomerFilters($filters);
        $out .= '<div class="mrc-launch-score"><strong>' . $this->e((string) count($customers)) . '</strong><span>' . $this->e($this->_('customers')) . '</span></div>';
        $out .= '</div>';
        $out .= '</div>';

        $headings = [$this->_('Customer'), $this->_('Segments'), $this->_('Orders'), $this->_('Paid'), $this->_('Pending'), $this->_('Processing'), $this->_('Failed'), $this->_('Canceled'), $this->_('Revenue'), $this->_('Last order'), ''];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$customers) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No customers yet. Customer rows appear after the first checkout order.')) . '</p></section>';
        }

        foreach ($customers as $customer) {
            $lastOrder = $customer['last_order'] ?? null;
            $lastOrderDate = $lastOrder instanceof Page ? date('Y-m-d H:i', (int) $lastOrder->created) : '-';
            $lastOrderLabel = $lastOrder instanceof Page ? (string) ($lastOrder->mrc_invoice_number ?: $lastOrder->title) : '-';
            $segments = (array) ($customer['segments'] ?? []);

            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e((string) ($customer['name'] ?: $customer['email'] ?: '-')) . '</strong><br><small>' . $this->e((string) ($customer['email'] ?: '-')) . '</small></td>';
            $out .= '<td><div class="mrc-customer-segments">';
            if ($segments) {
                foreach ($segments as $segment) {
                    $out .= '<span class="uk-label mrc-admin-status ' . $this->e((string) ($segment['class'] ?? 'is-pending')) . '">' . $this->e((string) ($segment['label'] ?? '-')) . '</span>';
                }
            } else {
                $out .= '<span class="uk-text-muted">-</span>';
            }
            $out .= '</div></td>';
            $out .= '<td>' . $this->e((string) $customer['orders']) . '</td>';
            $out .= '<td>' . $this->e((string) $customer['paid_orders']) . '</td>';
            $out .= '<td>' . $this->e((string) ($customer['pending_orders'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e((string) ($customer['processing_orders'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e((string) ($customer['failed_orders'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e((string) ($customer['canceled_orders'] ?? 0)) . '</td>';
            $out .= '<td>' . $this->e($commerce->formatPrice((float) $customer['revenue'])) . '</td>';
            $out .= '<td><strong>' . $this->e($lastOrderLabel) . '</strong><br><small>' . $this->e($lastOrderDate) . '</small></td>';
            $out .= '<td class="mrc-table-actions">';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->customerDetailUrl($customer)) . '"><i class="fa fa-user uk-margin-small-right"></i>' . $this->e($this->_('Profile')) . '</a>';
            if ($lastOrder instanceof Page) {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($lastOrder)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Last order')) . '</a>';
            }
            $out .= '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderCustomerDetail(array $customer, PageArray $orders, Mercato $commerce, array $noteResult = []): string {
        $name = (string) (($customer['name'] ?? '') ?: ($customer['email'] ?? $customer['key'] ?? '-'));

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($name) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Customer profile aggregated from order history.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-primary" href="' . $this->e($this->manualOrderCustomerUrl($customer)) . '"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Create manual order')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('customer-orders', ['key' => (string) ($customer['key'] ?? '')])) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export orders')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('customers')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export customers')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('customers/')) . '"><i class="fa fa-arrow-left uk-margin-small-right"></i>' . $this->e($this->_('Back to customers')) . '</a>';
        $out .= '</div></div>';

        $out .= '<div class="mrc-admin-stat-grid">';
        $stats = [
            [$this->_('Orders'), (string) ($customer['orders'] ?? 0), $this->_('Total order attempts.')],
            [$this->_('Paid'), (string) ($customer['paid_orders'] ?? 0), $this->_('Completed payments.')],
            [$this->_('Pending'), (string) ($customer['pending_orders'] ?? 0), $this->_('Awaiting payment.')],
            [$this->_('Processing'), (string) ($customer['processing_orders'] ?? 0), $this->_('Awaiting gateway.')],
            [$this->_('Failed'), (string) ($customer['failed_orders'] ?? 0), $this->_('Failed or expired.')],
            [$this->_('Canceled'), (string) ($customer['canceled_orders'] ?? 0), $this->_('Canceled before payment.')],
            [$this->_('Revenue'), $commerce->formatPrice((float) ($customer['revenue'] ?? 0)), $this->_('Paid order total.')],
        ];
        foreach ($stats as [$label, $value, $note]) {
            $out .= '<div><div class="mrc-admin-stat"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><small>' . $this->e($note) . '</small></div></div>';
        }
        $out .= '</div>';

        $out .= '<div class="mrc-customer-profile-grid">';
        $out .= '<div class="mrc-customer-profile-card"><h3 class="uk-h4">' . $this->e($this->_('Contact')) . '</h3>';
        $out .= '<dl class="mrc-detail-list">';
        foreach ([
            $this->_('Email') => (string) ($customer['email'] ?? ''),
            $this->_('Phone') => (string) ($customer['phone'] ?? ''),
            $this->_('Address') => trim(implode(', ', array_filter([(string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), (string) ($customer['zip'] ?? ''), (string) ($customer['country'] ?? '')]))),
        ] as $label => $value) {
            $out .= '<dt>' . $this->e((string) $label) . '</dt><dd>' . $this->e($value !== '' ? $value : '-') . '</dd>';
        }
        $out .= '</dl></div>';

        $out .= '<div class="mrc-customer-profile-card"><h3 class="uk-h4">' . $this->e($this->_('Segments')) . '</h3><div class="mrc-customer-segments">';
        foreach ((array) ($customer['segments'] ?? []) as $segment) {
            $out .= '<span class="uk-label mrc-admin-status ' . $this->e((string) ($segment['class'] ?? 'is-pending')) . '">' . $this->e((string) ($segment['label'] ?? '-')) . '</span>';
        }
        $out .= '</div></div></div>';
        $out .= '</section>';

        $out .= $this->renderCustomerNotesPanel($customer, $noteResult);
        $out .= $this->renderCustomerActivity($this->getCustomerActivity($orders, $customer, 10));

        $out .= '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Customer Orders')) . '</h2></div></div>';
        $out .= $this->renderOrdersTable($orders, $commerce, true);
        $out .= '</section>';

        return $out;
    }

    protected function renderCustomerNotesPanel(array $customer, array $result = []): string {
        $key = (string) ($customer['key'] ?? '');
        $notes = array_values(array_filter($this->getCustomerNoteEvents(50), static fn(array $event): bool => (string) ($event['customer_key'] ?? '') === $key));
        $notes = array_slice($notes, 0, 5);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Customer Notes')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Private operational notes for this customer across orders.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('customer-notes', ['key' => $key])) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export customer notes')) . '</a></div></div>';
        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }
        if ($notes) {
            $out .= '<div class="mrc-notes-list">';
            foreach ($notes as $note) {
                $out .= '<article class="mrc-note-item"><p>' . nl2br($this->e((string) ($note['note'] ?? ''))) . '</p>';
                $out .= '<small>' . $this->e((string) ($note['_time'] ?? '-')) . ' / ' . $this->e((string) ($note['user'] ?? 'system')) . '</small></article>';
            }
            $out .= '</div>';
        }
        $out .= '<form method="post" action="' . $this->e($this->customerDetailUrl($customer)) . '" class="mrc-order-note-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_add_customer_note" value="1">';
        $out .= '<input type="hidden" name="customer_key" value="' . $this->e($key) . '">';
        $out .= '<textarea class="uk-textarea" name="customer_note" rows="3" placeholder="' . $this->e($this->_('Add a customer note...')) . '"></textarea>';
        $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-sticky-note-o uk-margin-small-right"></i>' . $this->e($this->_('Add note')) . '</button>';
        $out .= '</form>';
        return $out . '</section>';
    }

    protected function getCustomerActivity(PageArray $orders, array $customer, int $limit = 10): array {
        $webhooks = $this->getWebhookEvents(10000);
        $inventory = $this->getInventoryEvents(10000);
        $fulfilment = $this->getFulfilmentEvents(10000);
        $notifications = $this->getNotificationEvents(10000);
        $payments = $this->getPaymentEvents(10000);
        $refunds = $this->getRefundEvents(10000);
        $edits = $this->getOrderEditEvents(10000);
        $notes = $this->getOrderNoteEvents(10000);
        $recovery = $this->getRecoveryEvents(10000);
        $events = [];

        foreach ($orders as $order) {
            if (!$order instanceof Page || !$order->id) {
                continue;
            }
            foreach (MercatoOrderTimeline::build($order, $webhooks, $inventory, $fulfilment, $notifications, $payments, $refunds, $edits, $notes, $recovery, $this->getPaymentAttemptEvents(10000)) as $event) {
                $event['order_id'] = (int) $order->id;
                $event['order_label'] = (string) ($order->mrc_invoice_number ?: $order->title);
                $event['order_url'] = $this->orderDetailUrl($order);
                $events[] = $event;
            }
        }

        $customerKey = (string) ($customer['key'] ?? '');
        foreach ($this->getCustomerNoteEvents(10000) as $note) {
            if ($customerKey === '' || (string) ($note['customer_key'] ?? '') !== $customerKey) {
                continue;
            }
            $events[] = [
                'time' => (string) ($note['_time'] ?? ''),
                'type' => 'customer',
                'label' => 'note',
                'details' => trim(implode(' / ', array_filter([
                    (string) ($note['note'] ?? ''),
                    !empty($note['user']) ? 'By ' . (string) $note['user'] : '',
                ]))),
                'class' => 'is-pending',
                'order_label' => '-',
                'order_url' => '',
                'meta' => [],
            ];
        }

        usort($events, static fn(array $a, array $b): int => strtotime((string) ($b['time'] ?? '')) <=> strtotime((string) ($a['time'] ?? '')));
        return array_slice($events, 0, max(1, $limit));
    }

    protected function renderCustomerActivity(array $events): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Customer Activity')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Latest payment, fulfilment, refund, email, inventory, order note, and customer note events across this customer history.')) . '</p></div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Time')) . '</th><th>' . $this->e($this->_('Order')) . '</th><th>' . $this->e($this->_('Area')) . '</th><th>' . $this->e($this->_('Event')) . '</th><th>' . $this->e($this->_('Details')) . '</th></tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(3, 5);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No customer activity yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $orderLabel = (string) ($event['order_label'] ?? '-');
            $orderUrl = (string) ($event['order_url'] ?? '');
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['time'] ?? '-')) . '</td>';
            $out .= '<td>' . ($orderUrl !== '' ? '<a href="' . $this->e($orderUrl) . '">' . $this->e($orderLabel) . '</a>' : $this->e($orderLabel)) . '</td>';
            $out .= '<td>' . $this->e(ucfirst((string) ($event['type'] ?? '-'))) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->e((string) ($event['class'] ?? 'is-pending')) . '">' . $this->e(ucfirst(str_replace('_', ' ', (string) ($event['label'] ?? '-')))) . '</span></td>';
            $out .= '<td>' . $this->renderTimelineEventDetails($event) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderSearch(string $query, array $results, Mercato $commerce): string {
        $orders = $results['orders'] ?? new PageArray();
        $products = $results['products'] ?? new PageArray();
        $customers = (array) ($results['customers'] ?? []);
        $hasQuery = $query !== '';

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Commerce Search')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Find orders, products, and customer summaries from one place.')) . '</p>';
        $out .= '</div>';
        if ($hasQuery) {
            $total = $orders->count() + $products->count() + count($customers);
            $out .= '<div class="mrc-launch-score"><strong>' . $this->e((string) $total) . '</strong><span>' . $this->e($this->_('matches')) . '</span></div>';
        }
        $out .= '</div>';

        $out .= '<form method="get" action="' . $this->e($this->adminUrl('search/')) . '" class="mrc-search-form uk-margin-bottom">';
        $out .= '<div class="uk-grid-small" uk-grid>';
        $out .= '<div class="uk-width-expand@s"><input class="uk-input" type="search" name="q" value="' . $this->e($query) . '" placeholder="' . $this->e($this->_('Invoice, email, payment ID, SKU, product title...')) . '"></div>';
        $out .= '<div class="uk-width-auto@s"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-search uk-margin-small-right"></i>' . $this->e($this->_('Search')) . '</button></div>';
        if ($hasQuery) {
            $out .= '<div class="uk-width-auto@s"><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('search/')) . '">' . $this->e($this->_('Reset')) . '</a></div>';
        }
        $out .= '</div></form>';

        if (!$hasQuery) {
            $out .= '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('Enter a query to search invoices, order emails, gateway payment IDs, product titles, SKUs, and customer summaries.')) . '</p>';
            return $out . '</section>';
        }

        $out .= '<div class="mrc-search-summary uk-grid-small uk-child-width-1-3@m" uk-grid>';
        $out .= '<div><div class="mrc-admin-stat"><span>' . $this->e($this->_('Orders')) . '</span><strong>' . $this->e((string) $orders->count()) . '</strong></div></div>';
        $out .= '<div><div class="mrc-admin-stat"><span>' . $this->e($this->_('Products')) . '</span><strong>' . $this->e((string) $products->count()) . '</strong></div></div>';
        $out .= '<div><div class="mrc-admin-stat"><span>' . $this->e($this->_('Customers')) . '</span><strong>' . $this->e((string) count($customers)) . '</strong></div></div>';
        $out .= '</div>';
        $out .= '</section>';

        $out .= '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Matching Orders')) . '</h2>';
        $out .= $this->renderOrdersTable($orders, $commerce, true);
        $out .= '</section>';

        $out .= $this->renderProducts($products, $commerce, true);
        $out .= $this->renderCustomers($customers, $commerce);
        return $out;
    }

    protected function renderDiscountAudit(array $events, Mercato $commerce, array $filters = []): string {
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $codeFilter = (string) ($filters['code'] ?? '');
        $eventOptions = [
            'all' => $this->_('All events'),
            'accepted' => $this->_('Accepted'),
            'rejected' => $this->_('Rejected'),
            'attached_to_order' => $this->_('Attached to order'),
            'removed_from_order' => $this->_('Removed from order'),
        ];
        foreach ($events as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            if ($eventName !== '' && !isset($eventOptions[$eventName])) {
                $eventOptions[$eventName] = ucwords(str_replace(['_', '-'], ' ', $eventName));
            }
        }

        $out = '<div class="mrc-admin-subsection">';
        $out .= '<div class="mrc-admin-panel-head"><div><h3 class="uk-h4">' . $this->e($this->_('Recent Coupon Activity')) . '</h3>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Coupon apply, reject, attach, and remove events for support review.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('discount-events', $this->getDiscountEventExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export activity')) . '</a></div></div>';
        $out .= '<form method="get" class="mrc-webhook-filters mrc-discount-event-filters">';
        $out .= '<label><span>' . $this->e($this->_('Event')) . '</span><select name="event">';
        foreach ($eventOptions as $value => $label) {
            $out .= '<option value="' . $this->e((string) $value) . '"' . ($eventFilter === (string) $value ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Code')) . '</span><input class="uk-input" type="text" name="code" value="' . $this->e($codeFilter) . '" placeholder="' . $this->e($this->_('Any code')) . '"></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($eventFilter !== 'all' || $codeFilter !== '') {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('discounts/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Event'), $this->_('Code'), $this->_('Customer'), $this->_('Amount'), $this->_('Order'), $this->_('Message')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(3, 7);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No coupon activity logged yet.')) . '</p></div>';
        }

        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $statusClass = match ($eventName) {
                'accepted', 'attached_to_order' => 'is-paid',
                'rejected' => 'is-failed',
                default => 'is-pending',
            };
            $orderId = (int) ($event['order_page_id'] ?? 0);
            $orderHtml = '-';
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                $label = (string) ($event['invoice'] ?? ('#' . $orderId));
                $orderHtml = ($order && $order->id)
                    ? '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($label) . '</a>'
                    : $this->e($label);
            }

            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($eventName ?: '-') . '</span></td>';
            $out .= '<td><strong>' . $this->e((string) ($event['code'] ?? '-')) . '</strong></td>';
            $out .= '<td>' . $this->e((string) ($event['email'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e($commerce->formatPrice((float) ($event['amount'] ?? 0))) . '</td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->renderWebhookEventMessage($event) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></div>';
        return $out;
    }


}
