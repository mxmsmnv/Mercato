<?php
namespace ProcessWire;

/**
 * Policy-enforced remote image ingestion.
 *
 * Resolver and transport are injected so production code can pin the validated
 * IP and enforce the byte limit while streaming. The transport receives
 * ($url, $validatedIp, $maxBytes, $timeoutSeconds) and must not follow redirects.
 */
final class MercatoCatalogImageIngestor {
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    ];

    public function __construct(
        private int $maxBytes = 8_388_608,
        private int $maxPixels = 40_000_000,
        private int $timeoutSeconds = 15,
        private int $maxRedirects = 3
    ) {}

    /**
     * @param callable(string):array<int,string> $resolver
     * @param callable(string,string,int,int):array{status:int,headers:array<string,string>,body:string} $transport
     * @param array<int,string> $knownChecksums
     * @return array{url:string,mime:string,extension:string,bytes:int,width:int,height:int,checksum:string,duplicate:bool,body:string}
     */
    public function fetch(string $url, callable $resolver, callable $transport, array $knownChecksums = []): array {
        for ($redirects = 0; $redirects <= $this->maxRedirects; $redirects++) {
            [$normalizedUrl, $host] = $this->validateUrl($url);
            $addresses = array_values(array_unique(array_filter(array_map('trim', (array) $resolver($host)))));
            if (!$addresses) throw new \RuntimeException('Image host did not resolve.');
            foreach ($addresses as $address) if (!$this->isPublicIp($address)) throw new \RuntimeException('Image host resolves to a private or reserved address.');
            $response = $transport($normalizedUrl, $addresses[0], $this->maxBytes, $this->timeoutSeconds);
            $status = (int) ($response['status'] ?? 0);
            $headers = array_change_key_case((array) ($response['headers'] ?? []), CASE_LOWER);
            if ($status >= 300 && $status < 400) {
                $location = trim((string) ($headers['location'] ?? ''));
                if ($location === '' || !preg_match('~^https://~i', $location)) throw new \RuntimeException('Image redirect must use an absolute HTTPS URL.');
                if ($redirects === $this->maxRedirects) throw new \RuntimeException('Image redirect limit exceeded.');
                $url = $location;
                continue;
            }
            if ($status < 200 || $status >= 300) throw new \RuntimeException(sprintf('Image server returned HTTP %d.', $status));
            return $this->validateBody($normalizedUrl, (string) ($headers['content-type'] ?? ''), (string) ($response['body'] ?? ''), $knownChecksums);
        }
        throw new \RuntimeException('Image redirect limit exceeded.');
    }

    /** @return array{0:string,1:string} */
    private function validateUrl(string $url): array {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') throw new \InvalidArgumentException('Image URL must use HTTPS.');
        if (isset($parts['user']) || isset($parts['pass'])) throw new \InvalidArgumentException('Image URL must not contain credentials.');
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) throw new \InvalidArgumentException('Image URL must use a public DNS host name.');
        if (isset($parts['port']) && (int) $parts['port'] !== 443) throw new \InvalidArgumentException('Image URL must use HTTPS port 443.');
        return [$url, $host];
    }

    private function isPublicIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param array<int,string> $knownChecksums */
    private function validateBody(string $url, string $declaredType, string $body, array $knownChecksums): array {
        $bytes = strlen($body);
        if ($bytes === 0) throw new \RuntimeException('Image response is empty.');
        if ($bytes > $this->maxBytes) throw new \RuntimeException('Image exceeds the configured byte limit.');
        $declaredType = strtolower(trim(explode(';', $declaredType, 2)[0]));
        if (!isset(self::MIME_EXTENSIONS[$declaredType])) throw new \RuntimeException('Image Content-Type is not allowed.');
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        if ($detected !== $declaredType || !isset(self::MIME_EXTENSIONS[$detected])) throw new \RuntimeException('Image MIME type does not match its content.');
        $size = @getimagesizefromstring($body);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) throw new \RuntimeException('Image dimensions could not be read.');
        $pixels = (int) $size[0] * (int) $size[1];
        if ($pixels > $this->maxPixels) throw new \RuntimeException('Image exceeds the configured pixel limit.');
        $checksum = hash('sha256', $body);
        return [
            'url' => $url, 'mime' => $detected, 'extension' => self::MIME_EXTENSIONS[$detected],
            'bytes' => $bytes, 'width' => (int) $size[0], 'height' => (int) $size[1],
            'checksum' => $checksum, 'duplicate' => in_array($checksum, $knownChecksums, true), 'body' => $body,
        ];
    }
}
