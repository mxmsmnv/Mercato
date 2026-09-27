<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
$baseUrl = rtrim((string) getenv('MERCATO_TEST_HTTP_BASE'), '/');
$confirm = (string) getenv('MERCATO_HTTP_BOUNDARY_CONFIRM');
if ($site === '' || $baseUrl === '' || $confirm !== 'I_UNDERSTAND_THIS_TEMPORARILY_ENABLES_THE_LOCAL_MCP_ENDPOINT') {
    echo "Mercato MCP/session HTTP boundary test skipped (set MERCATO_TEST_SITE, MERCATO_TEST_HTTP_BASE, and MERCATO_HTTP_BOUNDARY_CONFIRM).\n";
    exit(0);
}
if (!function_exists('curl_init')) throw new \RuntimeException('The curl extension is required.');
if (!is_file($site . '/wire/core/ProcessWire.php')) throw new \RuntimeException('MERCATO_TEST_SITE is not a ProcessWire root.');
$baseParts = parse_url($baseUrl);
$baseHost = strtolower((string) ($baseParts['host'] ?? ''));
if (!in_array($baseHost, ['localhost', '127.0.0.1', '::1'], true) && !str_ends_with($baseHost, '.test') && !str_ends_with($baseHost, '.local')) {
    throw new \RuntimeException('MERCATO_TEST_HTTP_BASE must be a local development hostname.');
}

$_SERVER['HTTP_HOST'] = $baseHost;
$_SERVER['SERVER_NAME'] = $baseHost;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$super = $wire->users->get('template=user, roles.name=superuser');
$wire->users->setCurrentUser($super);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$mcp = $wire->modules->getModule('McpServer', ['noPermissionCheck' => true]);
if (!$mcp || !method_exists($mcp, 'issueClient')) throw new \RuntimeException('McpServer is not installed.');
if ($mcp->environmentName() !== 'development') throw new \RuntimeException('This profile is restricted to development installations.');
if (($commerce->customer_accounts_mode ?? 'disabled') === 'disabled') throw new \RuntimeException('Customer accounts must already be enabled on the development site.');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$passes = 0;
$check = static function (bool $condition, string $message) use ($expect, &$passes): void {
    $expect($condition, $message);
    $passes++;
    echo "[PASS] {$message}\n";
};

/** @return array{status:int,body:string,json:mixed,headers:array<string,string>} */
$http = static function (string $url, string $method = 'GET', array|string|null $data = null, ?string $token = null, ?string $cookieFile = null, array $headers = [], bool $follow = false): array {
    $responseHeaders = [];
    $curl = curl_init($url);
    $requestHeaders = $headers;
    if ($token !== null) $requestHeaders[] = 'Authorization: Bearer ' . $token;
    if (is_string($data)) $requestHeaders[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $position = strpos($line, ':');
            if ($position !== false) $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            return strlen($line);
        },
    ]);
    if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    if ($cookieFile !== null) {
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookieFile);
    }
    $body = curl_exec($curl);
    if ($body === false) throw new \RuntimeException('HTTP request failed: ' . curl_error($curl));
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80500) curl_close($curl);
    return ['status' => $status, 'body' => (string) $body, 'json' => json_decode((string) $body, true), 'headers' => $responseHeaders];
};

