<?php
namespace ProcessWire;
require_once dirname(__DIR__) . '/src/Deployment/MercatoRuntimeCompatibility.php';
$old = MercatoRuntimeCompatibility::report([], '3.0.100'); if (empty($old['errors']) || $old['ready']) throw new \RuntimeException('Unsupported ProcessWire version was accepted.');
$missingStripe = MercatoRuntimeCompatibility::report(['stripe-card'], '3.0.246');
if (!class_exists('Stripe\\StripeClient') && $missingStripe['ready']) throw new \RuntimeException('Missing enabled Stripe dependency was accepted.');
$manual = MercatoRuntimeCompatibility::report(['bank-transfer'], '3.0.246'); if (!$manual['ready']) throw new \RuntimeException('Dependency-free payment configuration was rejected: ' . implode(' ', $manual['errors']));
echo "Mercato runtime compatibility tests passed.\n";
