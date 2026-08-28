<?php
namespace ProcessWire;

interface MercatoPushTransportInterface {
    /** @return array{accepted:bool,status:string,message?:string,provider_message_id?:string,invalid_token?:bool} */
    public function send(string $deviceToken, array $payload, array $context = []): array;
    public function getName(): string;
    /** @return array{ready:bool,errors:array,details:array} */
    public function getSetupStatus(): array;
}
