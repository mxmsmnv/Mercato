<?php
namespace ProcessWire;

interface MercatoEmailTransportInterface {
    public function getName(): string;

    /** @return array{accepted:bool,provider_message_id?:string,status?:string,message?:string} */
    public function send(array $message): array;

    /** @return array{ready:bool,errors:array<int,string>,details?:array<string,mixed>} */
    public function getSetupStatus(): array;
}
