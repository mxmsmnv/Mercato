<?php
namespace ProcessWire;

require_once __DIR__ . '/src/MercatoHealthSummaries.php';
require_once __DIR__ . '/src/MercatoConfigSupport.php';
require_once __DIR__ . '/src/MercatoStoreServices.php';
require_once __DIR__ . '/src/MercatoFrontendHooks.php';
require_once __DIR__ . '/src/MercatoOrderApi.php';
require_once __DIR__ . '/src/MercatoOrderExperience.php';
require_once __DIR__ . '/src/MercatoPersistenceGatewayHooks.php';
require_once __DIR__ . '/src/MercatoPublicEndpoints.php';
require_once __DIR__ . '/src/MercatoWebhookProductAuditHooks.php';
require_once __DIR__ . '/src/MercatoConfigInputfields.php';
require_once __DIR__ . '/src/MercatoConfigReadiness.php';
require_once __DIR__ . '/src/MercatoBusinessHealthSummaries.php';
require_once __DIR__ . '/src/Mcp/MercatoMcpProviderTrait.php';

/**
 * Mercato
 *
 * E-commerce module for ProcessWire. Cart, orders, and payment gateways.
 * Orders are stored as ProcessWire pages. Stripe and Mollie are supported out
 * of the box. Extensible gateway interface for custom providers.
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.4.0 (module info version: 140)
 * @license MIT
 */

class Mercato extends WireData implements Module, ConfigurableModule {

    use MercatoHealthSummaries;
    use MercatoConfigSupport;
    use MercatoStoreServices;
    use MercatoFrontendHooks;
    use MercatoOrderApi;
    use MercatoOrderExperience;
    use MercatoPersistenceGatewayHooks;
    use MercatoPublicEndpoints;
    use MercatoWebhookProductAuditHooks;
    use MercatoConfigInputfields;
    use MercatoConfigReadiness;
    use MercatoBusinessHealthSummaries;
    use MercatoMcpProviderTrait;

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_REQUIRES_CONFIRMATION = 'requires_confirmation';
    public const PAYMENT_STATUS_REQUIRES_ACTION = 'requires_action';
    public const PAYMENT_STATUS_PROCESSING = 'processing';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_CANCELED = 'canceled';

    public const SUBSCRIPTION_STATUS_NONE = 'none';
    public const SCHEMA_VERSION = 12;

