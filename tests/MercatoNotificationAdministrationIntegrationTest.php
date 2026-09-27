<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato notification administration integration test skipped (set MERCATO_TEST_SITE).\n";
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
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the notification administration profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Notification administration fixtures are forbidden in production mode.');

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new \RuntimeException($message);
};
$expectThrows = static function (callable $callable, string $class, string $message, ?string $contains = null) use (&$checks): \Throwable {
    $checks++;
    try {
        $callable();
    } catch (\Throwable $exception) {
        if (!$exception instanceof $class) throw new \RuntimeException($message . ' Unexpected exception: ' . $exception::class, 0, $exception);
        if ($contains !== null && !str_contains(strtolower($exception->getMessage()), strtolower($contains))) {
            throw new \RuntimeException($message . ' Unexpected message: ' . $exception->getMessage(), 0, $exception);
        }
        return $exception;
    }
    throw new \RuntimeException($message);
};

$modules = $wire->modules;
$storedConfig = (array) $modules->getConfig('Mercato');
$originalRuntime = [
    'notification_templates_json' => $commerce->notification_templates_json,
    'notification_header_html' => $commerce->notification_header_html,
    'notification_footer_html' => $commerce->notification_footer_html,
];
$runId = 'notification-admin-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$users = [];
$cleaned = false;

$cleanup = static function () use ($wire, $modules, $commerce, $superuser, $storedConfig, $originalRuntime, &$users, &$cleaned): void {
    if ($cleaned) return;
    $cleaned = true;
    $wire->users->setCurrentUser($superuser);
    $modules->saveConfig('Mercato', $storedConfig);
    foreach ($originalRuntime as $name => $value) $commerce->set($name, $value);
    foreach (array_reverse($users) as $user) {
        if (!$user instanceof User || !$user->id) continue;
        $fresh = $wire->users->get((int) $user->id);
        if ($fresh && $fresh->id) $wire->users->delete($fresh);
    }
};
register_shutdown_function($cleanup);

$supportRole = $wire->roles->get('mercato-support');
$managerRole = $wire->roles->get('mercato-manager');
$expect((bool) ($supportRole && $supportRole->id), 'mercato-support role is missing.');
$expect((bool) ($managerRole && $managerRole->id), 'mercato-manager role is missing.');
$expect($supportRole->hasPermission('mercato-admin'), 'Support fixture role lacks base Mercato dashboard access.');
$expect(!$supportRole->hasPermission('mercato-manage-notifications'), 'Support fixture role unexpectedly has notification designer permission.');
$expect($managerRole->hasPermission('mercato-admin') && $managerRole->hasPermission('mercato-manage-notifications'), 'Manager fixture role lacks the required notification permission pair.');

$createUser = static function (string $suffix, Role $role) use ($wire, $runId, &$users): User {
    $user = new User();
    $user->of(false);
    $user->name = 'e2e-' . $suffix . '-' . substr(hash('sha256', $runId), 0, 16);
    $user->email = $runId . '-' . $suffix . '@example.test';
    $user->pass = 'Notification-fixture-42!';
    $user->addRole($role);
    $wire->users->save($user);
    $users[] = $user;
    return $user;
};
$support = $createUser('support', $supportRole);
$manager = $createUser('manager', $managerRole);
$expect(!$support->isSuperuser() && $support->hasPermission('mercato-admin') && !$support->hasPermission('mercato-manage-notifications'), 'Support user permission fixture is invalid.');
$expect(!$manager->isSuperuser() && $manager->hasPermission('mercato-admin') && $manager->hasPermission('mercato-manage-notifications'), 'Manager user permission fixture is invalid.');

$beforeDenied = (array) $modules->getConfig('Mercato');
$expectThrows(
    fn() => $commerce->saveNotificationTemplate('payment_failed', 'Denied', 'Denied text', '<p>Denied</p>', $support),
    WirePermissionException::class,
    'Base dashboard permission bypassed the dedicated notification permission.'
);
$expectThrows(
    fn() => $commerce->resetNotificationTemplate('payment_failed', $support),
    WirePermissionException::class,
    'Base dashboard permission allowed notification reset.'
);
$expectThrows(
    fn() => $commerce->saveNotificationMailLayout('<p>Denied</p>', '<p>Denied</p>', $support),
    WirePermissionException::class,
    'Base dashboard permission allowed shared-layout mutation.'
);
$expect((array) $modules->getConfig('Mercato') === $beforeDenied, 'Denied notification mutations changed stored module configuration.');

$expectThrows(
    fn() => $commerce->saveNotificationTemplate('provider_future_event', 'Unknown', 'Unknown', '<p>Unknown</p>', $manager),
    WireException::class,
    'Unknown notification event was accepted.',
    'unknown'
);
foreach ([
    ['', 'Text', '<p>HTML</p>'],
    ['Subject', '', '<p>HTML</p>'],
    ['Subject', 'Text', '<script>unsafe()</script>'],
] as $invalid) {
    $expectThrows(
        fn() => $commerce->saveNotificationTemplate('payment_failed', $invalid[0], $invalid[1], $invalid[2], $manager),
        WireException::class,
        'Incomplete notification template was accepted.',
        'required'
    );
}

$subject = str_repeat('S', 250) . "\r\nInjected";
$text = "Payment failed for {customer}.\nRetry {payment_link}.";
$html = '<div onload="steal()"><p>Payment failed for <strong>{customer}</strong>.</p>'
    . '<a href="javascript:steal()">Unsafe</a><a href="{payment_link}" onclick="steal()">Retry</a>'
    . '<script>steal()</script><iframe src="https://evil.example"></iframe></div>';
