<?php
declare(strict_types=1);

const MERCATO_FRESH_DEFAULT_PROCESSWIRE_COMMIT = 'c334bc57c328a20e9bb8f6ef6bc83db6bceda614';
const MERCATO_FRESH_DEFAULT_PROCESSWIRE_VERSION = '3.0.265';
const MERCATO_FRESH_PROCESSWIRE_URL = 'https://github.com/processwire/processwire.git';
const MERCATO_FRESH_CONFIRMATION = 'I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE';

$moduleRoot = dirname(__DIR__);
if ((string) getenv('MERCATO_FRESH_CONFIRM') !== MERCATO_FRESH_CONFIRMATION) {
    fwrite(STDERR, "Set MERCATO_FRESH_CONFIRM=" . MERCATO_FRESH_CONFIRMATION . " to authorize the guarded disposable database lifecycle.\n");
    exit(2);
}
$processWireCommit = trim((string) (getenv('MERCATO_FRESH_PROCESSWIRE_COMMIT') ?: MERCATO_FRESH_DEFAULT_PROCESSWIRE_COMMIT));
$expectedProcessWireVersion = trim((string) (getenv('MERCATO_FRESH_EXPECTED_PROCESSWIRE_VERSION') ?: MERCATO_FRESH_DEFAULT_PROCESSWIRE_VERSION));
if (!preg_match('/^[a-f0-9]{40}$/D', $processWireCommit) || !preg_match('/^3\.0\.[0-9]+$/D', $expectedProcessWireVersion)) {
    fwrite(STDERR, "ProcessWire commit/version overrides failed validation.\n");
    exit(2);
}

$configSiteInput = trim((string) getenv('MERCATO_FRESH_DB_CONFIG_SITE'));
if ($configSiteInput !== '') {
    $configSite = realpath($configSiteInput);
    if (!$configSite || !is_file($configSite . '/site/config.php') || !is_file($configSite . '/wire/core/ProcessWire.php')) {
        fwrite(STDERR, "MERCATO_FRESH_DB_CONFIG_SITE is not a complete local ProcessWire site.\n");
        exit(2);
    }
    require $configSite . '/wire/core/ProcessWire.php';
    $sourceConfig = \ProcessWire\ProcessWire::buildConfig($configSite);
    if (!empty($sourceConfig->production)) {
        fwrite(STDERR, "Refusing to derive disposable database credentials from a production-mode ProcessWire site.\n");
        exit(2);
    }
} else {
    $directDatabaseUser = getenv('MERCATO_FRESH_DB_USER');
    if (!is_string($directDatabaseUser) || trim($directDatabaseUser) === '') {
        fwrite(STDERR, "Set MERCATO_FRESH_DB_CONFIG_SITE or explicit MERCATO_FRESH_DB_HOST, MERCATO_FRESH_DB_PORT, MERCATO_FRESH_DB_USER, and MERCATO_FRESH_DB_PASS values.\n");
        exit(2);
    }
    $sourceConfig = (object) [
        'dbHost' => trim((string) (getenv('MERCATO_FRESH_DB_HOST') ?: '127.0.0.1')),
        'dbPort' => max(1, (int) (getenv('MERCATO_FRESH_DB_PORT') ?: 3306)),
        'dbSocket' => trim((string) getenv('MERCATO_FRESH_DB_SOCKET')),
        'dbUser' => trim($directDatabaseUser),
        'dbPass' => (string) getenv('MERCATO_FRESH_DB_PASS'),
        'production' => false,
    ];
}

$runToken = bin2hex(random_bytes(16));
$suffix = substr($runToken, 0, 12);
$databaseName = 'mercato_codex_fresh_' . $suffix;
$temporaryBase = is_dir('/tmp') ? '/tmp' : rtrim(sys_get_temp_dir(), '/');
$siteRoot = $temporaryBase . '/mercato-fresh-install-' . $suffix;
$databaseCreated = false;
$cleanupComplete = false;
$serverDatabase = null;
$startedAt = microtime(true);
$adminUserOverride = getenv('MERCATO_FRESH_DB_ADMIN_USER');
$adminPasswordOverride = getenv('MERCATO_FRESH_DB_ADMIN_PASS');
$databaseUser = is_string($adminUserOverride) && $adminUserOverride !== '' ? $adminUserOverride : (string) $sourceConfig->dbUser;
$databasePassword = (string) getenv('MERCATO_FRESH_DB_ADMIN_PASS_EMPTY') === '1'
    ? ''
    : (is_string($adminPasswordOverride) ? $adminPasswordOverride : (string) $sourceConfig->dbPass);

