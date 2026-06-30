<?php
namespace ProcessWire;
/**
 * mrc-checkout.php
 *
 * Checkout page with customer fields, cart summary, Stripe Payment Element,
 * and redirect gateways such as Mollie.
 */

/** @var Mercato $commerce */
$commerce = $modules->get('Mercato');
if (($templateOverride = $commerce->getStorefrontTemplateOverridePath('mrc-checkout')) !== '') {
    include $templateOverride;
    return;
}
require_once __DIR__ . '/mrc-storefront.php';
$error = '';
$paymentLinkOrder = null;
$paymentLinkToken = '';
$couponMessage = '';
$couponError = '';
$cartMessage = '';

if ($input->get('mrc_order') && $input->get('mrc_token')) {
    $candidate = $pages->get((int) $input->get('mrc_order'));
    $token = $input->get->text('mrc_token');
    if ($candidate && $candidate->id && $commerce->verifyPaymentLinkToken($candidate, $token)) {
        $items = json_decode((string) $candidate->mrc_items, true);
        if (is_array($items) && count($items)) {
            $commerce->cart($items);
            $session->set('mrc_payment_link_order_id', (int) $candidate->id);
            $session->set('mrc_payment_link_token', $token);
            $paymentLinkOrder = $candidate;
            $paymentLinkToken = $token;
            $linkDiscountCode = strtoupper($input->get->text('mrc_discount'));
            if ($linkDiscountCode !== '') {
                $linkDiscount = $commerce->cart()->applyCoupon($linkDiscountCode, (string) $candidate->mrc_email);
                if (!empty($linkDiscount['valid'])) {
                    $couponMessage = (string) ($linkDiscount['message'] ?? 'Coupon applied.');
                }
            }
        } else {
            $error = 'This payment link has no payable items.';
        }
    } else {
        $error = 'This payment link is invalid or no longer payable.';
    }
}

if (!$paymentLinkOrder && $session->get('mrc_payment_link_order_id') && $session->get('mrc_payment_link_token')) {
    $candidate = $pages->get((int) $session->get('mrc_payment_link_order_id'));
    $token = (string) $session->get('mrc_payment_link_token');
    if ($candidate && $candidate->id && $commerce->verifyPaymentLinkToken($candidate, $token)) {
        $paymentLinkOrder = $candidate;
        $paymentLinkToken = $token;
    } else {
        $session->remove('mrc_payment_link_order_id');
        $session->remove('mrc_payment_link_token');
    }
}

$cart = $commerce->cart();
$pendingOrder = $session->get('mrc_pending_order');
$clientSecret = is_array($pendingOrder) ? ($pendingOrder['stripe_client_secret'] ?? '') : '';
$publishableKey = '';
$ui = $commerce->getFrontendUiClasses();
$frameworkAssets = $commerce->renderFrontendFrameworkAssets();
$isVanilla = $commerce->getFrontendFramework() === 'vanilla';
$csrf = $session->CSRF ?? null;
$csrfInput = ($csrf && method_exists($csrf, 'renderInput')) ? (string) $csrf->renderInput() : '';
$hasValidCsrf = static function () use ($csrf): bool {
    return !$csrf || !method_exists($csrf, 'hasValidToken') || (bool) $csrf->hasValidToken();
};
$postedAction = (string) $input->post('mrc_action');
$checkoutNonce = (string) $session->get('mrc_checkout_nonce');
if ($checkoutNonce === '') {
    $checkoutNonce = bin2hex(random_bytes(16));
    $session->set('mrc_checkout_nonce', $checkoutNonce);
}

try {
    $publishableKey = $commerce->getGateway('stripe')->getPublishableKey();
} catch (WireException $e) {
    $publishableKey = '';
}

if ($postedAction !== '' && !$hasValidCsrf()) {
    $error = 'Form session expired. Please reload the page and try again.';
} elseif ($postedAction === 'apply_coupon') {
    $code = strtoupper($input->post->text('discount_code'));
    $discount = $cart->applyCoupon($code, $input->post->email('email'), true, [
        'source' => 'checkout_apply',
        'email' => $input->post->email('email'),
    ]);
    if (!empty($discount['valid'])) {
        $couponMessage = (string) ($discount['message'] ?? 'Coupon applied.');
    } else {
        $couponError = (string) ($discount['message'] ?? 'Coupon could not be applied.');
    }
} elseif ($postedAction === 'remove_coupon') {
    $commerce->discountService()->recordAuditEvent('removed', [
        'valid' => false,
        'code' => (string) $session->get('mrc_discount_code'),
        'message' => 'Coupon removed.',
    ], [
        'source' => 'checkout_remove',
        'email' => $input->post->email('email'),
    ]);
    $cart->removeCoupon();
    $couponMessage = 'Coupon removed.';
} elseif ($postedAction === 'update_cart') {
    try {
        $commerce->clearPendingCheckoutSession();
        $removeKey = (string) $input->post('remove_item');
        if ($removeKey !== '') {
            $cart->remove($removeKey);
            $cartMessage = 'Item removed from cart.';
        } else {
            $quantities = (array) $input->post('cart_quantity');
            foreach ($quantities as $key => $quantity) {
                $itemKey = (string) $key;
                $requestedQuantity = max(0, (int) $quantity);
                $item = $cart->getItem($itemKey);
                if ($item && $requestedQuantity > 0) {
                    $stockPolicy = strtolower((string) ($item['stock_policy'] ?? 'deny'));
                    $stock = isset($item['stock']) ? (int) $item['stock'] : null;
                    $productId = (int) ($item['product_id'] ?? 0);
                    $reserved = ($stockPolicy === 'deny' && $productId > 0)
                        ? $commerce->orderRepository()->getReservedQuantityForProduct($productId)
                        : 0;
                    $available = $stock === null ? null : max(0, $stock - $reserved);
                    if ($stockPolicy === 'deny' && $available !== null && $requestedQuantity > $available) {
                        throw new WireException(sprintf('Only %d available for %s.', $available, (string) ($item['title'] ?? 'this item')));
                    }
                }
                $cart->update([
                    'key' => $itemKey,
                    'quantity' => $requestedQuantity,
                ]);
            }
            $cartMessage = 'Cart updated.';
        }
        if ($cart->count() === 0) {
            $session->remove('mrc_discount_code');
        }
    } catch (WireException $e) {
        $error = $e->getMessage();
    }
} elseif ($postedAction === 'checkout') {
    try {
        $postedPolicyPages = method_exists($commerce, 'getPolicyPages') ? $commerce->getPolicyPages() : new PageArray();
        if ($postedPolicyPages->count() > 0 && (string) $input->post->text('policy_accepted') !== '1') {
            throw new WireException('Please accept the store policies before continuing to payment.');
        }
        $discountCode = (string) $session->get('mrc_discount_code');
        $paymentData = [
            'first_name'     => $input->post->text('first_name'),
            'last_name'      => $input->post->text('last_name'),
            'company'        => $input->post->text('company'),
            'tax_number'     => strtoupper($input->post->text('tax_number')),
            'purchase_order_number' => $input->post->text('purchase_order_number'),
            'email'          => $input->post->email('email'),
            'phone'          => $input->post->text('phone'),
            'address'        => $input->post->text('address'),
            'city'           => $input->post->text('city'),
            'zip'            => $input->post->text('zip'),
            'country'        => strtoupper($input->post->text('country')),
            'region'         => strtoupper($input->post->text('region')),
            'delivery_window' => $input->post->text('delivery_window'),
            'delivery_note'  => $input->post->textarea('delivery_note'),
            'pickup_location' => $input->post->text('pickup_location'),
            'notes'          => $input->post->textarea('notes'),
            'fulfilment_method' => $input->post->text('fulfilment_method'),
            'payment_method' => $input->post->text('payment_method') ?: 'stripe-card',
            'discount_code'  => $discountCode,
            'checkout_nonce' => $input->post->text('checkout_nonce'),
            'mrc_policy_accepted' => $postedPolicyPages->count() > 0 ? 1 : 0,
        ];

        $linkOrderId = (int) $input->post('mrc_payment_link_order_id');
        $linkToken = $input->post->text('mrc_payment_link_token');
        if ($linkOrderId > 0 && $linkToken !== '') {
            $linkOrder = $pages->get($linkOrderId);
            if ($linkOrder && $linkOrder->id && $commerce->verifyPaymentLinkToken($linkOrder, $linkToken)) {
                $paymentData['mrc_order_page_id'] = (int) $linkOrder->id;
            } else {
                throw new WireException('This payment link is invalid or no longer payable.');
            }
        }

        $redirect = $commerce->initializePayment($paymentData);

        if ($redirect !== '') {
            $session->redirect($redirect);
        }

        $pendingOrder = $session->get('mrc_pending_order');
        $clientSecret = is_array($pendingOrder) ? ($pendingOrder['stripe_client_secret'] ?? '') : '';
    } catch (WireException $e) {
        $error = $e->getMessage();
    }
}

