<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato background jobs integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) continue;
    [$name, $value] = explode('=', substr($argument, 2), 2);
    $options[$name] = $value;
}
$worker = (string) ($options['worker'] ?? '');
$stateFile = (string) ($options['state'] ?? '');
$runId = (string) ($options['run'] ?? ('bg-' . bin2hex(random_bytes(6))));

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');

$jobs = [
    'retry' => 'fixture_retry_' . $runId,
    'retry_result' => 'fixture_retry_result_' . $runId,
    'fail' => 'fixture_fail_' . $runId,
    'programmer' => 'fixture_programmer_' . $runId,
    'success' => 'fixture_success_' . $runId,
    'concurrency' => 'fixture_concurrency_' . $runId,
    'crash' => 'fixture_crash_' . $runId,
];
$commerce->addHookAfter('backgroundJobs', static function (HookEvent $event) use ($jobs): void {
    $registered = is_array($event->return) ? $event->return : [];
    foreach ($jobs as $job) $registered[$job] = ['label' => $job, 'enabled' => true, 'schedule' => 'integration'];
    $event->return = $registered;
});
$commerce->addHookBefore('runBackgroundJob', static function (HookEvent $event) use ($jobs, $worker, $stateFile, $runId): void {
    $job = (string) $event->arguments(0);
    if (!in_array($job, $jobs, true)) return;
    $context = (array) $event->arguments(1);
    $attempt = (int) ($context['attempt'] ?? 1);
    $event->replace = true;
    if ($job === $jobs['retry']) {
        if ($attempt < 3) throw new \RuntimeException("$runId transient attempt $attempt");
        $event->return = ['ok' => true, 'value' => 'retry-complete'];
        return;
    }
    if ($job === $jobs['retry_result']) {
        $event->return = $attempt < 2
            ? ['ok' => false, 'retryable' => true, 'value' => 'try-again']
            : ['ok' => true, 'value' => 'result-retry-complete'];
        return;
    }
    if ($job === $jobs['fail']) throw new \RuntimeException("$runId permanent failure");
    if ($job === $jobs['programmer']) throw new \TypeError("$runId deterministic contract defect");
    if ($job === $jobs['success']) {
        $event->return = ['ok' => true, 'value' => 'independent-success'];
        return;
    }
    if ($job === $jobs['concurrency']) {
        if ($stateFile !== '') file_put_contents($stateFile, "started\n", LOCK_EX);
        usleep(900000);
        if ($stateFile !== '') file_put_contents($stateFile, "completed\n", LOCK_EX);
        $event->return = ['ok' => true, 'value' => 'concurrency-complete'];
        return;
    }
    if ($job === $jobs['crash'] && $worker === 'crash') {
        if ($stateFile !== '') file_put_contents($stateFile, "crashing\n", LOCK_EX);
        if (function_exists('posix_kill')) posix_kill(getmypid(), 9);
        exit(86);
    }
    $event->return = ['ok' => true, 'value' => 'crash-resumed'];
});

