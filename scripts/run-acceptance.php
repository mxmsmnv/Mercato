<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$site = rtrim((string) (getenv('MERCATO_E2E_SITE') ?: getenv('MERCATO_TEST_SITE')), '/');
if ($site === '') { fwrite(STDERR, "Set MERCATO_E2E_SITE to a non-production ProcessWire installation.\n"); exit(2); }
$baseUrl = (string) (getenv('MERCATO_E2E_BASE_URL') ?: 'https://mercato.dev');
$artifacts = (string) (getenv('MERCATO_E2E_ARTIFACTS') ?: $root . '/artifacts/e2e');
if (!is_dir($artifacts) && !mkdir($artifacts, 0775, true) && !is_dir($artifacts)) throw new RuntimeException('Cannot create artifacts directory.');
$state = tempnam(sys_get_temp_dir(), 'mercato-e2e-'); if ($state === false) throw new RuntimeException('Cannot create fixture state file.'); unlink($state);
$phpArgs = ['-d', 'pdo_mysql.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock', '-d', 'mysqli.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock'];
$localTls = parse_url($baseUrl, PHP_URL_HOST) === 'mercato.dev' ? '1' : (string) (getenv('MERCATO_E2E_IGNORE_HTTPS_ERRORS') ?: '0');
$env = array_merge(getenv(), ['MERCATO_E2E_SITE'=>$site, 'MERCATO_TEST_SITE'=>$site, 'MERCATO_E2E_STATE'=>$state, 'MERCATO_E2E_BASE_URL'=>$baseUrl, 'MERCATO_E2E_ARTIFACTS'=>$artifacts, 'MERCATO_E2E_IGNORE_HTTPS_ERRORS'=>$localTls, 'MERCATO_MYSQL_SOCKET'=>'/Applications/MAMP/tmp/mysql/mysql.sock']);
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
} catch (Throwable $e) {
    $results[] = ['scenario'=>'runner', 'expected'=>'suite completes', 'transition'=>'failed', 'exit_code'=>1, 'diagnostic'=>$e->getMessage()]; $failed = true;
} finally {
    if (is_file($state)) $failed = runAcceptance('fixture_cleanup', array_merge($fixture, ['cleanup']), 'only run-owned records are deleted and config restored', $env, $root) !== 0 || $failed;
}
$report = ['schema_version'=>1, 'generated_at'=>gmdate(DATE_ATOM), 'base_url'=>$baseUrl, 'live_provider_smoke'=>false, 'result'=>$failed?'failed':'passed', 'scenarios'=>$results, 'diagnostics'=>['playwright_json'=>$artifacts.'/playwright.json', 'playwright_html'=>$artifacts.'/html/index.html', 'coverage'=>$root.'/tests/e2e/coverage.json']];
file_put_contents($artifacts.'/acceptance.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$md = "# Mercato acceptance report\n\nResult: **".strtoupper($report['result'])."**  \nGenerated: {$report['generated_at']}  \nTarget: `{$baseUrl}`\n\n| Scenario | Expected | Transition | Exit | Seconds |\n|---|---|---:|---:|---:|\n";
foreach ($results as $r) $md .= '| '.str_replace('|','\\|',$r['scenario']).' | '.str_replace('|','\\|',$r['expected'])." | {$r['transition']} | {$r['exit_code']} | ".($r['duration_seconds']??'—')." |\n";
$md .= "\nDiagnostics: `playwright.json`, `html/index.html`, and `tests/e2e/coverage.json`. Live-provider smoke was not run.\n";
file_put_contents($artifacts.'/acceptance.md', $md); if (is_file($state)) unlink($state);
echo "\nAcceptance report: $artifacts/acceptance.md\n"; exit($failed ? 1 : 0);