$successPage = $pages->get('/' . ltrim((string) $commerce->success_page, '/') . '/');
$successUrl = ($successPage && $successPage->id)
    ? $successPage->httpUrl()
    : rtrim($config->urls->httpRoot, '/') . '/' . ltrim((string) $commerce->success_page, '/') . '/';

$allowedDeliveryCountries = method_exists($commerce, 'getAllowedDeliveryCountries') ? $commerce->getAllowedDeliveryCountries() : [];
$countryLabels = [
    'AT' => 'Austria',
    'BE' => 'Belgium',
    'CH' => 'Switzerland',
    'DE' => 'Germany',
    'DK' => 'Denmark',
    'ES' => 'Spain',
    'FR' => 'France',
    'GB' => 'United Kingdom',
    'IT' => 'Italy',
    'NL' => 'Netherlands',
    'PL' => 'Poland',
    'US' => 'United States',
];
$countryOptions = count($allowedDeliveryCountries) > 0
    ? array_fill_keys($allowedDeliveryCountries, '')
    : $countryLabels;
foreach (array_keys($countryOptions) as $countryCode) {
    $countryOptions[$countryCode] = $countryLabels[$countryCode] ?? $countryCode;
}
$defaultCountry = (string) ($allowedDeliveryCountries[0] ?? 'DE');
$postedCountry = strtoupper($input->post->text('country'));

$values = [
    'first_name' => $input->post->text('first_name'),
    'last_name' => $input->post->text('last_name'),
    'company' => $input->post->text('company'),
    'tax_number' => strtoupper($input->post->text('tax_number')),
    'purchase_order_number' => $input->post->text('purchase_order_number'),
    'email' => $input->post->email('email'),
    'phone' => $input->post->text('phone'),
    'address' => $input->post->text('address'),
    'city' => $input->post->text('city'),
    'zip' => $input->post->text('zip'),
    'country' => $postedCountry !== '' ? $postedCountry : $defaultCountry,
    'region' => strtoupper($input->post->text('region')),
    'delivery_window' => $input->post->text('delivery_window'),
    'delivery_note' => $input->post->textarea('delivery_note'),
    'pickup_location' => $input->post->text('pickup_location'),
    'notes' => $input->post->textarea('notes'),
    'fulfilment_method' => $input->post->text('fulfilment_method') ?: $commerce->getDefaultFulfilmentMethod(),
    'payment_method' => $input->post->text('payment_method') ?: 'stripe-card',
];

if ($paymentLinkOrder && !$input->post('mrc_action')) {
    $billingSnapshot = json_decode((string) $paymentLinkOrder->mrc_billing_address, true);
    $billingSnapshot = is_array($billingSnapshot) ? $billingSnapshot : [];
    $values = array_merge($values, [
        'first_name' => (string) $paymentLinkOrder->mrc_first_name,
        'last_name' => (string) $paymentLinkOrder->mrc_last_name,
        'company' => (string) ($billingSnapshot['company'] ?? ''),
        'tax_number' => (string) ($billingSnapshot['tax_number'] ?? ''),
        'purchase_order_number' => (string) ($billingSnapshot['purchase_order_number'] ?? ''),
        'email' => (string) $paymentLinkOrder->mrc_email,
        'phone' => (string) $paymentLinkOrder->mrc_phone,
        'address' => (string) $paymentLinkOrder->mrc_address,
        'city' => (string) $paymentLinkOrder->mrc_city,
        'zip' => (string) $paymentLinkOrder->mrc_zip,
        'country' => strtoupper((string) ($paymentLinkOrder->mrc_country ?: $defaultCountry)),
        'region' => strtoupper((string) ($billingSnapshot['region'] ?? '')),
        'delivery_window' => '',
        'delivery_note' => '',
        'pickup_location' => '',
        'notes' => (string) $paymentLinkOrder->mrc_notes,
        'fulfilment_method' => $paymentLinkOrder->hasField('mrc_fulfilment_method') && (string) $paymentLinkOrder->mrc_fulfilment_method !== ''
            ? (string) $paymentLinkOrder->mrc_fulfilment_method
            : $values['fulfilment_method'],
        'payment_method' => (string) ($paymentLinkOrder->mrc_payment_method ?: $values['payment_method']),
    ]);
}

