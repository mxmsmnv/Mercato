<?php
declare(strict_types=1);

use ProcessWire\ProcessWire;

$action = $argv[1] ?? '';
$siteInput = trim((string) getenv('MERCATO_E2E_SITE'));
$guardToken = trim((string) getenv('MERCATO_FRESH_GUARD_TOKEN'));
$confirmation = 'I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE';
if (!in_array($action, ['preflight', 'teardown'], true) || $siteInput === '' || getenv('MERCATO_FRESH_CONFIRM') !== $confirmation) {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/tmp/mercato-fresh-install-<12hex> MERCATO_FRESH_GUARD_TOKEN=<32hex> MERCATO_FRESH_CONFIRM={$confirmation} php admin-visual-disposable-lifecycle.php preflight|teardown\n");
    exit(2);
}
$site = realpath($siteInput);
if (!$site || !preg_match('#/(?:private/)?tmp/mercato-fresh-install-([a-f0-9]{12})$#D', $site, $siteMatch)) throw new RuntimeException('Disposable admin visual site path failed its ownership guard.');
if (!preg_match('/^[a-f0-9]{32}$/D', $guardToken)) throw new RuntimeException('Disposable admin visual guard token failed validation.');
if (!is_file($site . '/site/config.php') || !is_file($site . '/wire/core/ProcessWire.php')) throw new RuntimeException('Disposable admin visual site is incomplete.');

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$databaseName = (string) $config->dbName;
if (!preg_match('/^mercato_codex_fresh_([a-f0-9]{12})$/D', $databaseName, $databaseMatch) || !hash_equals($siteMatch[1], $databaseMatch[1])) throw new RuntimeException('Disposable admin visual database/site suffix mismatch.');
if (!empty($config->production)) throw new RuntimeException('Disposable admin visual site unexpectedly has production mode enabled.');

$sharedSite = realpath((string) (getenv('MERCATO_ADMIN_VISUAL_SHARED_SITE') ?: '/Users/mas/Sites/mercato.dev'));
if ($sharedSite && $sharedSite === $site) throw new RuntimeException('Disposable admin visual site resolves to the shared development site.');
if ($sharedSite && is_file($sharedSite . '/site/config.php') && is_file($sharedSite . '/wire/core/ProcessWire.php')) {
    $sharedConfig = ProcessWire::buildConfig($sharedSite);
    if ((string) $sharedConfig->dbName === $databaseName) throw new RuntimeException('Disposable admin visual database matches the shared development database.');
}

$host = trim((string) ($config->dbHost ?? '127.0.0.1')) ?: '127.0.0.1';
$port = max(1, (int) ($config->dbPort ?? 3306));
$socket = trim((string) ($config->dbSocket ?? ''));
$serverDsn = $socket !== '' ? 'mysql:unix_socket=' . $socket : 'mysql:host=' . $host . ';port=' . $port;
$databaseDsn = $serverDsn . ';dbname=' . $databaseName . ';charset=utf8mb4';
$database = new PDO($databaseDsn, (string) $config->dbUser, (string) $config->dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$storedGuard = (string) $database->query('SELECT token FROM mercato_fresh_install_guard LIMIT 1')->fetchColumn();
if (!hash_equals($guardToken, $storedGuard)) throw new RuntimeException('Disposable admin visual database ownership marker mismatch.');
$moduleCount = (int) $database->query("SELECT COUNT(*) FROM modules WHERE class IN ('Mercato', 'ProcessMercato')")->fetchColumn();
if ($moduleCount !== 2) throw new RuntimeException('Disposable site does not have Mercato and ProcessMercato installed.');

if ($action === 'preflight') {
    echo json_encode([
        'safe' => true, 'site' => $site, 'database' => $databaseName,
        'guard_sha256' => hash('sha256', $guardToken), 'modules' => 2,
        'shared_site_distinct' => true, 'production' => false,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if (getenv('MERCATO_ADMIN_VISUAL_TEARDOWN_CONFIRM') !== 'DROP_ADMIN_VISUAL_DISPOSABLE_SITE') throw new RuntimeException('Set MERCATO_ADMIN_VISUAL_TEARDOWN_CONFIRM=DROP_ADMIN_VISUAL_DISPOSABLE_SITE for teardown.');
$stateFile = trim((string) getenv('MERCATO_E2E_STATE'));
if ($stateFile !== '' && is_file($stateFile)) throw new RuntimeException('Fixture state still exists; run fixture cleanup before disposable teardown.');
$database = null;

$adminUser = trim((string) getenv('MERCATO_FRESH_DB_ADMIN_USER')) ?: (string) $config->dbUser;
$adminPassword = getenv('MERCATO_FRESH_DB_ADMIN_PASS');
$adminPassword = is_string($adminPassword) ? $adminPassword : (string) $config->dbPass;
if (getenv('MERCATO_FRESH_DB_ADMIN_PASS_EMPTY') === '1') $adminPassword = '';
$server = new PDO($serverDsn . ';charset=utf8mb4', $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE `{$databaseName}`");
$check = $server->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name');
$check->execute([':name' => $databaseName]);
if ((int) $check->fetchColumn() !== 0) throw new RuntimeException('Disposable admin visual database still exists after DROP DATABASE.');
$server = null;

$temporaryRoot = realpath('/tmp') ?: realpath(sys_get_temp_dir());
if (!$temporaryRoot || dirname($site) !== $temporaryRoot || !preg_match('/^mercato-fresh-install-[a-f0-9]{12}$/D', basename($site))) throw new RuntimeException('Disposable site failed the final filesystem ownership guard.');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) {
    $path = $item->getPathname();
    if ($item->isLink() || $item->isFile()) { if (!unlink($path)) throw new RuntimeException("Could not remove disposable file: {$path}"); }
    elseif (!rmdir($path)) throw new RuntimeException("Could not remove disposable directory: {$path}");
}
if (!rmdir($site) || file_exists($site)) throw new RuntimeException('Disposable admin visual site root still exists after teardown.');
echo json_encode(['cleaned' => true, 'database_dropped' => $databaseName, 'site_removed' => $site], JSON_UNESCAPED_SLASHES) . "\n";
