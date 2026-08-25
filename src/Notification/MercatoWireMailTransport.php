<?php
namespace ProcessWire;

final class MercatoWireMailTransport extends Wire implements MercatoEmailTransportInterface {
    public function getName(): string {
        return 'wiremail';
    }

    public function getSetupStatus(): array {
        return ['ready' => function_exists(__NAMESPACE__ . '\\wireMail') || function_exists('wireMail'), 'errors' => [], 'details' => ['provider' => 'ProcessWire WireMail']];
    }

    public function send(array $message): array {
        $mail = wireMail();
        $mail->to((string) $message['to'])
            ->from((string) $message['from_email'], (string) ($message['from_name'] ?? ''))
            ->subject((string) $message['subject'])
            ->body((string) $message['text']);
        if (method_exists($mail, 'bodyHTML')) $mail->bodyHTML((string) $message['html']);
        if (trim((string) ($message['reply_to'] ?? '')) !== '') $mail->header('Reply-To', (string) $message['reply_to']);
        foreach ((array) ($message['headers'] ?? []) as $name => $value) $mail->header((string) $name, (string) $value);
        $accepted = (int) $mail->send() > 0;
        $providerId = '';
        foreach (['getMessageId', 'messageId'] as $method) {
            if (method_exists($mail, $method)) { $providerId = (string) $mail->{$method}(); break; }
        }
        return ['accepted' => $accepted, 'provider_message_id' => $providerId, 'status' => $accepted ? 'accepted' : 'failed', 'message' => $accepted ? 'WireMail accepted the message.' : 'WireMail did not report a sent message.'];
    }
}