    private const MCP_IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._:-]{8,191}$/';
    private const MCP_OPERATION_TABLE = 'mercato_mcp_operations';

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Mercato',
            'summary'  => 'E-commerce toolkit for ProcessWire. Cart, orders, Stripe and Mollie payments.',
            'version'  => 140,
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'shopping-cart',
            'mcpProvider' => true,
            'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1.0'],
            'installs' => ['ProcessMercato'],
        ];
    }

    // -----------------------------------------------------------------------
    // Module config defaults
    // -----------------------------------------------------------------------

    public static function getDefaultConfig(): array {
        return [
            'orders_parent'            => 'orders',
            'quotes_parent'            => 'quotes',
            'installed_schema_version' => 0,
            'success_page'             => 'checkout/success',
            'cancel_page'              => 'checkout',
            'policy_pages'             => [],
            'currency'                 => 'GBP',
            'currency_symbol'          => '£',
            'currency_symbol_position' => 'before', // before | after
            'markets_json'             => '',
            'invoice_prefix'           => '',
            'production'               => false,
            'frontend_framework'       => 'tailwind',
            'frontend_auto_assets'     => true,
            'frontend_tailwind_cdn_url' => 'https://cdn.tailwindcss.com',
            'frontend_bootstrap_cdn_url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'frontend_uikit_cdn_url'    => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/css/uikit.min.css',
            'bank_transfer_instructions' => "Please transfer the order total to your bank account and include the invoice number as payment reference.",
            'reservation_ttl_minutes'  => 30,
            'reservation_cleanup_schedule' => 'every30Minutes',
            'cart_retention_days'      => 30,
            'draft_order_retention_days' => 14,
            'webhook_payload_retention_days' => 90,
            'gateway_timeout_seconds' => 30,
            'gateway_retries' => 2,
            'customer_data_retention_days' => 0,
            'email_log_retention_days' => 180,
            'payment_attempt_retention_days' => 180,
            'operational_log_retention_days' => 365,
            'provider_reference_retention_days' => 0,
            'signed_link_retention_days' => 3650,
            'privacy_retention_schedule' => 'everyDay',
            'privacy_retention_batch_limit' => 100,
            'privacy_policy_version' => '1.0',
            'privacy_backup_retention_note' => '',
            'customer_accounts_mode' => 'disabled',
            'account_claim_guest_orders' => true,
            'account_token_ttl_minutes' => 60,
            'account_login_attempts' => 5,
            'account_login_window_seconds' => 900,
            'account_orders_per_page' => 10,
            'checkout_enabled' => true,
            'checkout_maintenance_message' => 'Checkout is temporarily unavailable. Please try again later.',
            'headless_api_enabled' => true,
            'headless_api_token_ttl_minutes' => 60,
            'headless_api_rate_limit_per_minute' => 60,
            'headless_api_max_body_bytes' => 65536,
            'headless_api_allowed_origins' => '',
            'push_notifications_enabled' => false,
            'push_transport' => 'apns',
            'apns_environment' => 'sandbox',
            'apns_team_id' => '',
            'apns_key_id' => '',
            'apns_bundle_id' => '',
            'apns_private_key_path' => '',
            'preupgrade_backup_required' => false,
            'backup_max_age_hours' => 24,
            'backup_evidence' => '',
            'health_check_token' => '',
            'health_storage_min_bytes' => 104857600,
            'health_cron_max_age_seconds' => 172800,
            'analytics_enabled' => false,
            'analytics_adapters' => ['data_layer', 'first_party'],
            'analytics_default_consent' => 'denied',
            'analytics_order_identifier' => 'invoice',
            'analytics_account_identifier' => 'omit',
            'low_stock_threshold'      => 5,
            'notification_sender_name' => 'Mercato Store',
            'notification_sender_email' => '',
            'notification_reply_to'    => '',
            'notification_transport'   => 'wiremail',
            'notification_locale'      => 'en',
            'notification_brand_color' => '#6b4f3a',
            'notification_logo_url'    => '',
            'notification_retries'     => 2,
            'enabled_notification_events' => ['order_confirmation', 'payment_failed', 'payment_recovery', 'refund', 'cancellation', 'shipment_tracking', 'pickup_ready', 'local_delivery', 'account_created', 'account_security'],
            'seo_site_name'          => 'Mercato Store',
            'seo_default_description' => '',
            'seo_default_robots'     => 'index,follow,max-image-preview:large',
            'seo_social_image_url'   => '',
            'seo_organization_logo_url' => '',
            'merchant_legal_details'   => '',
            'receipt_template_file'    => '',
            'receipt_pdf_url_template' => '',
            'confirmation_email_subject' => 'Order confirmation {invoice}',
            'confirmation_email_body'  => "Hello {customer},\n\nThank you for your order {invoice}.\n\n{items}\n\nSubtotal: {subtotal}\n{fulfilment}: {shipping}\n{fulfilment_details}\nDiscount: {discount}\nTotal: {total}\n\nReceipt:\n{receipt_link}\n\nYou can check order status here:\n{order_status_link}\n\nWe will send the next fulfilment update.\n\n{policy_links}",
            'quote_requests_enabled' => false,
            'quote_expiry_days' => 30,
            'quote_inventory_policy' => 'none',
            'quote_merchant_email' => '',
            'quote_customer_email_subject' => 'Quote request {quote} received',
            'quote_customer_email_body' => "Hello {customer},\n\nWe received your quote request {quote} for {total}.\n\nTrack its status here:\n{status_link}\n\nThank you.",
            'payment_link_email_subject' => 'Payment link for order {invoice}',
            'payment_link_email_body' => "Hello {customer},\n\nYou can pay order {invoice} for {total} here:\n{payment_link}\n\n{recovery_discount_line}\n\nTo stop recovery payment-link reminders, use this link:\n{recovery_unsubscribe_link}\n\nThank you.",
            'recovery_email_cooldown_minutes' => 1440,
            'recovery_automation_enabled' => false,
            'recovery_automation_schedule' => 'disabled',
            'recovery_automation_min_age_minutes' => 60,
            'recovery_automation_batch_limit' => 10,
            'recovery_discount_code' => '',
            'recovery_suppressed_emails' => '',
            'shipping_email_subject'  => 'Your order {invoice} has shipped',
            'shipping_email_body'     => "Hello {customer},\n\nYour order {invoice} has shipped.\nTracking: {tracking}\n{tracking_url}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'pickup_ready_email_subject' => 'Your order {invoice} is ready for pickup',
            'pickup_ready_email_body' => "Hello {customer},\n\nYour order {invoice} is ready for pickup.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'local_delivery_email_subject' => 'Your order {invoice} is out for delivery',
            'local_delivery_email_body' => "Hello {customer},\n\nYour order {invoice} is out for local delivery.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'enabled_fulfilment_methods' => ['carrier_delivery'],
            'default_fulfilment_method' => 'carrier_delivery',
            'carrier_delivery_label'  => 'Delivery',
            'free_shipping_threshold'  => 0.0,
            'shipping_dimensions_enabled' => false,
            'shipping_dimensions_field' => 'mrc_dimensions',
            'shipping_calculation_mode' => 'flat',
            'shipping_dimensional_divisor' => 5000.0,
            'shipping_missing_measurements' => 'flat',
            'shipping_rate_table' => '',
            'shipping_provider' => 'manual',
            'shipping_provider_failure_policy' => 'manual_fallback',
            'shipping_provider_timeout_seconds' => 5,
            'shipping_provider_retries' => 1,
            'shipping_provider_quote_ttl_seconds' => 900,
            'shipping_provider_origin' => '',
            'shipping_provider_service_map' => '',
            'shipping_provider_handling_fixed' => 0.0,
            'shipping_provider_handling_percent' => 0.0,
            'shipping_provider_allowed_regions' => '',
            'shipping_provider_package_mode' => 'combined',
            'shipping_provider_include_manual_rates' => false,
            'shipping_provider_webhook_secret' => '',
            'default_tax_rate'         => 20.0,
            'tax_display_mode'         => 'included',
            'tax_label'                => 'VAT',
            'tax_rounding_mode'        => 'line',
            'tax_shipping'             => false,
            'shipping_tax_rate'        => 20.0,
            'tax_provider'             => 'manual',
            'tax_provider_failure_policy' => 'fail_closed',
            'tax_provider_timeout_seconds' => 5,
            'tax_provider_retries'     => 1,
            'tax_registrations'        => '',
            'tax_nexus_regions'        => '',
            'allowed_delivery_countries' => '',
            'delivery_regions'        => '',
            'store_pickup_label'      => 'Store pickup',
            'store_pickup_address'    => '',
            'store_pickup_instructions' => '',
            'store_pickup_locations'  => '',
            'local_delivery_label'    => 'Local delivery',
            'delivery_windows'        => '',
            'local_delivery_fee'      => 0.0,
            'local_delivery_minimum_order' => 0.0,
            'local_delivery_postcodes' => '',
            'local_delivery_instructions' => '',
            'enabled_payment_methods'  => ['stripe-card', 'stripe-ideal', 'stripe-sepa', 'stripe-klarna', 'mollie', 'bank-transfer'],
            'stripe_test_pk'           => '',
            'stripe_test_sk'           => '',
            'stripe_live_pk'           => '',
            'stripe_live_sk'           => '',
            'stripe_webhook_secret'    => '',
            'stripe_automatic_payment_methods' => false,
            'mollie_test_key'          => '',
            'mollie_live_key'          => '',
            'paypal_test_client_id'    => '',
            'paypal_test_secret'       => '',
            'paypal_test_webhook_id'   => '',
            'paypal_live_client_id'    => '',
            'paypal_live_secret'       => '',
            'paypal_live_webhook_id'   => '',
            'checkout_template'        => 'mrc-checkout',
            'cart_template'            => 'mrc-product',
            'product_template'         => 'mrc-product',
            'order_template'           => 'mrc-order',
            'orders_template'          => 'mrc-orders',
            'quote_template'           => 'mrc-quote',
            'quotes_template'          => 'mrc-quotes',
        ];
    }

    // -----------------------------------------------------------------------
    // Instance state
    // -----------------------------------------------------------------------

    /** @var MercatoCart|null Cart for this request — one instance per module (singular=true) */
    protected ?MercatoCart $_cart = null;

    /** @var array<string, MercatoGatewayInterface> Registered payment gateways */
    protected array $gateways = [];

    protected ?MercatoOrderRepository $orderRepository = null;

    protected ?MercatoPaymentService $paymentService = null;

    protected ?MercatoRefundService $refundService = null;

    protected ?MercatoPaymentReconciliationAuditService $paymentReconciliationAuditService = null;

    protected ?MercatoDiscountService $discountService = null;

    protected ?MercatoWebhookService $webhookService = null;
    protected ?MercatoEmailDeliveryService $emailDeliveryService = null;
    protected ?MercatoEmailWebhookService $emailWebhookService = null;
    protected ?MercatoPushNotificationService $pushNotificationService = null;
    protected ?MercatoSeoService $seoService = null;
    protected ?MercatoPrivacyService $privacyService = null;
    protected ?MercatoCustomerAccountService $customerAccountService = null;
    protected ?MercatoOperationalService $operationalService = null;
    protected ?MercatoAnalyticsService $analyticsService = null;

    protected ?MercatoFulfilmentService $fulfilmentService = null;

    protected ?MercatoShippingProviderService $shippingProviderService = null;

    protected ?MercatoPurchasabilityService $purchasabilityService = null;

    protected ?MercatoQuoteService $quoteService = null;

    protected ?MercatoTaxService $taxService = null;

    protected ?MercatoMarketService $marketService = null;

    /** @var array<string,array<string,mixed>> Product snapshots captured before admin page saves. */
    protected array $productSaveSnapshots = [];

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    public function init(): void {
        // Load vendor autoloader FIRST — class files below contain \Stripe\* type hints
        // that PHP resolves at parse time and will Fatal if the class doesn't exist.
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        require_once __DIR__ . '/MercatoProductList.php';    // base class — must load first
        require_once __DIR__ . '/MercatoCart.php';           // extends MercatoProductList
        $this->requireGatewayClasses();

        $this->registerCompatibilityFunctions();

        // Register Stripe webhook API endpoint: /api/mercato/stripe-webhook
        $this->addHook('/api/mercato/stripe-webhook', $this, 'handleStripeWebhook');
        $this->addHook('/api/mercato/mollie-webhook', $this, 'handleMollieWebhook');
        $this->addHook('/api/mercato/paypal-webhook', $this, 'handlePayPalWebhook');
        $this->addHook('/api/mercato/recovery-unsubscribe', $this, 'handleRecoveryUnsubscribe');
        $this->addHook('/api/mercato/order-lookup', $this, 'handleOrderLookup');
        $this->addHook('/api/mercato/order-status', $this, 'handleOrderStatus');
        $this->addHook('/api/mercato/order-receipt', $this, 'handleOrderReceipt');
        $this->addHook('/api/mercato/order-receipt-pdf', $this, 'handleOrderReceiptPdf');
        $this->addHook('/api/mercato/order-packing-slip-pdf', $this, 'handleOrderPackingSlipPdf');
        $this->addHook('/api/mercato/download', $this, 'handleOrderDownload');
        $this->addHook('/api/mercato/read', $this, 'handleReadApi');
        $this->addHook('/api/mercato/quote-status', $this, 'handleQuoteStatus');
        $this->addHook('/api/mercato/shipping-webhook', $this, 'handleShippingWebhook');
        $this->addHook('/api/mercato/email-webhook', $this, 'handleEmailWebhook');
        $this->addHook('/sitemap-mercato.xml', $this, 'handleSeoSitemap');
        $this->addHook('/api/mercato/health', $this, 'handleHealthCheck');
        $this->addHook('/api/mercato/analytics-consent', $this, 'handleAnalyticsConsent');
        $this->addHook('/api/mercato/v1/?', $this, 'handleHeadlessApi');
        $this->addHook('/api/mercato/v1/{resource}/?', $this, 'handleHeadlessApi');
        $this->addHook('/api/mercato/v1/{resource}/{id}/?', $this, 'handleHeadlessApi');
        $this->addHook('/api/mercato/v1/{resource}/{id}/{action}/?', $this, 'handleHeadlessApi');

        // Block unpublish on completed orders
        $this->addHookBefore('Pages::saveReady', $this, 'hookBlockCompletedOrderChange');
        $this->addHookBefore('Pages::saveReady', $this, 'hookValidateProductVariants');
        $this->addHookBefore('Pages::saveReady', $this, 'hookCaptureProductPageSnapshot');
        $this->addHookAfter('Pages::saved', $this, 'hookRecordProductPageEdit');
    }

    public function ready(): void {
        $schedule = $this->getReservationCleanupSchedule();
        if ($schedule !== 'disabled' && $this->wire('modules')->isInstalled('LazyCron')) {
            $this->addHook('LazyCron::' . $schedule, $this, 'hookCleanupExpiredReservations');
        }
        $recoverySchedule = $this->getRecoveryAutomationSchedule();
        if ((bool) ($this->recovery_automation_enabled ?? false) && $recoverySchedule !== 'disabled' && $this->wire('modules')->isInstalled('LazyCron')) {
            $this->addHook('LazyCron::' . $recoverySchedule, $this, 'hookRunRecoveryAutomation');
        }
        $privacySchedule = (string) ($this->privacy_retention_schedule ?? 'everyDay');
        if ($privacySchedule !== 'disabled' && $this->wire('modules')->isInstalled('LazyCron')) $this->addHook('LazyCron::' . $privacySchedule, $this, 'hookRunPrivacyRetention');
    }

    protected function requireArchitectureClasses(): void {
        foreach ([
            MercatoEventLog::class => '/src/Logging/MercatoEventLog.php',
            MercatoCurrency::class => '/src/Pricing/MercatoCurrency.php',
            MercatoMarketService::class => '/src/Pricing/MercatoMarketService.php',
            MercatoPaymentStatus::class => '/src/Payment/MercatoPaymentStatus.php',
            MercatoPaymentStatusMapper::class => '/src/Payment/MercatoPaymentStatusMapper.php',
            MercatoPaymentAttempt::class => '/src/Payment/MercatoPaymentAttempt.php',
            MercatoPaymentAttemptEventLog::class => '/src/Payment/MercatoPaymentAttemptEventLog.php',
            MercatoPaymentService::class => '/src/Payment/MercatoPaymentService.php',
            MercatoPaymentEventLog::class => '/src/Payment/MercatoPaymentEventLog.php',
            MercatoPaymentReconciliationService::class => '/src/Payment/MercatoPaymentReconciliationService.php',
            MercatoRefundEventLog::class => '/src/Payment/MercatoRefundEventLog.php',
            MercatoRefundService::class => '/src/Payment/MercatoRefundService.php',
            MercatoPaymentReconciliationAudit::class => '/src/Payment/MercatoPaymentReconciliationAudit.php',
            MercatoPaymentReconciliationAuditService::class => '/src/Payment/MercatoPaymentReconciliationAuditService.php',
            MercatoOrderRepository::class => '/src/Order/MercatoOrderRepository.php',
            MercatoOrderStatus::class => '/src/Order/MercatoOrderStatus.php',
            MercatoFulfilmentStatus::class => '/src/Order/MercatoFulfilmentStatus.php',
            MercatoOrderTimeline::class => '/src/Order/MercatoOrderTimeline.php',
            MercatoFulfilmentEventLog::class => '/src/Fulfilment/MercatoFulfilmentEventLog.php',
            MercatoFulfilmentMethodType::class => '/src/Fulfilment/MercatoFulfilmentMethodType.php',
            MercatoFulfilmentService::class => '/src/Fulfilment/MercatoFulfilmentService.php',
            MercatoShippingRateCalculator::class => '/src/Fulfilment/MercatoShippingRateCalculator.php',
            MercatoShippingProviderInterface::class => '/src/Fulfilment/MercatoShippingProviderInterface.php',
            MercatoShippingQuote::class => '/src/Fulfilment/MercatoShippingQuote.php',
            MercatoReferenceShippingProvider::class => '/src/Fulfilment/MercatoReferenceShippingProvider.php',
            MercatoShippingProviderService::class => '/src/Fulfilment/MercatoShippingProviderService.php',
            MercatoShippingNotificationService::class => '/src/Fulfilment/MercatoShippingNotificationService.php',
            MercatoEmailTransportInterface::class => '/src/Notification/MercatoEmailTransportInterface.php',
            MercatoWireMailTransport::class => '/src/Notification/MercatoWireMailTransport.php',
            MercatoEmailEventCatalog::class => '/src/Notification/MercatoEmailEventCatalog.php',
            MercatoEmailTemplateRenderer::class => '/src/Notification/MercatoEmailTemplateRenderer.php',
            MercatoEmailDeliveryService::class => '/src/Notification/MercatoEmailDeliveryService.php',
            MercatoEmailWebhookAdapterInterface::class => '/src/Notification/MercatoEmailWebhookAdapterInterface.php',
            MercatoEmailWebhookService::class => '/src/Notification/MercatoEmailWebhookService.php',
            MercatoPushTransportInterface::class => '/src/Notification/MercatoPushTransportInterface.php',
            MercatoApnsTransport::class => '/src/Notification/MercatoApnsTransport.php',
            MercatoPushNotificationService::class => '/src/Notification/MercatoPushNotificationService.php',
            MercatoSeoRules::class => '/src/Seo/MercatoSeoRules.php',
            MercatoSeoService::class => '/src/Seo/MercatoSeoService.php',
            MercatoPrivacyRetentionPolicy::class => '/src/Privacy/MercatoPrivacyRetentionPolicy.php',
            MercatoPrivacyService::class => '/src/Privacy/MercatoPrivacyService.php',
            MercatoAccountPolicy::class => '/src/Account/MercatoAccountPolicy.php',
            MercatoCustomerAccountService::class => '/src/Account/MercatoCustomerAccountService.php',
            MercatoRuntimeCompatibility::class => '/src/Deployment/MercatoRuntimeCompatibility.php',
            MercatoOperationalService::class => '/src/Operations/MercatoOperationalService.php',
            MercatoAnalyticsAdapterInterface::class => '/src/Analytics/MercatoAnalyticsAdapterInterface.php',
            MercatoFirstPartyAnalyticsAdapter::class => '/src/Analytics/MercatoFirstPartyAnalyticsAdapter.php',
            MercatoAnalyticsSchema::class => '/src/Analytics/MercatoAnalyticsSchema.php',
            MercatoAnalyticsService::class => '/src/Analytics/MercatoAnalyticsService.php',
            MercatoHeadlessApiService::class => '/src/Api/MercatoHeadlessApiService.php',
            MercatoOrderConfirmationService::class => '/src/Notification/MercatoOrderConfirmationService.php',
            MercatoPaymentLinkService::class => '/src/Notification/MercatoPaymentLinkService.php',
            MercatoRecoveryService::class => '/src/Recovery/MercatoRecoveryService.php',
            MercatoGatewayCapabilities::class => '/src/Gateway/MercatoGatewayCapabilities.php',
            MercatoGatewaySetupStatus::class => '/src/Gateway/MercatoGatewaySetupStatus.php',
            MercatoProductionGuard::class => '/src/Gateway/MercatoProductionGuard.php',
            MercatoGatewayRequestPolicy::class => '/src/Gateway/MercatoGatewayRequestPolicy.php',
            MercatoDiscountType::class => '/src/Discount/MercatoDiscountType.php',
            MercatoDiscountRule::class => '/src/Discount/MercatoDiscountRule.php',
            MercatoDiscountService::class => '/src/Discount/MercatoDiscountService.php',
            MercatoPurchasabilityService::class => '/src/Product/MercatoPurchasabilityService.php',
            MercatoVariantDefinition::class => '/src/Product/MercatoVariantDefinition.php',
            MercatoVariantService::class => '/src/Product/MercatoVariantService.php',
            MercatoQuoteStatus::class => '/src/Quote/MercatoQuoteStatus.php',
            MercatoQuoteService::class => '/src/Quote/MercatoQuoteService.php',
            MercatoTaxProviderInterface::class => '/src/Tax/MercatoTaxProviderInterface.php',
            MercatoTaxQuote::class => '/src/Tax/MercatoTaxQuote.php',
            MercatoManualTaxProvider::class => '/src/Tax/MercatoManualTaxProvider.php',
            MercatoTaxService::class => '/src/Tax/MercatoTaxService.php',
            MercatoWebhookEvent::class => '/src/Webhook/MercatoWebhookEvent.php',
            MercatoWebhookEventLog::class => '/src/Webhook/MercatoWebhookEventLog.php',
            MercatoWebhookService::class => '/src/Webhook/MercatoWebhookService.php',
        ] as $class => $file) {
            $path = __DIR__ . $file;
            if (!class_exists($class) && file_exists($path)) {
                require_once $path;
            }
        }
    }

    protected function requireGatewayClasses(): void {
        require_once __DIR__ . '/MercatoGatewayInterface.php';
        $this->requireArchitectureClasses();
        require_once __DIR__ . '/gateways/StripeGateway.php';
        require_once __DIR__ . '/gateways/MollieGateway.php';
        require_once __DIR__ . '/gateways/PayPalGateway.php';
        require_once __DIR__ . '/gateways/BankTransferGateway.php';
        require_once __DIR__ . '/gateways/DemoGateway.php';
    }

    // -----------------------------------------------------------------------
    // Install / Uninstall
    // -----------------------------------------------------------------------

    public function install(): void {
        require_once __DIR__ . '/install/install.php';
        mercato_install($this);
    }

    public function uninstall(): void {
        require_once __DIR__ . '/install/install.php';
        mercato_uninstall($this);
    }
}
