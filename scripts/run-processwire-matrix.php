<?php
declare(strict_types=1);

const MERCATO_PROCESSWIRE_MATRIX = [
    '3.0.200' => '3acd7709c1cfc1817579db00c2f608235bdfb1e7',
    '3.0.246' => '44fcf13ea2d7f14a04eed54c29afcc79eb46ec45',
    '3.0.265' => 'c334bc57c328a20e9bb8f6ef6bc83db6bceda614',
];

$root = dirname(__DIR__);
$requested = trim((string) getenv('MERCATO_PROCESSWIRE_MATRIX'));
$versions = $requested === ''
    ? array_keys(MERCATO_PROCESSWIRE_MATRIX)
    : array_values(array_filter(array_map('trim', explode(',', $requested)), 'strlen'));
if (!$versions) {
    fwrite(STDERR, "No ProcessWire matrix versions were selected.\n");
    exit(2);
}
foreach ($versions as $version) {
    if (!isset(MERCATO_PROCESSWIRE_MATRIX[$version])) {
        fwrite(STDERR, "Unsupported ProcessWire matrix target: $version\n");
        exit(2);
    }
}

$failures = [];
foreach ($versions as $version) {
    $startedAt = microtime(true);
    echo "\n== ProcessWire $version fresh boundary ==\n";
    $environment = array_merge(getenv(), [
        'MERCATO_FRESH_PROCESSWIRE_SOURCE' => '',
        'MERCATO_FRESH_PROCESSWIRE_COMMIT' => MERCATO_PROCESSWIRE_MATRIX[$version],
        'MERCATO_FRESH_EXPECTED_PROCESSWIRE_VERSION' => $version,
        // proc_open may omit an empty environment value. Preserve an intentional
        // blank local administrator password without storing any credential.
        'MERCATO_FRESH_DB_ADMIN_PASS_EMPTY' => getenv('MERCATO_FRESH_DB_ADMIN_PASS') === '' ? '1' : '0',
    ]);
    $process = proc_open(
        [PHP_BINARY, $root . '/scripts/run-fresh-install.php'],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
        $environment
    );
    $status = is_resource($process) ? proc_close($process) : 127;
    printf("ProcessWire %s matrix result: %s (%.2fs)\n", $version, $status === 0 ? 'passed' : 'failed', microtime(true) - $startedAt);
    if ($status !== 0) $failures[$version] = $status;
}

if ($failures) {
    fwrite(STDERR, 'ProcessWire matrix failures: ' . json_encode($failures, JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
echo "ProcessWire fresh-install compatibility matrix passed: " . implode(', ', $versions) . ".\n";
