<?php
require_once __DIR__ . '/../src/Gateway/MercatoGatewayRequestPolicy.php';
use ProcessWire\MercatoGatewayRequestPolicy;

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$attempts = [];
$result = MercatoGatewayRequestPolicy::run(static function (int $attempt) use (&$attempts): array {
    $attempts[] = $attempt;
    if ($attempt < 3) throw new RuntimeException('transient-' . $attempt);
    return ['ok' => true, 'attempt' => $attempt];
}, 2, 1);
$expect($result === ['ok' => true, 'attempt' => 3], 'Gateway retry did not return the successful response.');
$expect($attempts === [1, 2, 3], 'Gateway attempt numbering or retry count changed.');

foreach ([
    ['negative retries', -10, 1],
    ['zero retries', 0, 1],
    ['one retry', 1, 2],
    ['maximum retries', 3, 4],
    ['excess retries capped', 99, 4],
] as [$label, $retries, $expectedCalls]) {
    $calls = 0;
    $caught = null;
    try {
        MercatoGatewayRequestPolicy::run(static function () use (&$calls): array {
            $calls++;
            throw new RuntimeException('provider unavailable ' . $calls);
        }, $retries, 1);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }
    $expect($calls === $expectedCalls, "$label executed $calls calls instead of $expectedCalls.");
    $expect($caught instanceof RuntimeException, "$label did not surface the terminal provider failure.");
    $expect($caught?->getPrevious() instanceof RuntimeException, "$label did not preserve the terminal provider exception as cause.");
}

$invalidCalls = 0;
$validAfterInvalid = MercatoGatewayRequestPolicy::run(static function () use (&$invalidCalls): mixed {
    $invalidCalls++;
    return $invalidCalls === 1 ? 'not-an-array' : ['valid' => true];
}, 1, 1);
$expect($validAfterInvalid === ['valid' => true] && $invalidCalls === 2, 'Invalid provider response was not retried within the configured bound.');

$invalidCalls = 0;
$invalidFailure = null;
try {
    MercatoGatewayRequestPolicy::run(static function () use (&$invalidCalls): string {
        $invalidCalls++;
        return 'not-an-array';
    }, 99, 1);
} catch (RuntimeException $exception) {
    $invalidFailure = $exception;
}
$expect($invalidCalls === 4, 'Invalid provider response ignored the four-attempt cap.');
$expect($invalidFailure?->getMessage() === 'Gateway returned an invalid response.', 'Invalid provider response lost its stable failure message.');

// A call that returns after its deadline may already have created a payment or
// refund remotely. It must fail for reconciliation without being replayed.
$timeoutCalls = 0;
$timeoutFailure = null;
try {
    MercatoGatewayRequestPolicy::run(static function () use (&$timeoutCalls): array {
        $timeoutCalls++;
        usleep(20_000);
        return ['remote_mutation_may_exist' => true];
    }, 3, 0.005);
} catch (RuntimeException $exception) {
    $timeoutFailure = $exception;
}
$expect($timeoutCalls === 1, 'Ambiguous timed-out provider mutation was replayed.');
$expect(str_contains((string) $timeoutFailure?->getMessage(), 'timed out'), 'Gateway timeout did not surface a stable timeout failure.');

$mixedCalls = 0;
try {
    MercatoGatewayRequestPolicy::run(static function () use (&$mixedCalls): array {
        $mixedCalls++;
        if ($mixedCalls === 1) throw new RuntimeException('connection refused');
        usleep(20_000);
        return ['remote_mutation_may_exist' => true];
    }, 3, 0.005);
} catch (RuntimeException) {
}
$expect($mixedCalls === 2, 'Retry policy continued after a later ambiguous timeout.');

// Invalid/non-finite timeout values must not silently disable the deadline.
foreach ([0.0, -1.0, NAN, INF] as $invalidTimeout) {
    $calls = 0;
    $caught = null;
    try {
        MercatoGatewayRequestPolicy::run(static function () use (&$calls): array {
            $calls++;
            usleep(5_000);
            return [];
        }, 3, $invalidTimeout);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }
    $expect($calls === 1, 'Invalid timeout value disabled the deadline or replayed an ambiguous call.');
    $expect(str_contains((string) $caught?->getMessage(), 'timed out'), 'Invalid timeout value did not fail closed.');
}

$errorCalls = 0;
$sameError = new TypeError('fixture programmer error');
$caughtError = null;
try {
    MercatoGatewayRequestPolicy::run(static function () use (&$errorCalls, $sameError): array {
        $errorCalls++;
        throw $sameError;
    }, 3, 1);
} catch (TypeError $error) {
    $caughtError = $error;
}
$expect($errorCalls === 1, 'Programming error was retried as a transient provider failure.');
$expect($caughtError === $sameError, 'Programming error identity/trace was replaced by the retry policy.');

$empty = MercatoGatewayRequestPolicy::run(static fn(): array => [], 3, 1);
$expect($empty === [], 'Empty but structurally valid provider response was rejected.');

echo "Mercato gateway request policy matrix tests passed: $checks assertions.\n";
