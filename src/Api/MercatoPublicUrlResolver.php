<?php
namespace ProcessWire;

final class MercatoPublicUrlResolver {
    public static function storeId(string $siteSecret): string {
        $secret = $siteSecret !== '' ? $siteSecret : __FILE__;
        $digest = hash_hmac('sha256', 'mercato-store-identity-v1', $secret, true);
        return 'mrc_' . rtrim(strtr(base64_encode(substr($digest, 0, 18)), '+/', '-_'), '=');
    }

    public static function origin(string $configuredRoot, array $server, array $allowedHosts = []): string {
        $forwardedHost = trim(explode(',', (string) ($server['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        $host = $forwardedHost !== '' ? $forwardedHost : trim((string) ($server['HTTP_HOST'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) return '';

        $hostName = strtolower((string) parse_url('http://' . $host, PHP_URL_HOST));
        $allowed = array_values(array_unique(array_filter(array_map(static function (mixed $value): string {
            $candidate = strtolower(trim((string) $value));
            if ($candidate === '') return '';
            return (string) (parse_url(str_contains($candidate, '://') ? $candidate : 'http://' . $candidate, PHP_URL_HOST) ?: '');
        }, $allowedHosts))));
        $configuredHost = strtolower((string) parse_url($configuredRoot, PHP_URL_HOST));
        if ($configuredHost !== '') $allowed[] = $configuredHost;
        $allowed = array_values(array_unique($allowed));
        if ($allowed === [] || !in_array($hostName, $allowed, true)) return '';

        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $scheme = in_array($forwardedProto, ['http', 'https'], true)
            ? $forwardedProto
            : (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off' ? 'https' : 'http');
        return $scheme . '://' . strtolower($host);
    }

    public static function resolve(string $url, string $configuredRoot, array $server, array $allowedHosts = []): string {
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;

        $configuredHost = strtolower((string) parse_url($configuredRoot, PHP_URL_HOST));
        $sourceHost = strtolower((string) ($parts['host'] ?? ''));
        if ($sourceHost !== '' && $configuredHost !== '' && $sourceHost !== $configuredHost && !str_ends_with($sourceHost, '.test')) return $url;

        $origin = self::origin($configuredRoot, $server, $allowedHosts);
        if ($origin === '') return $url;
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $origin . $path . $query . $fragment;
    }
}