if (!preg_match('/^mercato_codex_fresh_[a-f0-9]{12}$/D', $databaseName)) {
    throw new RuntimeException('Generated database name failed its ownership guard.');
}
if (!preg_match('#/(?:private/)?tmp/mercato-fresh-install-[a-f0-9]{12}$#D', $siteRoot)) {
    throw new RuntimeException('Generated site path failed its ownership guard.');
}
if (file_exists($siteRoot) || !mkdir($siteRoot, 0700, false)) {
    throw new RuntimeException('Could not create the unique disposable site directory.');
}

/** @return array{0: string, 1: string, 2: string} */
function databaseConnectionData(object $config, string $database = ''): array {
    $host = trim((string) ($config->dbHost ?? '127.0.0.1')) ?: '127.0.0.1';
    $port = max(1, (int) ($config->dbPort ?? 3306));
    $socket = trim((string) ($config->dbSocket ?? ''));
    $dsn = $socket !== ''
        ? 'mysql:unix_socket=' . $socket
        : 'mysql:host=' . $host . ';port=' . $port;
    if ($database !== '') $dsn .= ';dbname=' . $database;
    $dsn .= ';charset=utf8mb4';
    return [$dsn, (string) $config->dbUser, (string) $config->dbPass];
}

function runFreshCommand(array $command, string $cwd, array $environment = []): void {
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        array_merge(getenv(), $environment)
    );
    if (!is_resource($process)) throw new RuntimeException('Could not start command: ' . (string) ($command[0] ?? 'unknown'));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($stdout !== '') echo $stdout;
    if ($stderr !== '') fwrite(STDERR, $stderr);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('Command failed with exit %d: %s', $status, implode(' ', array_map('strval', $command))));
    }
}

/** @return array{status: int, body: string} */
function legacyInstallerRequest(string $url, array $post = []): array {
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Could not initialize the legacy ProcessWire installer request.');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Host: mercato-fresh.invalid'],
    ]);
    if ($post) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    if (!is_string($body)) throw new RuntimeException('Legacy ProcessWire installer HTTP request failed: ' . $error);
    return ['status' => $status, 'body' => $body];
}

function runLegacyProcessWireInstaller(string $siteRoot, array $config): void {
    $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($listener)) throw new RuntimeException("Could not allocate a loopback installer port: $errorMessage ($errorNumber)");
    $address = (string) stream_socket_get_name($listener, false);
    fclose($listener);
    $separator = strrpos($address, ':');
    $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
    if ($port < 1) throw new RuntimeException('Could not determine the loopback installer port.');

    $stdoutPath = tempnam(sys_get_temp_dir(), 'mercato-pw-installer-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'mercato-pw-installer-err-');
    if ($stdoutPath === false || $stderrPath === false) throw new RuntimeException('Could not allocate legacy installer logs.');
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', "127.0.0.1:$port", '-t', $siteRoot],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdoutPath, 'a'], 2 => ['file', $stderrPath, 'a']],
        $pipes,
        $siteRoot
    );
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        throw new RuntimeException('Could not start the legacy ProcessWire installer server.');
    }

    $url = "http://127.0.0.1:$port/install.php";
    try {
        $ready = false;
        $deadline = microtime(true) + 5.0;
        do {
            try {
                $response = legacyInstallerRequest($url);
                $ready = $response['status'] === 200;
            } catch (Throwable) {
                usleep(50000);
            }
        } while (!$ready && microtime(true) < $deadline);
        if (!$ready) throw new RuntimeException('Legacy ProcessWire installer server did not become ready.');

        $profile = legacyInstallerRequest($url, ['step' => '0', 'profile' => 'site-blank']);
        if ($profile['status'] !== 200 || !is_dir($siteRoot . '/site/install')) {
            throw new RuntimeException('Legacy ProcessWire installer did not activate the blank site profile.');
        }

        $database = legacyInstallerRequest($url, [
            'step' => '4',
            'dbUser' => (string) $config['dbUser'],
            'dbName' => (string) $config['dbName'],
            'dbPass' => (string) $config['dbPass'],
            'dbHost' => (string) $config['dbHost'],
            'dbPort' => (string) $config['dbPort'],
            'dbEngine' => 'InnoDB',
            'dbCharset' => 'utf8mb4',
            'dbTablesAction' => 'ignore',
            'chmodDir' => '755',
            'chmodFile' => '644',
            'timezone' => '0',
            'httpHosts' => "mercato-fresh.invalid\n127.0.0.1",
            'debugMode' => '0',
        ]);
        if ($database['status'] !== 200
            || !is_file($siteRoot . '/site/config.php')
            || !str_contains($database['body'], 'Imported database file')) {
            throw new RuntimeException('Legacy ProcessWire installer did not import the blank profile database.');
        }

        $account = legacyInstallerRequest($url, [
            'step' => '5',
            'username' => (string) $config['username'],
            'userpass' => (string) $config['userpass'],
            'userpass_confirm' => (string) $config['userpass'],
            'useremail' => (string) $config['useremail'],
            'admin_name' => (string) $config['admin_name'],
        ]);
        if ($account['status'] !== 200
            || !is_file($siteRoot . '/site/assets/installed.php')
            || !str_contains($account['body'], 'User account saved')) {
            throw new RuntimeException('Legacy ProcessWire installer did not create the isolated admin account.');
        }
        echo "Legacy ProcessWire web installer completed over an isolated loopback server.\n";
    } finally {
        proc_terminate($process);
        $deadline = microtime(true) + 3.0;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        $status = proc_get_status($process);
        if ($status['running']) proc_terminate($process, 9);
        proc_close($process);
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}

