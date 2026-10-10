<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$site = rtrim((string) (getenv('MERCATO_E2E_SITE') ?: getenv('MERCATO_TEST_SITE')), '/');
if ($site === '') { fwrite(STDERR, "Set MERCATO_E2E_SITE to a non-production ProcessWire installation.\n"); exit(2); }
$baseUrl = (string) (getenv('MERCATO_E2E_BASE_URL') ?: 'https://mercato.test');
$artifacts = (string) (getenv('MERCATO_E2E_ARTIFACTS') ?: $root . '/artifacts/e2e');
if (!is_dir($artifacts) && !mkdir($artifacts, 0775, true) && !is_dir($artifacts)) throw new RuntimeException('Cannot create artifacts directory.');
$state = tempnam(sys_get_temp_dir(), 'mercato-e2e-'); if ($state === false) throw new RuntimeException('Cannot create fixture state file.'); unlink($state);
$mysqlSocket = trim((string) getenv('MERCATO_MYSQL_SOCKET'));
$phpArgs = $mysqlSocket === '' ? [] : ['-d', "pdo_mysql.default_socket={$mysqlSocket}", '-d', "mysqli.default_socket={$mysqlSocket}"];
$localTls = parse_url($baseUrl, PHP_URL_HOST) === 'mercato.test' ? '1' : (string) (getenv('MERCATO_E2E_IGNORE_HTTPS_ERRORS') ?: '0');
$env = array_merge(getenv(), ['MERCATO_E2E_SITE'=>$site, 'MERCATO_TEST_SITE'=>$site, 'MERCATO_E2E_STATE'=>$state, 'MERCATO_E2E_BASE_URL'=>$baseUrl, 'MERCATO_E2E_ARTIFACTS'=>$artifacts, 'MERCATO_E2E_IGNORE_HTTPS_ERRORS'=>$localTls]);
$results = [];
function runAcceptance(string $name, array $command, string $expected, array $env, string $cwd): int {
    global $results; $started = microtime(true);
    echo "\n== $name ==\n"; $process = proc_open($command, [0=>STDIN, 1=>STDOUT, 2=>STDERR], $pipes, $cwd, $env);
    $status = is_resource($process) ? proc_close($process) : 127;
    $results[] = ['scenario'=>$name, 'expected'=>$expected, 'transition'=>$status === 0 ? 'passed' : 'failed', 'exit_code'=>$status, 'duration_seconds'=>round(microtime(true)-$started, 3)];
    return $status;
}
$fixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/fixtures.php']); $failed = false;
try {
    if (runAcceptance('fixture_setup', array_merge($fixture, ['setup']), 'isolated fixture graph is ready', $env, $root) !== 0) throw new RuntimeException('Fixture setup failed.');
    $failed = runAcceptance('backend_deterministic_scenarios', array_merge([PHP_BINARY], $phpArgs, [$root.'/scripts/run-tests.php']), 'all unit and integration scenarios pass', $env, $root) !== 0 || $failed;
    $failed = runAcceptance('browser_matrix', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'all versioned browser, viewport and accessibility checks pass', $env, $root) !== 0 || $failed;
    $failed = runAcceptance('persisted_journey_state', array_merge($fixture, ['verify']), 'browser and native API orders are paid, discounted, and decrement stock exactly once', $env, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'runner', 'expected'=>'suite completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($state)) $failed = runAcceptance('fixture_cleanup', array_merge($fixture, ['cleanup']), 'only run-owned records are deleted and config restored', $env, $root) !== 0 || $failed;
}

// Stateful role-specific journeys own different fixture graphs. Keep them out
// of the generic browser matrix, then run each profile explicitly so a missing
// setup can never look like a skipped or passing acceptance scenario.
$adminState = tempnam(sys_get_temp_dir(), 'mercato-admin-e2e-');
if ($adminState === false) throw new RuntimeException('Cannot create admin fixture state file.');
unlink($adminState);
$adminArtifacts = $artifacts . '/admin';
if (!is_dir($adminArtifacts) && !mkdir($adminArtifacts, 0775, true) && !is_dir($adminArtifacts)) throw new RuntimeException('Cannot create admin artifacts directory.');
$adminEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$adminState, 'MERCATO_E2E_ARTIFACTS'=>$adminArtifacts, 'MERCATO_E2E_PROFILE'=>'admin']);
$adminFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/admin-fixtures.php']);
try {
    if (runAcceptance('admin_fixture_setup', array_merge($adminFixture, ['setup']), 'isolated least-privilege staff, manager, customer, and paid order are ready', $adminEnv, $root) !== 0) throw new RuntimeException('Admin fixture setup failed.');
    $adminBrowserFailed = runAcceptance('admin_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'staff mutation denials, manager keyboard fulfilment, and customer-visible state pass', $adminEnv, $root) !== 0;
    $failed = $adminBrowserFailed || $failed;
    if (!$adminBrowserFailed) $failed = runAcceptance('admin_persisted_state', array_merge($adminFixture, ['verify']), 'fulfilment persists while payment, refund, privacy, and notification state do not regress', $adminEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'admin_runner', 'expected'=>'admin profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($adminState)) $failed = runAcceptance('admin_fixture_cleanup', array_merge($adminFixture, ['cleanup']), 'only the exact admin order, users, roles, and config changes are removed', $adminEnv, $root) !== 0 || $failed;
    if (is_file($adminState)) unlink($adminState);
}

$lifecycleState = tempnam(sys_get_temp_dir(), 'mercato-customer-e2e-');
if ($lifecycleState === false) throw new RuntimeException('Cannot create customer lifecycle fixture state file.');
unlink($lifecycleState);
$lifecycleArtifacts = $artifacts . '/customer-lifecycle';
if (!is_dir($lifecycleArtifacts) && !mkdir($lifecycleArtifacts, 0775, true) && !is_dir($lifecycleArtifacts)) throw new RuntimeException('Cannot create customer lifecycle artifacts directory.');
$lifecycleEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$lifecycleState, 'MERCATO_E2E_ARTIFACTS'=>$lifecycleArtifacts, 'MERCATO_E2E_PROFILE'=>'customer-lifecycle']);
$lifecycleFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/customer-lifecycle-fixtures.php']);
try {
    if (runAcceptance('customer_lifecycle_fixture_setup', array_merge($lifecycleFixture, ['setup']), 'isolated customer, guest digital order, product, and bounded security configuration are ready', $lifecycleEnv, $root) !== 0) throw new RuntimeException('Customer lifecycle fixture setup failed.');
    $lifecycleBrowserFailed = runAcceptance('customer_lifecycle_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'registration, verification, reset, throttling, claim, signed documents, and one-time download pass', $lifecycleEnv, $root) !== 0;
    $failed = $lifecycleBrowserFailed || $failed;
    if (!$lifecycleBrowserFailed) $failed = runAcceptance('customer_lifecycle_persisted_state', array_merge($lifecycleFixture, ['verify']), 'tokens are consumed, claim ownership persists, paid state is stable, and one download is recorded', $lifecycleEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'customer_lifecycle_runner', 'expected'=>'customer lifecycle profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($lifecycleState)) $failed = runAcceptance('customer_lifecycle_fixture_cleanup', array_merge($lifecycleFixture, ['cleanup']), 'only the exact lifecycle order, users, product, files, and config changes are removed', $lifecycleEnv, $root) !== 0 || $failed;
    if (is_file($lifecycleState)) unlink($lifecycleState);
}

$refundState = tempnam(sys_get_temp_dir(), 'mercato-refund-e2e-');
if ($refundState === false) throw new RuntimeException('Cannot create refund fixture state file.');
unlink($refundState);
$refundArtifacts = $artifacts . '/refund';
if (!is_dir($refundArtifacts) && !mkdir($refundArtifacts, 0775, true) && !is_dir($refundArtifacts)) throw new RuntimeException('Cannot create refund artifacts directory.');
$refundEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$refundState, 'MERCATO_E2E_ARTIFACTS'=>$refundArtifacts, 'MERCATO_E2E_PROFILE'=>'refund']);
$refundFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/refund-fixtures.php']);
try {
    if (runAcceptance('refund_fixture_setup', array_merge($refundFixture, ['setup']), 'isolated refund manager, customer, paid order, and adjusted inventory are ready', $refundEnv, $root) !== 0) throw new RuntimeException('Refund fixture setup failed.');
    $refundBrowserFailed = runAcceptance('refund_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'authorized full refund, exact replay denial, and customer-visible documents pass', $refundEnv, $root) !== 0;
    $failed = $refundBrowserFailed || $failed;
    if (!$refundBrowserFailed) $failed = runAcceptance('refund_persisted_state', array_merge($refundFixture, ['verify']), 'one refund ledger/event persists and inventory is restored exactly once', $refundEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'refund_runner', 'expected'=>'refund profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($refundState)) $failed = runAcceptance('refund_fixture_cleanup', array_merge($refundFixture, ['cleanup']), 'only the exact refund order, users, role, product, and config changes are removed', $refundEnv, $root) !== 0 || $failed;
    if (is_file($refundState)) unlink($refundState);
}

$ownershipState = tempnam(sys_get_temp_dir(), 'mercato-ownership-e2e-');
if ($ownershipState === false) throw new RuntimeException('Cannot create ownership-boundary fixture state file.');
unlink($ownershipState);
$ownershipArtifacts = $artifacts . '/ownership-boundaries';
if (!is_dir($ownershipArtifacts) && !mkdir($ownershipArtifacts, 0775, true) && !is_dir($ownershipArtifacts)) throw new RuntimeException('Cannot create ownership-boundary artifacts directory.');
$ownershipEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$ownershipState, 'MERCATO_E2E_ARTIFACTS'=>$ownershipArtifacts, 'MERCATO_E2E_PROFILE'=>'ownership-boundaries']);
$ownershipFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/ownership-boundaries-fixtures.php']);
try {
    if (runAcceptance('ownership_boundary_fixture_setup', array_merge($ownershipFixture, ['setup']), 'isolated owner, second customer, unauthorized staff, paid orders, and expired signed routes are ready', $ownershipEnv, $root) !== 0) throw new RuntimeException('Ownership-boundary fixture setup failed.');
    $ownershipBrowserFailed = runAcceptance('ownership_boundary_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'anonymous signed-route privacy, account/order isolation, mutation denial, and staff denial pass', $ownershipEnv, $root) !== 0;
    $failed = $ownershipBrowserFailed || $failed;
    if (!$ownershipBrowserFailed) $failed = runAcceptance('ownership_boundary_persisted_state', array_merge($ownershipFixture, ['verify']), 'order ownership/payment and victim profile remain unchanged while one self-owned profile update persists', $ownershipEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'ownership_boundary_runner', 'expected'=>'ownership-boundary profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($ownershipState)) $failed = runAcceptance('ownership_boundary_fixture_cleanup', array_merge($ownershipFixture, ['cleanup']), 'only the exact orders, users, role, and configuration changes are removed', $ownershipEnv, $root) !== 0 || $failed;
    if (is_file($ownershipState)) unlink($ownershipState);
}

$guestState = tempnam(sys_get_temp_dir(), 'mercato-guest-e2e-');
if ($guestState === false) throw new RuntimeException('Cannot create guest checkout fixture state file.');
unlink($guestState);
$guestArtifacts = $artifacts . '/guest-checkout';
if (!is_dir($guestArtifacts) && !mkdir($guestArtifacts, 0775, true) && !is_dir($guestArtifacts)) throw new RuntimeException('Cannot create guest checkout artifacts directory.');
$guestEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$guestState, 'MERCATO_E2E_ARTIFACTS'=>$guestArtifacts, 'MERCATO_E2E_PROFILE'=>'guest-checkout']);
$guestFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/guest-checkout-fixtures.php']);
try {
    if (runAcceptance('guest_checkout_fixture_setup', array_merge($guestFixture, ['setup']), 'isolated physical/digital products, file, Demo configuration, and route policy are ready', $guestEnv, $root) !== 0) throw new RuntimeException('Guest checkout fixture setup failed.');
    $guestBrowserFailed = runAcceptance('guest_checkout_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'physical and digital guest checkout plus signed route/download/payment/access-recovery lifecycle pass', $guestEnv, $root) !== 0;
    $failed = $guestBrowserFailed || $failed;
    if (!$guestBrowserFailed) $failed = runAcceptance('guest_checkout_persisted_state', array_merge($guestFixture, ['verify']), 'two ownerless paid orders, exact stock decrement, one download, expiry, and clean logs persist', $guestEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'guest_checkout_runner', 'expected'=>'guest checkout profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($guestState)) $failed = runAcceptance('guest_checkout_fixture_cleanup', array_merge($guestFixture, ['cleanup']), 'only the exact orders, physical/digital products/files, and configuration changes are removed', $guestEnv, $root) !== 0 || $failed;
    if (is_file($guestState)) unlink($guestState);
}

$presentationState = tempnam(sys_get_temp_dir(), 'mercato-presentation-e2e-');
if ($presentationState === false) throw new RuntimeException('Cannot create presentation-state fixture state file.');
unlink($presentationState);
$presentationArtifacts = $artifacts . '/presentation-states';
if (!is_dir($presentationArtifacts) && !mkdir($presentationArtifacts, 0775, true) && !is_dir($presentationArtifacts)) throw new RuntimeException('Cannot create presentation-state artifacts directory.');
$presentationEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$presentationState, 'MERCATO_E2E_ARTIFACTS'=>$presentationArtifacts, 'MERCATO_E2E_PROFILE'=>'presentation-states']);
$presentationFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/presentation-states-fixtures.php']);
try {
    if (runAcceptance('presentation_state_fixture_setup', array_merge($presentationFixture, ['setup']), 'isolated empty/loading/long/translated/large fixtures and checkout-readiness setting are ready', $presentationEnv, $root) !== 0) throw new RuntimeException('Presentation-state fixture setup failed.');
    $presentationBrowserFailed = runAcceptance('presentation_state_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'catalog, product, account, and admin presentation states pass responsive/accessibility/keyboard gates', $presentationEnv, $root) !== 0;
    $failed = $presentationBrowserFailed || $failed;
    if (!$presentationBrowserFailed) $failed = runAcceptance('presentation_state_persisted_state', array_merge($presentationFixture, ['verify']), 'fixture cardinality, UTF-8 content, ownership/payment, readiness effect, and logs remain exact', $presentationEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'presentation_state_runner', 'expected'=>'presentation-state profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($presentationState)) $failed = runAcceptance('presentation_state_fixture_cleanup', array_merge($presentationFixture, ['cleanup']), 'only the exact products, orders, users, role, and configuration changes are removed', $presentationEnv, $root) !== 0 || $failed;
    if (is_file($presentationState)) unlink($presentationState);
}

$variantState = tempnam(sys_get_temp_dir(), 'mercato-auth-variant-e2e-');
if ($variantState === false) throw new RuntimeException('Cannot create authenticated-variant fixture state file.');
unlink($variantState);
$variantArtifacts = $artifacts . '/authenticated-variant';
if (!is_dir($variantArtifacts) && !mkdir($variantArtifacts, 0775, true) && !is_dir($variantArtifacts)) throw new RuntimeException('Cannot create authenticated-variant artifacts directory.');
$variantEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$variantState, 'MERCATO_E2E_ARTIFACTS'=>$variantArtifacts, 'MERCATO_E2E_PROFILE'=>'authenticated-variant']);
$variantFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/authenticated-variant-fixtures.php']);
try {
    if (runAcceptance('authenticated_variant_fixture_setup', array_merge($variantFixture, ['setup']), 'isolated verified customer and exact multi-option variant are ready', $variantEnv, $root) !== 0) throw new RuntimeException('Authenticated-variant fixture setup failed.');
    $variantBrowserFailed = runAcceptance('authenticated_variant_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'signed-in customer mutates cart quantity, completes Demo checkout, and sees the owned order', $variantEnv, $root) !== 0;
    $failed = $variantBrowserFailed || $failed;
    if (!$variantBrowserFailed) {
        $failed = runAcceptance('authenticated_variant_capture', array_merge($variantFixture, ['capture']), 'the browser-created order is captured by exact customer email', $variantEnv, $root) !== 0 || $failed;
        $failed = runAcceptance('authenticated_variant_persisted_state', array_merge($variantFixture, ['verify']), 'owner, paid state, exact variant snapshot, quantity, inventory, and logs persist', $variantEnv, $root) !== 0 || $failed;
    }
} catch (Throwable $e) {
    $results[] = ['scenario'=>'authenticated_variant_runner', 'expected'=>'authenticated variant profile completes', 'transition'=>'failed', 'exit_code'=>1,'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($variantState)) $failed = runAcceptance('authenticated_variant_fixture_cleanup', array_merge($variantFixture, ['cleanup']), 'only the exact order, user, product, and configuration changes are removed', $variantEnv, $root) !== 0 || $failed;
    if (is_file($variantState)) unlink($variantState);
}

$fulfilmentState = tempnam(sys_get_temp_dir(), 'mercato-fulfilment-e2e-');
if ($fulfilmentState === false) throw new RuntimeException('Cannot create fulfilment-matrix fixture state file.');
unlink($fulfilmentState);
$fulfilmentArtifacts = $artifacts . '/fulfilment-matrix';
if (!is_dir($fulfilmentArtifacts) && !mkdir($fulfilmentArtifacts, 0775, true) && !is_dir($fulfilmentArtifacts)) throw new RuntimeException('Cannot create fulfilment-matrix artifacts directory.');
$fulfilmentEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$fulfilmentState, 'MERCATO_E2E_ARTIFACTS'=>$fulfilmentArtifacts, 'MERCATO_E2E_PROFILE'=>'fulfilment-matrix']);
$fulfilmentFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/fulfilment-matrix-fixtures.php']);
try {
    if (runAcceptance('fulfilment_matrix_fixture_setup', array_merge($fulfilmentFixture, ['setup']), 'three isolated product types, three customers, Demo configuration, and six checkout identities are ready', $fulfilmentEnv, $root) !== 0) throw new RuntimeException('Fulfilment-matrix fixture setup failed.');
    $fulfilmentBrowserFailed = runAcceptance('fulfilment_matrix_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'carrier, pickup, local, digital, service, and mixed guest/customer checkouts pass cache, accessibility, responsive, and browser-error gates', $fulfilmentEnv, $root) !== 0;
    $failed = $fulfilmentBrowserFailed || $failed;
    if (!$fulfilmentBrowserFailed) {
        $failed = runAcceptance('fulfilment_matrix_capture', array_merge($fulfilmentFixture, ['capture']), 'six browser-created paid orders are captured by exact run-owned identity', $fulfilmentEnv, $root) !== 0 || $failed;
        $failed = runAcceptance('fulfilment_matrix_persisted_state', array_merge($fulfilmentFixture, ['verify']), 'owner, product, fulfilment, address, shipping, tax, inventory, and log snapshots persist exactly', $fulfilmentEnv, $root) !== 0 || $failed;
    }
} catch (Throwable $e) {
    $results[] = ['scenario'=>'fulfilment_matrix_runner', 'expected'=>'fulfilment matrix profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($fulfilmentState)) $failed = runAcceptance('fulfilment_matrix_fixture_cleanup', array_merge($fulfilmentFixture, ['cleanup']), 'only the exact six orders, three customers, three products, logs, and configuration changes are removed', $fulfilmentEnv, $root) !== 0 || $failed;
    if (is_file($fulfilmentState)) unlink($fulfilmentState);
}

$crossBrowserState = tempnam(sys_get_temp_dir(), 'mercato-cross-browser-e2e-');
if ($crossBrowserState === false) throw new RuntimeException('Cannot create cross-browser fixture state file.');
unlink($crossBrowserState);
$crossBrowserArtifacts = $artifacts . '/cross-browser-checkout';
if (!is_dir($crossBrowserArtifacts) && !mkdir($crossBrowserArtifacts, 0775, true) && !is_dir($crossBrowserArtifacts)) throw new RuntimeException('Cannot create cross-browser artifacts directory.');
$crossBrowserEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$crossBrowserState, 'MERCATO_E2E_ARTIFACTS'=>$crossBrowserArtifacts, 'MERCATO_E2E_PROFILE'=>'cross-browser-checkout']);
$crossBrowserFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/fixtures.php']);
try {
    if (runAcceptance('cross_browser_fixture_setup', array_merge($crossBrowserFixture, ['setup']), 'shared deterministic product, expired boundary, and three engine-specific failed orders are ready', $crossBrowserEnv, $root) !== 0) throw new RuntimeException('Cross-browser fixture setup failed.');
    $crossBrowserFailed = runAcceptance('cross_browser_checkout_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'Chromium, Firefox, and WebKit pass validation, checkout success, recovery, and private-document journeys', $crossBrowserEnv, $root) !== 0;
    $failed = $crossBrowserFailed || $failed;
    if (!$crossBrowserFailed) $failed = runAcceptance('cross_browser_persisted_state', array_merge($crossBrowserFixture, ['verify-cross-browser']), 'six unique paid orders, consumed recovery tokens, exact inventory, and unchanged expired boundary persist', $crossBrowserEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'cross_browser_runner', 'expected'=>'cross-browser checkout profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($crossBrowserState)) $failed = runAcceptance('cross_browser_fixture_cleanup', array_merge($crossBrowserFixture, ['cleanup']), 'only run-owned products, discount, users, orders, logs, and configuration are removed', $crossBrowserEnv, $root) !== 0 || $failed;
    if (is_file($crossBrowserState)) unlink($crossBrowserState);
}

$publicVisualState = tempnam(sys_get_temp_dir(), 'mercato-public-visual-e2e-');
if ($publicVisualState === false) throw new RuntimeException('Cannot create public visual fixture state file.');
unlink($publicVisualState);
$publicVisualArtifacts = $artifacts . '/public-visual-states';
if (!is_dir($publicVisualArtifacts) && !mkdir($publicVisualArtifacts, 0775, true) && !is_dir($publicVisualArtifacts)) throw new RuntimeException('Cannot create public visual artifacts directory.');
$publicVisualEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$publicVisualState, 'MERCATO_E2E_ARTIFACTS'=>$publicVisualArtifacts, 'MERCATO_E2E_PROFILE'=>'public-visual-states']);
$publicVisualFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/public-visual-fixtures.php']);
try {
    if (runAcceptance('public_visual_fixture_setup', array_merge($publicVisualFixture, ['setup']), '15-screen public/storefront visual graph with long, translated, large, empty, error, and private-route states is ready', $publicVisualEnv, $root) !== 0) throw new RuntimeException('Public visual fixture setup failed.');
    $publicVisualFailed = runAcceptance('public_visual_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], '15 public screens produce desktop/mobile screenshots and pass axe, overflow, console, and network gates', $publicVisualEnv, $root) !== 0;
    $failed = $publicVisualFailed || $failed;
    if (!$publicVisualFailed) $failed = runAcceptance('public_visual_persisted_state', array_merge($publicVisualFixture, ['verify']), 'checkout payment/inventory and all fixture cardinalities persist exactly', $publicVisualEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'public_visual_runner', 'expected'=>'public visual profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($publicVisualState)) $failed = runAcceptance('public_visual_fixture_cleanup', array_merge($publicVisualFixture, ['cleanup']), 'only run-owned orders, quotes, products, pages, users, logs, and configuration are removed', $publicVisualEnv, $root) !== 0 || $failed;
    if (is_file($publicVisualState)) unlink($publicVisualState);
}

$adminVisualState = tempnam(sys_get_temp_dir(), 'mercato-admin-visual-e2e-');
if ($adminVisualState === false) throw new RuntimeException('Cannot create admin visual fixture state file.');
unlink($adminVisualState);
$adminVisualArtifacts = $artifacts . '/admin-visual-breadth';
if (!is_dir($adminVisualArtifacts) && !mkdir($adminVisualArtifacts, 0775, true) && !is_dir($adminVisualArtifacts)) throw new RuntimeException('Cannot create admin visual artifacts directory.');
$adminVisualEnv = array_merge($env, ['MERCATO_E2E_STATE'=>$adminVisualState, 'MERCATO_E2E_ARTIFACTS'=>$adminVisualArtifacts, 'MERCATO_E2E_PROFILE'=>'admin-visual-breadth', 'MERCATO_E2E_ADMIN_VISUAL_BREADTH'=>'1', 'MERCATO_ADMIN_VISUAL_DATASET'=>'normal']);
$adminVisualFixture = array_merge([PHP_BINARY], $phpArgs, [$root.'/tests/e2e/admin-visual-breadth-fixtures.php']);
try {
    if (runAcceptance('admin_visual_fixture_setup', array_merge($adminVisualFixture, ['setup']), '22 HTML admin routes, module settings, long/translated/large data, and isolated least-privilege roles are ready', $adminVisualEnv, $root) !== 0) throw new RuntimeException('Admin visual fixture setup failed.');
    $adminVisualFailed = runAcceptance('admin_visual_browser_journey', ['npx','playwright','test','-c',$root.'/tests/e2e/playwright.config.js'], 'all admin routes and settings pass desktop/mobile, axe, overflow, permission-denial, console, and network gates', $adminVisualEnv, $root) !== 0;
    $failed = $adminVisualFailed || $failed;
    if (!$adminVisualFailed) $failed = runAcceptance('admin_visual_persisted_state', array_merge($adminVisualFixture, ['verify']), 'products, orders, quotes, permissions, config, and logs persist exactly', $adminVisualEnv, $root) !== 0 || $failed;
} catch (Throwable $e) {
    $results[] = ['scenario'=>'admin_visual_runner', 'expected'=>'admin visual profile completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($adminVisualState)) $failed = runAcceptance('admin_visual_fixture_cleanup', array_merge($adminVisualFixture, ['cleanup']), 'only run-owned products, orders, quotes, discount, users, roles, and logs are removed', $adminVisualEnv, $root) !== 0 || $failed;
    if (is_file($adminVisualState)) unlink($adminVisualState);
}

$taxProviderArtifacts = $artifacts . '/tax-providers';
if (!is_dir($taxProviderArtifacts) && !mkdir($taxProviderArtifacts, 0775, true) && !is_dir($taxProviderArtifacts)) throw new RuntimeException('Cannot create tax-provider artifacts directory.');
$taxProviderEnv = array_merge($env, ['MERCATO_E2E_ARTIFACTS'=>$taxProviderArtifacts]);
$failed = runAcceptance('tax_provider_browser_lifecycle', ['bash', $root.'/scripts/run-tax-provider-e2e.sh'], 'Stripe Tax and Quaderno complete real HTTP quote, commit, refund, inventory, private-page, accessibility, and cleanup lifecycles through loopback emulators', $taxProviderEnv, $root) !== 0 || $failed;

$report = ['schema_version'=>1, 'generated_at'=>gmdate(DATE_ATOM), 'base_url'=>$baseUrl, 'live_provider_smoke'=>false, 'result'=>$failed?'failed':'passed', 'scenarios'=>$results, 'diagnostics'=>['playwright_json'=>$artifacts.'/playwright.json', 'playwright_html'=>$artifacts.'/html/index.html', 'admin_playwright_json'=>$adminArtifacts.'/playwright.json', 'admin_playwright_html'=>$adminArtifacts.'/html/index.html', 'customer_lifecycle_playwright_json'=>$lifecycleArtifacts.'/playwright.json', 'customer_lifecycle_playwright_html'=>$lifecycleArtifacts.'/html/index.html', 'refund_playwright_json'=>$refundArtifacts.'/playwright.json', 'refund_playwright_html'=>$refundArtifacts.'/html/index.html', 'ownership_boundary_playwright_json'=>$ownershipArtifacts.'/playwright.json', 'ownership_boundary_playwright_html'=>$ownershipArtifacts.'/html/index.html', 'guest_checkout_playwright_json'=>$guestArtifacts.'/playwright.json', 'guest_checkout_playwright_html'=>$guestArtifacts.'/html/index.html', 'presentation_state_playwright_json'=>$presentationArtifacts.'/playwright.json', 'presentation_state_playwright_html'=>$presentationArtifacts.'/html/index.html', 'authenticated_variant_playwright_json'=>$variantArtifacts.'/playwright.json', 'authenticated_variant_playwright_html'=>$variantArtifacts.'/html/index.html', 'fulfilment_matrix_playwright_json'=>$fulfilmentArtifacts.'/playwright.json', 'fulfilment_matrix_playwright_html'=>$fulfilmentArtifacts.'/html/index.html', 'cross_browser_playwright_json'=>$crossBrowserArtifacts.'/playwright.json', 'cross_browser_playwright_html'=>$crossBrowserArtifacts.'/html/index.html', 'public_visual_playwright_json'=>$publicVisualArtifacts.'/playwright.json', 'public_visual_playwright_html'=>$publicVisualArtifacts.'/html/index.html', 'admin_visual_playwright_json'=>$adminVisualArtifacts.'/playwright.json', 'admin_visual_playwright_html'=>$adminVisualArtifacts.'/html/index.html', 'tax_provider_playwright_json'=>$taxProviderArtifacts.'/playwright.json', 'tax_provider_playwright_html'=>$taxProviderArtifacts.'/html/index.html', 'tax_provider_emulator_log'=>$taxProviderArtifacts.'/emulator.jsonl', 'coverage'=>$root.'/tests/e2e/coverage.json']];
file_put_contents($artifacts.'/acceptance.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$md = "# Mercato acceptance report\n\nResult: **".strtoupper($report['result'])."**  \nGenerated: {$report['generated_at']}  \nTarget: `{$baseUrl}`\n\n| Scenario | Expected | Transition | Exit | Seconds |\n|---|---|---:|---:|---:|\n";
foreach ($results as $r) $md .= '| '.str_replace('|','\\|',$r['scenario']).' | '.str_replace('|','\\|',$r['expected'])." | {$r['transition']} | {$r['exit_code']} | ".($r['duration_seconds']??'—')." |\n";
$md .= "\nDiagnostics: core `playwright.json` / `html/index.html`, dedicated `admin/`, `customer-lifecycle/`, `refund/`, `ownership-boundaries/`, `guest-checkout/`, `presentation-states/`, `authenticated-variant/`, `fulfilment-matrix/`, `cross-browser-checkout/`, `public-visual-states/`, `admin-visual-breadth/`, and `tax-providers/` Playwright reports, emulator JSONL, and `tests/e2e/coverage.json`. Live-provider smoke was not run.\n";
file_put_contents($artifacts.'/acceptance.md', $md); if (is_file($state)) unlink($state);
echo "\nAcceptance report: $artifacts/acceptance.md\n"; exit($failed ? 1 : 0);