if ($worker !== '') {
    $job = $worker === 'concurrency' ? $jobs['concurrency'] : $jobs['crash'];
    $result = $commerce->runBackgroundJobs([$job], ['source' => $runId, 'max_attempts' => 1]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    exit(empty($result[$job]['ok']) ? 1 : 0);
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$waitForState = static function (string $path, string $expected, int $timeoutMs = 3000): void {
    $deadline = microtime(true) + ($timeoutMs / 1000);
    do {
        clearstatcache(true, $path);
        if (is_file($path) && trim((string) file_get_contents($path)) === $expected) return;
        usleep(25000);
    } while (microtime(true) < $deadline);
    throw new \RuntimeException("Timed out waiting for worker state: $expected");
};
$startWorker = static function (string $mode, string $path) use ($site, $runId): array {
    $command = [PHP_BINARY, __FILE__, '--worker=' . $mode, '--state=' . $path, '--run=' . $runId];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, array_merge(getenv(), ['MERCATO_TEST_SITE' => $site]));
    if (!is_resource($process)) throw new \RuntimeException("Could not start $mode worker.");
    return [$process, $pipes];
};
$closeWorker = static function ($process, array $pipes): array {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$cleanupLog = static function (string $path, string $needle): void {
    if (!is_file($path)) return;
    $handle = fopen($path, 'c+');
    if (!$handle) return;
    flock($handle, LOCK_EX);
    rewind($handle);
    $lines = preg_split('/(?<=\n)/', (string) stream_get_contents($handle)) ?: [];
    $kept = array_filter($lines, static fn(string $line): bool => !str_contains($line, $needle));
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, implode('', $kept));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
};

$concurrencyState = tempnam(sys_get_temp_dir(), 'mercato-bg-concurrency-');
$crashState = tempnam(sys_get_temp_dir(), 'mercato-bg-crash-');
if ($concurrencyState === false || $crashState === false) throw new \RuntimeException('Could not allocate background job state files.');
$logPath = rtrim((string) $wire->config->paths->logs, '/') . '/mercato-background-jobs.txt';
register_shutdown_function(static function () use ($concurrencyState, $crashState, $cleanupLog, $logPath, $runId): void {
    if (is_file($concurrencyState)) unlink($concurrencyState);
    if (is_file($crashState)) unlink($crashState);
    $cleanupLog($logPath, $runId);
});

$retry = $commerce->runBackgroundJobs([$jobs['retry']], ['source' => $runId, 'attempt' => 99, 'max_attempts' => 3]);
$expect(!empty($retry[$jobs['retry']]['ok']) && ($retry[$jobs['retry']]['attempts'] ?? 0) === 3, 'Exception retry did not complete on the configured attempt.');
$retryResult = $commerce->runBackgroundJobs([$jobs['retry_result']], ['source' => $runId, 'max_attempts' => 3]);
$expect(!empty($retryResult[$jobs['retry_result']]['ok']) && ($retryResult[$jobs['retry_result']]['attempts'] ?? 0) === 2, 'Retryable result did not resume on the next attempt.');

$partial = $commerce->runBackgroundJobs([$jobs['fail'], $jobs['success']], ['source' => $runId, 'max_attempts' => 2]);
$expect(empty($partial[$jobs['fail']]['ok']) && ($partial[$jobs['fail']]['attempts'] ?? 0) === 2, 'Permanent failure retry accounting is incorrect.');
$expect(!empty($partial[$jobs['success']]['ok']) && ($partial[$jobs['success']]['attempts'] ?? 0) === 1, 'A failed job prevented the next independent job from running.');

$programmer = $commerce->runBackgroundJobs([$jobs['programmer'], $jobs['success']], ['source' => $runId, 'max_attempts' => 5]);
$expect(empty($programmer[$jobs['programmer']]['ok']) && ($programmer[$jobs['programmer']]['attempts'] ?? 0) === 1, 'Deterministic job contract defect was retried.');
$expect(!empty($programmer[$jobs['success']]['ok']) && ($programmer[$jobs['success']]['attempts'] ?? 0) === 1, 'A deterministic job defect prevented the next job from running.');

[$concurrencyWorker, $concurrencyPipes] = $startWorker('concurrency', $concurrencyState);
$waitForState($concurrencyState, 'started');
$contended = $commerce->runBackgroundJobs([$jobs['concurrency']], ['source' => $runId]);
$expect(!empty($contended[$jobs['concurrency']]['skipped']) && ($contended[$jobs['concurrency']]['reason'] ?? '') === 'already_running' && ($contended[$jobs['concurrency']]['attempts'] ?? -1) === 0, 'Concurrent duplicate execution was not rejected.');
[$concurrencyExit, $concurrencyOut, $concurrencyErr] = $closeWorker($concurrencyWorker, $concurrencyPipes);
$expect($concurrencyExit === 0 && str_contains($concurrencyOut, 'concurrency-complete') && $concurrencyErr === '', 'Concurrency worker did not finish cleanly.');

[$crashWorker, $crashPipes] = $startWorker('crash', $crashState);
$waitForState($crashState, 'crashing');
[$crashExit] = $closeWorker($crashWorker, $crashPipes);
$expect($crashExit !== 0, 'Crash worker unexpectedly exited successfully.');
$resumeDeadline = microtime(true) + 3.0;
do {
    $resumed = $commerce->runBackgroundJobs([$jobs['crash']], ['source' => $runId]);
    if (!empty($resumed[$jobs['crash']]['ok'])) break;
    if (($resumed[$jobs['crash']]['reason'] ?? '') !== 'already_running') break;
    usleep(50000);
} while (microtime(true) < $resumeDeadline);
$expect(!empty($resumed[$jobs['crash']]['ok']) && ($resumed[$jobs['crash']]['value'] ?? '') === 'crash-resumed', 'A crashed worker left the job locked or unable to resume.');

$unknown = $commerce->runBackgroundJobs(['fixture_unknown_' . $runId], ['source' => $runId]);
$expect(!empty($unknown['fixture_unknown_' . $runId]['skipped']), 'Unknown background job was not safely skipped.');

echo "Mercato background job concurrency, retry, partial failure, and crash-resume tests passed.\n";