function runFreshAdminVisualProfile(string $siteRoot, string $runToken, string $moduleRoot, string $sharedSite = ''): void {
    $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($listener)) throw new RuntimeException("Could not allocate an admin visual loopback port: $errorMessage ($errorNumber)");
    $address = (string) stream_socket_get_name($listener, false);
    fclose($listener);
    $separator = strrpos($address, ':');
    $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
    if ($port < 1) throw new RuntimeException('Could not determine the admin visual loopback port.');

    $stateFile = (realpath('/tmp') ?: sys_get_temp_dir()) . '/mercato-admin-empty-' . substr($runToken, 0, 12) . '.json';
    $stdoutPath = tempnam(sys_get_temp_dir(), 'mercato-admin-visual-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'mercato-admin-visual-err-');
    if ($stdoutPath === false || $stderrPath === false) throw new RuntimeException('Could not allocate admin visual server logs.');
    $environment = array_merge(getenv(), [
        'MERCATO_E2E_SITE' => $siteRoot,
        'MERCATO_E2E_STATE' => $stateFile,
        'MERCATO_E2E_BASE_URL' => "http://127.0.0.1:$port",
        'MERCATO_E2E_PROFILE' => 'admin-visual-breadth',
        'MERCATO_E2E_ADMIN_VISUAL_BREADTH' => '1',
        'MERCATO_ADMIN_VISUAL_DATASET' => 'empty',
        'MERCATO_ADMIN_VISUAL_EMPTY_ISOLATED' => '1',
        'MERCATO_FRESH_CONFIRM' => MERCATO_FRESH_CONFIRMATION,
        'MERCATO_FRESH_GUARD_TOKEN' => $runToken,
        'MERCATO_ADMIN_VISUAL_SHARED_SITE' => $sharedSite,
    ]);
    $server = null;
    $failure = null;
    try {
        runFreshCommand([PHP_BINARY, $moduleRoot . '/tests/e2e/admin-visual-disposable-lifecycle.php', 'preflight'], $moduleRoot, $environment);
        runFreshCommand([PHP_BINARY, $moduleRoot . '/tests/e2e/admin-visual-breadth-fixtures.php', 'setup'], $moduleRoot, $environment);
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-S', "127.0.0.1:$port", $moduleRoot . '/tests/e2e/admin-visual-disposable-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdoutPath, 'a'], 2 => ['file', $stderrPath, 'a']],
            $pipes,
            $siteRoot,
            $environment
        );
        if (!is_resource($server)) throw new RuntimeException('Could not start the disposable admin visual server.');
        $ready = false;
        $deadline = microtime(true) + 10.0;
        do {
            $handle = curl_init("http://127.0.0.1:$port/");
            if ($handle !== false) {
                curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
                curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $ready = $status >= 200 && $status < 500;
            }
            if (!$ready) usleep(100000);
        } while (!$ready && microtime(true) < $deadline);
        if (!$ready) throw new RuntimeException('Disposable admin visual server did not become ready.');
        runFreshCommand(['npx', 'playwright', 'test', '-c', 'tests/e2e/playwright.config.js', '--project=chromium-desktop'], $moduleRoot, $environment);
        runFreshCommand([PHP_BINARY, $moduleRoot . '/tests/e2e/admin-visual-breadth-fixtures.php', 'verify'], $moduleRoot, $environment);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        if (is_file($stateFile)) {
            try { runFreshCommand([PHP_BINARY, $moduleRoot . '/tests/e2e/admin-visual-breadth-fixtures.php', 'cleanup'], $moduleRoot, $environment); }
            catch (Throwable $cleanupError) { $failure = $failure ?? $cleanupError; }
        }
        if (is_resource($server)) {
            proc_terminate($server);
            $deadline = microtime(true) + 3.0;
            do {
                $status = proc_get_status($server);
                if (!$status['running']) break;
                usleep(50000);
            } while (microtime(true) < $deadline);
            $status = proc_get_status($server);
            if ($status['running']) proc_terminate($server, 9);
            proc_close($server);
        }
        @unlink($stateFile);
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
    if ($failure instanceof Throwable) throw $failure;
    echo "Disposable empty-admin visual profile passed with exact fixture cleanup.\n";
}

