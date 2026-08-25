<?php
namespace ProcessWire;

final class MercatoOperationalService extends Wire {
    private const BACKGROUND_LOG = 'mercato-background-jobs';

    public function __construct(private Mercato $commerce) { parent::__construct(); }

    public function isCheckoutAvailable(): bool { return !empty($this->commerce->checkout_enabled); }
    public function checkoutMessage(): string { return trim((string) ($this->commerce->checkout_maintenance_message ?? '')) ?: $this->commerce->_('Checkout is temporarily unavailable. The catalog remains open.'); }

    public function backupStatus(?int $now = null): array {
        $now ??= time(); $stored = json_decode((string) ($this->commerce->backup_evidence ?? ''), true); $stored = is_array($stored) ? $stored : [];
        $created = strtotime((string) ($stored['created_at'] ?? '')) ?: 0; $verified = strtotime((string) ($stored['restore_verified_at'] ?? '')) ?: 0; $maxAge = max(1, (int) ($this->commerce->backup_max_age_hours ?? 24));
        $status = ['recorded' => $created > 0, 'fresh' => $created > 0 && $created >= $now - ($maxAge * 3600), 'age_hours' => $created > 0 ? round(max(0, $now - $created) / 3600, 1) : null, 'max_age_hours' => $maxAge, 'scope' => array_values(array_intersect(['database', 'site_files', 'product_assets', 'configuration', 'release_metadata'], (array) ($stored['scope'] ?? []))), 'destination_category' => (string) ($stored['destination_category'] ?? ''), 'created_at' => (string) ($stored['created_at'] ?? ''), 'restore_verified' => $verified > 0, 'restore_verified_at' => (string) ($stored['restore_verified_at'] ?? ''), 'release' => (string) ($stored['release'] ?? ''), 'schema_version' => (int) ($stored['schema_version'] ?? 0)];
        $hooked = $this->commerce->backupStatus($status); return is_array($hooked) ? $hooked : $status;
    }

    public function recordBackupEvidence(array $data): array {
        $user = $this->wire('user'); if (!$user->isSuperuser() && !$user->hasPermission('mercato-launch-tools')) throw new WireException($this->commerce->_('Backup evidence permission is required.'), 403);
        $allowedDestinations = ['same_host', 'offsite', 'managed_backup', 'offline']; $destination = (string) ($data['destination_category'] ?? ''); if (!in_array($destination, $allowedDestinations, true)) throw new WireException($this->commerce->_('Choose a non-secret backup destination category.'), 422);
        $scope = array_values(array_intersect(['database', 'site_files', 'product_assets', 'configuration', 'release_metadata'], array_map('strval', (array) ($data['scope'] ?? [])))); if (!in_array('database', $scope, true) || !in_array('site_files', $scope, true)) throw new WireException($this->commerce->_('Backup evidence must cover database and site files.'), 422);
        $evidence = ['created_at' => date(DATE_ATOM, (int) ($data['created_at'] ?? time())), 'scope' => $scope, 'destination_category' => $destination, 'restore_verified_at' => !empty($data['restore_verified_at']) ? date(DATE_ATOM, (int) $data['restore_verified_at']) : '', 'release' => substr(trim((string) ($data['release'] ?? '')), 0, 80), 'schema_version' => $this->commerce->getInstalledSchemaVersion(), 'recorded_by' => (string) $user->name];
        $config = (array) $this->wire('modules')->getConfig($this->commerce); $config['backup_evidence'] = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $this->wire('modules')->saveConfig($this->commerce, $config); $this->commerce->set('backup_evidence', $config['backup_evidence']);
        $this->commerce->recordEvent('mercato-operations', ['event' => 'backup_evidence_recorded', 'status' => 'completed', 'scope' => $scope, 'destination_category' => $destination, 'restore_verified' => $evidence['restore_verified_at'] !== '', 'schema_version' => $evidence['schema_version'], 'operator' => (string) $user->name], 'backup_evidence_recorded'); return $this->backupStatus();
    }

    public function assertPreUpgradeBackup(): void {
        if (empty($this->commerce->preupgrade_backup_required)) return; $status = $this->backupStatus(); $requiredScope = ['database', 'site_files', 'configuration', 'release_metadata']; $missing = array_diff($requiredScope, (array) $status['scope']);
        if (empty($status['fresh']) || $missing) throw new WireException($this->commerce->_('Upgrade blocked: record a recent backup covering database, site files, configuration, and release metadata, then verify its destination.'));
    }

