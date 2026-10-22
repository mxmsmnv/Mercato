<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato native analytics integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Native analytics fixtures are forbidden in production mode.');
$original = [
    'enabled' => $commerce->analytics_enabled,
    'adapters' => $commerce->analytics_adapters,
    'default' => $commerce->analytics_default_consent,
    'identifier' => $commerce->analytics_order_identifier,
];
$originalConsent = $wire->session->get('mrc_analytics_consent');
$logPath = rtrim((string) $wire->config->paths->logs, '/') . '/mercato-analytics.txt';
$logExisted = is_file($logPath);
$logBefore = $logExisted ? (string) file_get_contents($logPath) : '';
$cleanup = static function () use ($wire, $commerce, $original, $originalConsent, $logPath, $logExisted, $logBefore): void {
    foreach ($original as $key => $value) {
        $property = match ($key) {
            'enabled' => 'analytics_enabled', 'adapters' => 'analytics_adapters',
            'default' => 'analytics_default_consent', default => 'analytics_order_identifier',
        };
        $commerce->set($property, $value);
    }
    if ($originalConsent === null) $wire->session->remove('mrc_analytics_consent');
    else $wire->session->set('mrc_analytics_consent', $originalConsent);
    if ($logExisted) file_put_contents($logPath, $logBefore, LOCK_EX);
    elseif (is_file($logPath)) unlink($logPath);
};
register_shutdown_function($cleanup);

$commerce->set('analytics_enabled', true);
$commerce->set('analytics_adapters', ['data_layer']);
$commerce->set('analytics_default_consent', 'denied');
$commerce->set('analytics_order_identifier', 'hash');
$api = $commerce->headlessApiService();

$denied = $api->analyticsConsent(['analytics' => false, 'marketing' => false]);
$suppressed = $api->analyticsEvent(['name' => 'search', 'payload' => ['query_length' => 4, 'result_count' => 0]], 'native-denied-' . bin2hex(random_bytes(4)));
if (!empty($denied['analytics']) || ($suppressed['status'] ?? '') !== 'consent_denied') throw new \RuntimeException('Native analytics ignored denied consent.');

$granted = $api->analyticsConsent(['analytics' => true, 'marketing' => false]);
$event = $api->analyticsEvent(['name' => 'search', 'payload' => ['query_length' => 4, 'result_count' => 2, 'email' => 'must-not-survive@example.test']], 'native-granted-' . bin2hex(random_bytes(4)));
if (empty($granted['analytics']) || ($event['status'] ?? '') !== 'processed') throw new \RuntimeException('Native analytics consent or event delivery failed.');

$invalid = false;
try {
    $api->analyticsEvent(['name' => 'unsupported_native_event', 'payload' => []], 'native-invalid-' . bin2hex(random_bytes(4)));
} catch (MercatoHeadlessApiException $error) {
    $invalid = $error->httpStatus === 422;
}
if (!$invalid) throw new \RuntimeException('Native analytics accepted an unsupported event.');

$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
if (str_contains($log, 'must-not-survive@example.test')) throw new \RuntimeException('Native analytics persisted prohibited PII.');

$cleanup();
echo "Mercato native analytics integration tests passed.\n";
