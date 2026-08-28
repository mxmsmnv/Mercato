<?php
require_once __DIR__ . '/../src/Payment/MercatoStripeCustomerData.php';

use ProcessWire\MercatoStripeCustomerData;

$details = MercatoStripeCustomerData::fromPendingOrder([
    'first_name' => ' Ada ',
    'last_name' => ' Lovelace ',
    'email' => 'ada@example.test',
    'phone' => '+1 949 555 0100',
    'address' => '1 Example Street',
    'address_2' => 'Suite 2',
    'city' => 'Irvine',
    'region' => 'CA',
    'zip' => '92612',
    'country' => 'us',
]);

if (($details['name'] ?? '') !== 'Ada Lovelace') throw new RuntimeException('Stripe customer name was not normalized.');
if (($details['email'] ?? '') !== 'ada@example.test') throw new RuntimeException('Stripe customer email was not preserved.');
if (($details['phone'] ?? '') !== '+1 949 555 0100') throw new RuntimeException('Stripe customer phone was not preserved.');
if (($details['address']['country'] ?? '') !== 'US' || ($details['address']['postal_code'] ?? '') !== '92612') throw new RuntimeException('Stripe billing address was not normalized.');

$minimal = MercatoStripeCustomerData::fromPendingOrder([
    'mrc_first_name' => 'Grace',
    'mrc_last_name' => 'Hopper',
    'mrc_email' => 'not-an-email',
    'mrc_country' => 'United States',
]);
if (($minimal['name'] ?? '') !== 'Grace Hopper') throw new RuntimeException('Stored order aliases were not supported.');
if (isset($minimal['email']) || isset($minimal['address']['country'])) throw new RuntimeException('Invalid Stripe customer fields were not omitted.');

echo "Mercato Stripe customer data tests passed.\n";
