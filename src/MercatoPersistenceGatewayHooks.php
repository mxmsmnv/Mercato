<?php
namespace ProcessWire;

trait MercatoPersistenceGatewayHooks {

    protected function saveOrderPage(array $pending): Page {
        return $this->orderRepository()->savePendingOrder($pending);
    }

    /**
     * Accept camelCase checkout keys while storing ProcessWire fields
     * with the module's snake_case names.
     */
    public function normalizeOrderData(array $data): array {
        $aliases = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'paymentMethod' => 'payment_method',
            'paymentComplete' => 'payment_complete',
            'paymentStatus' => 'payment_status',
            'paymentDetails' => 'payment_details',
            'paidDate' => 'paid_date',
            'stripePaymentIntentId' => 'stripe_payment_intent_id',
            'molliePaymentId' => 'mollie_payment_id',
            'discountCode' => 'discount_code',
            'fulfilmentMethod' => 'fulfilment_method',
            'deliveryWindow' => 'delivery_window',
            'deliveryNote' => 'delivery_note',
            'purchaseOrderNumber' => 'purchase_order_number',
            'policyAccepted' => 'mrc_policy_accepted',
        ];

        foreach ($aliases as $from => $to) {
            if (array_key_exists($from, $data) && !array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
        }

        if (!empty($data['payment_method'])) {
            $data['payment_method'] = $this->normalizePaymentMethod((string) $data['payment_method']);
        }

        return $data;
    }

    public function normalizePaymentMethod(string $method): string {
        return match ($method) {
            'credit-card-sca' => 'stripe-card',
            'sepa-debit' => 'stripe-sepa',
            'klarna' => 'stripe-klarna',
            'ideal' => 'stripe-ideal',
            'mollie-payment' => 'mollie',
            'bank_transfer', 'banktransfer', 'invoice', 'manual' => 'bank-transfer',
            default => $method,
        };
    }

