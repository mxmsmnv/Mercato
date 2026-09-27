<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$inventory = require __DIR__ . '/fixtures/MercatoVisualStateInventory.php';
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) throw new RuntimeException("Could not read {$path}.");
    return $contents;
};

$expect(($inventory['schema_version'] ?? null) === 1, 'Unexpected visual inventory schema.');
$requiredStates = $inventory['required_states'] ?? [];
$expect($requiredStates === ['empty', 'loading', 'normal', 'error', 'long_content', 'translated', 'large_data', 'responsive'], 'Visual-state vocabulary changed unexpectedly.');
$screens = $inventory['screens'] ?? [];
$expect(count($screens) >= 35, 'The visual inventory is unexpectedly small.');

// Every shipped Mercato template must be explicitly visual, storage-only, or a
// shared helper. New template files therefore fail closed until classified.
$templateFiles = array_map(
    static fn(string $path): string => 'templates/' . basename($path),
    glob($root . '/templates/mrc-*.php') ?: []
);
sort($templateFiles);
$classifiedTemplates = [];
foreach ($screens as $screen) {
    $source = (string) ($screen['source'] ?? '');
    if (str_starts_with($source, 'templates/mrc-')) $classifiedTemplates[$source] = true;
}
$classifiedTemplates = array_keys($classifiedTemplates); sort($classifiedTemplates);
$expect($classifiedTemplates === $templateFiles, 'A shipped Mercato template is missing from, or stale in, the visual inventory.');

// ProcessWire execute methods are the canonical admin route inventory. Export
// is included but classified non-visual because it returns a CSV download.
$adminSources = ['ProcessMercato.module.php', 'src/Admin/ProcessMercatoNotificationTemplates.php'];
$executeMethods = [];
foreach ($adminSources as $source) {
    preg_match_all('/public\s+function\s+(___execute[A-Za-z0-9_]*)\s*\(/', $read($source), $matches);
    foreach ($matches[1] as $method) $executeMethods[$source . '::' . $method] = true;
}
$classifiedMethods = [];
foreach ($screens as $screen) {
    if (!isset($screen['method'])) continue;
    $classifiedMethods[(string) $screen['source'] . '::' . (string) $screen['method']] = true;
}
ksort($executeMethods); ksort($classifiedMethods);
$expect(array_keys($executeMethods) === array_keys($classifiedMethods), 'A ProcessMercato execute route is missing from, or stale in, the visual inventory.');

$allowedStatuses = ['evidenced', 'fixture_ready', 'planned', 'not_applicable', 'manual_only'];
$stateTotals = array_fill_keys($requiredStates, 0);
$statusTotals = array_fill_keys($allowedStatuses, 0);
$visualCount = 0;
$visualRoutes = [];
foreach ($screens as $id => $screen) {
    $expect(isset($screen['surface'], $screen['source']), "{$id} lacks ownership/source metadata.");
    $expect(in_array($screen['surface'], ['storefront', 'admin', 'non_visual'], true), "{$id} has an unknown surface owner.");
    $expect(is_file($root . '/' . $screen['source']), "{$id} points to a missing source file.");
    if ($screen['surface'] === 'non_visual') {
        $expect(trim((string) ($screen['purpose'] ?? '')) !== '', "{$id} must explain why it is non-visual.");
        $expect(!isset($screen['states']), "{$id} is non-visual but declares visual states.");
        continue;
    }
    $visualCount++;
    $route = trim((string) ($screen['route'] ?? ''));
    $expect($route !== '', "{$id} lacks a concrete route contract.");
    $routeOwner = (string) ($visualRoutes[$route] ?? '');
    $expect($routeOwner === '', "{$id} duplicates visual route {$route} owned by {$routeOwner}.");
    $visualRoutes[$route] = $id;
    $states = $screen['states'] ?? [];
    $expect(array_keys($states) === $requiredStates, "{$id} does not classify every required visual state.");
    foreach ($states as $state => $definition) {
        $status = (string) ($definition['status'] ?? '');
        $expect(in_array($status, $allowedStatuses, true), "{$id}.{$state} has an invalid status.");
        $statusTotals[$status]++;
        if ($status === 'not_applicable') {
            $reason = trim((string) ($definition['reason'] ?? ''));
            $expect(strlen($reason) >= 40, "{$id}.{$state} needs a state-specific N/A reason, not a placeholder.");
            $expect(!preg_match('/\b(?:todo|tbd|unknown|n\/?a)\b/i', $reason), "{$id}.{$state} has a placeholder N/A reason.");
        }
        if (in_array($status, ['planned', 'fixture_ready'], true)) $expect(trim((string) ($definition['fixture_key'] ?? '')) !== '', "{$id}.{$state} lacks a deterministic fixture key.");
        if ($status === 'evidenced') {
            $evidence = trim((string) ($definition['evidence'] ?? ''));
            $expect($evidence !== '', "{$id}.{$state} lacks an evidence reference.");
            [$evidencePath, $evidenceClaim] = array_pad(explode('#', $evidence, 2), 2, '');
            $expect((bool) preg_match('#^tests/e2e/[^/]+\.spec\.js$#', $evidencePath), "{$id}.{$state} claims non-browser evidence as visual evidence: {$evidencePath}.");
            $expect(is_file($root . '/' . $evidencePath), "{$id}.{$state} points to missing browser evidence {$evidencePath}.");
            $expect(trim($evidenceClaim) !== '', "{$id}.{$state} browser evidence lacks a specific scenario claim.");
        }
        $stateTotals[$state]++;
    }
}
$expect($visualCount >= 35, 'Expected at least 35 Mercato-owned HTML screens/routes.');
foreach ($stateTotals as $state => $count) $expect($count === $visualCount, "State {$state} is not classified on every visual screen.");
$classifiedStateCount = array_sum($statusTotals);
$expect($classifiedStateCount === $visualCount * count($requiredStates), 'Status totals do not account for every visual screen/state cell.');
$expect($statusTotals['manual_only'] === 0, 'Per-screen states must not hide automated gaps behind manual_only.');