$commerce->saveNotificationTemplate('payment_failed', $subject, $text, $html, $manager);

$saved = $commerce->notificationTemplate('payment_failed');
$expect(!empty($saved['customized']), 'Authorized template save was not marked customized.');
$expect(strlen((string) $saved['subject']) === 240 && !str_contains((string) $saved['subject'], "\n"), 'Notification subject was not flattened and bounded.');
$savedHtmlLower = strtolower((string) $saved['html']);
$expect(!str_contains($savedHtmlLower, '<script') && !str_contains($savedHtmlLower, '<iframe') && !str_contains($savedHtmlLower, 'onload=') && !str_contains($savedHtmlLower, 'onclick='), 'Stored notification HTML retained executable markup.');
$expect(!str_contains($savedHtmlLower, 'javascript:') && str_contains($savedHtmlLower, 'href="#"'), 'Stored notification HTML retained an unsafe URL.');
$expect(in_array('payment_link', (array) $saved['variables'], true) && in_array('customer', (array) $saved['variables'], true), 'Saved notification lost its declared variable contract.');
$expect(trim((string) ($saved['updated_at'] ?? '')) !== '' || str_contains((string) $commerce->notification_templates_json, 'updated_at'), 'Saved notification lacks update evidence.');

$storedAfterSave = (array) $modules->getConfig('Mercato');
$storedTemplates = json_decode((string) ($storedAfterSave['notification_templates_json'] ?? ''), true);
$expect(is_array($storedTemplates) && isset($storedTemplates['payment_failed']), 'Authorized template save did not persist to module configuration.');
$expect(($storedTemplates['payment_failed']['subject'] ?? '') === $saved['subject'], 'Runtime and stored notification subjects diverged.');

$header = '<table><tr><td><img src="data:text/html,unsafe" onerror="steal()"><strong>{store_name}</strong></td></tr></table>';
$footer = '<form><input value="unsafe"></form><p><a href="vbscript:steal()">Contact</a></p>';
$commerce->saveNotificationMailLayout($header, $footer, $manager);
$layout = $commerce->notificationMailLayout();
$layoutLower = strtolower((string) $layout['header'] . (string) $layout['footer']);
$expect(str_contains((string) $layout['header'], '{store_name}') && str_contains((string) $layout['header'], '<table>'), 'Authorized shared header lost safe markup or variables.');
$expect(!str_contains($layoutLower, '<form') && !str_contains($layoutLower, '<input') && !str_contains($layoutLower, 'onerror=') && !str_contains($layoutLower, 'data:') && !str_contains($layoutLower, 'vbscript:'), 'Shared layout retained executable markup or unsafe URLs.');
$storedAfterLayout = (array) $modules->getConfig('Mercato');
$expect(($storedAfterLayout['notification_header_html'] ?? null) === $layout['header'] && ($storedAfterLayout['notification_footer_html'] ?? null) === $layout['footer'], 'Runtime and stored shared layouts diverged.');

$preview = $commerce->notificationDeliveryService()->preview('payment_failed', [
    'store_name' => 'Fixture & Store',
    'customer' => '<Customer>',
    'payment_link' => 'https://mercato.test/pay?token=signed&attempt=1',
]);
$previewLower = strtolower((string) ($preview['html'] ?? ''));
$expect(strlen((string) ($preview['subject'] ?? '')) === 240, 'Preview did not use the persisted bounded subject.');
$expect(str_contains((string) ($preview['text'] ?? ''), 'https://mercato.test/pay?token=signed&attempt=1'), 'Preview lost the signed payment link in plain text.');
$expect(str_contains((string) ($preview['html'] ?? ''), 'Fixture &amp; Store') && str_contains((string) ($preview['html'] ?? ''), '&lt;Customer&gt;'), 'Preview did not escape shared-layout or customer variables.');
$expect(!str_contains($previewLower, '<script') && !str_contains($previewLower, 'javascript:') && !str_contains($previewLower, 'vbscript:') && !str_contains($previewLower, 'data:'), 'Rendered preview reintroduced unsafe markup.');

$commerce->resetNotificationTemplate('payment_failed', $manager);
$reset = $commerce->notificationTemplate('payment_failed');
$expect(empty($reset['customized']), 'Authorized reset did not restore the configured default.');
$afterResetTemplates = json_decode((string) ((array) $modules->getConfig('Mercato'))['notification_templates_json'], true);
$expect(is_array($afterResetTemplates) && !isset($afterResetTemplates['payment_failed']), 'Reset template remained in persisted custom configuration.');
$expectThrows(fn() => $commerce->notificationTemplate('provider_future_event'), WireException::class, 'Unknown template read did not fail closed.', 'unknown');

$createdUserIds = array_map(static fn(User $user): int => (int) $user->id, $users);
$cleanup();
$expect((array) $modules->getConfig('Mercato') === $storedConfig, 'Notification administration profile did not restore module configuration exactly.');
foreach ($originalRuntime as $name => $value) $expect($commerce->get($name) === $value, "Notification runtime property $name was not restored exactly.");
$userExists = static function (int $id) use ($wire): bool {
    $statement = $wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');
    $statement->execute([':id' => $id]);
    return (int) $statement->fetchColumn() > 0;
};
foreach ($createdUserIds as $id) $expect(!$userExists((int) $id), "Notification fixture user $id remained after cleanup.");

echo "Mercato notification administration integration tests passed: {$checks} assertions, 2 role-scoped users; exact config/user cleanup complete.\n";
