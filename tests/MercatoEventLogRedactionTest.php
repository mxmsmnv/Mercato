<?php
namespace ProcessWire;
class Wire { public function __construct() {} }
require_once __DIR__ . '/../src/Logging/MercatoEventLog.php';
$log = new MercatoEventLog();
$data = $log->redact(['client_secret' => 'secret', 'card' => ['number' => '4242424242424242'], 'cvc' => '123', 'safe' => 'pi_fixture', 'nested' => ['authorization' => 'Bearer abc']]);
if ($data['client_secret'] !== '[redacted]' || $data['card'] !== '[redacted]' || $data['cvc'] !== '[redacted]' || $data['safe'] !== 'pi_fixture' || $data['nested']['authorization'] !== '[redacted]') throw new \RuntimeException('Sensitive payment payload redaction failed.');
echo "Mercato event log redaction tests passed.\n";
