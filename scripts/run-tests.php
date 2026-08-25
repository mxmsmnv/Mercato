<?php
$root = dirname(__DIR__); $unitOnly = in_array('--unit', $argv, true); $failures = 0;
$phpOptions = '';
$mysqlSocket = trim((string) getenv('MERCATO_MYSQL_SOCKET'));
if ($mysqlSocket !== '') {
    $phpOptions = ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $mysqlSocket)
        . ' -d ' . escapeshellarg('mysqli.default_socket=' . $mysqlSocket);
}
foreach (glob($root . '/tests/*Test.php') ?: [] as $test) {
    if ($unitOnly && str_contains(basename($test), 'IntegrationTest')) continue;
    passthru(escapeshellarg(PHP_BINARY) . $phpOptions . ' ' . escapeshellarg($test), $status); if ($status !== 0) $failures++;
}
exit($failures === 0 ? 0 : 1);
