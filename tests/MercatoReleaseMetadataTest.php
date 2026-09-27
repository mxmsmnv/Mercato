<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expected = (string) ($argv[1] ?? '');
$package = json_decode((string) file_get_contents($root . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($root . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$version = (string) ($package['version'] ?? '');
if ($expected !== '' && $version !== $expected) throw new RuntimeException("Release argument {$expected} does not match package version {$version}.");
if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $parts)) throw new RuntimeException("Package version {$version} is not a stable semantic version.");
$moduleVersion = ((int) $parts[1] * 100) + ((int) $parts[2] * 10) + (int) $parts[3];
$module = (string) file_get_contents($root . '/Mercato.module.php');
$process = (string) file_get_contents($root . '/ProcessMercato.module.php');
$changelog = (string) file_get_contents($root . '/CHANGELOG.md');
if (!str_contains($module, "@version {$version} (module info version: {$moduleVersion})") || !str_contains($module, "public const MODULE_VERSION = {$moduleVersion};")) throw new RuntimeException('Mercato module metadata does not match package version.');
if (!str_contains($process, "'version' => {$moduleVersion},")) throw new RuntimeException('ProcessMercato metadata does not match package version.');
if (($lock['version'] ?? null) !== $version || ($lock['packages']['']['version'] ?? null) !== $version) throw new RuntimeException('Package lock metadata does not match package version.');
if (!str_contains($changelog, "## [{$version}]")) throw new RuntimeException('Changelog has no entry for the package version.');
echo "Mercato release metadata is aligned at {$version}/{$moduleVersion}.\n";
