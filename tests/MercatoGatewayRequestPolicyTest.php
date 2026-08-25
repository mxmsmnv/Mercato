<?php
require_once __DIR__ . '/../src/Gateway/MercatoGatewayRequestPolicy.php';
use ProcessWire\MercatoGatewayRequestPolicy;
$calls = 0; $result = MercatoGatewayRequestPolicy::run(static function () use (&$calls): array { $calls++; if ($calls < 3) throw new RuntimeException('transient'); return ['ok' => true]; }, 2, 1);
if (empty($result['ok']) || $calls !== 3) throw new RuntimeException('Gateway retry policy failed.');
$timedOut = false; try { MercatoGatewayRequestPolicy::run(static function (): array { usleep(20000); return []; }, 0, 0.01); } catch (RuntimeException $e) { $timedOut = str_contains($e->getMessage(), 'timed out'); }
if (!$timedOut) throw new RuntimeException('Gateway timeout policy failed.');
echo "Mercato gateway request policy tests passed.\n";
