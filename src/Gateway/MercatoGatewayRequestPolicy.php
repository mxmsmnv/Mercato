<?php
namespace ProcessWire;

final class MercatoGatewayRequestPolicy {
    public static function run(callable $request, int $retries, float $timeoutSeconds): array {
        $attempts = max(1, min(4, $retries + 1));
        $timeoutSeconds = is_finite($timeoutSeconds) && $timeoutSeconds > 0 ? $timeoutSeconds : 0.001;
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);
            $deadlineExceeded = false;
            try {
                $result = $request($attempt);
                if ((microtime(true) - $started) > $timeoutSeconds) {
                    $deadlineExceeded = true;
                    throw new \RuntimeException('Gateway request timed out.');
                }
                if (!is_array($result)) throw new \RuntimeException('Gateway returned an invalid response.');
                return $result;
            } catch (\Throwable $e) {
                // Programming/runtime engine errors are not transient provider
                // failures and retrying them only repeats the same defect.
                if (!$e instanceof \Exception) throw $e;
                $last = $e;
                // A request that returned only after the deadline may already
                // have mutated the provider. Never replay that ambiguous call.
                if ($deadlineExceeded) break;
            }
        }
        throw new \RuntimeException($last?->getMessage() ?: 'Gateway request failed.', 0, $last);
    }
}
