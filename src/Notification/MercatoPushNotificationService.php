<?php
namespace ProcessWire;

final class MercatoPushNotificationService extends Wire {
    private const DEVICE_TABLE = 'mercato_push_devices';
    private const DELIVERY_TABLE = 'mercato_push_deliveries';
    private const EVENTS = ['order_confirmation','payment_failed','payment_recovery','refund','cancellation','shipment_tracking','pickup_ready','local_delivery','account_security'];

    public function __construct(private readonly Mercato $commerce, private readonly MercatoPushTransportInterface $transport) { parent::__construct(); }
    public function setWire(ProcessWire $wire) { parent::setWire($wire); if ($this->transport instanceof Wire) $this->transport->setWire($wire); }

    public function ensureSchema(): void {
        $this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `" . self::DEVICE_TABLE . "` (registration_id VARCHAR(80) NOT NULL PRIMARY KEY, installation_hash CHAR(64) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, token_cipher MEDIUMTEXT NOT NULL, owner_type VARCHAR(16) NOT NULL, owner_id BIGINT UNSIGNED NOT NULL, environment VARCHAR(12) NOT NULL, bundle_id VARCHAR(255) NOT NULL, locale VARCHAR(32) NOT NULL, topics_json TEXT NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX owner_status (owner_type, owner_id, status), INDEX updated_at (updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `" . self::DELIVERY_TABLE . "` (delivery_key CHAR(64) NOT NULL PRIMARY KEY, registration_id VARCHAR(80) NOT NULL, event VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, provider_message_id VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX registration_event (registration_id, event), INDEX updated_at (updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function register(array $input, string $ownerType, int $ownerId): array {
        $this->ensureSchema(); $device = $this->normalize($input); $now = date('Y-m-d H:i:s');
        $existing = $this->deviceByTokenHash($device['token_hash']); $registration = (string) ($existing['registration_id'] ?? ('dev_' . $this->opaque(24)));
        $sql = 'INSERT INTO ' . self::DEVICE_TABLE . ' (registration_id,installation_hash,token_hash,token_cipher,owner_type,owner_id,environment,bundle_id,locale,topics_json,status,created_at,updated_at) VALUES (:registration,:installation,:token_hash,:cipher,:owner_type,:owner_id,:environment,:bundle,:locale,:topics,\'active\',:created,:updated) ON DUPLICATE KEY UPDATE installation_hash=VALUES(installation_hash),token_cipher=VALUES(token_cipher),owner_type=VALUES(owner_type),owner_id=VALUES(owner_id),environment=VALUES(environment),bundle_id=VALUES(bundle_id),locale=VALUES(locale),topics_json=VALUES(topics_json),status=\'active\',updated_at=VALUES(updated_at)';
        $statement = $this->wire('database')->prepare($sql); $statement->execute([':registration'=>$registration,':installation'=>$device['installation_hash'],':token_hash'=>$device['token_hash'],':cipher'=>$this->encrypt($device['token']),':owner_type'=>$ownerType,':owner_id'=>$ownerId,':environment'=>$device['environment'],':bundle'=>$device['bundle_id'],':locale'=>$device['locale'],':topics'=>json_encode($device['topics']),':created'=>$now,':updated'=>$now]);
        return ['id'=>$registration,'status'=>'active','topics'=>$device['topics'],'push_available'=>$this->isReady()];
    }

    public function revoke(string $registrationId, string $ownerType, int $ownerId): array {
        $this->ensureSchema(); $statement=$this->wire('database')->prepare('UPDATE '.self::DEVICE_TABLE.' SET status=\'revoked\',token_cipher=\'\',updated_at=:updated WHERE registration_id=:registration AND owner_type=:owner_type AND owner_id=:owner_id');$statement->execute([':updated'=>date('Y-m-d H:i:s'),':registration'=>$registrationId,':owner_type'=>$ownerType,':owner_id'=>$ownerId]);
        if ($statement->rowCount() < 1) throw new WireException('Push registration not found.', 404);
        return ['id'=>$registrationId,'status'=>'revoked'];
    }

    public function revokeOwner(string $ownerType, int $ownerId): int {
        if (!in_array($ownerType, ['account','order'], true) || $ownerId <= 0) return 0;
        $this->ensureSchema(); $statement=$this->wire('database')->prepare('UPDATE '.self::DEVICE_TABLE.' SET status=\'revoked\',token_cipher=\'\',updated_at=:updated WHERE owner_type=:owner_type AND owner_id=:owner_id AND status=\'active\'');$statement->execute([':updated'=>date('Y-m-d H:i:s'),':owner_type'=>$ownerType,':owner_id'=>$ownerId]);return$statement->rowCount();
    }

    public function sendOrderEvent(Page $order, string $event, string $businessId = ''): array {
        if (!$order->id || !in_array($event, self::EVENTS, true)) return ['status'=>'ignored','sent'=>0];
        $invoice = substr((string) ($order->mrc_invoice_number ?: $order->title), 0, 80);
        $businessId = $businessId ?: (string) ($order->modified ?: time());
        $results = [$this->sendToOwner('order',(int)$order->id,$event,$this->content($event,$invoice),$businessId)];
        $accountId = (int) ($order->mrc_customer_user_id ?? 0); if ($accountId > 0) $results[] = $this->sendToOwner('account',$accountId,$event,$this->content($event,$invoice),$businessId);
        $sent=array_sum(array_map(static fn(array$result):int=>(int)($result['sent']??0),$results));$failed=array_sum(array_map(static fn(array$result):int=>(int)($result['failed']??0),$results));return['status'=>$failed>0&&$sent===0?'failed':($sent>0?'sent':'disabled'),'sent'=>$sent,'failed'=>$failed];
    }

    public function sendUserEvent(int $userId, string $event, string $businessId = ''): array { return $this->sendToOwner('account',$userId,$event,$this->content($event,''),$businessId ?: (string)time()); }
    public function getSetupStatus(): array { $setup=$this->transport->getSetupStatus();$errors=(array)$setup['errors'];if(empty($this->commerce->push_notifications_enabled))$errors[]='Push notifications are disabled.';return ['ready'=>$errors===[]&&!empty($setup['ready']),'errors'=>array_values(array_unique($errors)),'transport'=>$this->transport->getName(),'details'=>$setup['details']??[]]; }
    public function isReady(): bool { return !empty($this->getSetupStatus()['ready']); }

    private function sendToOwner(string $ownerType,int $ownerId,string $event,array $content,string $businessId): array {
        if (!$this->isReady() || $ownerId <= 0) return ['status'=>'disabled','sent'=>0]; $this->ensureSchema();
        $statement=$this->wire('database')->prepare('SELECT * FROM '.self::DEVICE_TABLE.' WHERE owner_type=:owner_type AND owner_id=:owner_id AND status=\'active\'');$statement->execute([':owner_type'=>$ownerType,':owner_id'=>$ownerId]);$sent=0;$failed=0;
        while($device=$statement->fetch(\PDO::FETCH_ASSOC)){ if((string)$device['environment']!==((string)$this->commerce->apns_environment==='production'?'production':'sandbox'))continue;$topics=json_decode((string)$device['topics_json'],true);if(!in_array('order_updates',is_array($topics)?$topics:[],true))continue;$key=hash('sha256',$event.'|'.$businessId.'|'.$device['registration_id']);if($this->deliveryExists($key))continue;
            try{$payload=['aps'=>['alert'=>$content,'sound'=>'default','thread-id'=>'mercato-orders'],'mercato'=>['event'=>$event,'route'=>'orders','reference'=>$ownerType==='order'?(string)$ownerId:'']];$result=$this->transport->send($this->decrypt((string)$device['token_cipher']),$payload,['registration_id'=>$device['registration_id']]);}catch(\Throwable $e){$result=['accepted'=>false,'status'=>'failed','message'=>$e->getMessage()];}
            $this->recordDelivery($key,(string)$device['registration_id'],$event,(string)($result['status']??'failed'),(string)($result['provider_message_id']??''));if(!empty($result['accepted']))$sent++;else$failed++;if(!empty($result['invalid_token']))$this->disable((string)$device['registration_id']);
        } return ['status'=>$failed>0&&$sent===0?'failed':'sent','sent'=>$sent,'failed'=>$failed];
    }
    private function content(string $event,string $invoice): array { $suffix=$invoice!==''?' '.$invoice:'';return match($event){'order_confirmation'=>['title'=>'Order confirmed','body'=>'We received order'.$suffix.'.'],'payment_failed'=>['title'=>'Payment needs attention','body'=>'Payment for order'.$suffix.' was not completed.'],'payment_recovery'=>['title'=>'Complete your order','body'=>'Your order'.$suffix.' is waiting for payment.'],'refund'=>['title'=>'Refund update','body'=>'A refund was recorded for order'.$suffix.'.'],'cancellation'=>['title'=>'Order canceled','body'=>'Order'.$suffix.' was canceled.'],'shipment_tracking'=>['title'=>'Order shipped','body'=>'Order'.$suffix.' is on its way.'],'pickup_ready'=>['title'=>'Ready for pickup','body'=>'Order'.$suffix.' is ready for pickup.'],'local_delivery'=>['title'=>'Out for delivery','body'=>'Order'.$suffix.' is out for delivery.'],default=>['title'=>'Account security','body'=>'There is a security update for your account.']}; }
    private function normalize(array $input): array { $token=strtolower(preg_replace('/[^a-f0-9]/i','',(string)($input['token']??'')));if(!preg_match('/^[a-f0-9]{64,200}$/',$token))throw new WireException('A valid APNs device token is required.',422);$installation=trim((string)($input['installation_id']??''));if(!preg_match('/^[A-Za-z0-9._:-]{8,191}$/',$installation))throw new WireException('A valid installation ID is required.',422);$environment=(string)($input['environment']??'sandbox')==='production'?'production':'sandbox';$bundle=substr(trim((string)($input['bundle_id']??'')),0,255);if(!preg_match('/^[A-Za-z0-9.-]+$/',$bundle))throw new WireException('A valid app bundle ID is required.',422);$allowedBundle=trim((string)$this->commerce->apns_bundle_id);if($allowedBundle!==''&&!hash_equals($allowedBundle,$bundle))throw new WireException('The app bundle is not registered for this store.',403);$locale=substr(preg_replace('/[^A-Za-z0-9_-]+/','',(string)($input['locale']??'en'))?:'en',0,32);$topics=array_values(array_intersect(['order_updates'],array_map('strval',(array)($input['topics']??['order_updates']))));return['token'=>$token,'token_hash'=>hash_hmac('sha256',$token,$this->secret()),'installation_hash'=>hash_hmac('sha256',$installation,$this->secret()),'environment'=>$environment,'bundle_id'=>$bundle,'locale'=>$locale,'topics'=>$topics]; }
    private function deviceByTokenHash(string $hash): ?array {$statement=$this->wire('database')->prepare('SELECT registration_id FROM '.self::DEVICE_TABLE.' WHERE token_hash=:hash LIMIT 1');$statement->execute([':hash'=>$hash]);$row=$statement->fetch(\PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    private function deliveryExists(string $key): bool {$statement=$this->wire('database')->prepare('SELECT delivery_key FROM '.self::DELIVERY_TABLE.' WHERE delivery_key=:key LIMIT 1');$statement->execute([':key'=>$key]);return(bool)$statement->fetchColumn();}
    private function recordDelivery(string $key,string $registration,string $event,string $status,string $providerId):void{$now=date('Y-m-d H:i:s');$statement=$this->wire('database')->prepare('INSERT IGNORE INTO '.self::DELIVERY_TABLE.' (delivery_key,registration_id,event,status,provider_message_id,created_at,updated_at) VALUES (:key,:registration,:event,:status,:provider,:created,:updated)');$statement->execute([':key'=>$key,':registration'=>$registration,':event'=>$event,':status'=>$status,':provider'=>$providerId,':created'=>$now,':updated'=>$now]);}
    private function disable(string $registration):void{$statement=$this->wire('database')->prepare('UPDATE '.self::DEVICE_TABLE.' SET status=\'invalid\',token_cipher=\'\',updated_at=:updated WHERE registration_id=:registration');$statement->execute([':updated'=>date('Y-m-d H:i:s'),':registration'=>$registration]);}
    private function encrypt(string $token):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',hash('sha256',$this->secret(),true),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new WireException('Could not protect push token.');return rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');}
    private function decrypt(string $encoded):string{$raw=base64_decode(strtr($encoded,'-_','+/'),true);if($raw===false||strlen($raw)<29)throw new WireException('Push token is unavailable.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$this->secret(),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($plain===false)throw new WireException('Push token is unavailable.');return$plain;}
    private function secret():string{return(string)($this->wire('config')->userAuthSalt?:__FILE__);}private function opaque(int$bytes):string{return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');}
}