$csrf = static function (string $html): array {
    if (!preg_match('/<input[^>]+name=[\'\"]([^\'\"]+)[\'\"][^>]+value=[\'\"]([^\'\"]+)[\'\"][^>]+class=[\'\"]_post_token[\'\"]/i', $html, $match)) {
        throw new \RuntimeException('ProcessWire CSRF token was not found in the account response.');
    }
    return [$match[1], html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5)];
};
$sessionId = static function (string $cookieFile): string {
    $lines = is_file($cookieFile) ? file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    foreach (array_reverse($lines ?: []) as $line) {
        if (str_starts_with($line, '#') && !str_starts_with($line, '#HttpOnly_')) continue;
        $columns = explode("\t", preg_replace('/^#HttpOnly_/', '', $line) ?: $line);
        if (count($columns) >= 7 && preg_match('/^pw[a-z0-9]+$/i', $columns[5])) return (string) $columns[6];
    }
    return '';
};
$profileRevision = static function (string $html): int {
    if (!preg_match('/name=[\'\"]revision[\'\"]\s+value=[\'\"](\d+)[\'\"]/i', $html, $match)) throw new \RuntimeException('Profile revision was not found.');
    return (int) $match[1];
};
$mcpMeta = static fn(): array => [
    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
    'io.modelcontextprotocol/clientInfo' => ['name' => 'Mercato HTTP boundary test', 'version' => '1.0.0'],
];
$mcpBody = static fn(string $id, string $name, array $arguments): string => json_encode([
    'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
    'params' => ['name' => $name, 'arguments' => $arguments, '_meta' => $mcpMeta()],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$mcpHeaders = static fn(string $name): array => [
    'Accept: application/json, text/event-stream',
    'MCP-Protocol-Version: 2026-07-28',
    'Mcp-Method: tools/call',
    'Mcp-Name: ' . $name,
];
$toolPayload = static function (array $response): array {
    $text = (string) ($response['json']['result']['content'][0]['text'] ?? '');
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
};

$runId = 'mrc-http-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$endpoint = $baseUrl . '/mcp/';
$accountUrl = $baseUrl . '/account/';
$originalEnabled = (bool) $mcp->enabled;
$issuedIds = [];
$order = null;
$user = null;
$cookieFiles = [tempnam(sys_get_temp_dir(), 'mrc-http-a-'), tempnam(sys_get_temp_dir(), 'mrc-http-b-')];
$operationKeys = [];
$cleanupDone = false;

$cleanup = static function () use (&$cleanupDone, $wire, $mcp, $originalEnabled, &$issuedIds, &$order, &$user, &$operationKeys, &$cookieFiles, $runId, $super): void {
    if ($cleanupDone) return;
    $cleanupDone = true;
    try {
        $wire->users->setCurrentUser($super);
        foreach (array_unique($issuedIds) as $id) {
            try { $mcp->revokeClient((string) $id); } catch (\Throwable) {}
        }
        $wire->modules->saveConfig('McpServer', 'enabled', $originalEnabled ? 1 : 0);
        $mcp->set('enabled', $originalEnabled ? 1 : 0);
        $deleteOperation = $wire->database->prepare('DELETE FROM mercato_mcp_operations WHERE operation_key_hash=:key');
        foreach ($operationKeys as [$action, $key]) $deleteOperation->execute([':key' => hash('sha256', $action . ':' . $key)]);
        $deleteAudit = $wire->database->prepare('DELETE FROM mcp_server_audit WHERE request_id LIKE :prefix');
        $deleteAudit->execute([':prefix' => $runId . '%']);
        if ($issuedIds) {
            $deleteClient = $wire->database->prepare('DELETE FROM mcp_server_clients WHERE id=:id');
            foreach (array_unique($issuedIds) as $id) $deleteClient->execute([':id' => $id]);
        }
        if ($order instanceof Page && $order->id) {
            $freshOrder = $wire->pages->get((int) $order->id);
            if ($freshOrder->id) $wire->pages->delete($freshOrder, true);
        }
        if ($user instanceof User && $user->id) {
            $freshUser = $wire->users->get((int) $user->id);
            if ($freshUser->id) $wire->users->delete($freshUser);
        }
    } finally {
        foreach ($cookieFiles as $file) if (is_string($file) && is_file($file)) @unlink($file);
    }
};
register_shutdown_function($cleanup);

try {
    $role = $wire->roles->get('mercato-customer');
    $expect((bool) $role->id, 'mercato-customer role is missing.');
    $email = $runId . '@example.test';
    $password = 'Boundary-session-42!';
    $user = new User();
    $user->of(false);
    $user->name = 'customer-' . substr(hash('sha256', $runId), 0, 20);
    $user->email = $email;
    $user->pass = $password;
    $user->addRole($role);
    $user->mrc_first_name = 'Boundary';
    $user->mrc_last_name = 'Fixture';
    $user->mrc_customer_verified = 1;
    $user->mrc_customer_revision = 0;
    $wire->users->save($user);

    foreach ($cookieFiles as $cookieFile) {
        $guest = $http($accountUrl, 'GET', null, null, $cookieFile);
        $check($guest['status'] === 200, 'account endpoint creates an isolated guest session');
        $guestId = $sessionId($cookieFile);
        [$csrfName, $csrfValue] = $csrf($guest['body']);
        $login = $http($accountUrl, 'POST', [
            $csrfName => $csrfValue, 'mrc_account_action' => 'login', 'email' => $email, 'password' => $password,
        ], null, $cookieFile, [], true);
        $check($login['status'] === 200 && str_contains($login['body'], 'Profile and primary address'), 'verified customer logs in through the real account endpoint');
        $authenticatedId = $sessionId($cookieFile);
        $check($guestId !== '' && $authenticatedId !== '' && !hash_equals($guestId, $authenticatedId), 'ProcessWire session cookie rotates on account login');
    }
    $check($sessionId($cookieFiles[0]) !== $sessionId($cookieFiles[1]), 'concurrent customer sessions keep distinct session identifiers');

    $profileA = $http($accountUrl, 'GET', null, null, $cookieFiles[0]);
    $profileB = $http($accountUrl, 'GET', null, null, $cookieFiles[1]);
    $revisionA = $profileRevision($profileA['body']);
    $revisionB = $profileRevision($profileB['body']);
    $check($revisionA === $revisionB, 'two sessions observe the same initial profile revision');
    [$csrfAName, $csrfAValue] = $csrf($profileA['body']);
    [$csrfBName, $csrfBValue] = $csrf($profileB['body']);
    $saveA = $http($accountUrl, 'POST', [
        $csrfAName => $csrfAValue, 'mrc_account_action' => 'profile', 'revision' => $revisionA,
        'first_name' => 'Boundary', 'last_name' => 'Fixture', 'phone' => '+1 555 0101',
        'address' => 'Session A', 'city' => 'Test', 'zip' => '10001', 'country' => 'us',
    ], null, $cookieFiles[0]);
    $check($saveA['status'] === 200 && str_contains($saveA['body'], 'Profile saved.'), 'first session persists its profile revision');
    $saveB = $http($accountUrl, 'POST', [
        $csrfBName => $csrfBValue, 'mrc_account_action' => 'profile', 'revision' => $revisionB,
        'first_name' => 'Boundary', 'last_name' => 'Fixture', 'phone' => '+1 555 0199',
        'address' => 'Session B', 'city' => 'Test', 'zip' => '10002', 'country' => 'us',
    ], null, $cookieFiles[1]);
    $check($saveB['status'] === 200 && str_contains($saveB['body'], 'Your profile changed in another session. Reload and try again.'), 'stale concurrent profile revision is rejected through HTTP');
    $persistedProfile = $wire->database->prepare(
        'SELECT phone.data AS phone, revision.data AS revision '
        . 'FROM field_mrc_phone phone JOIN field_mrc_customer_revision revision ON revision.pages_id=phone.pages_id '
        . 'WHERE phone.pages_id=:id'
    );
    $persistedProfile->execute([':id' => (int) $user->id]);
    $persistedProfile = $persistedProfile->fetch(\PDO::FETCH_ASSOC) ?: [];
    $check((string) ($persistedProfile['phone'] ?? '') === '+1 555 0101' && (int) ($persistedProfile['revision'] ?? -1) === $revisionA + 1, 'stale session cannot overwrite the winning profile update');

    $items = [['id' => $runId, 'uid' => $runId, 'product_id' => 999991, 'title' => 'HTTP boundary fixture', 'sku' => 'HTTP-BOUNDARY', 'price' => 12, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'service']];
    $order = $commerce->orderRepository()->savePendingOrder([
        'first_name' => 'MCP', 'last_name' => 'Boundary', 'email' => $email,
        'payment_status' => MercatoPaymentStatus::PAID, 'payment_complete' => 1,
        'mrc_items' => json_encode($items), 'mrc_subtotal_amount' => 12, 'mrc_total_amount' => 12, 'mrc_currency' => 'USD',
        'fulfilment_method' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
        'mrc_fulfilment_status' => MercatoFulfilmentStatus::UNFULFILLED,
    ]);
    $order->of(false);
    $order->mrc_fulfilment_status = MercatoFulfilmentStatus::UNFULFILLED;
    $wire->pages->save($order);
    $reference = (string) $order->mrc_invoice_number;

    $wire->modules->saveConfig('McpServer', 'enabled', 1);
    $mcp->set('enabled', 1);
    $read = $mcp->issueClient($runId . ' read', ['read']);
    $publish = $mcp->issueClient($runId . ' publish', ['publish']);
    $issuedIds = [(string) $read['id'], (string) $publish['id']];
    $tools = [];
    foreach ($mcp->tools() as $tool) $tools[(string) $tool['name']] = $tool;
    $provider = array_values(array_filter($mcp->providers(), static fn(array $item): bool => ($item['module'] ?? '') === 'Mercato'))[0] ?? [];
    $expectedProviderVersion = $wire->modules->formatVersion((string) ($wire->modules->getModuleInfo('Mercato')['version'] ?? ''));
    $check(($provider['version'] ?? '') === $expectedProviderVersion, 'McpServer discovery reports the installed Mercato release version');
    $prefix = $mcp->namespacePrefix() . '_';
    $readName = $prefix . 'mercato_get_order';
    $advanceName = $prefix . 'mercato_advance_fulfilment';
    $labelName = $prefix . 'mercato_purchase_shipping_label';
    $check(isset($tools[$readName], $tools[$advanceName], $tools[$labelName]), 'McpServer discovers namespaced Mercato read/publish/admin tools');

    $readResponse = $http($endpoint, 'POST', $mcpBody($runId . '-read', $readName, ['order_reference' => $reference]), (string) $read['token'], null, $mcpHeaders($readName));
    $readPayload = $toolPayload($readResponse);
    $check($readResponse['status'] === 200 && empty($readResponse['json']['error']) && (int) ($readPayload['order_id'] ?? 0) === (int) $order->id, 'read-scoped client reaches the Mercato provider through Streamable HTTP');
    $check(!str_contains(json_encode($readPayload) ?: '', $email), 'HTTP MCP order projection excludes customer PII');

    $advanceArgs = [
        'order_reference' => $reference, 'status' => MercatoFulfilmentStatus::SHIPPED,
        'idempotency_key' => 'advance-' . $runId, 'reason' => 'Advance isolated HTTP boundary fixture',
        'confirmation' => 'ADVANCE_VALIDATED_FULFILMENT',
    ];
    $operationKeys[] = ['advance_fulfilment', $advanceArgs['idempotency_key']];
    $denied = $http($endpoint, 'POST', $mcpBody($runId . '-scope-denied', $advanceName, $advanceArgs), (string) $read['token'], null, $mcpHeaders($advanceName));
    $check($denied['status'] === 403 && ($denied['json']['error'] ?? '') === 'insufficient_scope', 'read client is denied before a publish mutation reaches Mercato');
    $labelDenied = $http($endpoint, 'POST', $mcpBody($runId . '-admin-denied', $labelName, [
        'order_reference' => $reference, 'idempotency_key' => 'label-' . $runId,
        'reason' => 'Prove admin boundary without purchasing a label', 'confirmation' => 'PURCHASE_PROVIDER_LABEL_WITH_COST',
    ]), (string) $publish['token'], null, $mcpHeaders($labelName));
    $check($labelDenied['status'] === 403, 'publish client is denied the admin-only label tool without external side effects');

    $invalidArgs = $advanceArgs;
    $invalidArgs['confirmation'] = 'WRONG_CONFIRMATION';
    $invalid = $http($endpoint, 'POST', $mcpBody($runId . '-schema-error', $advanceName, $invalidArgs), (string) $publish['token'], null, $mcpHeaders($advanceName));
    $invalidRejected = $invalid['status'] >= 400 || isset($invalid['json']['error']) || !empty($invalid['json']['result']['isError']);
    $check($invalidRejected, 'closed MCP schema rejects an incorrect exact confirmation');

    $advanced = $http($endpoint, 'POST', $mcpBody($runId . '-advance', $advanceName, $advanceArgs), (string) $publish['token'], null, $mcpHeaders($advanceName));
    $advancedPayload = $toolPayload($advanced);
    $check($advanced['status'] === 200 && empty($advanced['json']['result']['isError']) && ($advancedPayload['status'] ?? '') === MercatoFulfilmentStatus::SHIPPED, 'publish client performs an authorized local-only fulfilment mutation');
    $replay = $http($endpoint, 'POST', $mcpBody($runId . '-replay', $advanceName, $advanceArgs), (string) $publish['token'], null, $mcpHeaders($advanceName));
    $replayPayload = $toolPayload($replay);
    $check(!empty($replayPayload['idempotent_replay']) && ($replayPayload['status'] ?? '') === MercatoFulfilmentStatus::SHIPPED, 'HTTP MCP mutation replay returns the durable stored result');
    $conflictArgs = $advanceArgs;
    $conflictArgs['status'] = MercatoFulfilmentStatus::DELIVERED;
    $conflict = $http($endpoint, 'POST', $mcpBody($runId . '-conflict', $advanceName, $conflictArgs), (string) $publish['token'], null, $mcpHeaders($advanceName));
    $conflictPayload = $toolPayload($conflict);
    $check(!empty($conflict['json']['result']['isError']) && ($conflictPayload['code'] ?? '') === 'idempotency_conflict', 'same MCP idempotency key with different input is rejected');

    $mcp->revokeClient((string) $read['id']);
    $revoked = $http($endpoint, 'POST', $mcpBody($runId . '-revoked', $readName, ['order_reference' => $reference]), (string) $read['token'], null, $mcpHeaders($readName));
    $check($revoked['status'] === 401, 'revoked scoped credential is rejected at the HTTP boundary');

    $audit = $wire->database->prepare('SELECT request_id, tool_name, scope, status, arguments_digest FROM mcp_server_audit WHERE request_id LIKE :prefix ORDER BY id');
    $audit->execute([':prefix' => $runId . '%']);
    $auditRows = $audit->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $auditJson = json_encode($auditRows, JSON_UNESCAPED_SLASHES) ?: '';
    $check(count($auditRows) >= 7 && str_contains($auditJson, '"status":"denied"') && str_contains($auditJson, '"status":"ok"') && str_contains($auditJson, '"status":"error"'), 'McpServer persists scoped ok/denied/error audit outcomes');
    $check(!str_contains($auditJson, $reference) && !str_contains($auditJson, $email) && !str_contains($auditJson, $advanceArgs['reason']), 'gateway audit stores only argument digests, not order/customer/reason data');
} finally {
    $cleanup();
}

$residualOrders = $wire->pages->count('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ', include=all, mrc_email=' . $wire->sanitizer->selectorValue($runId . '@example.test'));
$residualUsers = $wire->users->count('include=all, email=' . $wire->sanitizer->selectorValue($runId . '@example.test'));
$residualAudit = (int) $wire->database->query("SELECT COUNT(*) FROM mcp_server_audit WHERE request_id LIKE " . $wire->database->quote($runId . '%'))->fetchColumn();
$residualClients = (int) $wire->database->query("SELECT COUNT(*) FROM mcp_server_clients WHERE label LIKE " . $wire->database->quote($runId . '%'))->fetchColumn();
$check($residualOrders === 0 && $residualUsers === 0 && $residualAudit === 0 && $residualClients === 0, 'exact run-owned HTTP fixtures, audit rows, and client credentials are cleaned');
$check((bool) $wire->modules->get('McpServer')->enabled === $originalEnabled, 'McpServer enabled state is restored exactly');
echo "Mercato MCP/session HTTP boundary integration passed with {$passes} assertions; run {$runId}.\n";
