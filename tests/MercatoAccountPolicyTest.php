<?php
namespace ProcessWire;
require_once dirname(__DIR__) . '/src/Account/MercatoAccountPolicy.php';

$expect = static function (bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); };
$expect(MercatoAccountPolicy::normalizeMode('required_verified') === 'required_verified', 'Required mode failed.');
$expect(MercatoAccountPolicy::normalizeMode('unexpected') === 'disabled', 'Unsafe account mode was accepted.');
$expect(MercatoAccountPolicy::tokenExpired(100, 101), 'Expired token boundary failed.');
$expect(!MercatoAccountPolicy::tokenExpired(101, 101), 'Valid token boundary failed.');
$expect(MercatoAccountPolicy::ownsRecord(7, 7) && !MercatoAccountPolicy::ownsRecord(7, 8), 'Ownership policy failed.');
$expect(MercatoAccountPolicy::canClaim(' Customer@Example.test ', 'customer@example.test'), 'Verified email claim failed.');
$expect(!MercatoAccountPolicy::canClaim('a@example.test', 'b@example.test'), 'Mismatched claim was accepted.');
$expect(MercatoAccountPolicy::mergeConflict(8, 7) && !MercatoAccountPolicy::mergeConflict(0, 7), 'Merge conflict policy failed.');
$expect(MercatoAccountPolicy::GENERIC_AUTH_MESSAGE !== '', 'Enumeration-safe message is empty.');
echo "Mercato account policy tests passed.\n";
