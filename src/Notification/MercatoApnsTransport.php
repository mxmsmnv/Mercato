<?php
namespace ProcessWire;

final class MercatoApnsTransport extends Wire implements MercatoPushTransportInterface {
    private ?string $cachedJwt = null;
    private int $cachedJwtAt = 0;

    public function __construct(private readonly Mercato $commerce) { parent::__construct(); }

    public function getName(): string { return 'apns'; }

    public function getSetupStatus(): array {
        $errors = [];
        if (!function_exists('curl_init')) $errors[] = 'PHP cURL is required for APNs.';
        if (!function_exists('openssl_sign')) $errors[] = 'PHP OpenSSL is required for APNs token signing.';
        if (!preg_match('/^[A-Z0-9]{10}$/', trim((string) $this->commerce->apns_team_id))) $errors[] = 'A valid Apple Team ID is required.';
        if (!preg_match('/^[A-Z0-9]{10}$/', trim((string) $this->commerce->apns_key_id))) $errors[] = 'A valid APNs Key ID is required.';
        if (!preg_match('/^[A-Za-z0-9.-]+$/', trim((string) $this->commerce->apns_bundle_id))) $errors[] = 'A valid APNs bundle ID is required.';
        $path = trim((string) $this->commerce->apns_private_key_path);
        if ($path === '' || !is_file($path) || !is_readable($path)) $errors[] = 'The APNs private key file is not readable.';
        return ['ready' => $errors === [], 'errors' => $errors, 'details' => ['environment' => $this->environment(), 'bundle_id' => trim((string) $this->commerce->apns_bundle_id)]];
    }

    public function send(string $deviceToken, array $payload, array $context = []): array {
        $setup = $this->getSetupStatus();
        if (!$setup['ready']) return ['accepted' => false, 'status' => 'not_configured', 'message' => implode(' ', $setup['errors'])];
        $token = strtolower(trim($deviceToken));
        if (!preg_match('/^[a-f0-9]{64,200}$/', $token)) return ['accepted' => false, 'status' => 'invalid_token', 'message' => 'APNs device token is invalid.', 'invalid_token' => true];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 4096) return ['accepted' => false, 'status' => 'invalid_payload', 'message' => 'APNs payload exceeds 4 KB.'];
        $host = $this->environment() === 'production' ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        $id = preg_match('/^[A-Fa-f0-9-]{36}$/', (string) ($context['apns_id'] ?? '')) ? (string) $context['apns_id'] : $this->uuid();
        $headers = ['authorization: bearer ' . $this->jwt(), 'apns-topic: ' . trim((string) $this->commerce->apns_bundle_id), 'apns-push-type: alert', 'apns-priority: 10', 'apns-id: ' . $id, 'content-type: application/json'];
        $curl = curl_init($host . '/3/device/' . rawurlencode($token));
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]);
        $response = curl_exec($curl); $error = curl_error($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($response === false) return ['accepted' => false, 'status' => 'transport_error', 'message' => $error];
        $body = substr((string) $response, (int) strpos((string) $response, "\r\n\r\n") + 4); $decoded = json_decode($body, true); $reason = (string) ($decoded['reason'] ?? '');
        return ['accepted' => $status === 200, 'status' => $status === 200 ? 'sent' : strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $reason) ?: 'rejected'), 'message' => $reason, 'provider_message_id' => $id, 'invalid_token' => in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true)];
    }

    private function environment(): string { return (string) $this->commerce->apns_environment === 'production' ? 'production' : 'sandbox'; }
    private function jwt(): string {
        if ($this->cachedJwt && time() - $this->cachedJwtAt < 3000) return $this->cachedJwt;
        $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'ES256', 'kid' => trim((string) $this->commerce->apns_key_id)]));
        $claims = $encode((string) json_encode(['iss' => trim((string) $this->commerce->apns_team_id), 'iat' => time()]));
        $key = openssl_pkey_get_private((string) file_get_contents(trim((string) $this->commerce->apns_private_key_path)));
        $der = ''; if (!$key || !openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) throw new WireException('Could not sign the APNs provider token.');
        $this->cachedJwtAt = time(); return $this->cachedJwt = $header . '.' . $claims . '.' . $encode($this->derToJose($der));
    }
    private function derToJose(string $der): string {
        $offset = 2; if ((ord($der[1]) & 0x80) !== 0) $offset = 2 + (ord($der[1]) & 0x7f);
        if (ord($der[$offset]) !== 0x02) throw new WireException('Invalid APNs signature.');
        $rLength = ord($der[++$offset]); $r = substr($der, ++$offset, $rLength); $offset += $rLength;
        if (ord($der[$offset]) !== 0x02) throw new WireException('Invalid APNs signature.');
        $sLength = ord($der[++$offset]); $s = substr($der, ++$offset, $sLength);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    private function uuid(): string { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
}
