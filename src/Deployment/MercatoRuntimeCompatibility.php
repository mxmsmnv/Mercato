<?php
namespace ProcessWire;

final class MercatoRuntimeCompatibility {
    public const MIN_PHP = '8.1.0';
    public const MIN_PROCESSWIRE = '3.0.200';
    public const REQUIRED_EXTENSIONS = ['filter', 'hash', 'json', 'session'];

    public static function report(array $enabledPaymentMethods = [], ?string $processWireVersion = null): array {
        $errors = []; $warnings = [];
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) $errors[] = 'Mercato requires PHP ' . self::MIN_PHP . ' or newer; found ' . PHP_VERSION . '.';
        $processWireVersion ??= defined('PROCESSWIRE_VERSION') ? (string) constant('PROCESSWIRE_VERSION') : '';
        if ($processWireVersion !== '' && version_compare($processWireVersion, self::MIN_PROCESSWIRE, '<')) $errors[] = 'Mercato requires ProcessWire ' . self::MIN_PROCESSWIRE . ' or newer; found ' . $processWireVersion . '.';
        foreach (self::REQUIRED_EXTENSIONS as $extension) if (!extension_loaded($extension)) $errors[] = "Mercato requires the PHP extension ext-$extension.";
        $stripeEnabled = count(array_filter($enabledPaymentMethods, static fn($method) => str_starts_with((string) $method, 'stripe-'))) > 0;
        if ($stripeEnabled && !class_exists('Stripe\\StripeClient')) $errors[] = 'Stripe is enabled but stripe/stripe-php is missing. Run `composer install --no-dev --classmap-authoritative` inside the Mercato module directory, or deploy the complete release artifact.';
        if (!extension_loaded('curl')) $warnings[] = 'ext-curl is recommended for payment and provider HTTP requests; confirm ProcessWire WireHttp has a supported transport.';
        return ['ready' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'versions' => ['php' => PHP_VERSION, 'processwire' => $processWireVersion], 'extensions' => array_fill_keys(self::REQUIRED_EXTENSIONS, true), 'stripe_enabled' => $stripeEnabled, 'stripe_sdk' => class_exists('Stripe\\StripeClient')];
    }

    public static function assertInstallable(array $enabledPaymentMethods = [], ?string $processWireVersion = null): void {
        $report = self::report($enabledPaymentMethods, $processWireVersion);
        if (!$report['ready']) throw new \RuntimeException(implode(' ', $report['errors']));
    }
}
