<?php
namespace ProcessWire;

final class MercatoSeoRules {
    public static function normalizeUrl(string $url, int $pageNum = 1): string {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) return '';
        $parts = parse_url($url); if (!is_array($parts) || empty($parts['host'])) return '';
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
        $host = strtolower((string) $parts['host']); $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        if ($pageNum > 1 && !preg_match('#/page' . $pageNum . '/?$#', $path)) $path = rtrim($path, '/') . '/page' . $pageNum . '/';
        elseif (!str_contains(basename($path), '.') && !str_ends_with($path, '/')) $path .= '/';
        return $scheme . '://' . $host . $port . $path;
    }

    public static function safeText(string $value, int $limit): string {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');
        if (mb_strlen($value) <= $limit) return $value;
        return rtrim(mb_substr($value, 0, max(1, $limit - 1))) . '…';
    }

    public static function normalizeRobots(string $robots, bool $private = false): string {
        if ($private) return 'noindex,nofollow,noarchive';
        $allowed = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'max-image-preview:large'];
        $tokens = array_values(array_unique(array_intersect($allowed, array_map('trim', explode(',', strtolower($robots))))));
        if (!array_intersect($tokens, ['index', 'noindex'])) array_unshift($tokens, 'index');
        if (!array_intersect($tokens, ['follow', 'nofollow'])) $tokens[] = 'follow';
        return implode(',', $tokens);
    }

    public static function isPrivatePath(string $path, array $query = []): bool {
        $path = '/' . trim(strtolower($path), '/') . '/';
        foreach (['/cart/', '/checkout/', '/account/', '/order-status/', '/order-receipt/', '/download/', '/my-quotes/', '/quote-status/', '/recovery-unsubscribe/'] as $private) if (str_contains($path, $private)) return true;
        foreach (['token', 'signature', 'key', 'payment_link'] as $key) if (isset($query[$key]) && trim((string) $query[$key]) !== '') return true;
        return false;
    }
}
