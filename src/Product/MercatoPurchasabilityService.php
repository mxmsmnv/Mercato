<?php
namespace ProcessWire;

final class MercatoPurchasabilityService extends Wire {

    public function __construct(protected Mercato $commerce) {
        parent::__construct();
    }

    /**
     * Evaluate whether a product can be bought right now.
     *
     * Admin/catalog UI may still display products that fail this check, but
     * public add-to-cart and payment creation should use this shared decision.
     */
    public function evaluate(Page $product, int $requestedQuantity = 1, float $cartQuantity = 0.0, int $excludeOrderId = 0): array {
        $requestedQuantity = max(1, (int) ceil($requestedQuantity));
        $cartQuantity = max(0.0, $cartQuantity);
        $errors = [];

        $stockPolicy = $product && $product->hasField('mrc_stock_policy')
            ? strtolower(trim((string) $product->mrc_stock_policy))
            : 'deny';
        if (!in_array($stockPolicy, ['deny', 'backorder', 'preorder'], true)) {
            $stockPolicy = 'deny';
        }
        $productStatus = $product && $product->hasField('mrc_product_status')
            ? strtolower(trim((string) $product->mrc_product_status))
            : 'active';
        if (!in_array($productStatus, ['active', 'archived', 'discontinued'], true)) {
            $productStatus = 'active';
        }
        $productType = $product && $product->hasField('mrc_product_type')
            ? strtolower(trim((string) $product->mrc_product_type))
            : 'physical';
        if (!in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true)) {
            $productType = 'physical';
        }
        $purchasableType = in_array($productType, ['physical', 'digital', 'service'], true);
        $allowsOversell = in_array($stockPolicy, ['backorder', 'preorder'], true);
        $stock = $product && $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
        $reservedQuantity = ($product && $product->id && !$allowsOversell)
            ? $this->commerce->orderRepository()->getReservedQuantityForProduct((int) $product->id, $excludeOrderId)
            : 0;
        $availableStock = max(0, $stock - $reservedQuantity);
        $remainingStock = max(0, $availableStock - (int) ceil($cartQuantity));
        $hasValidPrice = $product && $product->hasField('mrc_price') && (float) $product->mrc_price > 0;

        if (!$product || !$product->id || !$product->template || $product->template->name !== 'mrc-product') {
            $errors[] = $this->commerce->_('Product is no longer available.');
        } else {
            if ($product->isHidden() || $product->isUnpublished()) {
                $errors[] = $this->commerce->_('Product is no longer available.');
            }
            if ($productStatus === 'archived') {
                $errors[] = $this->commerce->_('Product has been archived.');
            } elseif ($productStatus === 'discontinued') {
                $errors[] = $this->commerce->_('Product has been discontinued.');
            }
            if (!$purchasableType) {
                $errors[] = $this->commerce->_('Product type is not purchasable.');
            }
            if (!$hasValidPrice) {
                $errors[] = $this->commerce->_('Product does not have a valid price.');
            }
            if (!$allowsOversell && ($requestedQuantity + (int) ceil($cartQuantity)) > $availableStock) {
                $errors[] = $this->commerce->_('Requested quantity is not available.');
            }
        }

        $stockLabel = match ($stockPolicy) {
            'backorder' => $remainingStock > 0 ? $remainingStock . ' ' . $this->commerce->_('available') : $this->commerce->_('Available on backorder'),
            'preorder' => $remainingStock > 0 ? $remainingStock . ' ' . $this->commerce->_('available') : $this->commerce->_('Available for preorder'),
            default => $remainingStock > 0 ? $remainingStock . ' ' . $this->commerce->_('available') : $this->commerce->_('Out of stock'),
        };

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'first_error' => $errors[0] ?? '',
            'unavailable_label' => (!$hasValidPrice || in_array($productStatus, ['archived', 'discontinued'], true)) ? $this->commerce->_('Unavailable') : $this->commerce->_('Out of stock'),
            'product_status' => $productStatus,
            'product_type' => $productType,
            'purchasable_type' => $purchasableType,
            'has_valid_price' => $hasValidPrice,
            'stock_policy' => $stockPolicy,
            'allows_oversell' => $allowsOversell,
            'stock' => $stock,
            'reserved_quantity' => $reservedQuantity,
            'available_stock' => $availableStock,
            'remaining_stock' => $remainingStock,
            'stock_label' => $stockLabel,
        ];
    }
}
