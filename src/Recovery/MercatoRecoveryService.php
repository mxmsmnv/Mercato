<?php
namespace ProcessWire;

final class MercatoRecoveryService extends Wire {

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function run(array $options = []): array {
        $dryRun = !empty($options['dry_run']);
        $force = !empty($options['force']);
        $enabled = (bool) ($this->commerce->recovery_automation_enabled ?? false);
        $minAge = (int) ($options['min_age_minutes'] ?? $this->commerce->getRecoveryAutomationMinAgeMinutes());
        $limit = (int) ($options['limit'] ?? $this->commerce->getRecoveryAutomationBatchLimit());
        $recoveryDiscountCode = strtoupper(trim((string) ($this->commerce->recovery_discount_code ?? '')));
        $recoveryDiscountCode = substr(preg_replace('/[^A-Z0-9_-]+/', '', $recoveryDiscountCode) ?: '', 0, 64);

        if (!$enabled && !$force) {
            return [
                'status' => 'skipped',
                'reason' => 'Recovery automation is disabled.',
                'dry_run' => $dryRun,
                'checked' => 0,
                'eligible' => 0,
                'sent' => 0,
                'failed' => 0,
                'blocked' => 0,
                'orders' => [],
            ];
        }

        $limit = max(1, min(100, $limit));
        $sent = 0;
        $failed = 0;
        $blocked = 0;
        $eligible = 0;
        $checked = 0;
        $orders = [];
        $latestEmails = $this->getLatestSentEmailsByOrder();

        foreach ($this->getUnpaidOrders() as $order) {
            if (!$order instanceof Page || !$order->id) continue;
            $checked++;
            $eligibility = $this->getEligibility($order, (array) ($latestEmails[(int) $order->id] ?? []), $minAge);
            if (empty($eligibility['allowed'])) {
                $blocked++;
                continue;
            }

            $eligible++;
            $orders[] = [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'email' => (string) $order->mrc_email,
                'age_minutes' => (int) ($eligibility['age_minutes'] ?? 0),
                'recovery_discount_code' => $recoveryDiscountCode,
            ];

            if ($dryRun) {
                if (count($orders) >= $limit) break;
                continue;
            }

            $paymentLink = new MercatoPaymentLinkService($this->commerce);
            $paymentLink->setWire($this->wire());
            $result = $paymentLink->send($order);
            $success = (string) ($result['status'] ?? '') === 'sent';
            $success ? $sent++ : $failed++;
            $this->recordRecoveryEvent($order, $success ? 'sent' : 'failed', (string) ($result['message'] ?? ''), [
                'source' => 'automation',
                'recipient' => (string) ($result['recipient'] ?? $order->mrc_email),
                'recovery_discount_code' => $recoveryDiscountCode,
            ]);

            if (($sent + $failed) >= $limit) break;
        }

        $payload = [
            'status' => $dryRun ? 'preview' : 'completed',
            'dry_run' => $dryRun,
            'enabled' => $enabled,
            'min_age_minutes' => $minAge,
            'batch_limit' => $limit,
            'checked' => $checked,
            'eligible' => $eligible,
            'sent' => $sent,
            'failed' => $failed,
            'blocked' => $blocked,
            'recovery_discount_code' => $recoveryDiscountCode,
            'orders' => $orders,
        ];

        if (!$dryRun) {
            $message = sprintf(
                'Automation run checked %d order(s), found %d eligible, sent %d, failed %d, blocked %d.',
                $checked,
                $eligible,
                $sent,
                $failed,
                $blocked
            );
            $this->wire('log')->save('mercato-recovery', json_encode([
                'event' => 'recovery_automation_run',
                'status' => 'completed',
                'source' => 'automation',
                'message' => $message,
                'user' => 'system',
                'min_age_minutes' => $minAge,
                'batch_limit' => $limit,
                'checked' => $checked,
                'eligible' => $eligible,
                'sent' => $sent,
                'failed' => $failed,
                'blocked' => $blocked,
                'recovery_discount_code' => $recoveryDiscountCode,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'recovery_automation_run');
        }

        return $payload;
    }

    protected function getUnpaidOrders(): PageArray {
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        return $this->wire('pages')->find("template=$template, include=all, mrc_payment_complete=0, sort=created, limit=1000");
    }

    protected function getEligibility(Page $order, array $latestEmail, int $minAge): array {
        $status = strtolower(trim((string) $order->mrc_payment_status));
        if ((int) $order->mrc_payment_complete === 1 || $status === MercatoPaymentStatus::PAID) {
            return ['allowed' => false, 'reason' => 'Already paid', 'age_minutes' => 0];
        }

        $email = (string) $this->wire('sanitizer')->email((string) $order->mrc_email);
        if ($email === '') {
            return ['allowed' => false, 'reason' => 'No customer email', 'age_minutes' => 0];
        }
        if ($this->commerce->isRecoveryEmailSuppressed($email)) {
            return ['allowed' => false, 'reason' => 'Email suppressed', 'age_minutes' => 0];
        }

        $ageMinutes = max(0, (int) floor((time() - (int) $order->created) / 60));
        if ($ageMinutes < $minAge) {
            return ['allowed' => false, 'reason' => 'Too recent', 'age_minutes' => $ageMinutes];
        }

        $cooldownMinutes = $this->commerce->getRecoveryEmailCooldownMinutes();
        $sentAt = $this->getLogTimestamp($latestEmail);
        if ($sentAt > 0) {
            $elapsed = max(0, (int) floor((time() - $sentAt) / 60));
            if (($cooldownMinutes - $elapsed) > 0) {
                return ['allowed' => false, 'reason' => 'Cooldown active', 'age_minutes' => $ageMinutes];
            }
        }

        return ['allowed' => true, 'reason' => 'Ready', 'age_minutes' => $ageMinutes];
    }

    protected function getLatestSentEmailsByOrder(): array {
        $map = [];
        foreach (['mercato-recovery', 'mercato-notifications'] as $logName) {
            foreach ($this->getLogEvents($logName, 10000) as $event) {
                $eventName = (string) ($event['event'] ?? '');
                $status = (string) ($event['status'] ?? '');
                if (!in_array($eventName, ['recovery_email', 'payment_link_email'], true) || $status !== 'sent') {
                    continue;
                }
                $orderId = (int) ($event['order_id'] ?? 0);
                $eventTime = $this->getLogTimestamp($event);
                $existingTime = isset($map[$orderId]) ? $this->getLogTimestamp((array) $map[$orderId]) : 0;
                if ($orderId > 0 && $eventTime >= $existingTime) {
                    $map[$orderId] = $event;
                }
            }
        }
        return $map;
    }

    protected function getLogEvents(string $logName, int $limit): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/' . $logName . '.txt';
        if (!is_file($logFile) || !is_readable($logFile)) return [];
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseLogLine((string) $line);
            if (!$event) continue;
            $events[] = $event;
            if (count($events) >= $limit) break;
        }
        return $events;
    }

    protected function parseLogLine(string $line): ?array {
        $jsonStart = strpos($line, '{');
        if ($jsonStart === false) return null;
        $event = json_decode(substr($line, $jsonStart), true);
        if (!is_array($event)) return null;
        $event['_time'] = trim(substr($line, 0, $jsonStart)) ?: '-';
        return $event;
    }

    protected function getLogTimestamp(array $event): int {
        $time = trim((string) ($event['_time'] ?? ''));
        if ($time === '' || $time === '-') return 0;
        $timestamp = strtotime($time);
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    protected function recordRecoveryEvent(Page $order, string $status, string $message, array $context = []): void {
        $payload = [
            'event' => 'recovery_email',
            'status' => $status,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'email' => (string) $order->mrc_email,
            'message' => $message,
            'recovery_discount_code' => $this->getRecoveryDiscountCode(),
        ] + $context;
        $this->wire('log')->save('mercato-recovery', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $status);
    }

    protected function getRecoveryDiscountCode(): string {
        $code = strtoupper(trim((string) ($this->commerce->recovery_discount_code ?? '')));
        return substr(preg_replace('/[^A-Z0-9_-]+/', '', $code) ?: '', 0, 64);
    }
}
