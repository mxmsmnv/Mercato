<?php
namespace ProcessWire;

/**
 * Maps external gateway status values into the canonical Mercato payment model.
 */
final class MercatoPaymentStatusMapper {

    public static function generic(string $status): string {
        return match (self::normalize($status)) {
            'paid', 'succeeded', 'complete', 'completed' => MercatoPaymentStatus::PAID,
            'authorized', 'requires_capture', 'approved' => MercatoPaymentStatus::AUTHORIZED,
            'pending', 'open', 'created', 'saved' => MercatoPaymentStatus::PENDING,
            'processing' => MercatoPaymentStatus::PROCESSING,
            'requires_payment_method', 'failed', 'denied', 'declined' => MercatoPaymentStatus::FAILED,
            'canceled', 'cancelled', 'voided' => MercatoPaymentStatus::CANCELED,
            'expired' => MercatoPaymentStatus::EXPIRED,
            'refunded' => MercatoPaymentStatus::REFUNDED,
            default => MercatoPaymentStatus::PENDING,
        };
    }

    public static function stripePaymentIntent(string $status): string {
        return match (self::normalize($status)) {
            'requires_payment_method' => MercatoPaymentStatus::FAILED,
            'requires_confirmation' => MercatoPaymentStatus::REQUIRES_CONFIRMATION,
            'requires_action' => MercatoPaymentStatus::REQUIRES_ACTION,
            'processing' => MercatoPaymentStatus::PROCESSING,
            'requires_capture' => MercatoPaymentStatus::AUTHORIZED,
            'succeeded' => MercatoPaymentStatus::PAID,
            'canceled' => MercatoPaymentStatus::CANCELED,
            default => self::generic($status),
        };
    }

    public static function stripeWebhookEvent(string $eventType): string {
        return match (self::normalize($eventType)) {
            'payment_intent.succeeded' => MercatoPaymentStatus::PAID,
            'payment_intent.payment_failed' => MercatoPaymentStatus::FAILED,
            'payment_intent.processing' => MercatoPaymentStatus::PROCESSING,
            'payment_intent.canceled' => MercatoPaymentStatus::CANCELED,
            default => '',
        };
    }

    public static function molliePayment(string $status): string {
        return match (self::normalize($status)) {
            'paid' => MercatoPaymentStatus::PAID,
            'authorized' => MercatoPaymentStatus::AUTHORIZED,
            'pending' => MercatoPaymentStatus::PROCESSING,
            'open' => MercatoPaymentStatus::PENDING,
            'canceled', 'cancelled' => MercatoPaymentStatus::CANCELED,
            'expired' => MercatoPaymentStatus::EXPIRED,
            'failed' => MercatoPaymentStatus::FAILED,
            default => self::generic($status),
        };
    }

    public static function payPalOrder(string $status): string {
        return match (self::normalize($status)) {
            'completed' => MercatoPaymentStatus::PAID,
            'approved' => MercatoPaymentStatus::AUTHORIZED,
            'created', 'saved' => MercatoPaymentStatus::PENDING,
            'payer_action_required' => MercatoPaymentStatus::REQUIRES_ACTION,
            'voided' => MercatoPaymentStatus::CANCELED,
            default => self::generic($status),
        };
    }

    public static function payPalWebhookEvent(string $eventType): string {
        return match (self::normalize($eventType)) {
            'checkout.order.approved' => MercatoPaymentStatus::AUTHORIZED,
            'payment.capture.completed' => MercatoPaymentStatus::PAID,
            'payment.capture.denied', 'payment.capture.declined' => MercatoPaymentStatus::FAILED,
            'payment.capture.pending' => MercatoPaymentStatus::PROCESSING,
            'payment.capture.refunded' => MercatoPaymentStatus::REFUNDED,
            'checkout.order.cancelled', 'checkout.order.canceled', 'checkout.order.voided' => MercatoPaymentStatus::CANCELED,
            default => '',
        };
    }

    private static function normalize(string $status): string {
        return strtolower(trim($status));
    }
}
