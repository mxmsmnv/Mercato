<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato push integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser')); $wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$transport = new class implements MercatoPushTransportInterface { public array $messages=[];public function send(string$deviceToken,array$payload,array$context=[]):array{$this->messages[]=['token'=>$deviceToken,'payload'=>$payload,'context'=>$context];return['accepted'=>true,'status'=>'sent','provider_message_id'=>'fixture-message'];}public function getName():string{return'fixture';}public function getSetupStatus():array{return['ready'=>true,'errors'=>[],'details'=>[]];} };
$service = new MercatoPushNotificationService($commerce, $transport); $service->setWire($wire); $service->ensureSchema();
$nonce = bin2hex(random_bytes(12)); $token = hash('sha256', 'push-token-' . $nonce); $registration = null;
$originalEnabled=$commerce->push_notifications_enabled;$originalEnvironment=$commerce->apns_environment;
try {
    $commerce->set('push_notifications_enabled',true);$commerce->set('apns_environment','sandbox');
    $input = ['token'=>$token,'installation_id'=>'fixture-' . $nonce,'environment'=>'sandbox','bundle_id'=>'org.smnv.mercato.fixture','locale'=>'en_GB','topics'=>['order_updates']];
    $registration = $service->register($input, 'account', 99999991); $replay = $service->register($input, 'account', 99999991);
    if (!str_starts_with((string)($registration['id']??''), 'dev_') || $registration['id'] !== $replay['id'] || isset($registration['token'])) throw new \RuntimeException('Push registration is not stable or minimized.');
    $statement=$wire->database->prepare('SELECT token_hash,token_cipher,status FROM mercato_push_devices WHERE registration_id=:id');$statement->execute([':id'=>$registration['id']]);$stored=$statement->fetch(\PDO::FETCH_ASSOC);
    if (!$stored || (string)$stored['status'] !== 'active' || str_contains((string)$stored['token_cipher'], $token) || hash_equals($token, (string)$stored['token_hash'])) throw new \RuntimeException('Push token was not protected at rest.');
    $sent=$service->sendUserEvent(99999991,'account_security','fixture-event');$replayed=$service->sendUserEvent(99999991,'account_security','fixture-event');if(($sent['sent']??0)!==1||($replayed['sent']??0)!==0||count($transport->messages)!==1)throw new \RuntimeException('Push delivery idempotency failed.');$wirePayload=json_encode($transport->messages[0]['payload']);if(str_contains((string)$wirePayload,'@')||str_contains((string)$wirePayload,$token))throw new \RuntimeException('Push payload exposed PII or a device token.');
    $denied=false;try{$service->revoke((string)$registration['id'],'account',99999992);}catch(WireException$e){$denied=(int)$e->getCode()===404;}if(!$denied)throw new \RuntimeException('Another owner revoked a push registration.');
    $revoked=$service->revoke((string)$registration['id'],'account',99999991);if(($revoked['status']??'')!=='revoked')throw new \RuntimeException('Push registration was not revoked.');
} finally {
    $commerce->set('push_notifications_enabled',$originalEnabled);$commerce->set('apns_environment',$originalEnvironment);
    if (is_array($registration) && !empty($registration['id'])) { $statement=$wire->database->prepare('DELETE FROM mercato_push_deliveries WHERE registration_id=:id');$statement->execute([':id'=>$registration['id']]);$statement=$wire->database->prepare('DELETE FROM mercato_push_devices WHERE registration_id=:id');$statement->execute([':id'=>$registration['id']]); }
}
echo "Mercato push notification integration tests passed.\n";
