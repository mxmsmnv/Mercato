<?php
require_once __DIR__ . '/../src/Fulfilment/MercatoShippingQuote.php';
use ProcessWire\MercatoShippingQuote;

$rates = MercatoShippingQuote::normalizeRates([
    ['id' => 'slow', 'service' => 'standard', 'label' => 'Standard', 'amount' => 12.345, 'currency' => 'usd', 'delivery_days_min' => 3, 'delivery_days_max' => 5],
    ['id' => 'fast', 'service' => 'express', 'amount' => 20, 'currency' => 'USD', 'expires_at' => '2030-01-01T00:00:00+00:00'],
    ['id' => '', 'service' => 'invalid', 'amount' => 1],
    ['id' => 'negative', 'service' => 'invalid', 'amount' => -1],
], ['provider' => 'fixture', 'currency' => 'USD', 'quote_ttl_seconds' => 900]);
if (count($rates) !== 2) throw new RuntimeException('Invalid live rates were not rejected.');
if ($rates[0]['amount'] !== 12.35 || $rates[0]['provider'] !== 'fixture') throw new RuntimeException('Rate normalization or rounding failed.');
if ($rates[0]['delivery_days_min'] !== 3 || $rates[0]['delivery_days_max'] !== 5) throw new RuntimeException('Delivery estimate normalization failed.');
echo "Mercato shipping quote tests passed.\n";