    protected function registerCompatibilityFunctions(): void {
        if (!function_exists(__NAMESPACE__ . '\\mercato')) {
            function mercato(): Mercato {
                return wire('modules')->get('Mercato');
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\cart')) {
            function cart(array $data = []): MercatoCart {
                return wire('modules')->get('Mercato')->cart($data ?: null);
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\productList')) {
            function productList(array $data = []): MercatoProductList {
                return wire('modules')->get('Mercato')->productList($data);
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\formatPrice')) {
            function formatPrice(float $price, ?bool $symbolBefore = null): string {
                return wire('modules')->get('Mercato')->formatPrice($price, $symbolBefore);
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\calculateTax')) {
            function calculateTax(float $grossPrice, float $taxRate): float {
                return wire('modules')->get('Mercato')->calculateTax($grossPrice, $taxRate);
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\calculateNet')) {
            function calculateNet(float $grossPrice, float $taxRate): float {
                return wire('modules')->get('Mercato')->calculateNet($grossPrice, $taxRate);
            }
        }
        if (!function_exists(__NAMESPACE__ . '\\formatIBAN')) {
            function formatIBAN(string $iban): string {
                return implode(' ', str_split(str_replace(' ', '', $iban), 4));
            }
        }
    }

    /**
     * Map form field keys to PW field names.
     * Override or extend via hook if you add custom fields.
     */
    protected function getOrderFieldMap(): array {
        return $this->orderRepository()->getFieldMap();
    }

    // -----------------------------------------------------------------------
    // Gateway registry
    // -----------------------------------------------------------------------

    /**
     * Register a gateway instance.
     *
     * @param string $name e.g. 'stripe', 'paypal'
     * @param MercatoGatewayInterface $gateway
     */
    public function registerGateway(string $name, MercatoGatewayInterface $gateway): void {
        $this->gateways[$name] = $gateway;
    }

    /**
     * Get gateway by payment_method name.
     * All 'stripe-*' methods (stripe-card, stripe-klarna, stripe-ideal, stripe-sepa)
     * are routed to the single StripeGateway instance.
     *
     * @throws WireException if not found
     */
    public function getGateway(string $name): MercatoGatewayInterface {
        // Module instances can be requested from CLI/admin contexts before init().
        // Keep payment operations safe in those contexts as well.
        $this->requireGatewayClasses();
        $name = $this->normalizePaymentMethod($name);

        // Lazy-init built-in gateways
        if (!isset($this->gateways['stripe'])) {
            $this->gateways['stripe'] = new StripeGateway($this);
        }
        if (!isset($this->gateways['mollie'])) {
            $this->gateways['mollie'] = new MollieGateway($this);
        }
        if (!isset($this->gateways['paypal'])) {
            $this->gateways['paypal'] = new PayPalGateway($this);
        }
        if (!isset($this->gateways['bank-transfer'])) {
            $this->gateways['bank-transfer'] = new BankTransferGateway($this);
        }
        if (empty($this->production) && !isset($this->gateways['demo'])) {
            $this->gateways['demo'] = new DemoGateway($this);
        }

        // Route all stripe-* payment methods to StripeGateway
        if (str_starts_with($name, 'stripe-') || $name === 'stripe') {
            return $this->gateways['stripe'];
        }
        if ($name === 'mollie') {
            return $this->gateways['mollie'];
        }
        if ($name === 'paypal') {
            return $this->gateways['paypal'];
        }
        if ($name === 'bank-transfer') {
            return $this->gateways['bank-transfer'];
        }
        if ($name === 'demo' && !empty($this->production)) {
            throw new WireException($this->_('Demo Payment is disabled in production mode.'));
        }
        if ($name === 'demo') {
            return $this->gateways['demo'];
        }

        if (!isset($this->gateways[$name])) {
            throw new WireException(
                sprintf($this->_('No gateway registered for payment method "%s".'), $name)
            );
        }
        return $this->gateways[$name];
    }

    /**
     * Return list of all registered gateway names.
     */
    public function getGatewayNames(): array {
        // Ensure built-in gateways are registered
        $this->getGateway('stripe');
        $this->getGateway('mollie');
        $this->getGateway('paypal');
        $this->getGateway('bank-transfer');
        if (empty($this->production)) {
            $this->getGateway('demo');
        }
        return array_keys($this->gateways);
    }

    /**
     * Gateway capability metadata for admin diagnostics and future checkout UI.
     *
     * @return array<string, MercatoGatewayCapabilities>
     */
    public function getGatewayCapabilities(): array {
        $capabilities = [];
        foreach ($this->getGatewayNames() as $name) {
            $gateway = $this->gateways[$name];
            if (method_exists($gateway, 'getCapabilities')) {
                $capabilities[$name] = $gateway->getCapabilities();
            }
        }
        return $capabilities;
    }

    /**
     * Gateway setup readiness for launch checklist diagnostics.
     *
     * @return array<string, MercatoGatewaySetupStatus>
     */
    public function getGatewaySetupStatuses(): array {
        $statuses = [];
        foreach ($this->getGatewayNames() as $name) {
            $gateway = $this->gateways[$name];
            if (method_exists($gateway, 'getSetupStatus')) {
                $statuses[$name] = $gateway->getSetupStatus();
            }
        }
        return $statuses;
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Validate order form data against required fields.
     * Returns array of error messages (empty = valid).
     */
    public function validateOrderData(array $data): array {
        $errors   = [];
        $required = ['first_name', 'last_name', 'email', 'payment_method'];

        foreach ($required as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                $errors[] = sprintf($this->_('Field "%s" is required.'), $field);
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->_('Invalid email address.');
        }

        // Validate payment_method is a known gateway to give a clear user-facing error
        // rather than a developer-facing "No gateway registered" exception later.
        if (!empty($data['payment_method'])) {
            if (!in_array($data['payment_method'], $this->getEnabledPaymentMethods(), true)) {
                $errors[] = sprintf($this->_('Payment method "%s" is disabled.'), $data['payment_method']);
                return $errors;
            }
            try {
                $this->getGateway($data['payment_method']);
            } catch (WireException $e) {
                $errors[] = sprintf($this->_('Invalid payment method "%s".'), $data['payment_method']);
            }
        }

        return $errors;
    }

    /**
     * Sanitize all string values in form data.
     * Numeric fields are cast, not stripped.
     */
    public function sanitizeFormData(array $data): array {
        $sanitize = $this->wire('sanitizer');
        $numeric  = ['quantity', 'price', 'tax_rate'];
        $result   = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $numeric)) {
                $result[$key] = (float) $value;
            } elseif ($key === 'email' && is_string($value)) {
                // Use email-specific sanitizer to preserve + and avoid entity encoding
                $result[$key] = $sanitize->email(trim($value));
            } elseif (in_array($key, ['notes', 'delivery_note'], true) && is_string($value)) {
                $result[$key] = $sanitize->textarea(trim($value));
            } elseif (is_string($value)) {
                $result[$key] = $sanitize->text(trim($value));
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function getProductPurchasability(Page $product, int $requestedQuantity = 1, float $cartQuantity = 0.0, int $excludeOrderId = 0): array {
        return $this->purchasabilityService()->evaluate($product, $requestedQuantity, $cartQuantity, $excludeOrderId);
    }

    // -----------------------------------------------------------------------
    // Hooks
    // -----------------------------------------------------------------------


}
