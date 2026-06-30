<?php
namespace ProcessWire;
/**
 * mrc-success.php
 *
 * Shown after a successful payment.
 * Reads the last completed order from session and displays a confirmation.
 *
 * Customize this to match your site's layout.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-success')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';
$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';

$completionAttempted = false;
$pendingOrder = $session->get('mrc_pending_order');
$isMollieReturn = is_array($pendingOrder)
    && (($pendingOrder['payment_method'] ?? '') === 'mollie')
    && $input->get('mollie');
$isDemoReturn = is_array($pendingOrder)
    && (($pendingOrder['payment_method'] ?? '') === 'demo')
    && $input->get('demo_payment');
$isBankTransferReturn = is_array($pendingOrder)
    && (($pendingOrder['payment_method'] ?? '') === 'bank-transfer')
    && $input->get('bank_transfer');

if ($input->get('payment_intent') || $input->get('redirect_status') || $isMollieReturn || $isDemoReturn || $isBankTransferReturn) {
    $completionAttempted = true;
    try {
        $orderPage = $commerce->completePayment([
            'payment_intent' => $input->get->text('payment_intent'),
            'payment_intent_secret' => $input->get->text('payment_intent_client_secret'),
            'redirect_status' => $input->get->text('redirect_status'),
            'mollie' => $input->get->text('mollie'),
            'demo_payment' => $input->get->text('demo_payment'),
            'bank_transfer' => $input->get->text('bank_transfer'),
        ]);
        $session->set('mrc_last_order_id', $orderPage->id);
        $session->redirect($page->url);
    } catch (WireException $e) {
        $commerce->setMessage('Error: ' . $e->getMessage());
    }
}

// After completion the order id is kept for one clean page load so refreshes
// do not try to capture the same gateway payment again.
$orderPage = $session->get('mrc_last_order_id')
    ? $pages->get((int) $session->get('mrc_last_order_id'))
    : null;

$session->remove('mrc_last_order_id');

$message = $commerce->getMessage();
$status = ($orderPage && $orderPage->id) ? (string) ($orderPage->mrc_payment_status ?: '') : '';
$isPaid = ($orderPage && $orderPage->id) && ((int) $orderPage->mrc_payment_complete === 1 || $status === Mercato::PAYMENT_STATUS_PAID);
$isProcessing = $status === Mercato::PAYMENT_STATUS_PROCESSING;
$isFailed = MercatoPaymentStatus::isFailureOutcome($status);
$isBankTransferOrder = ($orderPage && $orderPage->id) && (string) $orderPage->mrc_payment_method === 'bank-transfer';
$bankTransferInstructions = trim((string) $commerce->bank_transfer_instructions);
$confirmationSent = $isPaid && $orderPage->hasField('mrc_confirmation_sent_date') && trim((string) $orderPage->mrc_confirmation_sent_date) !== '';
$senderConfigured = trim((string) $commerce->notification_sender_email) !== '';
$analyticsEvent = ($orderPage && $orderPage->id && $isPaid && method_exists($commerce, 'getOrderAnalyticsEvent'))
    ? $commerce->getOrderAnalyticsEvent($orderPage, 'purchase')
    : [];
$productsUrl = mrc_storefront_page_url($pages, $config, 'products');
$checkoutUrl = mrc_storefront_page_url($pages, $config, 'checkout');
$successPanelClass = $isVanilla ? $ui['panel'] : $ui['panel'] . ' mrc-reveal mx-auto my-8 max-w-5xl';
$successHeadingClass = $isVanilla ? '' : 'mrc-display text-5xl font-semibold leading-none md:text-7xl';
$statusKicker = 'Unavailable';
$statusTitle = 'Order status unavailable';
$statusLead = 'No completed payment is available for this session.';
$statusTone = 'neutral';
if ($isPaid) {
    $statusKicker = 'Confirmed';
    $statusTitle = 'Thank you for your order.';
    $statusLead = 'We have received your payment and the studio will prepare your pieces for fulfilment.';
    $statusTone = 'paid';
} elseif ($isProcessing) {
    $statusKicker = 'Processing';
    $statusTitle = $isBankTransferOrder ? 'Your order is awaiting bank transfer.' : 'Your payment is processing.';
    $statusLead = $isBankTransferOrder
        ? 'We reserved your order and will confirm it as soon as the transfer arrives.'
        : 'The payment provider is still confirming the payment. Keep this page or your order number for reference.';
    $statusTone = 'processing';
} elseif ($isFailed) {
    $statusKicker = 'Payment failed';
    $statusTitle = 'Payment could not be completed.';
    $statusLead = 'The order was created, but payment did not complete. You can return to checkout and try again.';
    $statusTone = 'failed';
}

$cart = null;
$formattedItems = [];
$discountTotal = 0.0;
$shippingTotal = 0.0;
$fulfilmentLabel = 'Shipping';
$orderTotal = 0.0;
$taxRates = [];
$taxLabel = '';
if ($orderPage && $orderPage->id && ($isPaid || $isProcessing)) {
    $cart = $commerce->productList(json_decode($orderPage->mrc_items, true) ?? []);
    $formattedItems = $cart->getFormattedItems();
    $discountTotal = $orderPage->hasField('mrc_discount_total') ? (float) $orderPage->mrc_discount_total : 0.0;
    $shippingTotal = $orderPage->hasField('mrc_shipping_amount') ? (float) $orderPage->mrc_shipping_amount : $cart->getShipping();
    $fulfilmentLabel = ($orderPage->hasField('mrc_fulfilment_label') && trim((string) $orderPage->mrc_fulfilment_label) !== '')
        ? (string) $orderPage->mrc_fulfilment_label
        : 'Shipping';
    $hasFulfilmentSnapshot = $orderPage->hasField('mrc_fulfilment_method') && trim((string) $orderPage->mrc_fulfilment_method) !== '';
    $orderTotal = ($orderPage->hasField('mrc_total_amount') && ((float) $orderPage->mrc_total_amount > 0 || $hasFulfilmentSnapshot))
        ? (float) $orderPage->mrc_total_amount
        : max(0, $cart->getSubtotal() + $shippingTotal - $discountTotal);
    $taxRates = $commerce->getTaxRatesForOrder($cart, $shippingTotal);
    $taxLabel = $commerce->getTaxLabel($orderPage);
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Confirmed</title>
    <?= $frameworkAssets ?>
    <?= mrc_storefront_assets($isVanilla) ?>
	    <?php if ($isVanilla): ?>
	    <style>
        .mrc-page {
            --pw-spacing: 25px;
            --pw-main-color: #eb1d61;
            --pw-text-color: #111;
            --pw-muted-color: rgba(0,0,0,0.55);
            --pw-border-color: rgba(0,0,0,0.16);
            --pw-main-background: #eee;
            --pw-inputs-background: #f8f8f8;
            --pw-blocks-background: #fff;
            --pw-alert-success: #c1e7cd;
            --pw-alert-warning: #fff0be;
            --pw-alert-danger: #fee6e6;
            color: var(--pw-text-color);
            background: var(--pw-main-background);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
            margin: 0;
            min-height: 100vh;
            padding: var(--pw-spacing);
        }
        .mrc-page a { color: var(--pw-main-color); }
        .mrc-wrap {
            background: var(--pw-blocks-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            max-width: 920px;
            padding: var(--pw-spacing);
        }
        .mrc-kicker {
            color: var(--pw-muted-color);
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .mrc-message,
        .mrc-status-note {
            background: var(--pw-inputs-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            margin-bottom: 20px;
            padding: 12px 16px;
        }
        .mrc-status-note.is-paid { background: var(--pw-alert-success); }
        .mrc-status-note.is-processing { background: var(--pw-alert-warning); }
        .mrc-status-note.is-failed { background: var(--pw-alert-danger); }
        .mrc-table { border-collapse: collapse; margin: 20px 0; width: 100%; }
        .mrc-table th {
            color: var(--pw-muted-color);
            font-size: 12px;
            letter-spacing: 0.04em;
            text-align: left;
            text-transform: uppercase;
        }
        .mrc-table th,
        .mrc-table td {
            border-top: 1px solid var(--pw-border-color);
            padding: 10px 8px;
        }
        .mrc-button {
            background: var(--pw-text-color);
            border: 1px solid transparent;
            border-radius: 6px;
            color: var(--pw-blocks-background);
            display: inline-flex;
            font-weight: 700;
            min-height: 40px;
            padding: 0 18px;
            text-decoration: none;
            align-items: center;
        }
	    </style>
	    <?php else: ?>
	    <style>
	        .mrc-success-shell {
	            display: grid;
	            gap: clamp(34px, 5vw, 74px);
	            padding-bottom: clamp(60px, 8vw, 110px);
	            padding-top: clamp(28px, 5vw, 70px);
	        }
	        .mrc-success-hero {
	            display: grid;
	            gap: clamp(26px, 5vw, 72px);
	            grid-template-columns: minmax(0, 1.05fr) minmax(360px, .95fr);
	            align-items: stretch;
	        }
	        .mrc-success-copy {
	            align-content: center;
	            display: grid;
	            min-height: 520px;
	        }
	        .mrc-success-title {
	            font-size: clamp(62px, 8vw, 124px);
	            font-weight: 600;
	            line-height: .88;
	            margin: 0;
	            max-width: 820px;
	        }
	        .mrc-success-lead {
	            color: var(--mrc-muted);
	            font-size: clamp(17px, 1.45vw, 21px);
	            line-height: 1.75;
	            margin: 24px 0 0;
	            max-width: 650px;
	        }
	        .mrc-success-actions {
	            display: flex;
	            flex-wrap: wrap;
	            gap: 12px;
	            margin-top: 32px;
	        }
	        .mrc-success-meta {
	            display: flex;
	            flex-wrap: wrap;
	            gap: 10px;
	            margin-top: 28px;
	        }
	        .mrc-success-chip {
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            color: var(--mrc-muted);
	            display: inline-flex;
	            font-size: 12px;
	            font-weight: 800;
	            gap: 8px;
	            letter-spacing: .08em;
	            padding: 10px 12px;
	            text-transform: uppercase;
	        }
	        .mrc-success-status {
	            background: var(--mrc-cream);
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            display: grid;
	            grid-template-rows: auto 1fr auto;
	            min-height: 520px;
	            overflow: hidden;
	        }
	        .mrc-success-status-top {
	            align-items: center;
	            border-bottom: 1px solid var(--mrc-line);
	            display: flex;
	            justify-content: space-between;
	            padding: 22px;
	        }
	        .mrc-success-mark {
	            align-items: center;
	            background: var(--mrc-pine);
	            border-radius: var(--mrc-radius);
	            color: var(--mrc-cream);
	            display: inline-flex;
	            font-size: 20px;
	            font-weight: 700;
	            height: 46px;
	            justify-content: center;
	            width: 46px;
	        }
	        .mrc-success-status.is-processing .mrc-success-mark { background: var(--mrc-rust); }
	        .mrc-success-status.is-failed .mrc-success-mark { background: #5b241f; }
	        .mrc-success-status-body {
	            align-content: center;
	            display: grid;
	            gap: 18px;
	            padding: clamp(24px, 4vw, 44px);
	        }
	        .mrc-success-status-body h2 {
	            font-size: clamp(34px, 4.4vw, 64px);
	            font-weight: 600;
	            line-height: .94;
	            margin: 0;
	        }
	        .mrc-success-status-body p {
	            color: var(--mrc-muted);
	            line-height: 1.7;
	            margin: 0;
	        }
	        .mrc-success-note {
	            background: rgba(165, 145, 124, .12);
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            color: var(--mrc-ink);
	            line-height: 1.65;
	            padding: 16px;
	        }
	        .mrc-success-status-foot {
	            border-top: 1px solid var(--mrc-line);
	            display: grid;
	            gap: 12px;
	            padding: 22px;
	        }
	        .mrc-success-foot-row {
	            display: flex;
	            gap: 18px;
	            justify-content: space-between;
	        }
	        .mrc-success-foot-row span {
	            color: var(--mrc-muted);
	        }
	        .mrc-success-foot-row strong {
	            text-align: right;
	        }
	        .mrc-success-grid {
	            display: grid;
	            gap: 18px;
	            grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr);
	        }
	        .mrc-success-panel {
	            background: var(--mrc-cream);
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            padding: clamp(20px, 3vw, 34px);
	        }
	        .mrc-success-panel-head {
	            align-items: end;
	            border-bottom: 1px solid var(--mrc-line);
	            display: flex;
	            gap: 18px;
	            justify-content: space-between;
	            margin-bottom: 22px;
	            padding-bottom: 18px;
	        }
	        .mrc-success-panel-head h2 {
	            font-size: clamp(36px, 4.5vw, 64px);
	            font-weight: 600;
	            line-height: .94;
	            margin: 0;
	        }
	        .mrc-success-items {
	            display: grid;
	            gap: 14px;
	        }
	        .mrc-success-item {
	            align-items: center;
	            display: grid;
	            gap: 16px;
	            grid-template-columns: 84px minmax(0, 1fr) auto;
	        }
	        .mrc-success-item-media {
	            aspect-ratio: 1;
	            background: var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            overflow: hidden;
	        }
	        .mrc-success-item-media img {
	            display: block;
	            height: 100%;
	            object-fit: cover;
	            width: 100%;
	        }
	        .mrc-success-item-placeholder {
	            align-items: center;
	            color: var(--mrc-muted);
	            display: flex;
	            font-size: 11px;
	            font-weight: 800;
	            height: 100%;
	            justify-content: center;
	            letter-spacing: .18em;
	            text-transform: uppercase;
	        }
	        .mrc-success-item-title {
	            font-size: 18px;
	            font-weight: 700;
	            line-height: 1.25;
	            margin: 0;
	        }
	        .mrc-success-item-meta {
	            color: var(--mrc-muted);
	            font-size: 13px;
	            margin-top: 5px;
	        }
	        .mrc-success-item-sum {
	            font-weight: 800;
	            text-align: right;
	            white-space: nowrap;
	        }
	        .mrc-success-totals {
	            border-top: 1px solid var(--mrc-line);
	            display: grid;
	            gap: 12px;
	            margin-top: 24px;
	            padding-top: 20px;
	        }
	        .mrc-success-total-row {
	            display: flex;
	            gap: 18px;
	            justify-content: space-between;
	        }
	        .mrc-success-total-row span {
	            color: var(--mrc-muted);
	        }
	        .mrc-success-total-row strong {
	            text-align: right;
	        }
	        .mrc-success-total-row.is-grand {
	            border-top: 1px solid var(--mrc-line);
	            font-size: 22px;
	            padding-top: 16px;
	        }
	        .mrc-success-next {
	            display: grid;
	            gap: 14px;
	        }
	        .mrc-success-step {
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            display: grid;
	            gap: 8px;
	            padding: 18px;
	        }
	        .mrc-success-step strong {
	            font-size: 17px;
	        }
	        .mrc-success-step span {
	            color: var(--mrc-muted);
	            line-height: 1.55;
	        }
	        .mrc-success-empty {
	            background: var(--mrc-cream);
	            border: 1px solid var(--mrc-line);
	            border-radius: var(--mrc-radius);
	            display: grid;
	            gap: 16px;
	            padding: clamp(24px, 4vw, 44px);
	        }
	        @media (max-width: 980px) {
	            .mrc-success-hero,
	            .mrc-success-grid {
	                grid-template-columns: 1fr;
	            }
	            .mrc-success-copy,
	            .mrc-success-status {
	                min-height: auto;
	            }
	        }
	        @media (max-width: 620px) {
	            .mrc-success-item {
	                align-items: start;
	                grid-template-columns: 72px minmax(0, 1fr);
	            }
	            .mrc-success-item-sum {
	                grid-column: 2;
	                text-align: left;
	            }
	        }
	    </style>
	    <?php endif; ?>
	</head>
<body class="<?= $ui['body'] ?>">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'checkout') ?>
<?php endif; ?>
<?php if ($isVanilla): ?>
<main class="<?= $successPanelClass ?>">
    <?php if ($message): ?>
        <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($message) ?></div>
    <?php endif; ?>
    <span class="<?= $ui['kicker'] ?>"><?= $sanitizer->entities($statusKicker) ?></span>
    <h1 class="<?= $successHeadingClass ?>"><?= $sanitizer->entities($statusTitle) ?></h1>
    <p><?= $sanitizer->entities($statusLead) ?></p>
    <?php if ($orderPage && $orderPage->id && ($isPaid || $isProcessing)): ?>
        <p>Your order number is <strong><?= $sanitizer->entities($orderPage->mrc_invoice_number) ?></strong>.</p>
        <?php if ($isBankTransferOrder && $bankTransferInstructions !== ''): ?>
            <div class="<?= $ui['message'] ?>"><?= nl2br($sanitizer->entities($bankTransferInstructions)) ?></div>
        <?php endif; ?>
        <h2>Order Summary</h2>
        <table class="<?= $ui['table'] ?>">
            <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($formattedItems as $item): ?>
                <tr>
                    <td><?= $sanitizer->entities($item['title']) ?></td>
                    <td><?= (int) $item['quantity'] ?></td>
                    <td><?= $item['price'] ?></td>
                    <td><?= $item['sum'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php if ($shippingTotal > 0): ?>
                    <tr><td colspan="3"><?= $sanitizer->entities($fulfilmentLabel) ?></td><td><?= $commerce->formatPrice($shippingTotal) ?></td></tr>
                <?php elseif ($orderPage->hasField('mrc_fulfilment_method') && (string) $orderPage->mrc_fulfilment_method !== ''): ?>
                    <tr><td colspan="3"><?= $sanitizer->entities($fulfilmentLabel) ?></td><td>Free</td></tr>
                <?php endif; ?>
                <?php if ($discountTotal > 0): ?>
                    <tr><td colspan="3">Discount <?= $sanitizer->entities((string) $orderPage->mrc_discount_code) ?></td><td>-<?= $commerce->formatPrice($discountTotal) ?></td></tr>
                <?php endif; ?>
                <tr><td colspan="3"><strong>Total</strong></td><td><strong><?= $commerce->formatPrice($orderTotal) ?></strong></td></tr>
                <?php foreach ($taxRates as $rate): ?>
                    <tr><td colspan="3">incl. <?= $sanitizer->entities($taxLabel) ?> <?= $rate['tax_rate'] ?>%</td><td><?= $commerce->formatPrice($rate['sum']) ?></td></tr>
                <?php endforeach; ?>
            </tfoot>
        </table>
    <?php elseif ($orderPage && $orderPage->id && $isFailed): ?>
        <p class="<?= $ui['statusNote'] ?> <?= $ui['statusFailed'] ?>">Order <strong><?= $sanitizer->entities($orderPage->mrc_invoice_number) ?></strong> was not paid.</p>
    <?php elseif ($completionAttempted): ?>
        <p>The payment return could not be matched to a valid order.</p>
    <?php endif; ?>
    <p><a class="<?= $ui['button'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Continue shopping</a></p>
</main>
<?php else: ?>
<main class="mrc-section mrc-success-shell">
    <?php if ($message): ?>
        <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($message) ?></div>
    <?php endif; ?>
    <section class="mrc-success-hero">
        <div class="mrc-success-copy">
            <span class="<?= $ui['kicker'] ?>"><?= $sanitizer->entities($statusKicker) ?></span>
            <h1 class="mrc-display mrc-success-title"><?= $sanitizer->entities($statusTitle) ?></h1>
            <p class="mrc-success-lead"><?= $sanitizer->entities($statusLead) ?></p>
            <?php if ($orderPage && $orderPage->id): ?>
                <div class="mrc-success-meta" aria-label="Order details">
                    <span class="mrc-success-chip">Order <?= $sanitizer->entities($orderPage->mrc_invoice_number) ?></span>
                    <?php if (trim((string) $orderPage->mrc_email) !== ''): ?>
                        <span class="mrc-success-chip"><?= $sanitizer->entities($orderPage->mrc_email) ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $orderPage->mrc_payment_method) !== ''): ?>
                        <span class="mrc-success-chip"><?= $sanitizer->entities(str_replace('-', ' ', (string) $orderPage->mrc_payment_method)) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="mrc-success-actions">
                <a class="<?= $ui['button'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Continue shopping</a>
                <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $sanitizer->entities($checkoutUrl) ?>">Back to checkout</a>
            </div>
        </div>
        <aside class="mrc-success-status is-<?= $sanitizer->entities($statusTone) ?>" aria-label="Order status">
            <div class="mrc-success-status-top">
                <span class="<?= $ui['kicker'] ?>">Status</span>
                <span class="mrc-success-mark"><?= $isPaid ? 'OK' : ($isFailed ? '!' : '...') ?></span>
            </div>
            <div class="mrc-success-status-body">
                <h2 class="mrc-display"><?= $isPaid ? 'Order confirmed' : ($isFailed ? 'Action needed' : ($isProcessing ? 'Reserved for you' : 'No order loaded')) ?></h2>
                <?php if ($orderPage && $orderPage->id && ($isPaid || $isProcessing)): ?>
                    <?php if ($isPaid && $confirmationSent): ?>
                        <p>A confirmation was sent to <strong><?= $sanitizer->entities($orderPage->mrc_email) ?></strong>.</p>
                    <?php elseif ($isPaid && $senderConfigured): ?>
                        <p>Payment received. Confirmation email delivery is pending; please keep your order number.</p>
                    <?php elseif ($isPaid): ?>
                        <p>Payment received. Please keep your order number; email notifications are not configured yet.</p>
                    <?php elseif ($isBankTransferOrder): ?>
                        <p>We will confirm the order after the transfer arrives.</p>
                    <?php else: ?>
                        <p>We will confirm the order after the payment provider finishes processing it.</p>
                    <?php endif; ?>
                    <?php if ($isBankTransferOrder && $bankTransferInstructions !== ''): ?>
                        <div class="mrc-success-note"><?= nl2br($sanitizer->entities($bankTransferInstructions)) ?></div>
                    <?php endif; ?>
                <?php elseif ($orderPage && $orderPage->id && $isFailed): ?>
                    <p>Order <strong><?= $sanitizer->entities($orderPage->mrc_invoice_number) ?></strong> was not paid.</p>
                <?php elseif ($completionAttempted): ?>
                    <p>The payment return could not be matched to a valid order.</p>
                <?php else: ?>
                    <p>No completed payment is available for this session.</p>
                <?php endif; ?>
            </div>
            <?php if ($orderPage && $orderPage->id && ($isPaid || $isProcessing)): ?>
                <div class="mrc-success-status-foot">
                    <div class="mrc-success-foot-row"><span>Order total</span><strong><?= $commerce->formatPrice($orderTotal) ?></strong></div>
                    <div class="mrc-success-foot-row"><span>Fulfilment</span><strong><?= $sanitizer->entities($fulfilmentLabel) ?></strong></div>
                    <div class="mrc-success-foot-row"><span>Payment</span><strong><?= $sanitizer->entities(ucwords(str_replace('-', ' ', (string) $orderPage->mrc_payment_method))) ?></strong></div>
                </div>
            <?php endif; ?>
        </aside>
    </section>

    <?php if ($orderPage && $orderPage->id && ($isPaid || $isProcessing)): ?>
        <section class="mrc-success-grid">
            <div class="mrc-success-panel">
                <div class="mrc-success-panel-head">
                    <div>
                        <span class="<?= $ui['kicker'] ?>">Order summary</span>
                        <h2 class="mrc-display">Your pieces.</h2>
                    </div>
                    <span class="mrc-success-chip"><?= count($formattedItems) ?> line<?= count($formattedItems) === 1 ? '' : 's' ?></span>
                </div>
                <div class="mrc-success-items">
                    <?php foreach ($formattedItems as $item): ?>
                        <?php
                        $productId = (int) ($item['product_id'] ?? 0);
                        $product = $productId > 0 ? $pages->get($productId) : null;
                        $imageUrl = '';
                        if ($product && $product->id && $product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                            $imageUrl = $product->mrc_images->first()->url;
                        }
                        ?>
                        <article class="mrc-success-item">
                            <div class="mrc-success-item-media">
                                <?php if ($imageUrl !== ''): ?>
                                    <img src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($item['title'] ?? $item['id']) ?>">
                                <?php else: ?>
                                    <span class="mrc-success-item-placeholder">Item</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="mrc-success-item-title"><?= $sanitizer->entities($item['title'] ?? $item['id']) ?></p>
                                <div class="mrc-success-item-meta">Qty <?= (int) $item['quantity'] ?> / <?= $item['price'] ?> each</div>
                            </div>
                            <div class="mrc-success-item-sum"><?= $item['sum'] ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="mrc-success-totals">
                    <?php if ($shippingTotal > 0): ?>
                        <div class="mrc-success-total-row"><span><?= $sanitizer->entities($fulfilmentLabel) ?></span><strong><?= $commerce->formatPrice($shippingTotal) ?></strong></div>
                    <?php elseif ($orderPage->hasField('mrc_fulfilment_method') && (string) $orderPage->mrc_fulfilment_method !== ''): ?>
                        <div class="mrc-success-total-row"><span><?= $sanitizer->entities($fulfilmentLabel) ?></span><strong>Free</strong></div>
                    <?php endif; ?>
                    <?php if ($discountTotal > 0): ?>
                        <div class="mrc-success-total-row"><span>Discount <?= $sanitizer->entities((string) $orderPage->mrc_discount_code) ?></span><strong>-<?= $commerce->formatPrice($discountTotal) ?></strong></div>
                    <?php endif; ?>
                    <div class="mrc-success-total-row is-grand"><span>Total</span><strong><?= $commerce->formatPrice($orderTotal) ?></strong></div>
                    <?php foreach ($taxRates as $rate): ?>
                        <div class="mrc-success-total-row"><span>incl. <?= $sanitizer->entities($taxLabel) ?> <?= $rate['tax_rate'] ?>%</span><strong><?= $commerce->formatPrice($rate['sum']) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="mrc-success-panel">
                <div class="mrc-success-panel-head">
                    <div>
                        <span class="<?= $ui['kicker'] ?>">Next steps</span>
                        <h2 class="mrc-display">What happens now.</h2>
                    </div>
                </div>
                <div class="mrc-success-next">
                    <div class="mrc-success-step"><strong>Confirmation</strong><span><?= $isBankTransferOrder ? 'The studio confirms the order after the bank transfer is received.' : 'The order is stored with payment, fulfilment, tax, and discount snapshots.' ?></span></div>
                    <div class="mrc-success-step"><strong>Fulfilment</strong><span><?= $sanitizer->entities($fulfilmentLabel) ?> details stay attached to this order for receipts and admin handling.</span></div>
                    <div class="mrc-success-step"><strong>Receipt trail</strong><span>Invoice number <?= $sanitizer->entities($orderPage->mrc_invoice_number) ?> is the customer-safe reference for support.</span></div>
                </div>
            </aside>
        </section>
    <?php else: ?>
        <section class="mrc-success-empty">
            <span class="<?= $ui['kicker'] ?>">No receipt</span>
            <h2 class="mrc-display mrc-card-title">There is no completed order to show.</h2>
            <p class="mrc-lead"><?= $completionAttempted ? 'The payment return could not be matched to a valid order.' : 'No completed payment is available for this session.' ?></p>
            <p><a class="<?= $ui['button'] ?>" href="<?= $sanitizer->entities($productsUrl) ?>">Continue shopping</a></p>
        </section>
    <?php endif; ?>
</main>
<?php endif; ?>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
<?php if ($analyticsEvent): ?>
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push(<?= json_encode($analyticsEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
</script>
<?php endif; ?>
</body>
</html>