    public function health(bool $detailed = false, ?int $now = null): array {
        $now ??= time(); $checks = []; $checks['application'] = $this->check('application', !empty($this->commerce->getRuntimeCompatibilityReport()['ready']), 'Runtime and dependency compatibility');
        try { $query = $this->wire('database')->query('SELECT 1'); $checks['database'] = $this->check('database', (bool) $query, 'Database connectivity'); } catch (\Throwable) { $checks['database'] = $this->check('database', false, 'Database connectivity'); }
        $assets = (string) $this->wire('config')->paths->assets; $free = is_dir($assets) ? @disk_free_space($assets) : false; $minimum = max(1048576, (int) ($this->commerce->health_storage_min_bytes ?? 104857600)); $checks['storage'] = $this->check('storage', is_dir($assets) && is_writable($assets) && $free !== false && $free >= $minimum, 'Writable asset storage and free-space threshold', ['free_bytes' => is_numeric($free) ? (int) $free : null, 'minimum_bytes' => $minimum]);
        $email = $this->commerce->notificationDeliveryService()->getSetupStatus(); $checks['email'] = $this->check('email', !empty($email['ready']), 'Transactional email transport');
        $gatewayRows = []; $gatewaysReady = true; foreach ($this->commerce->getEnabledPaymentMethods() as $method) { try { $gateway = $this->commerce->getGateway($method); $name = $gateway->getName(); if (isset($gatewayRows[$name])) continue; $setup = $gateway->getSetupStatus(); $gatewayRows[$name] = ['ready' => $setup->ready, 'error_count' => count($setup->errors), 'warning_count' => count($setup->warnings)]; if (!$setup->ready) $gatewaysReady = false; } catch (\Throwable) { $gatewayRows[(string) $method] = ['ready' => false, 'error_count' => 1, 'warning_count' => 0]; $gatewaysReady = false; } } $checks['providers'] = $this->check('providers', $gatewaysReady, 'Enabled payment provider configuration', ['gateways' => $gatewayRows]);
        $checks['webhooks'] = $this->check('webhooks', $gatewaysReady, 'Provider webhook configuration follows gateway readiness');
        $expired = $this->commerce->orderRepository()->countExpiredReservations(); $checks['reservations'] = $this->check('reservations', $expired === 0, 'Expired inventory reservations', ['expired_count' => $expired]);
        $lastJob = $this->lastBackgroundRun(); $enabledJobs = array_filter($this->commerce->getBackgroundJobs(), static fn($job) => !empty($job['enabled'])); $cronReady = !$enabledJobs || ($lastJob > 0 && $lastJob >= $now - max(3600, (int) ($this->commerce->health_cron_max_age_seconds ?? 172800))); $checks['cron'] = $this->check('cron', $cronReady, 'Configured background jobs have recent evidence', ['last_run_at' => $lastJob ? date(DATE_ATOM, $lastJob) : null, 'enabled_jobs' => count($enabledJobs)]);
        $backup = $this->backupStatus($now); $checks['backup'] = $this->check('backup', !empty($backup['fresh']) && !empty($backup['restore_verified']), 'Recent backup and verified restore evidence', $backup);
        $checks['configuration'] = $this->check('configuration', $this->commerce->getSchemaStatus()['up_to_date'] && !$this->commerce->getSchemaStatus()['newer_than_code'], 'Module schema and configuration');
        $checks['checkout'] = $this->check('checkout', $this->isCheckoutAvailable(), 'Independent checkout availability');
        $failed = array_keys(array_filter($checks, static fn($check) => empty($check['ok']))); $status = in_array('application', $failed, true) || in_array('database', $failed, true) || in_array('storage', $failed, true) ? 'down' : ($failed ? 'degraded' : 'ok');
        $response = ['service' => 'mercato', 'status' => $status, 'checked_at' => date(DATE_ATOM, $now)]; if ($detailed) $response += ['checks' => $checks, 'failed_categories' => $failed]; return $response;
    }

    public function verifyHealthToken(string $token): bool { $expected = trim((string) ($this->commerce->health_check_token ?? '')); return $expected !== '' && $token !== '' && hash_equals($expected, $token); }
    private function check(string $category, bool $ok, string $message, array $details = []): array { return ['category' => $category, 'ok' => $ok, 'status' => $ok ? 'ok' : 'failed', 'message' => $message] + ($details ? ['details' => $details] : []); }
    private function lastBackgroundRun(): int { $path = rtrim((string) $this->wire('config')->paths->logs, '/') . '/' . self::BACKGROUND_LOG . '.txt'; if (!is_readable($path)) return 0; $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []; foreach (array_reverse($lines) as $line) { $stamp = strtotime(substr((string) $line, 0, 19)); if ($stamp) return $stamp; } return 0; }
}
