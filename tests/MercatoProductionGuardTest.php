<?php
require_once __DIR__ . '/../src/Gateway/MercatoProductionGuard.php';
use ProcessWire\MercatoProductionGuard;
$base = ['production' => true, 'enabled_payment_methods' => ['stripe-card'], 'stripe_live_pk' => 'pk_live_fixture', 'stripe_live_sk' => 'sk_live_fixture', 'stripe_test_pk' => 'pk_test_fixture', 'stripe_test_sk' => 'sk_test_fixture', 'stripe_webhook_secret' => 'whsec_fixture'];
if (MercatoProductionGuard::validate($base, 'https://shop.example')) throw new RuntimeException('Valid Stripe production config was blocked.');
$demo = $base; $demo['enabled_payment_methods'] = ['demo']; $errors = MercatoProductionGuard::validate($demo, 'http://shop.example');
if (!str_contains(implode(' ', $errors), 'Demo Payment') || !str_contains(implode(' ', $errors), 'HTTPS')) throw new RuntimeException('Demo/HTTPS blockers failed.');
$badStripe = $base; $badStripe['stripe_live_sk'] = 'sk_test_wrong'; $badStripe['stripe_live_pk'] = $badStripe['stripe_test_pk'];
if (count(MercatoProductionGuard::validate($badStripe, 'https://shop.example')) < 2) throw new RuntimeException('Stripe mismatch checks failed.');
$mollie = ['production' => true, 'enabled_payment_methods' => ['mollie'], 'mollie_live_key' => 'test_wrong', 'mollie_test_key' => 'test_wrong'];
if (count(MercatoProductionGuard::validate($mollie, 'https://shop.example')) < 2) throw new RuntimeException('Mollie mismatch checks failed.');
$email = $base + ['enabled_notification_events' => ['order_confirmation'], 'notification_sender_name' => 'Store', 'notification_sender_email' => 'not-an-email', 'notification_transport' => 'wiremail'];
if (!str_contains(implode(' ', MercatoProductionGuard::validate($email, 'https://shop.example')), 'Transactional email sender')) throw new RuntimeException('Invalid transactional sender did not block launch.');
echo "Mercato production guard tests passed.\n";
