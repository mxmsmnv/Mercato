<?php
declare(strict_types=1);
$root = dirname(__DIR__); $manifest = json_decode((string) file_get_contents($root.'/tests/e2e/coverage.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['schema_version'] ?? null) !== 1) throw new RuntimeException('Unsupported acceptance coverage schema.');
$paths = [];
foreach ((array) ($manifest['scenarios'] ?? []) as $references) foreach ((array) $references as $reference) $paths[] = $reference;
foreach ($paths as $path) if (!is_file($root.'/'.$path)) throw new RuntimeException("Missing acceptance scenario reference: $path");
if (count($manifest['browser_contract']['engines'] ?? []) < 3 || count($manifest['browser_contract']['viewports'] ?? []) < 3) throw new RuntimeException('Browser contract is incomplete.');
echo 'Acceptance contract valid: '.count($manifest['scenarios'])." scenario groups.\n";