// Loading is a semantic renderer capability, not a generic screenshot wish.
// The home template delegates to the catalog renderer, and no other current
// Mercato-owned HTML renderer declares an async busy/status contract.
$loadingCapableScreens = ['storefront.catalog', 'storefront.home'];
sort($loadingCapableScreens);
foreach ($screens as $id => $screen) {
    if (($screen['surface'] ?? '') === 'non_visual') continue;
    $loadingStatus = (string) ($screen['states']['loading']['status'] ?? '');
    if (in_array($id, $loadingCapableScreens, true)) {
        $expect($loadingStatus !== 'not_applicable', "{$id}.loading is implemented by the catalog renderer and cannot be N/A.");
    } else {
        $expect($loadingStatus === 'not_applicable', "{$id}.loading needs renderer evidence before it can be applicable.");
    }
}
$declaredLoadingSources = [];
$loadingCandidates = array_merge(
    glob($root . '/templates/*.php') ?: [],
    glob($root . '/src/*.php') ?: [],
    glob($root . '/src/Admin/*.php') ?: [],
    [$root . '/ProcessMercato.module.php']
);
foreach ($loadingCandidates as $path) {
    $contents = file_get_contents($path);
    if ($contents !== false && (str_contains($contents, 'aria-busy') || str_contains($contents, 'data-mrc-loading-status'))) {
        $declaredLoadingSources[] = str_replace($root . '/', '', $path);
    }
}
sort($declaredLoadingSources);
$expect($declaredLoadingSources === ['templates/mrc-products.php'], 'Mercato-owned async loading markup changed; re-audit the visual-state applicability matrix.');

$dark = $inventory['dark_mode'] ?? [];
$expect(($dark['status'] ?? '') === 'not_applicable' && ($dark['declared_supported'] ?? true) === false, 'Dark mode must remain explicitly N/A unless Mercato declares a store-wide contract.');
$expect(!str_contains($read('Mercato.module.php'), "'dark_mode'"), 'A dark-mode setting now exists; replace the N/A classification with real state coverage.');
foreach ($dark['incidental_adaptive_sources'] ?? [] as $source) {
    $expect(str_contains($read($source), 'prefers-color-scheme'), "Declared incidental adaptive source {$source} no longer contains its media-query behavior.");
}

$assistive = $inventory['assistive_evidence'] ?? [];
$expect(($assistive['status'] ?? '') === 'manual_open_system_setting_unchanged', 'Manual screen-reader status must stay explicit.');
$automatable = implode(' ', $assistive['automatable_via_dom_ax'] ?? []);
$manual = implode(' ', $assistive['manual_voiceover_open'] ?? []);
foreach (['accessible names', 'roles and aria state', 'live-region presence', 'validation message', 'contrast', 'reflow'] as $claim) {
    $expect(str_contains($automatable, $claim), "Automatable AX/DOM scope is missing {$claim}.");
}
foreach (['spoken announcement timing', 'rotor', 'speech naturalness', 'context retention', 'screen-reader-specific'] as $claim) {
    $expect(str_contains($manual, $claim), "Manual VoiceOver scope is missing {$claim}.");
}
$assistiveSpec = $read('tests/e2e/assistive-visual-evidence.spec.js');
$expect(str_contains($assistiveSpec, 'manualVoiceOver'), 'The agent-led evidence spec lost its manual VoiceOver checklist.');
$expect(str_contains($assistiveSpec, 'withRules([\'color-contrast\'])'), 'The agent-led evidence spec lost deterministic contrast coverage.');

echo "Mercato visual-state inventory contract passed: {$visualCount} HTML screens/routes, {$classifiedStateCount} state cells"
    . " (evidenced={$statusTotals['evidenced']}, fixture_ready={$statusTotals['fixture_ready']}, planned={$statusTotals['planned']}, not_applicable={$statusTotals['not_applicable']}), "
    . count($templateFiles) . ' templates, ' . count($executeMethods) . " admin execute routes, and explicit dark-mode/VoiceOver boundaries.\n";
