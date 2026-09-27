<?php
declare(strict_types=1);

$site = realpath((string) getenv('MERCATO_E2E_SITE'));
if (!$site || !preg_match('#/(?:private/)?tmp/mercato-fresh-install-[a-f0-9]{12}$#D', $site)) {
    http_response_code(503); echo 'Disposable site ownership guard failed.'; return;
}
$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$candidate = realpath($site . '/' . ltrim($requestPath, '/'));
if ($requestPath !== '/' && $candidate && str_starts_with($candidate, $site . '/') && is_file($candidate)) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/index.php';