function removeOwnedFreshDirectory(string $path): void {
    $real = realpath($path);
    $temporaryRoot = realpath(is_dir('/tmp') ? '/tmp' : sys_get_temp_dir());
    if (!$real || !$temporaryRoot || dirname($real) !== $temporaryRoot || !preg_match('/^mercato-fresh-install-[a-f0-9]{12}$/D', basename($real))) {
        throw new RuntimeException('Refusing to remove a directory that failed the disposable-site ownership guard.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $pathName = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($pathName)) throw new RuntimeException("Could not remove disposable file: $pathName");
        } elseif (!rmdir($pathName)) {
            throw new RuntimeException("Could not remove disposable directory: $pathName");
        }
    }
    if (!rmdir($real)) throw new RuntimeException('Could not remove the disposable site root.');
}

try {
    [$serverDsn] = databaseConnectionData($sourceConfig);
    $serverDatabase = new PDO($serverDsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $existing = $serverDatabase->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name');
    $existing->execute([':name' => $databaseName]);
    if ($existing->fetchColumn() !== false) throw new RuntimeException('Generated disposable database name already exists.');
    try {
        $serverDatabase->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $error) {
        throw new RuntimeException(
            'The configured database account cannot create a disposable database. ' .
            'Provide MERCATO_FRESH_DB_ADMIN_USER and MERCATO_FRESH_DB_ADMIN_PASS for a local non-production database administrator. ' .
            'MySQL error: ' . (string) ($error->errorInfo[1] ?? $error->getCode()),
            0,
            $error
        );
    }
    $databaseCreated = true;

    [$ownedDsn] = databaseConnectionData($sourceConfig, $databaseName);
    $ownedDatabase = new PDO($ownedDsn, $databaseUser, $databasePassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ownedDatabase->exec('CREATE TABLE mercato_fresh_install_guard (token CHAR(32) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $marker = $ownedDatabase->prepare('INSERT INTO mercato_fresh_install_guard (token) VALUES (:token)');
    $marker->execute([':token' => $runToken]);
    $ownedDatabase = null;

    $processWireSource = trim((string) getenv('MERCATO_FRESH_PROCESSWIRE_SOURCE'));
    if ($processWireSource !== '') {
        $processWireSource = (string) realpath($processWireSource);
        if ($processWireSource === '' || !is_file($processWireSource . '/install.php') || !is_dir($processWireSource . '/site-blank')) {
            throw new RuntimeException('MERCATO_FRESH_PROCESSWIRE_SOURCE is not a complete ProcessWire source checkout.');
        }
        if (is_dir($processWireSource . '/.git')) {
            $sourceCommit = trim((string) shell_exec('git -C ' . escapeshellarg($processWireSource) . ' rev-parse HEAD 2>/dev/null'));
            if ($sourceCommit !== $processWireCommit) {
                throw new RuntimeException("Local ProcessWire source is not the pinned $expectedProcessWireVersion commit.");
            }
            $sourceStatus = trim((string) shell_exec('git -C ' . escapeshellarg($processWireSource) . ' status --porcelain 2>/dev/null'));
            if ($sourceStatus !== '') {
                throw new RuntimeException('Local ProcessWire source has uncommitted or untracked files; refusing a non-repeatable fresh install.');
            }
        }
        runFreshCommand(['rsync', '-a', '--exclude=/.git/', $processWireSource . '/', $siteRoot . '/'], $moduleRoot);
    } else {
        runFreshCommand(['git', '-C', $siteRoot, 'init', '--quiet'], $moduleRoot);
        runFreshCommand(['git', '-C', $siteRoot, 'remote', 'add', 'origin', MERCATO_FRESH_PROCESSWIRE_URL], $moduleRoot);
        runFreshCommand(['git', '-C', $siteRoot, 'fetch', '--quiet', '--depth', '1', 'origin', $processWireCommit], $moduleRoot);
        runFreshCommand(['git', '-C', $siteRoot, 'checkout', '--quiet', '--detach', 'FETCH_HEAD'], $moduleRoot);
    }
    $processWireSourceFile = file_get_contents($siteRoot . '/wire/core/ProcessWire.php');
    $expectedRevision = (int) substr(strrchr($expectedProcessWireVersion, '.'), 1);
    if (!is_string($processWireSourceFile)
        || !preg_match('/const versionMajor = 3;/', $processWireSourceFile)
        || !preg_match('/const versionMinor = 0;/', $processWireSourceFile)
        || !preg_match('/const versionRevision = ' . $expectedRevision . ';/', $processWireSourceFile)) {
        throw new RuntimeException("Pinned ProcessWire source did not identify itself as version $expectedProcessWireVersion.");
    }

    $installerConfigPath = $siteRoot . '/install-config.php';
    $installerConfig = [
        'profile' => 'site-blank',
        'dbName' => $databaseName,
        'dbUser' => $databaseUser,
        'dbPass' => $databasePassword,
        'dbHost' => (string) ($sourceConfig->dbHost ?? '127.0.0.1'),
        'dbPort' => (int) ($sourceConfig->dbPort ?? 3306),
        'dbSocket' => (string) ($sourceConfig->dbSocket ?? ''),
        'dbCon' => trim((string) ($sourceConfig->dbSocket ?? '')) !== '' ? 'Socket' : 'Hostname',
        'dbEngine' => 'InnoDB',
        'dbCharset' => 'utf8mb4',
        'dbTablesAction' => 'ignore',
        'timezone' => 'America/New_York',
        'httpHosts' => ['mercato-fresh.invalid', '127.0.0.1'],
        'debugMode' => 0,
        'admin_name' => 'processwire',
        'username' => 'fresh-admin',
        'useremail' => '',
        'userpass' => bin2hex(random_bytes(16)),
        'extraConfig' => ['production' => false],
    ];
    $installerSource = (string) file_get_contents($siteRoot . '/install.php');
    if (str_contains($installerSource, 'class InstallerCli')) {
        $installerConfigPhp = "<?php\nreturn " . var_export($installerConfig, true) . ";\n";
        if (file_put_contents($installerConfigPath, $installerConfigPhp, LOCK_EX) === false || !chmod($installerConfigPath, 0600)) {
            throw new RuntimeException('Could not write the protected ProcessWire installer configuration.');
        }
        runFreshCommand([PHP_BINARY, 'install.php', '--config', $installerConfigPath], $siteRoot);
        runFreshCommand([PHP_BINARY, 'install.php', '--cleanup'], $siteRoot);
    } else {
        runLegacyProcessWireInstaller($siteRoot, $installerConfig);
        $extraConfig = "\n/** Mercato disposable boundary profile. */\n\$config->production = false;\n";
        if (file_put_contents($siteRoot . '/site/config.php', $extraConfig, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not append the safe disposable-site production flag.');
        }
    }

    $moduleTarget = $siteRoot . '/site/modules/Mercato';
    if (!mkdir($moduleTarget, 0755, true) && !is_dir($moduleTarget)) throw new RuntimeException('Could not create the disposable Mercato module directory.');
    runFreshCommand([
        'rsync', '-a', '--delete',
        '--exclude=/.git/', '--exclude=/.github/', '--exclude=/.claude/', '--exclude=/.mercato-local/',
        '--exclude=/tests/', '--exclude=/tools/', '--exclude=/node_modules/', '--exclude=/artifacts/', '--exclude=/dist/',
        '--exclude=/AGENTS.md', '--exclude=/ACCEPTANCE.md', '--exclude=/TESTING.md',
        '--exclude=/scripts/build-release.sh', '--exclude=/scripts/check-licenses.php', '--exclude=/scripts/run-acceptance.php',
        '--exclude=/scripts/run-fresh-install.php', '--exclude=/scripts/run-processwire-matrix.php', '--exclude=/scripts/run-tests.php',
        '--exclude=/scripts/validate-acceptance.php', '--exclude=/.DS_Store', '--exclude=/.gitignore', '--exclude=/phpunit.xml.dist',
        '--exclude=/package.json', '--exclude=/package-lock.json',
        $moduleRoot . '/', $moduleTarget . '/',
    ], $moduleRoot);
    runFreshCommand(['composer', 'install', '--no-dev', '--prefer-dist', '--classmap-authoritative', '--no-interaction', '--no-progress'], $moduleTarget);
    runFreshCommand([PHP_BINARY, 'scripts/verify-runtime.php', '--release'], $moduleTarget);

    $testCommand = [PHP_BINARY];
    if (PHP_VERSION_ID >= 80400 && $expectedRevision < 246) {
        // Old ProcessWire cores predate PHP 8.4's deprecation notices. The supported
        // CI pair runs them on PHP 8.1; keep newer local CLIs focused on Mercato's
        // warnings/errors rather than upstream dynamic-property noise.
        $testCommand[] = '-d';
        $testCommand[] = 'error_reporting=' . (E_ALL & ~E_DEPRECATED);
    }
    $testCommand[] = $moduleRoot . '/tests/MercatoFreshInstallLifecycleIntegrationTest.php';
    runFreshCommand($testCommand, $moduleRoot, [
        'MERCATO_FRESH_SITE' => $siteRoot,
        'MERCATO_FRESH_GUARD_TOKEN' => $runToken,
        'MERCATO_FRESH_CONFIRM' => MERCATO_FRESH_CONFIRMATION,
    ]);
    if (getenv('MERCATO_FRESH_ADMIN_VISUAL') === '1') {
        runFreshAdminVisualProfile($siteRoot, $runToken, $moduleRoot, $configSiteInput !== '' ? (string) $configSite : '');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Fresh-install profile failed: {$error->getMessage()}\n");
    $failure = $error;
} finally {
    try {
        if ($databaseCreated && $serverDatabase instanceof PDO) {
            [$ownedDsn] = databaseConnectionData($sourceConfig, $databaseName);
            $ownedDatabase = new PDO($ownedDsn, $databaseUser, $databasePassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $guard = $ownedDatabase->query('SELECT token FROM mercato_fresh_install_guard LIMIT 1')->fetchColumn();
            if (!is_string($guard) || !hash_equals($runToken, $guard)) {
                throw new RuntimeException('Disposable database ownership token is missing or mismatched; refusing DROP DATABASE.');
            }
            $ownedDatabase = null;
            $serverDatabase->exec("DROP DATABASE `$databaseName`");
            $check = $serverDatabase->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name');
            $check->execute([':name' => $databaseName]);
            if ($check->fetchColumn() !== false) throw new RuntimeException('Disposable database still exists after DROP DATABASE.');
        }
        if (is_dir($siteRoot)) removeOwnedFreshDirectory($siteRoot);
        $cleanupComplete = !is_dir($siteRoot);
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "Fresh-install cleanup failed: {$cleanupError->getMessage()}\n");
        $failure = $failure ?? $cleanupError;
    }
}

if (isset($failure)) exit(1);
if (!$cleanupComplete) {
    fwrite(STDERR, "Fresh-install profile did not confirm complete cleanup.\n");
    exit(1);
}
printf(
    "Mercato fresh empty-database lifecycle profile passed on ProcessWire %s in %.2f seconds; disposable database and site were removed.\n",
    $expectedProcessWireVersion,
    microtime(true) - $startedAt
);