$paymentMethods = [];
foreach ($commerce->getEnabledPaymentMethods() as $method) {
    $paymentMethods[$method] = Mercato::getPaymentMethodOptions()[$method] ?? $method;
}
if (!$paymentMethods) {
    $paymentMethods = ['stripe-card' => 'Stripe Card'];
}
if (!isset($paymentMethods[$values['payment_method']])) {
    $values['payment_method'] = array_key_first($paymentMethods);
}
$deliveryRegionsByCountry = method_exists($commerce, 'getDeliveryRegionsByCountry') ? $commerce->getDeliveryRegionsByCountry() : [];
$deliveryRegions = method_exists($commerce, 'getDeliveryRegionsForCountry') ? $commerce->getDeliveryRegionsForCountry($values['country']) : [];
$deliveryWindows = method_exists($commerce, 'getDeliveryWindowOptions') ? $commerce->getDeliveryWindowOptions() : [];
$pickupLocations = method_exists($commerce, 'getStorePickupLocations') ? $commerce->getStorePickupLocations() : [];

$discountCode = (string) $session->get('mrc_discount_code');
$discount = $discountCode !== ''
    ? $cart->validateCoupon($discountCode, $values['email'])
    : ['valid' => false, 'code' => '', 'amount' => 0.0];
if (empty($discount['valid'])) {
    $discount = ['valid' => false, 'code' => '', 'amount' => 0.0];
}
$discountAmount = round((float) ($discount['amount'] ?? 0), 2);
$fulfilmentMethods = $commerce->fulfilmentService()->getCheckoutMethods($cart, $values);
$selectedFulfilment = $fulfilmentMethods[0] ?? [
    'type' => 'carrier_delivery',
    'label' => 'Delivery',
    'amount' => $cart->getShipping(),
    'details' => '',
    'available' => true,
];
foreach ($fulfilmentMethods as $method) {
    if (!empty($method['available'])) {
        $selectedFulfilment = $method;
        break;
    }
}
foreach ($fulfilmentMethods as $method) {
    if ($method['type'] === $values['fulfilment_method'] && !empty($method['available'])) {
        $selectedFulfilment = $method;
        break;
    }
}
$values['fulfilment_method'] = $selectedFulfilment['type'];
$selectedPickupLocations = is_array($selectedFulfilment['pickup_locations'] ?? null) ? $selectedFulfilment['pickup_locations'] : $pickupLocations;
if ($values['pickup_location'] === '' && count($selectedPickupLocations) > 0) {
    $values['pickup_location'] = (string) array_key_first($selectedPickupLocations);
}
$deliveryAddressRequired = $values['fulfilment_method'] !== 'store_pickup';
$orderTotal = round(max(0, $cart->getSubtotal() + (float) $selectedFulfilment['amount'] - $discountAmount), 2);
$taxRates = $commerce->getTaxRatesForOrder($cart, (float) $selectedFulfilment['amount']);
$taxLabel = $commerce->getTaxLabel();
$policyPages = method_exists($commerce, 'getPolicyPages') ? $commerce->getPolicyPages() : new PageArray();
$productsPage = $pages->get('/products/');
$shoppingUrl = ($productsPage && $productsPage->id) ? $productsPage->url : $config->urls->root;
$emptyCartProducts = $cart->count() === 0
    ? $pages->find('template=mrc-product, sort=-modified, limit=3')
    : new PageArray();
