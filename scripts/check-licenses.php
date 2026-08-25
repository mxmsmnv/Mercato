<?php
$root = dirname(__DIR__); $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true); if (!is_array($lock)) { fwrite(STDERR, "A valid composer.lock is required.\n"); exit(1); }
$packages = array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])); $allowed = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC']; $failures = [];
foreach ($packages as $package) {
    $name = (string) ($package['name'] ?? 'unknown'); $licenses = (array) ($package['license'] ?? []);
    if (!$licenses || !array_intersect($licenses, $allowed)) $failures[$name] = $licenses ?: ['unknown'];
}
if ($failures) { foreach ($failures as $name => $licenses) fwrite(STDERR, $name . ': unapproved license ' . implode(' OR ', $licenses) . PHP_EOL); exit(1); }
echo 'Dependency licenses approved: ' . count($packages) . PHP_EOL;
