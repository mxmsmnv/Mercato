<?php
namespace ProcessWire;

final class MercatoGatewayRequestPolicy {
    public static function run(callable $request, int $retries, float $timeoutSeconds): array {
        $attempts = max(1, min(4, $retries + 1)); $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);
            try {
                $result = $request($attempt);
                if ((microtime(true) - $started) > $timeoutSeconds) throw new \RuntimeException('Gateway request timed out.');
                if (!is_array($result)) throw new \RuntimeException('Gateway returned an invalid response.');
                return $result;
            } catch (\Throwable $e) { $last = $e; }
        }
        throw new \RuntimeException($last?->getMessage() ?: 'Gateway request failed.', 0, $last);
    }
}
