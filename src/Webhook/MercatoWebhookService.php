<?php
namespace ProcessWire;

/**
 * Webhook request handling bridge.
 *
 * Endpoint methods in Mercato.module.php delegate here so gateway/webhook logic
 * can move out of the module facade incrementally.
 */
class MercatoWebhookService extends Wire {

    protected MercatoWebhookEventLog $eventLog;

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
        $this->eventLog = new MercatoWebhookEventLog();
    }

    public function getStripeInfoResponse(): array {
        return [
            'ok' => true,
            'endpoint' => 'Mercato Stripe webhook',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'expects' => 'POST from Stripe with Stripe-Signature header',
            'events' => [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'payment_intent.processing',
                'payment_intent.canceled',
                'checkout.session.completed',
                'checkout.session.expired',
                'charge.refunded',
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'invoice.paid',
                'invoice.payment_failed',
                'refund.created',
                'refund.updated',
                'refund.failed',
            ],
        ];
    }

    public function handleStripeRequest(): array {
        $payload = @file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if ($payload === false || $payload === '') {
            throw new WireException('Empty or unreadable payload', 400);
        }

        /** @var StripeGateway $gateway */
        $gateway = $this->commerce->getGateway('stripe');
        try {
            $stripeEvent = $gateway->constructWebhookEvent($payload, $sigHeader);
            $context = $this->getStripeEventContext($stripeEvent);
            if ($this->isProcessedDuplicate('stripe', (string) $stripeEvent->type, $context)) {
                $this->eventLog->ignored('stripe', (string) $stripeEvent->type, 'Duplicate webhook event already processed.', $context);
                return [
                    'received' => true,
                    'type' => $stripeEvent->type,
                    'processed' => false,
                    'duplicate' => true,
                ];
            }
            $this->eventLog->received('stripe', (string) $stripeEvent->type, $context);
            $applied = $this->applyStripeEvent($stripeEvent);
            if ($applied) {
                $this->eventLog->processed('stripe', (string) $stripeEvent->type, $context);
            } else {
                $this->eventLog->ignored('stripe', (string) $stripeEvent->type, 'No matching order/status update.', $context);
            }
        } catch (\Throwable $e) {
            $this->eventLog->failed('stripe', 'unknown', $e->getMessage());
            throw $e;
        }

        return [
            'received' => true,
            'type' => $stripeEvent->type,
        ];
    }

    public function getMollieInfoResponse(): array {
        return [
            'ok' => true,
            'endpoint' => 'Mercato Mollie webhook',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'expects' => 'POST from Mollie with form field "id"',
            'events' => ['payment status changes', 'refund processing/failed/refunded status changes'],
        ];
    }

    public function getPayPalInfoResponse(): array {
        return [
            'ok' => true,
            'endpoint' => 'Mercato PayPal webhook',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'expects' => 'POST from PayPal with application/json webhook payload',
            'events' => [
                'CHECKOUT.ORDER.APPROVED',
                'CHECKOUT.ORDER.CANCELLED',
                'PAYMENT.CAPTURE.COMPLETED',
                'PAYMENT.CAPTURE.DENIED',
                'PAYMENT.CAPTURE.PENDING',
                'PAYMENT.CAPTURE.REFUNDED',
            ],
            'verification' => 'Mercato verifies PayPal webhook signatures with the configured Webhook ID before processing production events.',
        ];
    }

    public function handleMollieRequest(): array {
        $paymentId = $this->getMolliePaymentIdFromRequest();
        if ($paymentId === '') {
            throw new WireException('Missing Mollie payment id', 400);
        }

        /** @var MollieGateway $gateway */
        $gateway = $this->commerce->getGateway('mollie');
        $context = ['external_payment_id' => $paymentId];
        $this->eventLog->received('mollie', 'payment.status', $context);

        $failureLogged = false;
        try {
            $payment = $gateway->retrievePayment($paymentId);
            $context['event_type'] = (string) ($payment['status'] ?? 'payment.status');
            $context['status'] = (string) ($payment['status'] ?? '');
            if ($this->isProcessedDuplicate('mollie', 'payment.status', $context)) {
                $this->eventLog->ignored('mollie', 'payment.status', 'Duplicate webhook event already processed.', $context);
                return [
                    'received' => true,
                    'payment_id' => $paymentId,
                    'processed' => false,
                    'duplicate' => true,
                ];
            }
            $order = $this->commerce->orderRepository()->findByMolliePayment($payment);

            if (!$order || !$order->id) {
                $this->eventLog->failed('mollie', 'payment.status', 'Order not found for Mollie payment', $context);
                $failureLogged = true;
                throw new WireException('Order not found for Mollie payment', 404);
            }

            $context['order_page_id'] = (int) $order->id;
            if (in_array(strtolower((string) $order->mrc_payment_status), [MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
                $refundResult = $this->reconcileRefundWebhook($order, 'mollie');
                $context['status'] = (string) ($refundResult['status'] ?? $order->mrc_payment_status);
                $context['refund_reconciled'] = true;
                $this->eventLog->processed('mollie', 'refund.status', $context);
                return [
                    'received' => true,
                    'payment_id' => $paymentId,
                    'order_id' => $order->id,
                    'status' => $context['status'],
                ];
            }

            $incomingStatus = $gateway->mapExternalStatus((string) ($payment['status'] ?? 'open'));
            if (MercatoPaymentStatus::wouldRegressSettled((string) $order->mrc_payment_status, $incomingStatus)) {
                $context['status'] = $incomingStatus;
                $this->eventLog->ignored('mollie', 'payment.status', 'Delayed webhook would regress a settled payment.', $context);
                return [
                    'received' => true,
                    'payment_id' => $paymentId,
                    'order_id' => $order->id,
                    'status' => (string) $order->mrc_payment_status,
                    'processed' => false,
                    'out_of_order' => true,
                ];
            }

            $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
            $pending = $this->commerce->orderRepository()->pageToPendingData($order);
            $pending = $gateway->applyPaymentToOrder($pending, $payment);
            $updated = $this->commerce->orderRepository()->savePendingOrder($pending);
            if (($pending['payment_status'] ?? '') === Mercato::PAYMENT_STATUS_PAID) {
                $this->commerce->orderRepository()->decrementStockOnce($updated);
                $this->commerce->paymentCompleted($updated, MercatoPaymentStatus::PAID);
            } elseif (MercatoPaymentStatus::isFailureOutcome((string) ($pending['payment_status'] ?? ''))) {
                $this->commerce->orderRepository()->releaseStockReservation($updated);
                $this->commerce->paymentFailed($updated, (string) $pending['payment_status']);
            }
            $this->commerce->emitOrderStatusChanged($updated, $previousOrderStatus, ['source' => 'mollie_webhook', 'payment_status' => (string) ($pending['payment_status'] ?? '')]);
            $context['status'] = (string) ($pending['payment_status'] ?? Mercato::PAYMENT_STATUS_PENDING);
            $this->eventLog->processed('mollie', 'payment.status', $context);
        } catch (\Throwable $e) {
            if (!$failureLogged) {
                $this->eventLog->failed('mollie', 'payment.status', $e->getMessage(), $context);
            }
            throw $e;
        }

        return [
            'received' => true,
            'payment_id' => $paymentId,
            'order_id' => $updated->id,
            'status' => $pending['payment_status'] ?? Mercato::PAYMENT_STATUS_PENDING,
        ];
    }

    public function handlePayPalRequest(): array {
        $payload = @file_get_contents('php://input');
        if ($payload === false || trim($payload) === '') {
            throw new WireException('Empty or unreadable payload', 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new WireException('Invalid PayPal webhook JSON', 400);
        }

        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $context = $this->getPayPalEventContext($event);
        if ($this->isProcessedDuplicate('paypal', $eventType, $context)) {
            $this->eventLog->ignored('paypal', $eventType, 'Duplicate webhook event already processed.', $context);
            return [
                'received' => true,
                'processed' => false,
                'duplicate' => true,
                'type' => $eventType,
            ];
        }
        $this->eventLog->received('paypal', $eventType, $context);

        /** @var PayPalGateway $gateway */
        $gateway = $this->commerce->getGateway('paypal');
        $verified = false;
        if ($this->shouldVerifyPayPalWebhook()) {
            $verified = $gateway->verifyWebhookSignature($event, $this->getPayPalSignatureHeaders());
            if (!$verified) {
                $this->eventLog->failed('paypal', $eventType, 'PayPal webhook signature verification failed.', $context);
                throw new WireException('PayPal webhook signature verification failed.', 400);
            }
            $context['verified'] = true;
        } else {
            $context['verified'] = false;
        }

        $status = $this->mapPayPalWebhookStatus($eventType);
        if ($status === '') {
            $this->eventLog->ignored('paypal', $eventType, 'Unsupported PayPal event type.', $context);
            return [
                'received' => true,
                'processed' => false,
                'type' => $eventType,
                'reason' => 'Unsupported PayPal event type.',
            ];
        }

        $order = $this->commerce->orderRepository()->findByPayPalReference(
            (string) ($context['paypal_order_id'] ?? ''),
            (int) ($context['order_page_id'] ?? 0),
            (string) ($context['invoice'] ?? '')
        );

        if (!$order || !$order->id) {
            $this->eventLog->ignored('paypal', $eventType, 'Order not found for PayPal webhook.', $context);
            return [
                'received' => true,
                'processed' => false,
                'type' => $eventType,
                'reason' => 'Order not found for PayPal webhook.',
            ];
        }

        $context['order_page_id'] = (int) $order->id;
        if (MercatoPaymentStatus::wouldRegressSettled((string) $order->mrc_payment_status, $status)) {
            $context['status'] = $status;
            $this->eventLog->ignored('paypal', $eventType, 'Delayed webhook would regress a settled payment.', $context);
            return [
                'received' => true,
                'processed' => false,
                'out_of_order' => true,
                'verified' => $verified,
                'type' => $eventType,
                'order_id' => (int) $order->id,
                'status' => (string) $order->mrc_payment_status,
            ];
        }
        $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
        $pending = $this->commerce->orderRepository()->pageToPendingData($order);
        $pending['payment_method'] = 'paypal';
        $pending['payment_status'] = $status;
        $pending['payment_complete'] = $status === MercatoPaymentStatus::PAID ? 1 : 0;
        $pending['payment_details'] = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($status === MercatoPaymentStatus::PAID) {
            $pending['paid_date'] = date('Y-m-d H:i:s');
        }

        $updated = $this->commerce->orderRepository()->savePendingOrder($pending);
        if ($status === MercatoPaymentStatus::PAID) {
            $this->commerce->orderRepository()->decrementStockOnce($updated);
            $this->commerce->paymentCompleted($updated, MercatoPaymentStatus::PAID);
        } elseif (MercatoPaymentStatus::isFailureOutcome($status)) {
            $this->commerce->orderRepository()->releaseStockReservation($updated);
            $this->commerce->paymentFailed($updated, $status);
        }
        $this->commerce->emitOrderStatusChanged($updated, $previousOrderStatus, ['source' => 'paypal_webhook', 'payment_status' => $status]);

        $context['status'] = $status;
        $this->eventLog->processed('paypal', $eventType, $context);

        return [
            'received' => true,
            'processed' => true,
            'verified' => $verified,
            'type' => $eventType,
            'order_id' => (int) $updated->id,
            'status' => $status,
        ];
    }

    public function simulatePaymentStatus(Page $order, string $gateway, string $targetStatus, string $userName = ''): array {
        if (!empty($this->commerce->production)) {
            throw new WireException($this->commerce->_('Webhook simulation is available only in test mode.'));
        }
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            throw new WireException($this->commerce->_('Order not found.'));
        }

        $gateway = strtolower(trim($gateway));
        if (!in_array($gateway, ['stripe', 'mollie', 'paypal'], true)) {
            throw new WireException($this->commerce->_('Only Stripe, Mollie, and PayPal webhook simulation is supported.'));
        }

        $targetStatus = strtolower(trim($targetStatus));
        $allowedStatuses = [
            MercatoPaymentStatus::PAID,
            MercatoPaymentStatus::PROCESSING,
            MercatoPaymentStatus::FAILED,
            MercatoPaymentStatus::CANCELED,
        ];
        if (!in_array($targetStatus, $allowedStatuses, true)) {
            throw new WireException($this->commerce->_('Invalid simulated payment status.'));
        }

        $from = strtolower(trim((string) $order->mrc_payment_status)) ?: MercatoPaymentStatus::PENDING;
        $alreadySettled = (int) $order->mrc_payment_complete === 1
            || in_array($from, [
                MercatoPaymentStatus::PAID,
                MercatoPaymentStatus::REFUNDED,
                MercatoPaymentStatus::PARTIALLY_REFUNDED,
            ], true);
        if ($alreadySettled) {
            throw new WireException($this->commerce->_('Settled orders cannot be changed by webhook simulation.'));
        }

        $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
        $externalId = $this->getSimulatedExternalPaymentId($order, $gateway);
        $eventType = $this->getSimulatedEventType($gateway, $targetStatus);
        $context = [
            'event_id' => 'sim_' . $gateway . '_' . $order->id . '_' . time(),
            'order_page_id' => (int) $order->id,
            'external_payment_id' => $externalId,
            'simulated' => true,
            'from' => $from,
            'to' => $targetStatus,
            'user' => $userName,
        ];

        $this->eventLog->received($gateway, $eventType, $context);

        $pending = $this->commerce->orderRepository()->pageToPendingData($order);
        $pending['payment_status'] = $targetStatus;
        $pending['payment_complete'] = $targetStatus === MercatoPaymentStatus::PAID ? 1 : 0;
        $pending['payment_details'] = json_encode([
            'simulated_webhook' => true,
            'gateway' => $gateway,
            'event_type' => $eventType,
            'external_payment_id' => $externalId,
            'from' => $from,
            'to' => $targetStatus,
            'at' => date('c'),
            'user' => $userName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($targetStatus === MercatoPaymentStatus::PAID) {
            $pending['paid_date'] = date('Y-m-d H:i:s');
        }
        if ($gateway === 'stripe') {
            $pending['stripe_payment_intent_id'] = $externalId;
        } elseif ($gateway === 'mollie') {
            $pending['mollie_payment_id'] = $externalId;
        }

        $updated = $this->commerce->orderRepository()->savePendingOrder($pending);
        $inventory = ['adjusted' => false, 'items' => [], 'errors' => []];
        if ($targetStatus === MercatoPaymentStatus::PAID) {
            $inventory = $this->commerce->orderRepository()->decrementStockOnce($updated);
            $this->commerce->paymentCompleted($updated, MercatoPaymentStatus::PAID);
        } elseif (MercatoPaymentStatus::isFailureOutcome($targetStatus)) {
            $this->commerce->orderRepository()->releaseStockReservation($updated, 'simulated_' . $targetStatus);
        }

        $context['inventory_errors'] = (array) ($inventory['errors'] ?? []);
        $this->eventLog->processed($gateway, $eventType, $context);
        $this->commerce->emitOrderStatusChanged($updated, $previousOrderStatus, [
            'source' => 'webhookSimulation',
            'gateway' => $gateway,
            'payment_status_from' => $from,
            'payment_status_to' => $targetStatus,
        ]);

        return [
            'order' => $updated,
            'gateway' => $gateway,
            'event_type' => $eventType,
            'from' => $from,
            'to' => $targetStatus,
            'external_payment_id' => $externalId,
            'inventory' => $inventory,
        ];
    }

    public function simulateDuplicateCallback(Page $order, string $gateway, string $userName = ''): array {
        if (!empty($this->commerce->production)) throw new WireException($this->commerce->_('Webhook simulation is available only in test mode.'));
        if (!$order || !$order->id) throw new WireException($this->commerce->_('Order not found.'));
        $gateway = strtolower(trim($gateway)); $eventType = $this->getSimulatedEventType($gateway, MercatoPaymentStatus::PROCESSING);
        $context = ['event_id' => 'sim_duplicate_' . $gateway . '_' . (int) $order->id, 'order_page_id' => (int) $order->id, 'external_payment_id' => $this->getSimulatedExternalPaymentId($order, $gateway), 'simulated' => true, 'user' => $userName];
        if ($this->isProcessedDuplicate($gateway, $eventType, $context)) return ['duplicate' => true, 'order' => $order, 'gateway' => $gateway, 'event_type' => $eventType, 'from' => (string) $order->mrc_payment_status, 'to' => (string) $order->mrc_payment_status, 'inventory' => ['errors' => []]];
        $this->eventLog->received($gateway, $eventType, $context); $this->eventLog->processed($gateway, $eventType, $context);
        return ['duplicate' => false, 'order' => $order, 'gateway' => $gateway, 'event_type' => $eventType, 'from' => (string) $order->mrc_payment_status, 'to' => (string) $order->mrc_payment_status, 'inventory' => ['errors' => []]];
    }

    protected function getMolliePaymentIdFromRequest(): string {
        $paymentId = trim((string) ($this->wire('input')->post->text('id') ?: ($_POST['id'] ?? '')));
        if ($paymentId !== '') {
            return $paymentId;
        }

        parse_str((string) @file_get_contents('php://input'), $rawPost);
        return trim((string) ($rawPost['id'] ?? ''));
    }

    protected function isProcessedDuplicate(string $gateway, string $eventType, array $context): bool {
        $this->eventLog->setWire($this->wire());
        return $this->eventLog->hasProcessed(new MercatoWebhookEvent(
            gateway: $gateway,
            eventType: $eventType,
            status: 'received',
            eventId: (string) ($context['event_id'] ?? ''),
            orderPageId: (int) ($context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($context['external_payment_id'] ?? ''),
            context: $context,
        ));
    }

    protected function getSimulatedExternalPaymentId(Page $order, string $gateway): string {
        $current = '';
        if ($gateway === 'stripe') {
            $current = (string) $order->mrc_stripe_payment_intent_id;
        } elseif ($gateway === 'mollie') {
            $current = (string) $order->mrc_mollie_payment_id;
        } elseif ($gateway === 'paypal') {
            $details = json_decode((string) $order->mrc_payment_details, true);
            $current = is_array($details) ? (string) ($details['id'] ?? $details['paypal_order_id'] ?? '') : '';
        }
        if (trim($current) !== '') {
            return trim($current);
        }

        return match ($gateway) {
            'mollie' => 'tr_sim_' . $order->id,
            'paypal' => 'PAYPAL-SIM-' . $order->id,
            default => 'pi_sim_' . $order->id,
        };
    }

    protected function getSimulatedEventType(string $gateway, string $status): string {
        if ($gateway === 'mollie') {
            return 'payment.status';
        }
        if ($gateway === 'paypal') {
            return match ($status) {
                MercatoPaymentStatus::PAID => 'PAYMENT.CAPTURE.COMPLETED',
                MercatoPaymentStatus::FAILED => 'PAYMENT.CAPTURE.DENIED',
                MercatoPaymentStatus::CANCELED => 'CHECKOUT.ORDER.CANCELLED',
                default => 'CHECKOUT.ORDER.APPROVED',
            };
        }

        return match ($status) {
            MercatoPaymentStatus::PAID => 'payment_intent.succeeded',
            MercatoPaymentStatus::FAILED => 'payment_intent.payment_failed',
            MercatoPaymentStatus::CANCELED => 'payment_intent.canceled',
            default => 'payment_intent.processing',
        };
    }

    protected function getPayPalEventContext(array $event): array {
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $purchaseUnit = $this->getPayPalPurchaseUnit($resource);
        $relatedIds = is_array($resource['supplementary_data']['related_ids'] ?? null)
            ? $resource['supplementary_data']['related_ids']
            : [];
        $paypalOrderId = (string) ($relatedIds['order_id'] ?? '');
        if ($paypalOrderId === '') {
            $paypalOrderId = (string) ($resource['id'] ?? '');
        }

        $orderPageId = (int) ($purchaseUnit['custom_id'] ?? $purchaseUnit['reference_id'] ?? 0);
        $invoice = (string) ($purchaseUnit['invoice_id'] ?? '');

        return [
            'event_id' => (string) ($event['id'] ?? ''),
            'event_type' => (string) ($event['event_type'] ?? ''),
            'order_page_id' => $orderPageId,
            'external_payment_id' => (string) ($resource['id'] ?? $paypalOrderId),
            'paypal_order_id' => $paypalOrderId,
            'invoice' => $invoice,
            'status' => (string) ($resource['status'] ?? ''),
        ];
    }

    protected function shouldVerifyPayPalWebhook(): bool {
        if (!empty($this->commerce->production)) {
            return true;
        }

        $headers = $this->getPayPalSignatureHeaders();
        foreach ($headers as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function getPayPalSignatureHeaders(): array {
        return [
            'transmission_id' => (string) ($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ''),
            'transmission_time' => (string) ($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? ''),
            'cert_url' => (string) ($_SERVER['HTTP_PAYPAL_CERT_URL'] ?? ''),
            'auth_algo' => (string) ($_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? ''),
            'transmission_sig' => (string) ($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? ''),
        ];
    }

    protected function getPayPalPurchaseUnit(array $resource): array {
        $purchaseUnits = $resource['purchase_units'] ?? [];
        if (is_array($purchaseUnits) && is_array($purchaseUnits[0] ?? null)) {
            return $purchaseUnits[0];
        }
        return [];
    }

    protected function mapPayPalWebhookStatus(string $eventType): string {
        return MercatoPaymentStatusMapper::payPalWebhookEvent($eventType);
    }

    protected function applyStripeEvent(\Stripe\Event $stripeEvent): bool {
        if ($this->isStripeRefundEvent((string) $stripeEvent->type)) {
            return $this->applyStripeRefundEvent($stripeEvent);
        }
        if ($this->isStripeCheckoutSessionEvent((string) $stripeEvent->type)) {
            return $this->applyStripeCheckoutSessionEvent($stripeEvent);
        }
        if ($this->isStripeSubscriptionEvent((string) $stripeEvent->type)) {
            return $this->applyStripeSubscriptionEvent($stripeEvent);
        }
        if ($this->isStripeSubscriptionInvoiceEvent((string) $stripeEvent->type)) {
            return $this->applyStripeSubscriptionInvoiceEvent($stripeEvent);
        }

        $pi = $stripeEvent->data->object ?? null;
        if (!$pi || empty($pi->metadata->mrc_order_id)) {
            return false;
        }

        $order = $this->wire('pages')->get((int) $pi->metadata->mrc_order_id);
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }

        $status = MercatoPaymentStatusMapper::stripeWebhookEvent((string) $stripeEvent->type);

        if ($status === '') {
            return false;
        }

        return $this->saveStripeOrderStatus($order, $status, $pi);
    }

    protected function getStripeEventContext(\Stripe\Event $stripeEvent): array {
        $object = $stripeEvent->data->object ?? null;
        $eventType = (string) $stripeEvent->type;
        return [
            'event_id' => (string) ($stripeEvent->id ?? ''),
            'order_page_id' => (int) ($object->metadata->mrc_order_id ?? 0),
            'external_payment_id' => (string) (
                $this->isStripeRefundEvent($eventType)
                    ? $this->getStripeRefundPaymentIntent($object)
                    : ($this->isStripeCheckoutSessionEvent($eventType)
                        ? ($object->payment_intent ?? $object->id ?? '')
                        : ($this->isStripeSubscriptionEvent($eventType)
                            ? ($object->id ?? '')
                            : ($this->isStripeSubscriptionInvoiceEvent($eventType)
                                ? ($this->getStripeObjectId($object->payment_intent ?? '') ?: ($object->id ?? ''))
                                : ($object->id ?? ''))))
            ),
            'external_refund_id' => $this->getStripeEventRefundId($eventType, $object),
        ];
    }

    protected function applyStripeRefundEvent(\Stripe\Event $stripeEvent): bool {
        $object = $stripeEvent->data->object ?? null;
        if (!$object) {
            return false;
        }

        $orderId = (int) ($object->metadata->mrc_order_id ?? 0);
        $order = $orderId > 0 ? $this->wire('pages')->get($orderId) : null;
        if (!$order || !$order->id) {
            $order = $this->commerce->orderRepository()->findByStripePaymentIntent($this->getStripeRefundPaymentIntent($object));
        }
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }
        if (!in_array(strtolower((string) $order->mrc_payment_status), [MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
            return false;
        }

        $this->reconcileRefundWebhook($order, 'stripe');
        return true;
    }

    protected function applyStripeCheckoutSessionEvent(\Stripe\Event $stripeEvent): bool {
        $session = $stripeEvent->data->object ?? null;
        if (!$session) {
            return false;
        }

        $order = $this->getStripeOrderFromObject($session);
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }

        $status = $this->getStripeCheckoutSessionStatus((string) $stripeEvent->type, $session);
        if ($status === '') {
            return false;
        }

        $this->saveStripeCheckoutSessionSubscription($order, $session);
        return $this->saveStripeOrderStatus($order, $status, $session);
    }

    protected function isStripeRefundEvent(string $eventType): bool {
        return in_array($eventType, ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'], true);
    }

    protected function isStripeCheckoutSessionEvent(string $eventType): bool {
        return in_array($eventType, ['checkout.session.completed', 'checkout.session.expired'], true);
    }

    protected function isStripeSubscriptionEvent(string $eventType): bool {
        return in_array($eventType, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true);
    }

    protected function isStripeSubscriptionInvoiceEvent(string $eventType): bool {
        return in_array($eventType, ['invoice.paid', 'invoice.payment_failed'], true);
    }

    protected function applyStripeSubscriptionEvent(\Stripe\Event $stripeEvent): bool {
        $subscription = $stripeEvent->data->object ?? null;
        if (!$subscription) {
            return false;
        }

        $order = $this->getStripeOrderFromSubscription($subscription);
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }

        $this->saveStripeSubscriptionStatus($order, $subscription);
        return true;
    }

    protected function applyStripeSubscriptionInvoiceEvent(\Stripe\Event $stripeEvent): bool {
        $invoice = $stripeEvent->data->object ?? null;
        if (!$invoice) {
            return false;
        }

        $order = $this->getStripeOrderFromInvoice($invoice);
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }

        $this->recordStripeSubscriptionRenewal($order, (string) $stripeEvent->type, $invoice);
        return true;
    }

    protected function getStripeOrderFromObject(object $object): ?Page {
        $orderId = (int) ($object->metadata->mrc_order_id ?? 0);
        $order = $orderId > 0 ? $this->wire('pages')->get($orderId) : null;
        if ($order && $order->id) {
            return $order;
        }

        return $this->commerce->orderRepository()->findByStripePaymentIntent((string) ($object->payment_intent ?? ''));
    }

    protected function getStripeOrderFromSubscription(object $subscription): ?Page {
        $orderId = (int) ($subscription->metadata->mrc_order_id ?? 0);
        $order = $orderId > 0 ? $this->wire('pages')->get($orderId) : null;
        if ($order && $order->id) {
            return $order;
        }

        $subscriptionId = $this->wire('sanitizer')->selectorValue((string) ($subscription->id ?? ''));
        if ($subscriptionId === '') {
            return null;
        }

        return $this->wire('pages')->get("template={$this->commerce->order_template}, include=all, mrc_subscription_id=$subscriptionId");
    }

    protected function getStripeOrderFromInvoice(object $invoice): ?Page {
        $orderId = (int) ($invoice->metadata->mrc_order_id ?? 0);
        $order = $orderId > 0 ? $this->wire('pages')->get($orderId) : null;
        if ($order && $order->id) {
            return $order;
        }

        $subscriptionId = $this->getStripeInvoiceSubscriptionId($invoice);
        if ($subscriptionId === '') {
            return null;
        }

        return $this->wire('pages')->get("template={$this->commerce->order_template}, include=all, mrc_subscription_id=$subscriptionId");
    }

    protected function getStripeInvoiceSubscriptionId(object $invoice): string {
        return $this->wire('sanitizer')->selectorValue($this->getStripeObjectId($invoice->subscription ?? ''));
    }

    protected function getStripeObjectId(mixed $value): string {
        if (is_object($value)) {
            return (string) ($value->id ?? '');
        }
        return (string) $value;
    }

    protected function saveStripeCheckoutSessionSubscription(Page $order, object $session): void {
        $subscriptionId = $this->getStripeObjectId($session->subscription ?? '');
        $customerId = $this->getStripeObjectId($session->customer ?? '');
        if ($subscriptionId === '' && $customerId === '') {
            return;
        }

        $wire = $this->wire();
        $pages = $this->wire('pages');
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');

        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot update order subscription from checkout session.'));
        }

        $wire->users->setCurrentUser($superuser);

        try {
            $order->of(false);
            if ($subscriptionId !== '' && $order->hasField('mrc_subscription_id')) {
                $order->mrc_subscription_id = $subscriptionId;
            }
            if ($customerId !== '' && $order->hasField('mrc_stripe_customer_id')) {
                $order->mrc_stripe_customer_id = $customerId;
            }
            $pages->save($order);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }
    }

    protected function getStripeCheckoutSessionStatus(string $eventType, object $session): string {
        if ($eventType === 'checkout.session.expired') {
            return MercatoPaymentStatus::EXPIRED;
        }
        if ($eventType !== 'checkout.session.completed') {
            return '';
        }

        $paymentStatus = strtolower(trim((string) ($session->payment_status ?? '')));
        if (in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            return MercatoPaymentStatus::PAID;
        }
        if ($paymentStatus === 'unpaid') {
            return MercatoPaymentStatus::PROCESSING;
        }

        return MercatoPaymentStatusMapper::generic((string) ($session->status ?? 'processing'));
    }

    protected function getStripeRefundPaymentIntent(?object $object): string {
        return $object ? (string) ($object->payment_intent ?? '') : '';
    }

    protected function getStripeEventRefundId(string $eventType, ?object $object): string {
        if (!$object || !$this->isStripeRefundEvent($eventType)) {
            return '';
        }
        if ($eventType === 'charge.refunded') {
            return (string) ($object->latest_refund ?? '');
        }
        return (string) ($object->id ?? '');
    }

    protected function reconcileRefundWebhook(Page $order, string $gatewayName): array {
        $refundService = new MercatoRefundService($this->commerce);
        $refundService->setWire($this->wire());
        return $refundService->reconcilePendingFromWebhook($order, $gatewayName);
    }

    protected function saveStripeOrderStatus(Page $order, string $status, object $paymentIntent): bool {
        if (MercatoPaymentStatus::wouldRegressSettled((string) $order->mrc_payment_status, $status)) {
            return false;
        }
        $wire = $this->wire();
        $pages = $this->wire('pages');
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');

        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot update order from webhook.'));
        }

        $wire->users->setCurrentUser($superuser);

        try {
            $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
            $order->of(false);
            $order->mrc_payment_complete = $status === Mercato::PAYMENT_STATUS_PAID ? 1 : 0;
            if ($order->template->hasField('mrc_payment_status')) {
                $order->mrc_payment_status = $status;
            }
            if ($status === Mercato::PAYMENT_STATUS_PAID) {
                $order->mrc_paid_date = date('Y-m-d H:i:s');
                $order->removeStatus(Page::statusHidden);
            }
            $order->mrc_payment_details = json_encode($paymentIntent->toArray());
            $pages->save($order);
            if ($status === Mercato::PAYMENT_STATUS_PAID) {
                $this->commerce->orderRepository()->decrementStockOnce($order);
                $this->commerce->paymentCompleted($order, MercatoPaymentStatus::PAID);
            } elseif (MercatoPaymentStatus::isFailureOutcome($status)) {
                $this->commerce->orderRepository()->releaseStockReservation($order);
                $this->commerce->paymentFailed($order, $status);
            }
            $this->commerce->emitOrderStatusChanged($order, $previousOrderStatus, [
                'source' => 'stripe_webhook',
                'payment_status' => $status,
            ]);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }

        return true;
    }

    protected function saveStripeSubscriptionStatus(Page $order, object $subscription): void {
        $wire = $this->wire();
        $pages = $this->wire('pages');
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');

        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot update order subscription from webhook.'));
        }

        $wire->users->setCurrentUser($superuser);

        try {
            $order->of(false);
            if ($order->hasField('mrc_subscription_id')) {
                $order->mrc_subscription_id = (string) ($subscription->id ?? '');
            }
            if ($order->hasField('mrc_stripe_customer_id')) {
                $order->mrc_stripe_customer_id = $this->getStripeObjectId($subscription->customer ?? '');
            }
            if ($order->hasField('mrc_subscription_status')) {
                $order->mrc_subscription_status = (string) ($subscription->status ?? Mercato::SUBSCRIPTION_STATUS_NONE);
            }
            if ($order->hasField('mrc_subscription_current_period_end')) {
                $periodEnd = (int) ($subscription->current_period_end ?? 0);
                $order->mrc_subscription_current_period_end = $periodEnd > 0 ? date('Y-m-d H:i:s', $periodEnd) : '';
            }
            if ($order->hasField('mrc_subscription_cancel_at_period_end')) {
                $order->mrc_subscription_cancel_at_period_end = !empty($subscription->cancel_at_period_end) ? 1 : 0;
            }
            if ($order->hasField('mrc_subscription_canceled_at')) {
                $canceledAt = (int) ($subscription->canceled_at ?? $subscription->ended_at ?? 0);
                $order->mrc_subscription_canceled_at = $canceledAt > 0 ? date('Y-m-d H:i:s', $canceledAt) : '';
            }
            if ($order->hasField('mrc_subscription_cancel_details')) {
                $order->mrc_subscription_cancel_details = json_encode($this->getStripeSubscriptionCancelDetails($subscription), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if ($order->hasField('mrc_subscription_details')) {
                $details = method_exists($subscription, 'toArray') ? $subscription->toArray() : (array) $subscription;
                $order->mrc_subscription_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $pages->save($order);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }
    }

    protected function getStripeSubscriptionCancelDetails(object $subscription): array {
        $details = $subscription->cancellation_details ?? null;
        if (is_object($details)) {
            $details = method_exists($details, 'toArray') ? $details->toArray() : (array) $details;
        }
        if (!is_array($details)) {
            $details = [];
        }

        return [
            'cancel_at_period_end' => !empty($subscription->cancel_at_period_end),
            'canceled_at' => (int) ($subscription->canceled_at ?? 0),
            'ended_at' => (int) ($subscription->ended_at ?? 0),
            'cancel_at' => (int) ($subscription->cancel_at ?? 0),
            'reason' => (string) ($details['reason'] ?? ''),
            'feedback' => (string) ($details['feedback'] ?? ''),
            'comment' => (string) ($details['comment'] ?? ''),
            'details' => $details,
        ];
    }

    protected function recordStripeSubscriptionRenewal(Page $order, string $eventType, object $invoice): void {
        if (!$order->hasField('mrc_subscription_renewal_details')) {
            return;
        }

        $wire = $this->wire();
        $pages = $this->wire('pages');
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');

        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot record subscription renewal from webhook.'));
        }

        $wire->users->setCurrentUser($superuser);

        try {
            $existing = json_decode((string) $order->mrc_subscription_renewal_details, true);
            $events = is_array($existing) ? array_values($existing) : [];
            $invoiceId = (string) ($invoice->id ?? '');
            foreach ($events as $index => $event) {
                if (is_array($event) && (string) ($event['invoice_id'] ?? '') === $invoiceId && $invoiceId !== '') {
                    unset($events[$index]);
                }
            }

            $details = method_exists($invoice, 'toArray') ? $invoice->toArray() : (array) $invoice;
            $events[] = [
                'event_type' => $eventType,
                'invoice_id' => $invoiceId,
                'subscription_id' => $this->getStripeInvoiceSubscriptionId($invoice),
                'payment_intent_id' => $this->getStripeObjectId($invoice->payment_intent ?? ''),
                'status' => (string) ($invoice->status ?? ''),
                'paid' => !empty($invoice->paid),
                'amount_paid' => (int) ($invoice->amount_paid ?? 0),
                'amount_due' => (int) ($invoice->amount_due ?? 0),
                'currency' => (string) ($invoice->currency ?? ''),
                'created' => (int) ($invoice->created ?? 0),
                'recorded_at' => date('c'),
                'details' => $details,
            ];

            $order->of(false);
            if ($order->hasField('mrc_stripe_customer_id')) {
                $order->mrc_stripe_customer_id = $this->getStripeObjectId($invoice->customer ?? '');
            }
            $order->mrc_subscription_renewal_details = json_encode(array_values($events), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $pages->save($order);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }
    }
}