$checkoutShellClass = $isVanilla ? $ui['shell'] : $ui['shell'] . ' mrc-section-reveal mrc-checkout-shell';
$checkoutHeadingClass = $isVanilla ? '' : 'mrc-display mrc-checkout-title';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $sanitizer->entities($page->title ?: 'Checkout') ?></title>
    <?= $frameworkAssets ?>
    <?= mrc_storefront_assets($isVanilla) ?>
    <?php if (!$isVanilla): ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600;700&display=swap');
        .mrc-luxury-theme { font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        .mrc-display { font-family: "Cormorant Garamond", Georgia, serif; letter-spacing: 0; }
        .mrc-checkout-page .mrc-checkout-shell {
            align-items: start;
            gap: clamp(22px, 3vw, 42px);
            grid-template-columns: minmax(0, 1.08fr) minmax(360px, .54fr);
            max-width: var(--mrc-shell);
            padding: clamp(24px, 4vw, 56px) var(--mrc-shell-pad) clamp(64px, 8vw, 116px);
        }
        .mrc-checkout-page .mrc-checkout-main,
        .mrc-checkout-page .mrc-checkout-summary {
            box-shadow: none;
        }
        .mrc-checkout-page .mrc-checkout-main {
            display: grid;
            gap: clamp(20px, 3vw, 32px);
            padding: clamp(22px, 4vw, 44px);
        }
        .mrc-checkout-page .mrc-checkout-summary {
            position: sticky;
            top: 96px;
        }
        .mrc-checkout-title {
            color: var(--mrc-ink);
            font-size: clamp(56px, 7vw, 94px);
            font-weight: 600;
            line-height: .9;
            margin: 0;
        }
        .mrc-checkout-lead {
            color: var(--mrc-muted);
            font-size: clamp(16px, 1.35vw, 20px);
            line-height: 1.7;
            margin: 14px 0 0;
            max-width: 620px;
        }
        .mrc-checkout-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 20px;
        }
        .mrc-checkout-meta span {
            background: #fbf6ed;
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            color: var(--mrc-muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .14em;
            padding: 9px 12px;
            text-transform: uppercase;
        }
        .mrc-checkout-step {
            border-top: 1px solid var(--mrc-line);
            display: grid;
            gap: 22px;
            padding-top: clamp(22px, 3vw, 34px);
        }
        .mrc-checkout-step-head {
            align-items: baseline;
            display: grid;
            gap: 14px;
            grid-template-columns: 54px minmax(0, 1fr);
        }
        .mrc-checkout-step-index {
            color: var(--mrc-gold);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .18em;
        }
        .mrc-checkout-step-title {
            color: var(--mrc-ink);
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(30px, 3vw, 42px);
            font-weight: 600;
            line-height: 1;
            margin: 0;
        }
        .mrc-checkout-page .mrc-grid {
            gap: 16px;
        }
        .mrc-checkout-page .mrc-field label {
            color: var(--mrc-ink);
            font-size: 13px;
            font-weight: 800;
        }
        .mrc-checkout-page .mrc-input,
        .mrc-checkout-page .mrc-select,
        .mrc-checkout-page .mrc-textarea {
            border-color: var(--mrc-line);
            box-shadow: none;
            min-height: 48px;
        }
        .mrc-checkout-page .mrc-address-fields {
            background: rgba(251, 246, 237, .72);
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            padding: clamp(18px, 2.8vw, 28px);
        }
        .mrc-checkout-page .mrc-policy-acceptance {
            background: #fbf6ed;
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            margin-top: 4px;
            padding: 16px;
        }
        .mrc-checkout-page .mrc-button {
            width: fit-content;
        }
        .mrc-checkout-page .mrc-payment-panel,
        .mrc-checkout-page .mrc-empty-products {
            border-top: 1px solid var(--mrc-line);
            margin-top: 0;
            padding-top: 24px;
        }
        .mrc-checkout-page .mrc-checkout-summary .mrc-kicker {
            color: var(--mrc-gold);
        }
        .mrc-checkout-summary-title {
            color: var(--mrc-ink);
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(32px, 3.3vw, 48px);
            font-weight: 600;
            line-height: .98;
            margin: 0 0 20px;
        }
        .mrc-checkout-items {
            border-block: 1px solid var(--mrc-line);
            display: grid;
            gap: 0;
        }
        .mrc-checkout-item {
            border-bottom: 1px solid var(--mrc-line);
            display: grid;
            gap: 14px;
            grid-template-columns: 76px minmax(0, 1fr);
            padding: 16px 0;
        }
        .mrc-checkout-item:last-child {
            border-bottom: 0;
        }
        .mrc-checkout-item-media {
            aspect-ratio: 1;
            background: #fbf6ed;
            border: 1px solid var(--mrc-line);
            border-radius: var(--mrc-radius);
            overflow: hidden;
        }
        .mrc-checkout-item-media img {
            display: block;
            height: 100%;
            object-fit: cover;
            width: 100%;
        }
        .mrc-checkout-item-placeholder {
            align-items: center;
            color: var(--mrc-gold);
            display: flex;
            font-size: 10px;
            font-weight: 800;
            height: 100%;
            justify-content: center;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .mrc-checkout-item-body {
            display: grid;
            gap: 10px;
            min-width: 0;
        }
        .mrc-checkout-item-top {
            align-items: start;
            display: grid;
            gap: 12px;
            grid-template-columns: minmax(0, 1fr) auto;
        }
        .mrc-checkout-item-title {
            color: var(--mrc-ink);
            font-weight: 800;
            line-height: 1.25;
            margin: 0;
        }
        .mrc-checkout-item-price {
            color: var(--mrc-rust);
            font-weight: 800;
            white-space: nowrap;
        }
        .mrc-checkout-item-controls {
            align-items: end;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }
        .mrc-checkout-qty {
            display: grid;
            gap: 5px;
            width: 82px;
        }
        .mrc-checkout-qty span {
            color: var(--mrc-gold);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }
        .mrc-checkout-remove {
            min-height: 36px !important;
            padding-inline: 12px !important;
        }
        .mrc-checkout-totals {
            display: grid;
            gap: 12px;
            margin-top: 20px;
        }
        .mrc-checkout-total-row {
            align-items: baseline;
            display: flex;
            gap: 16px;
            justify-content: space-between;
        }
        .mrc-checkout-total-row span:first-child {
            color: var(--mrc-muted);
        }
        .mrc-checkout-total-row.is-grand {
            border-top: 1px solid var(--mrc-line);
            margin-top: 6px;
            padding-top: 16px;
        }
        .mrc-checkout-page .mrc-table th {
            color: var(--mrc-gold);
            font-size: 11px;
            letter-spacing: .16em;
            text-transform: uppercase;
        }
        .mrc-checkout-page .mrc-table th,
        .mrc-checkout-page .mrc-table td {
            border-color: var(--mrc-line);
            padding: 13px 6px;
        }
        .mrc-checkout-page .mrc-table input[type="number"] {
            min-height: 40px;
            min-width: 72px;
            text-align: center;
        }
        .mrc-checkout-page .mrc-total {
            color: var(--mrc-rust);
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(30px, 3vw, 42px);
            line-height: 1;
            white-space: nowrap;
        }
        .mrc-checkout-page .mrc-policy-links {
            border-color: var(--mrc-line);
        }
        .mrc-checkout-page .mrc-policy-links a {
            color: var(--mrc-muted);
        }
        .mrc-checkout-page .mrc-empty-product-card {
            background: #fbf6ed;
            border-color: var(--mrc-line);
        }
        .mrc-checkout-page #payment-element {
            background: #fbf6ed;
            border-color: var(--mrc-line);
        }
        @media (max-width: 980px) {
            .mrc-checkout-page .mrc-checkout-shell {
                grid-template-columns: 1fr;
            }
            .mrc-checkout-page .mrc-checkout-summary {
                position: static;
            }
        }
        @media (max-width: 560px) {
            .mrc-checkout-page .mrc-checkout-title {
                font-size: clamp(48px, 14vw, 64px);
            }
            .mrc-checkout-step-head {
                grid-template-columns: 1fr;
            }
            .mrc-checkout-item {
                grid-template-columns: 64px minmax(0, 1fr);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .mrc-section-reveal { animation: none; }
        }
    </style>
    <?php endif; ?>
    <style>
        .mrc-address-fields[hidden] { display: none !important; }
        .mrc-empty-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 18px 0 8px; }
        .mrc-empty-products { margin-top: 28px; }
        .mrc-empty-product-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 14px; }
        .mrc-empty-product-card { border: 1px solid rgba(0,0,0,0.16); border-radius: 6px; display: grid; gap: 12px; padding: 14px; }
        .mrc-empty-product-media { aspect-ratio: 3 / 2; background: rgba(0,0,0,0.08); border-radius: 6px; overflow: hidden; }
        .mrc-empty-product-media img { display: block; height: 100%; object-fit: cover; width: 100%; }
        .mrc-empty-product-placeholder { align-items: center; color: rgba(0,0,0,0.5); display: flex; font-size: 12px; height: 100%; justify-content: center; text-transform: uppercase; }
        .mrc-empty-product-title { font-weight: 700; margin: 0; }
        .mrc-empty-product-meta { color: rgba(0,0,0,0.6); margin: 0; }
        .mrc-line-stock { color: rgba(0,0,0,0.58); display: block; font-size: 12px; margin-top: 4px; }
        @media (prefers-color-scheme: dark) {
            .mrc-empty-product-card { border-color: rgba(255,255,255,0.22); }
            .mrc-empty-product-media { background: rgba(255,255,255,0.1); }
            .mrc-empty-product-placeholder,
            .mrc-empty-product-meta,
            .mrc-line-stock { color: rgba(255,255,255,0.62); }
        }
    </style>
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
            --pw-alert-danger: #fee6e6;
            color: var(--pw-text-color);
            background: var(--pw-main-background);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
            margin: 0;
            min-height: 100vh;
            padding: var(--pw-spacing);
        }
        .mrc-shell { display: grid; gap: var(--pw-spacing); grid-template-columns: minmax(0, 1.3fr) minmax(320px, 0.7fr); max-width: 1180px; }
        .mrc-wrap {
            background: var(--pw-blocks-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
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
        .mrc-grid { display: grid; gap: 14px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .mrc-field { display: grid; gap: 6px; }
        .mrc-field.is-wide { grid-column: 1 / -1; }
        .mrc-field label { font-weight: 700; }
        .mrc-help { color: var(--pw-muted-color); display: block; font-size: 13px; margin-top: 4px; }
        .mrc-input,
        .mrc-select,
        .mrc-textarea {
            background: var(--pw-inputs-background);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            box-sizing: border-box;
            color: var(--pw-text-color);
            min-height: 40px;
            padding: 8px 10px;
            width: 100%;
        }
        .mrc-textarea { border-radius: 6px; min-height: 96px; resize: vertical; }
        .mrc-button {
            background: var(--pw-text-color);
            border: 1px solid transparent;
            border-radius: 6px;
            color: var(--pw-blocks-background);
            cursor: pointer;
            display: inline-flex;
            font-weight: 700;
            min-height: 42px;
            padding: 0 20px;
            text-decoration: none;
            align-items: center;
            justify-content: center;
        }
        .mrc-button[disabled] { cursor: wait; opacity: 0.65; }
        .mrc-button-secondary {
            background: transparent;
            border-color: var(--pw-text-color);
            color: var(--pw-text-color);
        }
        .mrc-error {
            background: var(--pw-alert-danger);
            border: 1px solid var(--pw-border-color);
            border-radius: 6px;
            margin-bottom: 18px;
            padding: 12px 16px;
        }
        .mrc-table { border-collapse: collapse; width: 100%; }
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
            padding: 10px 6px;
            vertical-align: top;
        }
        .mrc-total { font-size: 22px; font-weight: 800; }
        .mrc-policy-links {
            border-top: 1px solid var(--pw-border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            margin-top: 18px;
            padding-top: 14px;
        }
        .mrc-policy-links a {
            color: var(--pw-muted-color);
            font-size: 13px;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .mrc-policy-acceptance {
            align-items: flex-start;
            border-top: 1px solid var(--pw-border-color);
            display: flex;
            gap: 10px;
            margin-top: 6px;
            padding-top: 14px;
        }
        .mrc-policy-acceptance input { margin-top: 4px; }
        .mrc-policy-acceptance a {
            color: var(--pw-text-color);
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .mrc-payment-panel { margin-top: 22px; }
        #payment-element { border: 1px solid var(--pw-border-color); border-radius: 6px; padding: 14px; }
        #payment-message { color: var(--pw-muted-color); margin-top: 12px; }
        @media (max-width: 860px) {
            .mrc-shell,
            .mrc-grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?= $ui['body'] ?> mrc-checkout-page">
<?php if (!$isVanilla): ?>
<?= mrc_storefront_header($commerce, $pages, $config, $sanitizer, 'checkout') ?>
<?php endif; ?>
<main class="<?= $checkoutShellClass ?>">
    <section class="<?= $ui['panel'] ?> mrc-checkout-main">
        <span class="<?= $ui['kicker'] ?>">Checkout</span>
        <h1 class="<?= $checkoutHeadingClass ?>"><?= $sanitizer->entities($page->title ?: 'Checkout') ?></h1>
        <p class="mrc-checkout-lead">Confirm customer details, choose fulfilment, apply discounts, and continue to the configured payment flow.</p>
        <div class="mrc-checkout-meta" aria-label="Checkout features">
            <span>Policy acceptance</span>
            <span>Fulfilment options</span>
            <span>Discount codes</span>
        </div>

        <?php if ($error): ?>
            <div class="<?= $ui['error'] ?>"><?= $sanitizer->entities($error) ?></div>
        <?php endif; ?>
        <?php if ($couponError): ?>
            <div class="<?= $ui['error'] ?>"><?= $sanitizer->entities($couponError) ?></div>
        <?php endif; ?>
        <?php if ($cartMessage): ?>
            <div class="<?= $ui['message'] ?>"><?= $sanitizer->entities($cartMessage) ?></div>
        <?php endif; ?>

        <?php if ($cart->count() === 0): ?>
            <section class="mrc-checkout-step">
                <div class="mrc-checkout-step-head">
                    <span class="mrc-checkout-step-index">01</span>
                    <h2 class="mrc-checkout-step-title">Your cart is empty.</h2>
                </div>
                <p class="mrc-checkout-lead">Your cart is empty. Add a product to start checkout.</p>
                <p class="mrc-checkout-lead">Checkout will then show fulfilment, discounts, policy acceptance, taxes, and payment totals.</p>
            </section>
            <div class="mrc-empty-actions">
                <a class="<?= $ui['button'] ?>" href="<?= $shoppingUrl ?>">Browse products</a>
            </div>
            <?php if ($emptyCartProducts->count() > 0): ?>
                <section class="mrc-empty-products" aria-label="Available products">
                    <span class="<?= $ui['kicker'] ?>">Available products</span>
                    <div class="mrc-empty-product-grid">
                        <?php foreach ($emptyCartProducts as $product): ?>
                            <?php
                            $imageUrl = '';
                            if ($product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                                $imageUrl = $product->mrc_images->first()->url;
                            }
                            ?>
                            <article class="mrc-empty-product-card">
                                <a class="mrc-empty-product-media" href="<?= $product->url ?>" aria-label="View <?= $sanitizer->entities($product->title) ?>">
                                    <?php if ($imageUrl !== ''): ?>
                                        <img src="<?= $imageUrl ?>" alt="<?= $sanitizer->entities($product->title) ?>">
                                    <?php else: ?>
                                        <span class="mrc-empty-product-placeholder">Product</span>
                                    <?php endif; ?>
                                </a>
                                <div>
                                    <p class="mrc-empty-product-title"><?= $sanitizer->entities($product->title) ?></p>
                                    <p class="mrc-empty-product-meta"><?= $commerce->formatPrice((float) $product->mrc_price) ?></p>
                                </div>
                                <a class="<?= $ui['buttonSecondary'] ?>" href="<?= $product->url ?>">View product</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <form method="post" action="" class="<?= $ui['form'] ?>">
                <input type="hidden" name="mrc_action" value="checkout">
                <input type="hidden" name="checkout_nonce" value="<?= $sanitizer->entities($checkoutNonce) ?>">
                <?= $csrfInput ?>
                <?php if ($paymentLinkOrder && $paymentLinkOrder->id): ?>
                    <input type="hidden" name="mrc_payment_link_order_id" value="<?= (int) $paymentLinkOrder->id ?>">
                    <input type="hidden" name="mrc_payment_link_token" value="<?= $sanitizer->entities($paymentLinkToken) ?>">
                    <p class="<?= $ui['message'] ?>">Paying order <strong><?= $sanitizer->entities((string) ($paymentLinkOrder->mrc_invoice_number ?: $paymentLinkOrder->title)) ?></strong>.</p>
                <?php endif; ?>
                <section class="mrc-checkout-step">
                    <div class="mrc-checkout-step-head">
                        <span class="mrc-checkout-step-index">01</span>
                        <h2 class="mrc-checkout-step-title">Customer and fulfilment</h2>
                    </div>
                    <div class="<?= $ui['grid'] ?>">
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-first-name">First name</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-first-name" name="first_name" value="<?= $sanitizer->entities($values['first_name']) ?>" required>
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-last-name">Last name</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-last-name" name="last_name" value="<?= $sanitizer->entities($values['last_name']) ?>" required>
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-email">Email</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-email" name="email" type="email" value="<?= $sanitizer->entities($values['email']) ?>" required>
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-phone">Phone</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-phone" name="phone" value="<?= $sanitizer->entities($values['phone']) ?>">
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-company">Company</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-company" name="company" value="<?= $sanitizer->entities($values['company']) ?>" autocomplete="organization">
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-tax-number">Tax / VAT number</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-tax-number" name="tax_number" value="<?= $sanitizer->entities($values['tax_number']) ?>">
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-purchase-order-number">Purchase order number</label>
                        <input class="<?= $ui['input'] ?>" id="mrc-purchase-order-number" name="purchase_order_number" value="<?= $sanitizer->entities($values['purchase_order_number']) ?>">
                    </div>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-fulfilment-method">Receive order by</label>
                        <select class="<?= $ui['select'] ?>" id="mrc-fulfilment-method" name="fulfilment_method" aria-controls="mrc-delivery-address">
                            <?php foreach ($fulfilmentMethods as $method): ?>
                                <option
                                    value="<?= $sanitizer->entities($method['type']) ?>"
                                    data-label="<?= $sanitizer->entities($method['label']) ?>"
                                    data-fee="<?= (float) $method['amount'] ?>"
                                    data-details="<?= $sanitizer->entities($method['details']) ?>"
                                    <?= empty($method['available']) ? 'disabled' : '' ?>
                                    <?= $values['fulfilment_method'] === $method['type'] ? 'selected' : '' ?>
                                ><?= $sanitizer->entities($method['label']) ?> - <?= (float) $method['amount'] > 0 ? $commerce->formatPrice((float) $method['amount']) : 'Free' ?><?= empty($method['available']) ? ' (unavailable)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="mrc-help" id="mrc-fulfilment-detail"><?= nl2br($sanitizer->entities($selectedFulfilment['details'] ?? '')) ?></small>
                    </div>
                    <?php if (count($selectedPickupLocations) > 1): ?>
                        <div
                            class="<?= $ui['field'] ?>"
                            id="mrc-pickup-location-field"
                            aria-hidden="<?= $values['fulfilment_method'] === 'store_pickup' ? 'false' : 'true' ?>"
                            <?= $values['fulfilment_method'] === 'store_pickup' ? '' : 'hidden' ?>
                        >
                            <label for="mrc-pickup-location">Pickup location</label>
                            <select class="<?= $ui['select'] ?>" id="mrc-pickup-location" name="pickup_location">
                                <?php foreach ($selectedPickupLocations as $key => $location): ?>
                                    <?php $pickupDetails = trim(implode("\n", array_filter([
                                        (string) ($location['label'] ?? ''),
                                        (string) ($location['address'] ?? ''),
                                        (string) ($location['instructions'] ?? ''),
                                        (string) ($location['hours'] ?? ''),
                                    ]))); ?>
                                    <option
                                        value="<?= $sanitizer->entities((string) $key) ?>"
                                        data-details="<?= $sanitizer->entities($pickupDetails) ?>"
                                        <?= $values['pickup_location'] === (string) $key ? 'selected' : '' ?>
                                    >
                                        <?= $sanitizer->entities((string) ($location['label'] ?? $key)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="<?= $ui['field'] ?>">
                        <label for="mrc-payment-method">Payment method</label>
                        <select class="<?= $ui['select'] ?>" id="mrc-payment-method" name="payment_method">
                            <?php foreach ($paymentMethods as $method => $label): ?>
                                <option value="<?= $sanitizer->entities($method) ?>" <?= $values['payment_method'] === $method ? 'selected' : '' ?>><?= $sanitizer->entities($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <section
                        class="<?= $ui['fieldWide'] ?> mrc-address-fields"
                        id="mrc-delivery-address"
                        aria-hidden="<?= $deliveryAddressRequired ? 'false' : 'true' ?>"
                        <?= $deliveryAddressRequired ? '' : 'hidden' ?>
                    >
                        <span class="<?= $ui['kicker'] ?>">Delivery address</span>
                        <div class="<?= $ui['grid'] ?>">
                            <div class="<?= $ui['fieldWide'] ?>">
                                <label for="mrc-address">Address</label>
                                <input class="<?= $ui['input'] ?>" id="mrc-address" name="address" value="<?= $sanitizer->entities($values['address']) ?>" data-mrc-delivery-required <?= $deliveryAddressRequired ? 'required' : '' ?>>
                            </div>
                            <div class="<?= $ui['field'] ?>">
                                <label for="mrc-city">City</label>
                                <input class="<?= $ui['input'] ?>" id="mrc-city" name="city" value="<?= $sanitizer->entities($values['city']) ?>" data-mrc-delivery-required <?= $deliveryAddressRequired ? 'required' : '' ?>>
                            </div>
                            <div class="<?= $ui['field'] ?>">
                                <label for="mrc-zip">ZIP / Postal code</label>
                                <input class="<?= $ui['input'] ?>" id="mrc-zip" name="zip" value="<?= $sanitizer->entities($values['zip']) ?>" data-mrc-delivery-required <?= $deliveryAddressRequired ? 'required' : '' ?>>
                            </div>
                            <div class="<?= $ui['field'] ?>">
                                <label for="mrc-country">Country</label>
                                <select class="<?= $ui['select'] ?>" id="mrc-country" name="country" data-mrc-delivery-required <?= $deliveryAddressRequired ? 'required' : '' ?>>
                                    <?php foreach ($countryOptions as $countryCode => $countryLabel): ?>
                                        <?php $countryCode = strtoupper((string) $countryCode); ?>
                                        <option value="<?= $sanitizer->entities($countryCode) ?>" <?= $values['country'] === $countryCode ? 'selected' : '' ?>><?= $sanitizer->entities((string) $countryLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="<?= $ui['field'] ?>" id="mrc-region-field" <?= $values['country'] !== '' && $deliveryRegions ? '' : 'hidden' ?>>
                                <label for="mrc-region">State / region</label>
                                <select class="<?= $ui['select'] ?>" id="mrc-region" name="region">
                                    <option value="">Choose only if required</option>
                                    <?php foreach ($deliveryRegions as $code => $label): ?>
                                        <option value="<?= $sanitizer->entities((string) $code) ?>" <?= $values['region'] === (string) $code ? 'selected' : '' ?>><?= $sanitizer->entities((string) $label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="<?= $ui['fieldWide'] ?>">
                                <label for="mrc-delivery-window">Preferred delivery window</label>
                                <?php if ($deliveryWindows): ?>
                                    <select class="<?= $ui['select'] ?>" id="mrc-delivery-window" name="delivery_window">
                                        <option value="">Choose delivery window</option>
                                        <?php foreach ($deliveryWindows as $value => $label): ?>
                                            <option value="<?= $sanitizer->entities((string) $value) ?>" <?= $values['delivery_window'] === (string) $value ? 'selected' : '' ?>><?= $sanitizer->entities((string) $label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input class="<?= $ui['input'] ?>" id="mrc-delivery-window" name="delivery_window" value="<?= $sanitizer->entities($values['delivery_window']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="<?= $ui['fieldWide'] ?>">
                                <label for="mrc-delivery-note">Delivery note</label>
                                <textarea class="<?= $ui['textarea'] ?>" id="mrc-delivery-note" name="delivery_note"><?= $sanitizer->entities($values['delivery_note']) ?></textarea>
                            </div>
                        </div>
                    </section>
                    <div class="<?= $ui['fieldWide'] ?>">
                        <label for="mrc-notes">Notes</label>
                        <textarea class="<?= $ui['textarea'] ?>" id="mrc-notes" name="notes"><?= $sanitizer->entities($values['notes']) ?></textarea>
                    </div>
                    </div>
                </section>
                <?php if ($policyPages->count() > 0): ?>
                    <label class="<?= $ui['fieldWide'] ?> mrc-policy-acceptance">
                        <input type="checkbox" name="policy_accepted" value="1" required>
                        <span>
                            I agree to the store policies:
                            <?php $policyLinks = []; ?>
                            <?php foreach ($policyPages as $policyPage): ?>
                                <?php $policyLinks[] = '<a href="' . $policyPage->url . '" target="_blank" rel="noopener noreferrer">' . $sanitizer->entities($policyPage->title ?: $policyPage->name) . '</a>'; ?>
                            <?php endforeach; ?>
                            <?= implode(', ', $policyLinks) ?>
                        </span>
                    </label>
                <?php endif; ?>
                <p><button class="<?= $ui['button'] ?>" type="submit">Continue to payment</button></p>
            </form>

            <?php if ($clientSecret && $publishableKey): ?>
                <div class="<?= $ui['paymentPanel'] ?>">
                    <span class="<?= $ui['kicker'] ?>">Payment</span>
                    <div id="payment-element" class="<?= $ui['paymentElement'] ?>"></div>
                    <p><button class="<?= $ui['button'] ?>" id="mrc-pay-button" type="button">Pay now</button></p>
                    <div id="payment-message"></div>
                </div>
                <script src="https://js.stripe.com/v3/"></script>
                <script>
                (async () => {
                    const stripe = Stripe('<?= $sanitizer->entities($publishableKey) ?>');
                    const elements = stripe.elements({ clientSecret: '<?= $sanitizer->entities($clientSecret) ?>' });
                    elements.create('payment').mount('#payment-element');

                    const button = document.getElementById('mrc-pay-button');
                    const message = document.getElementById('payment-message');
                    button.addEventListener('click', async () => {
                        button.disabled = true;
                        message.textContent = '';
                        const result = await stripe.confirmPayment({
                            elements,
                            confirmParams: { return_url: '<?= $sanitizer->entities($successUrl) ?>' }
                        });
                        if (result.error) {
                            message.textContent = result.error.message || 'Payment could not be confirmed.';
                            button.disabled = false;
                        }
                    });
                })();
                </script>
            <?php elseif ($clientSecret && !$publishableKey): ?>
                <div class="<?= $ui['error'] ?>">Stripe publishable key is not configured.</div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <aside class="<?= $ui['panel'] ?> mrc-checkout-summary">
        <span class="<?= $ui['kicker'] ?>">Order summary</span>
        <h2 class="mrc-checkout-summary-title">Review</h2>
        <?php if ($cart->count() === 0): ?>
            <p>Add products to see fulfilment options, coupon fields, taxes, and payment totals.</p>
        <?php else: ?>
            <form method="post" action="" class="<?= $ui['form'] ?>">
                <?= $csrfInput ?>
                <input type="hidden" name="mrc_action" value="update_cart">
                <div class="mrc-checkout-items">
                    <?php foreach ($cart->getFormattedItems() as $item): ?>
                        <?php
                        $itemKey = (string) ($item['key'] ?? $item['id']);
                        $stockPolicy = strtolower((string) ($item['stock_policy'] ?? 'deny'));
                        $stock = isset($item['stock']) ? (int) $item['stock'] : 0;
                        $productId = (int) ($item['product_id'] ?? 0);
                        $reserved = ($stockPolicy === 'deny' && $productId > 0)
                            ? $commerce->orderRepository()->getReservedQuantityForProduct($productId)
                            : 0;
                        $available = max(0, $stock - $reserved);
                        $maxAttr = $stockPolicy === 'deny' && $available > 0 ? ' max="' . $available . '"' : '';
                        $product = $productId > 0 ? $pages->get($productId) : null;
                        $productUrl = ($product && $product->id) ? $product->url : '';
                        $imageUrl = '';
                        if ($product && $product->id && $product->hasField('mrc_images') && $product->mrc_images && $product->mrc_images->count()) {
                            $imageUrl = $product->mrc_images->first()->url;
                        }
                        ?>
                        <article class="mrc-checkout-item">
                            <?php if ($productUrl !== ''): ?>
                                <a class="mrc-checkout-item-media" href="<?= $sanitizer->entities($productUrl) ?>" aria-label="View <?= $sanitizer->entities($item['title'] ?? $item['id']) ?>">
                            <?php else: ?>
                                <div class="mrc-checkout-item-media">
                            <?php endif; ?>
                                <?php if ($imageUrl !== ''): ?>
                                    <img src="<?= $sanitizer->entities($imageUrl) ?>" alt="<?= $sanitizer->entities($item['title'] ?? $item['id']) ?>">
                                <?php else: ?>
                                    <span class="mrc-checkout-item-placeholder">Item</span>
                                <?php endif; ?>
                            <?php if ($productUrl !== ''): ?>
                                </a>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                            <div class="mrc-checkout-item-body">
                                <div class="mrc-checkout-item-top">
                                    <div>
                                        <p class="mrc-checkout-item-title"><?= $sanitizer->entities($item['title'] ?? $item['id']) ?></p>
                                        <?php if ($stockPolicy === 'deny' && $productId > 0): ?>
                                            <small class="mrc-line-stock"><?= (int) $available ?> available</small>
                                        <?php elseif ($stockPolicy === 'backorder'): ?>
                                            <small class="mrc-line-stock">Backorder allowed</small>
                                        <?php elseif ($stockPolicy === 'preorder'): ?>
                                            <small class="mrc-line-stock">Preorder allowed</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mrc-checkout-item-price"><?= $item['sum'] ?></div>
                                </div>
                                <div class="mrc-checkout-item-controls">
                                    <label class="mrc-checkout-qty">
                                        <span>Qty</span>
                                        <input class="<?= $ui['input'] ?>" type="number" name="cart_quantity[<?= $sanitizer->entities($itemKey) ?>]" min="0" step="1"<?= $maxAttr ?> value="<?= (int) ceil((float) $item['quantity']) ?>">
                                    </label>
                                    <button class="<?= $ui['buttonSecondary'] ?> mrc-checkout-remove" type="submit" name="remove_item" value="<?= $sanitizer->entities($itemKey) ?>">Remove</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="mrc-checkout-totals">
                    <div class="mrc-checkout-total-row">
                        <span id="mrc-fulfilment-summary-label"><?= $sanitizer->entities($selectedFulfilment['label']) ?></span>
                        <strong id="mrc-fulfilment-summary-fee"><?= (float) $selectedFulfilment['amount'] > 0 ? $commerce->formatPrice((float) $selectedFulfilment['amount']) : 'Free' ?></strong>
                    </div>
                    <?php if ($discountAmount > 0): ?>
                        <div class="mrc-checkout-total-row">
                            <span>Discount <?= $sanitizer->entities($discount['code'] ?? '') ?></span>
                            <strong>-<?= $commerce->formatPrice($discountAmount) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="mrc-checkout-total-row is-grand">
                        <span>Total</span>
                        <strong class="<?= $ui['total'] ?>" id="mrc-order-total"><?= $commerce->formatPrice($orderTotal) ?></strong>
                    </div>
                    <?php foreach ($taxRates as $rate): ?>
                        <div class="mrc-checkout-total-row">
                            <span>incl. <?= $sanitizer->entities($taxLabel) ?> <?= $rate['tax_rate'] ?>%</span>
                            <strong><?= $commerce->formatPrice($rate['sum']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><button class="<?= $ui['buttonSecondary'] ?>" type="submit">Update cart</button></p>
            </form>
            <form method="post" action="" class="<?= $ui['form'] ?>">
                <?= $csrfInput ?>
                <div class="<?= $ui['field'] ?>">
                    <label for="mrc-discount-code">Coupon code</label>
                    <input class="<?= $ui['input'] ?>" id="mrc-discount-code" name="discount_code" value="<?= $sanitizer->entities($discount['code'] ?? '') ?>">
                </div>
                <p>
                    <button class="<?= $ui['button'] ?>" type="submit" name="mrc_action" value="apply_coupon">Apply coupon</button>
                    <?php if (!empty($discount['valid'])): ?>
                        <button class="<?= $ui['button'] ?>" type="submit" name="mrc_action" value="remove_coupon">Remove</button>
                    <?php endif; ?>
                </p>
                <?php if ($couponMessage): ?>
                    <p><?= $sanitizer->entities($couponMessage) ?></p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <?php if ($policyPages->count() > 0): ?>
            <nav class="mrc-policy-links" aria-label="Store policies">
                <?php foreach ($policyPages as $policyPage): ?>
                    <a href="<?= $policyPage->url ?>"><?= $sanitizer->entities($policyPage->title ?: $policyPage->name) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </aside>
</main>
<?php if (!$isVanilla): ?>
<?= mrc_storefront_footer($commerce, $pages, $config, $sanitizer) ?>
<?php endif; ?>
<script>
(() => {
    const method = document.getElementById('mrc-fulfilment-method');
    const fee = document.getElementById('mrc-fulfilment-summary-fee');
    const label = document.getElementById('mrc-fulfilment-summary-label');
    const total = document.getElementById('mrc-order-total');
    const detail = document.getElementById('mrc-fulfilment-detail');
    const addressPanel = document.getElementById('mrc-delivery-address');
    const pickupPanel = document.getElementById('mrc-pickup-location-field');
    const pickupLocation = document.getElementById('mrc-pickup-location');
    const addressFields = document.querySelectorAll('[data-mrc-delivery-required]');
    const country = document.getElementById('mrc-country');
    const regionField = document.getElementById('mrc-region-field');
    const region = document.getElementById('mrc-region');
    const regionsByCountry = <?= json_encode($deliveryRegionsByCountry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    if (!method || !fee || !label || !total) return;
    const subtotal = <?= json_encode((float) $cart->getSubtotal()) ?>;
    const discount = <?= json_encode($discountAmount) ?>;
    const symbol = <?= json_encode((string) $commerce->currency_symbol) ?>;
    const before = <?= json_encode(($commerce->currency_symbol_position ?? 'before') === 'before') ?>;
    const format = amount => before ? symbol + ' ' + amount.toFixed(2) : amount.toFixed(2) + ' ' + symbol;
    const refreshFulfilment = () => {
        const option = method.options[method.selectedIndex];
        const amount = Number(option.dataset.fee || 0);
        const addressRequired = option.value !== 'store_pickup';
        label.textContent = option.dataset.label || option.textContent;
        fee.textContent = amount > 0 ? format(amount) : 'Free';
        total.textContent = format(Math.max(0, subtotal + amount - discount));
        if (detail) {
            detail.textContent = option.value === 'store_pickup' && pickupLocation && pickupLocation.selectedOptions.length
                ? (pickupLocation.selectedOptions[0].dataset.details || option.dataset.details || '')
                : (option.dataset.details || '');
        }
        if (addressPanel) {
            addressPanel.hidden = !addressRequired;
            addressPanel.setAttribute('aria-hidden', addressRequired ? 'false' : 'true');
        }
        if (pickupPanel) {
            pickupPanel.hidden = option.value !== 'store_pickup';
            pickupPanel.setAttribute('aria-hidden', option.value === 'store_pickup' ? 'false' : 'true');
        }
        if (pickupLocation) {
            pickupLocation.required = option.value === 'store_pickup';
        }
        addressFields.forEach(field => {
            field.required = addressRequired;
        });
        if (region) {
            region.required = addressRequired && !regionField.hidden;
        }
    };
    const refreshRegion = () => {
        if (!country || !region || !regionField) return;
        const selected = region.value;
        const options = regionsByCountry[(country.value || '').toUpperCase()] || {};
        region.innerHTML = '<option value="">Choose only if required</option>';
        Object.entries(options).forEach(([code, text]) => {
            const option = document.createElement('option');
            option.value = code;
            option.textContent = text;
            if (code === selected) option.selected = true;
            region.appendChild(option);
        });
        regionField.hidden = Object.keys(options).length === 0;
        region.required = !regionField.hidden && method.value !== 'store_pickup';
    };
    method.addEventListener('change', refreshFulfilment);
    if (pickupLocation) pickupLocation.addEventListener('change', refreshFulfilment);
    if (country) {
        ['input', 'change'].forEach(eventName => {
            country.addEventListener(eventName, () => {
                refreshRegion();
                refreshFulfilment();
            });
        });
    }
    refreshRegion();
    refreshFulfilment();
})();
</script>
</body>
</html>
