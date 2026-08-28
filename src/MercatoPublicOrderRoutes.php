<?php
namespace ProcessWire;

trait MercatoPublicOrderRoutes {

    protected function getOrderPublicRouteCode(Page $order, string $purpose): string {
        $orderId = (string) (int) $order->id;
        $token = $this->getOrderPublicRouteToken($order, $purpose);
        if ($orderId === '0' || $token === '') return '';

        $signature = hash_hmac(
            'sha256',
            'mercato-order-public-route|' . $purpose . '|' . $orderId . '|' . $token,
            $this->orderPublicRouteSecret(),
            true
        );
        return rtrim(strtr(base64_encode($orderId . '.' . $signature), '+/', '-_'), '=');
    }

    protected function resolveOrderPublicRouteCode(string $code, string $purpose): ?Page {
        if (!in_array($purpose, ['status', 'receipt'], true) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $code)) {
            return null;
        }

        $padding = (4 - strlen($code) % 4) % 4;
        $decoded = base64_decode(strtr($code . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded) || !str_contains($decoded, '.')) return null;

        [$orderId, $signature] = explode('.', $decoded, 2);
        if (!ctype_digit($orderId) || strlen($signature) !== 32) return null;

        $order = $this->wire('pages')->get((int) $orderId);
        if (!$order || !$order->id || !hash_equals($this->getOrderPublicRouteCode($order, $purpose), $code)) {
            return null;
        }

        $token = $this->getOrderPublicRouteToken($order, $purpose);
        $valid = $purpose === 'receipt'
            ? $this->verifyOrderReceiptToken($order, $token)
            : $this->verifyOrderStatusToken($order, $token);
        return $valid ? $order : null;
    }

    protected function getOrderPublicRouteToken(Page $order, string $purpose): string {
        return match ($purpose) {
            'status' => $this->getOrderStatusToken($order),
            'receipt' => $this->getOrderReceiptToken($order),
            default => '',
        };
    }

    protected function orderPublicRouteSecret(): string {
        return (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
    }
}
