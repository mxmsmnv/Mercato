<?php
namespace ProcessWire;

require_once dirname(__DIR__) . '/src/MercatoStoreServices.php';

final class MercatoInstallBootstrapHarness {
    use MercatoStoreServices;

    public int $architectureBootstrapCalls = 0;

    protected function requireArchitectureClasses(): void {
        $this->architectureBootstrapCalls++;
        require_once dirname(__DIR__) . '/src/Deployment/MercatoRuntimeCompatibility.php';
    }

    public function getEnabledPaymentMethods(): array {
        return ['bank-transfer'];
    }

    public function wire(?string $name = null): mixed {
        return $name === 'config' ? (object) ['version' => '3.0.246'] : null;
    }
}

$harness = new MercatoInstallBootstrapHarness();
$report = $harness->getRuntimeCompatibilityReport();

if ($harness->architectureBootstrapCalls !== 1) {
    throw new \RuntimeException('Runtime preflight did not bootstrap architecture classes.');
}
if (empty($report['ready'])) {
    throw new \RuntimeException('Fresh-install runtime preflight failed: ' . implode(' ', (array) ($report['errors'] ?? [])));
}

echo "Mercato fresh-install bootstrap test passed.\n";
