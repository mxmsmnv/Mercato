<?php
$root = dirname(__DIR__); $required = ['Mercato.module.php', 'ProcessMercato.module.php', 'MercatoGatewayInterface.php', 'install/install.php', 'templates/mrc-checkout.php', 'vendor/autoload.php', 'vendor/composer/installed.php']; $missing = [];
foreach ($required as $file) if (!is_file($root . '/' . $file)) $missing[] = $file;
if ($missing) { fwrite(STDERR, 'Missing release runtime files: ' . implode(', ', $missing) . PHP_EOL); exit(1); }
require $root . '/vendor/autoload.php';
if (!class_exists('Stripe\\StripeClient')) { fwrite(STDERR, "Stripe SDK is not autoloadable.\n"); exit(1); }
if (in_array('--release', $argv, true)) {
    $forbidden = ['.env', '.DS_Store', '.mercato-local', '.git', '.github', 'tests']; foreach ($forbidden as $name) if (file_exists($root . '/' . $name)) { fwrite(STDERR, "Forbidden release path present: $name\n"); exit(1); }
}
echo "Mercato runtime manifest verified.\n";
