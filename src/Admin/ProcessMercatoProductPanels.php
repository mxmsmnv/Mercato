<?php
namespace ProcessWire;

trait ProcessMercatoProductPanels {

    protected function renderProducts($products, Mercato $commerce, bool $compact = true, array $importResult = [], array $adjustmentResult = [], array $bulkResult = [], array $filters = []): string {
        $productsParent = $this->wire('pages')->get('/products/');
        $productTemplate = $this->wire('templates')->get('mrc-product');
        $addProductUrl = ($productsParent && $productsParent->id && $productTemplate)
            ? $this->wire('config')->urls->admin . 'page/add/?parent_id=' . (int) $productsParent->id . '&template_id=' . (int) $productTemplate->id
            : '';

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Products')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Products available to the storefront and checkout flow.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('products', $this->getProductExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a>';
        if ($compact) {
            $productsUrl = $this->adminUrl('products/');
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($productsUrl) . '"><i class="fa fa-table uk-margin-small-right"></i>' . $this->e($this->_('View all products')) . '</a>';
        }
        if ($addProductUrl) {
            $out .= '<a class="uk-button uk-button-primary" href="' . $this->e($addProductUrl) . '"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Add Product')) . '</a>';
        }
        $out .= '</div>';
        $out .= '</div>';

        if ($adjustmentResult) {
            $class = !empty($adjustmentResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '">';
            $out .= '<p><strong>' . $this->e((string) ($adjustmentResult['summary'] ?? '')) . '</strong></p>';
            if (!empty($adjustmentResult['errors'])) {
                $out .= '<ul>';
                foreach ((array) $adjustmentResult['errors'] as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }
        if ($bulkResult) {
            $class = !empty($bulkResult['errors']) ? 'uk-alert-warning' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '">';
            $out .= '<p><strong>' . $this->e((string) ($bulkResult['summary'] ?? '')) . '</strong></p>';
            if (!empty($bulkResult['errors'])) {
                $out .= '<ul>';
                foreach (array_slice((array) $bulkResult['errors'], 0, 8) as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }

        if (!$compact) {
            $out .= $this->renderProductFilters($filters);
            $out .= $this->renderProductBulkActions();
        }

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table mrc-products-table">';
        $out .= '<thead><tr>';
        $headings = $compact
            ? [$this->_('Product'), $this->_('Collections'), $this->_('Price'), $this->_('Stock'), $this->_('Policy')]
            : ['', $this->_('Product'), $this->_('Collections'), $this->_('Price'), $this->_('Stock'), $this->_('Policy')];
        if (!$compact) {
            $headings[] = $this->_('Adjust');
        }
        $headings = array_merge($headings, [$this->_('Shipping'), $this->_('Status'), '']);
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$products->count()) {
            $out .= $this->renderSkeletonRows(4, $compact ? 8 : 10);
            $out .= '</tbody></table></div><p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No products yet. Run the installer or add your first product.')) . '</p>';
            if (!$compact) {
                $out .= $this->renderProductImportPanel($importResult);
            }
            $out .= '</section>';
            if (!$compact) {
                $out .= $this->renderProductActivity($this->getProductEvents(30));
            }
            return $out;
        }

        foreach ($products as $product) {
            $statusKey = $this->getProductPublicationStatus($product);
            $status = match ($statusKey) {
                'unpublished' => $this->_('Unpublished'),
                'hidden' => $this->_('Hidden'),
                default => $this->_('Published'),
            };
            $statusClass = match ($statusKey) {
                'unpublished' => 'is-failed',
                'hidden' => 'is-pending',
                default => 'is-paid',
            };
            $lifecycle = $this->getProductLifecycleStatus($product);
            $lifecycleLabel = match ($lifecycle) {
                'archived' => $this->_('Archived'),
                'discontinued' => $this->_('Discontinued'),
                default => $this->_('Active'),
            };
            $lifecycleClass = match ($lifecycle) {
                'archived' => 'is-pending',
                'discontinued' => 'is-failed',
                default => 'is-paid',
            };
            $productType = $this->getProductType($product);
            $productTypeLabel = match ($productType) {
                'digital' => $this->_('Digital'),
                'service' => $this->_('Service'),
                'placeholder' => $this->_('Placeholder'),
                'recurring' => $this->_('Recurring'),
                default => $this->_('Physical'),
            };

            $image = ($product->hasField('mrc_images') && count($product->mrc_images))
                ? $product->mrc_images->first()
                : null;
            $shipping = $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : 0.0;
            $stock = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
            $stockState = $this->getProductStockState($product, $commerce);

            $out .= '<tr>';
            if (!$compact) {
                $out .= '<td class="mrc-select-cell"><input class="uk-checkbox" type="checkbox" name="product_ids[]" value="' . (int) $product->id . '" form="mrc-product-bulk-form" aria-label="' . $this->e(sprintf($this->_('Select %s'), (string) $product->title)) . '"></td>';
            }
            $out .= '<td class="mrc-product-cell"><div class="mrc-product-row">';
            $out .= '<div class="mrc-product-thumb">';
            if ($image) {
                $thumb = method_exists($image, 'size') ? $image->size(96, 72) : $image;
                $out .= '<img src="' . $this->e($thumb->url) . '" alt="' . $this->e((string) $product->title) . '">';
            } else {
                $out .= '<span>' . $this->e($this->_('No image')) . '</span>';
            }
            $out .= '</div>';
            $out .= '<div><strong>' . $this->e((string) $product->title) . '</strong><br><small>' . $this->e((string) ($product->mrc_sku ?: '-')) . ' · ' . $this->e($productTypeLabel) . '</small></div>';
            $out .= '</div></td>';
            $out .= '<td>' . $this->e($this->getProductCollectionLabel($product)) . '</td>';
            $out .= '<td>' . $this->e($commerce->formatPrice((float) $product->mrc_price)) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $stockState['class'] . '">' . $this->e((string) $stock) . '</span><br><small>' . $this->e($stockState['label']) . '</small></td>';
            $out .= '<td>' . $this->renderProductStockPolicy($product) . '</td>';
            if (!$compact) {
                $out .= '<td>' . $this->renderStockAdjustmentForm($product) . '</td>';
            }
            $out .= '<td>' . $this->e($shipping > 0 ? $commerce->formatPrice($shipping) : $this->_('Free')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($status) . '</span><br><small><span class="uk-label mrc-admin-status ' . $lifecycleClass . '">' . $this->e($lifecycleLabel) . '</span></small></td>';
            $out .= '<td class="mrc-table-actions">';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->productDetailUrl($product)) . '"><i class="fa fa-info-circle uk-margin-small-right"></i>' . $this->e($this->_('Detail')) . '</a>';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($product)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit')) . '</a>';
            if (!$product->isUnpublished()) {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($product->httpUrl()) . '" target="_blank" rel="noopener"><i class="fa fa-external-link uk-margin-small-right"></i>' . $this->e($this->_('View')) . '</a>';
            }
            $out .= '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        if (!$compact) {
            $out .= $this->renderProductImportPanel($importResult);
        }
        $out .= '</section>';
        if (!$compact) {
            $out .= $this->renderProductActivity($this->getProductEvents(30));
        }

        return $out;
    }

    protected function renderProductActivity(array $events): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Product Activity')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent product status, price, policy, and stock operations.')) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('product-events')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export activity')) . '</a>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Time')) . '</th><th>' . $this->e($this->_('Event')) . '</th><th>' . $this->e($this->_('Product')) . '</th><th>' . $this->e($this->_('Details')) . '</th><th>' . $this->e($this->_('User')) . '</th></tr></thead><tbody>';
        if (!$events) {
            $out .= $this->renderSkeletonRows(3, 5);
            $out .= '</tbody></table></div><p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No product activity logged yet.')) . '</p></section>';
            return $out;
        }

        foreach ($events as $event) {
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? $event['at'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status is-pending">' . $this->e((string) ($event['event'] ?? '-')) . '</span></td>';
            $out .= '<td><strong>' . $this->e((string) ($event['title'] ?? $event['product_title'] ?? '-')) . '</strong><br><small>' . $this->e((string) ($event['sku'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e($this->describeProductEvent($event)) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['user'] ?? '-')) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderProductDetail(Page $product, Mercato $commerce, $orders, array $adjustmentResult = [], array $quickUpdateResult = [], array $duplicateResult = [], bool $canViewOrders = false, array $variantResult = []): string {
        $stock = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
        $stockState = $this->getProductStockState($product, $commerce);
        $shipping = $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : 0.0;
        $statusKey = $this->getProductPublicationStatus($product);
        $events = $this->getProductEventsForProduct($product, 20);
        $metrics = $canViewOrders ? $this->getProductOrderMetrics($orders, $product, $commerce) : ['orders' => '-', 'quantity' => '-', 'revenue' => null];
        $image = ($product->hasField('mrc_images') && count($product->mrc_images)) ? $product->mrc_images->first() : null;

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<div class="ds-section-label">' . $this->e($this->_('Product Detail')) . '</div>';
        $out .= '<h2 class="uk-h3">' . $this->e((string) $product->title) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e((string) ($product->mrc_sku ?: $product->name)) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('products/')) . '"><i class="fa fa-arrow-left uk-margin-small-right"></i>' . $this->e($this->_('Back to products')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($product)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit fields')) . '</a>';
        if (!$product->isUnpublished()) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($product->httpUrl()) . '" target="_blank" rel="noopener"><i class="fa fa-external-link uk-margin-small-right"></i>' . $this->e($this->_('View storefront')) . '</a>';
        }
        $out .= '</div></div>';

        if ($adjustmentResult) {
            $class = !empty($adjustmentResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($adjustmentResult['summary'] ?? '')) . '</strong></p></div>';
        }
        if ($quickUpdateResult) {
            $class = !empty($quickUpdateResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($quickUpdateResult['summary'] ?? '')) . '</strong></p>';
            if (!empty($quickUpdateResult['errors'])) {
                $out .= '<ul>';
                foreach (array_slice((array) $quickUpdateResult['errors'], 0, 6) as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }
        if ($duplicateResult) {
            $class = !empty($duplicateResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($duplicateResult['summary'] ?? '')) . '</strong></p>';
            if (!empty($duplicateResult['errors'])) {
                $out .= '<ul>';
                foreach (array_slice((array) $duplicateResult['errors'], 0, 6) as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            } elseif (!empty($duplicateResult['product_url'])) {
                $out .= '<p><a class="uk-button uk-button-default" href="' . $this->e((string) $duplicateResult['product_url']) . '">' . $this->e($this->_('Open duplicate')) . '</a></p>';
            }
            $out .= '</div>';
        }
        if ($variantResult) {
            $class = !empty($variantResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($variantResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($variantResult['errors'] ?? []) as $error) $out .= '<p>' . $this->e((string) $error) . '</p>';
            $out .= '</div>';
        }

        $out .= '<div class="mrc-product-detail-grid">';
        $out .= '<div class="mrc-product-detail-media">';
        if ($image) {
            $thumb = method_exists($image, 'size') ? $image->size(520, 360) : $image;
            $out .= '<img src="' . $this->e($thumb->url) . '" alt="' . $this->e((string) $product->title) . '">';
        } else {
            $out .= '<span>' . $this->e($this->_('No image')) . '</span>';
        }
        $out .= '</div>';
        $out .= '<div class="mrc-product-detail-summary">';
        $summary = [
            $this->_('Price') => $commerce->formatPrice((float) $product->mrc_price),
            $this->_('Shipping') => $shipping > 0 ? $commerce->formatPrice($shipping) : $this->_('Free'),
            $this->_('Stock') => (string) $stock . ' / ' . $stockState['label'],
            $this->_('Policy') => ucfirst($this->getProductStockPolicy($product)),
            $this->_('Status') => ucfirst($statusKey),
            $this->_('Collections') => $this->getProductCollectionLabel($product),
        ];
        foreach ($summary as $label => $value) {
            $out .= '<div class="mrc-detail-row"><span>' . $this->e((string) $label) . '</span><strong>' . $this->e((string) $value) . '</strong></div>';
        }
        $out .= $this->renderProductQuickUpdateForm($product);
        $out .= $this->renderProductDuplicateForm($product);
        $out .= '<div class="mrc-product-detail-stock">' . $this->renderStockAdjustmentForm($product) . '</div>';
        $out .= '</div></div>';
        $out .= '</section>';

        $out .= $this->renderProductVariantManager($product, $commerce);

        $out .= '<div class="pw-wrap mrc-admin-stats uk-child-width-1-4@l uk-child-width-1-2@s" uk-grid>';
        foreach ([
            [$this->_('Orders'), (string) $metrics['orders'], $canViewOrders ? $this->_('Orders containing this product.') : $this->_('Requires order view permission.')],
            [$this->_('Quantity sold'), (string) $metrics['quantity'], $canViewOrders ? $this->_('Total quantity across orders.') : $this->_('Requires order view permission.')],
            [$this->_('Product revenue'), $metrics['revenue'] === null ? '-' : $commerce->formatPrice((float) $metrics['revenue']), $canViewOrders ? $this->_('Line revenue before order-level totals.') : $this->_('Requires order view permission.')],
            [$this->_('Activity events'), (string) count($events), $this->_('Recent product audit records.')],
        ] as [$label, $value, $note]) {
            $out .= '<div><div class="uk-card uk-card-default uk-card-body uk-card-small mrc-admin-card">';
            $out .= '<span class="ds-section-label">' . $this->e((string) $label) . '</span>';
            $out .= '<strong class="uk-display-block">' . $this->e((string) $value) . '</strong>';
            $out .= '<small class="uk-text-muted">' . $this->e((string) $note) . '</small>';
            $out .= '</div></div>';
        }
        $out .= '</div>';

        $out .= $this->renderProductOrders($orders, $product, $commerce, $canViewOrders);
        $out .= $this->renderProductActivity($events);
        return $out;
    }

    protected function renderProductVariantManager(Page $product, Mercato $commerce): string {
        $definition = $commerce->variantService()->getDefinition($product);
        $optionsJson = json_encode($definition['options'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $variantsJson = json_encode($definition['variants'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div>';
        $out .= '<div class="ds-section-label">' . $this->e($this->_('Variants')) . '</div><h2 class="uk-h3">' . $this->e($this->_('Product options and combinations')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Define option groups, then list only valid purchasable combinations. Variant SKU, price, stock, policy, measurements, images, and status override the base product.')) . '</p></div></div>';
        $out .= '<form method="post" action="' . $this->e($this->productDetailUrl($product)) . '" class="uk-form-stacked">' . $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_save_variants" value="1"><input type="hidden" name="product_id" value="' . (int) $product->id . '">';
        $out .= '<div class="uk-grid-small uk-child-width-1-2@m" uk-grid><label><span class="uk-form-label">' . $this->e($this->_('Option groups JSON')) . '</span><textarea class="uk-textarea" rows="18" name="variant_options_json" spellcheck="false">' . $this->e($optionsJson) . '</textarea></label>';
        $out .= '<label><span class="uk-form-label">' . $this->e($this->_('Valid variants JSON')) . '</span><textarea class="uk-textarea" rows="18" name="variants_json" spellcheck="false">' . $this->e($variantsJson) . '</textarea></label></div>';
        $out .= '<p class="uk-text-meta">' . $this->e($this->_('Option ids and value ids are stable API keys. Existing products remain single-SKU when both arrays are empty.')) . '</p>';
        $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-save uk-margin-small-right"></i>' . $this->e($this->_('Validate and save variants')) . '</button></form></section>';
        return $out;
    }

    protected function renderProductOrders($orders, Page $product, Mercato $commerce, bool $canViewOrders = false): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Orders With This Product')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent orders whose item snapshot references this product.')) . '</p></div></div>';
        if (!$canViewOrders) {
            $out .= '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('Order metrics and order rows require the mercato-view-orders permission.')) . '</p></section>';
            return $out;
        }
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Invoice')) . '</th><th>' . $this->e($this->_('Customer')) . '</th><th>' . $this->e($this->_('Quantity')) . '</th><th>' . $this->e($this->_('Payment')) . '</th><th>' . $this->e($this->_('Total')) . '</th><th>' . $this->e($this->_('Created')) . '</th><th></th></tr></thead><tbody>';
        if (!$orders->count()) {
            $out .= $this->renderSkeletonRows(3, 7);
            $out .= '</tbody></table></div><p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No orders contain this product yet.')) . '</p></section>';
            return $out;
        }
        foreach ($orders as $order) {
            $payment = $this->getOrderPaymentState($order);
            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e((string) ($order->mrc_invoice_number ?: $order->title)) . '</strong></td>';
            $out .= '<td>' . $this->e($this->getOrderCustomer($order)) . '</td>';
            $out .= '<td>' . $this->e((string) $this->getOrderProductQuantity($order, $product)) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $payment['class'] . '">' . $this->e((string) $payment['label']) . '</span></td>';
            $out .= '<td>' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</td>';
            $out .= '<td>' . $this->e(date('Y-m-d H:i', (int) $order->created)) . '</td>';
            $out .= '<td class="mrc-table-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($this->_('Order')) . '</a>';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($order)) . '">' . $this->e($this->_('Timeline')) . '</a></td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderProductQuickUpdateForm(Page $product): string {
        $policy = $this->getProductStockPolicy($product);
        $lifecycleStatus = $this->getProductLifecycleStatus($product);
        $productType = $this->getProductType($product);
        $status = $this->getProductPublicationStatus($product);
        $policies = [
            'deny' => $this->_('Deny oversell'),
            'backorder' => $this->_('Backorder'),
            'preorder' => $this->_('Preorder'),
        ];
        $statuses = [
            'published' => $this->_('Published'),
            'hidden' => $this->_('Hidden'),
            'unpublished' => $this->_('Unpublished'),
        ];
        $lifecycleStatuses = [
            'active' => $this->_('Active'),
            'archived' => $this->_('Archived'),
            'discontinued' => $this->_('Discontinued'),
        ];
        $productTypes = [
            'physical' => $this->_('Physical product'),
            'digital' => $this->_('Digital product'),
            'service' => $this->_('Service'),
            'placeholder' => $this->_('Placeholder / not purchasable'),
            'recurring' => $this->_('Recurring subscription / not one-off purchasable'),
        ];

        $out = '<form method="post" action="' . $this->e($this->productDetailUrl($product)) . '" class="mrc-product-quick-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_quick_update_product" value="1">';
        $out .= '<input type="hidden" name="product_id" value="' . (int) $product->id . '">';
        $out .= '<label><span>' . $this->e($this->_('Price')) . '</span><input class="uk-input" type="number" min="0" step="0.01" name="quick_price" value="' . $this->e($this->formatCsvAmount($product->hasField('mrc_price') ? (float) $product->mrc_price : 0.0)) . '"></label>';
        $out .= '<label><span>' . $this->e($this->_('Shipping')) . '</span><input class="uk-input" type="number" min="0" step="0.01" name="quick_shipping_price" value="' . $this->e($this->formatCsvAmount($product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : 0.0)) . '"></label>';
        $out .= '<label><span>' . $this->e($this->_('Low stock')) . '</span><input class="uk-input" type="number" min="0" step="1" name="quick_low_stock_threshold" value="' . $this->e((string) ($product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : 0)) . '"></label>';
        $out .= '<label><span>' . $this->e($this->_('Policy')) . '</span><select class="uk-select" name="quick_stock_policy">';
        foreach ($policies as $value => $label) {
            $selected = $value === $policy ? ' selected' : '';
            $out .= '<option value="' . $this->e($value) . '"' . $selected . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Product type')) . '</span><select class="uk-select" name="quick_product_type">';
        foreach ($productTypes as $value => $label) {
            $selected = $value === $productType ? ' selected' : '';
            $out .= '<option value="' . $this->e($value) . '"' . $selected . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Product status')) . '</span><select class="uk-select" name="quick_product_status">';
        foreach ($lifecycleStatuses as $value => $label) {
            $selected = $value === $lifecycleStatus ? ' selected' : '';
            $out .= '<option value="' . $this->e($value) . '"' . $selected . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Status')) . '</span><select class="uk-select" name="quick_status">';
        foreach ($statuses as $value => $label) {
            $selected = $value === $status ? ' selected' : '';
            $out .= '<option value="' . $this->e($value) . '"' . $selected . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-save uk-margin-small-right"></i>' . $this->e($this->_('Update commerce fields')) . '</button>';
        $out .= '</form>';
        return $out;
    }

    protected function renderProductDuplicateForm(Page $product): string {
        $title = sprintf($this->_('%s copy'), (string) $product->title);
        $sku = (string) ($product->hasField('mrc_sku') && trim((string) $product->mrc_sku) !== '' ? $product->mrc_sku . '-COPY' : 'MRC-COPY-' . (int) $product->id);
        $out = '<form method="post" action="' . $this->e($this->productDetailUrl($product)) . '" class="mrc-product-duplicate-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_duplicate_product" value="1">';
        $out .= '<input type="hidden" name="product_id" value="' . (int) $product->id . '">';
        $out .= '<label><span>' . $this->e($this->_('Duplicate title')) . '</span><input class="uk-input" type="text" name="duplicate_title" value="' . $this->e($title) . '"></label>';
        $out .= '<label><span>' . $this->e($this->_('Duplicate SKU')) . '</span><input class="uk-input" type="text" name="duplicate_sku" value="' . $this->e($sku) . '"></label>';
        $checked = ($product->hasField('mrc_images') && count($product->mrc_images)) ? ' checked' : '';
        $out .= '<label class="mrc-checkbox-line"><input class="uk-checkbox" type="checkbox" name="duplicate_copy_images" value="1"' . $checked . '> ' . $this->e($this->_('Copy product images')) . '</label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-clone uk-margin-small-right"></i>' . $this->e($this->_('Duplicate product')) . '</button>';
        $out .= '</form>';
        return $out;
    }

    protected function renderProductFilters(array $filters): string {
        $q = (string) ($filters['q'] ?? '');
        $stock = (string) ($filters['stock'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $lifecycle = (string) ($filters['lifecycle'] ?? 'all');
        $productType = (string) ($filters['product_type'] ?? 'all');
        $policy = (string) ($filters['policy'] ?? 'all');
        $sort = (string) ($filters['sort'] ?? 'modified_desc');

        $stockOptions = [
            'all' => $this->_('All stock'),
            'in_stock' => $this->_('In stock'),
            'low_stock' => $this->_('Low stock'),
            'out_of_stock' => $this->_('Out of stock'),
            'backorder' => $this->_('Backorder'),
            'preorder' => $this->_('Preorder'),
        ];
        $statusOptions = [
            'all' => $this->_('All statuses'),
            'published' => $this->_('Published'),
            'hidden' => $this->_('Hidden'),
            'unpublished' => $this->_('Unpublished'),
        ];
        $lifecycleOptions = [
            'all' => $this->_('All product statuses'),
            'active' => $this->_('Active'),
            'archived' => $this->_('Archived'),
            'discontinued' => $this->_('Discontinued'),
        ];
        $productTypeOptions = [
            'all' => $this->_('All product types'),
            'physical' => $this->_('Physical'),
            'digital' => $this->_('Digital'),
            'service' => $this->_('Service'),
            'placeholder' => $this->_('Placeholder'),
            'recurring' => $this->_('Recurring'),
        ];
        $policyOptions = [
            'all' => $this->_('All policies'),
            'deny' => $this->_('Deny oversell'),
            'backorder' => $this->_('Backorder'),
            'preorder' => $this->_('Preorder'),
        ];
        $sortOptions = [
            'modified_desc' => $this->_('Newest updated'),
            'modified_asc' => $this->_('Oldest updated'),
            'title_asc' => $this->_('Title A-Z'),
            'price_asc' => $this->_('Price low-high'),
            'price_desc' => $this->_('Price high-low'),
            'stock_asc' => $this->_('Stock low-high'),
            'stock_desc' => $this->_('Stock high-low'),
        ];

        $out = '<form method="get" action="' . $this->e($this->adminUrl('products/')) . '" class="mrc-product-filters uk-margin-bottom">';
        $out .= '<div class="uk-grid-small uk-flex-middle" uk-grid>';
        $out .= '<div class="uk-width-expand@m"><input class="uk-input" type="search" name="q" value="' . $this->e($q) . '" placeholder="' . $this->e($this->_('Search title, SKU, description...')) . '"></div>';
        foreach ([
            'stock' => [$stockOptions, $stock],
            'status' => [$statusOptions, $status],
            'lifecycle' => [$lifecycleOptions, $lifecycle],
            'product_type' => [$productTypeOptions, $productType],
            'policy' => [$policyOptions, $policy],
            'sort' => [$sortOptions, $sort],
        ] as $name => [$options, $active]) {
            $out .= '<div class="uk-width-auto@m"><select class="uk-select" name="' . $this->e((string) $name) . '" aria-label="' . $this->e(ucfirst((string) $name)) . '">';
            foreach ($options as $value => $label) {
                $selected = (string) $value === (string) $active ? ' selected' : '';
                $out .= '<option value="' . $this->e((string) $value) . '"' . $selected . '>' . $this->e((string) $label) . '</option>';
            }
            $out .= '</select></div>';
        }
        $out .= '<div class="uk-width-auto@m"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Filter')) . '</button></div>';
        if ($q !== '' || $stock !== 'all' || $status !== 'all' || $lifecycle !== 'all' || $productType !== 'all' || $policy !== 'all' || $sort !== 'modified_desc') {
            $out .= '<div class="uk-width-auto@m"><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('products/')) . '">' . $this->e($this->_('Reset')) . '</a></div>';
        }
        $out .= '</div></form>';
        return $out;
    }

    protected function renderProductBulkActions(): string {
        $actions = [
            '' => $this->_('Bulk action'),
            'publish' => $this->_('Publish'),
            'hide' => $this->_('Hide'),
            'unpublish' => $this->_('Unpublish'),
            'policy:deny' => $this->_('Set policy: deny oversell'),
            'policy:backorder' => $this->_('Set policy: backorder'),
            'policy:preorder' => $this->_('Set policy: preorder'),
            'lifecycle:active' => $this->_('Set product status: active'),
            'lifecycle:archived' => $this->_('Set product status: archived'),
            'lifecycle:discontinued' => $this->_('Set product status: discontinued'),
            'type:physical' => $this->_('Set product type: physical'),
            'type:digital' => $this->_('Set product type: digital'),
            'type:service' => $this->_('Set product type: service'),
            'type:placeholder' => $this->_('Set product type: placeholder'),
            'type:recurring' => $this->_('Set product type: recurring'),
            'price:set' => $this->_('Set price to value'),
            'price:add' => $this->_('Add amount to price'),
            'price:percent' => $this->_('Change price by percent'),
        ];

        $out = '<form id="mrc-product-bulk-form" method="post" action="' . $this->e($this->adminUrl('products/')) . '" class="mrc-product-bulk-actions uk-margin-bottom">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_bulk_product_action" value="1">';
        $out .= '<div class="uk-grid-small uk-flex-middle" uk-grid>';
        $out .= '<div class="uk-width-auto@m"><select class="uk-select" name="bulk_action" aria-label="' . $this->e($this->_('Bulk product action')) . '" required>';
        foreach ($actions as $value => $label) {
            $out .= '<option value="' . $this->e((string) $value) . '">' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></div>';
        $out .= '<div class="uk-width-small@m"><input class="uk-input" type="number" name="bulk_price_value" value="" step="0.01" placeholder="' . $this->e($this->_('Price value')) . '" aria-label="' . $this->e($this->_('Bulk price value')) . '"></div>';
        $out .= '<div class="uk-width-auto@m"><button class="uk-button uk-button-default" type="submit"><i class="fa fa-check-square-o uk-margin-small-right"></i>' . $this->e($this->_('Apply to selected')) . '</button></div>';
        $out .= '<div class="uk-width-expand@m"><small class="uk-text-muted">' . $this->e($this->_('Select products in the table below. Price actions use the value field; product status and placeholder type actions control purchasability without changing ProcessWire publication.')) . '</small></div>';
        $out .= '</div></form>';
        return $out;
    }

    protected function renderStockAdjustmentForm(Page $product): string {
        if (!$product->hasField('mrc_stock')) {
            return '-';
        }

        $out = '<form method="post" action="' . $this->e($this->adminUrl('products/')) . '" class="mrc-stock-adjust-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_adjust_stock" value="1">';
        $out .= '<input type="hidden" name="product_id" value="' . (int) $product->id . '">';
        $out .= '<input class="uk-input" type="number" name="stock_delta" value="1" step="1" aria-label="' . $this->e($this->_('Stock delta')) . '">';
        $out .= '<input class="uk-input" type="text" name="stock_note" value="" placeholder="' . $this->e($this->_('Note')) . '" aria-label="' . $this->e($this->_('Stock note')) . '">';
        $out .= '<button class="uk-button uk-button-default" type="submit" title="' . $this->e($this->_('Apply stock adjustment')) . '" aria-label="' . $this->e($this->_('Apply stock adjustment')) . '"><i class="fa fa-check"></i></button>';
        $out .= '</form>';
        return $out;
    }

    protected function getProductStockThreshold(Page $product, Mercato $commerce): int {
        if ($product->hasField('mrc_low_stock_threshold') && (int) $product->mrc_low_stock_threshold > 0) {
            return (int) $product->mrc_low_stock_threshold;
        }
        return $commerce->getLowStockThreshold();
    }

    protected function isLowStockProduct(Page $product, Mercato $commerce): bool {
        $variants = $commerce->variantService()->getDefinition($product)['variants'];
        if ($variants) {
            foreach ($variants as $variant) {
                if ($variant['status'] !== 'active') continue;
                $threshold = (int) ($variant['low_stock_threshold'] ?: $this->getProductStockThreshold($product, $commerce));
                if ($variant['stock_policy'] === 'deny' && $threshold > 0 && (int) $variant['stock'] <= $threshold) return true;
            }
            return false;
        }
        if (!$product->hasField('mrc_stock')) {
            return false;
        }
        $threshold = $this->getProductStockThreshold($product, $commerce);
        if ($this->getProductStockPolicy($product) !== 'deny' && (int) $product->mrc_stock <= 0) {
            return false;
        }
        return $threshold > 0 && (int) $product->mrc_stock <= $threshold;
    }

    protected function getProductStockState(Page $product, Mercato $commerce): array {
        $stock = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
        $threshold = $this->getProductStockThreshold($product, $commerce);

        $policy = $this->getProductStockPolicy($product);
        if ($stock <= 0 && $policy === 'backorder') {
            $label = $stock < 0
                ? sprintf($this->_('Backorder: %d owed'), abs($stock))
                : $this->_('Backorder');
            return ['raw' => 'backorder', 'label' => $label, 'class' => 'is-pending'];
        }
        if ($stock <= 0 && $policy === 'preorder') {
            $label = $stock < 0
                ? sprintf($this->_('Preorder: %d owed'), abs($stock))
                : $this->_('Preorder');
            return ['raw' => 'preorder', 'label' => $label, 'class' => 'is-pending'];
        }
        if ($stock <= 0) {
            return ['raw' => 'out_of_stock', 'label' => $this->_('Out of stock'), 'class' => 'is-failed'];
        }
        if ($threshold > 0 && $stock <= $threshold) {
            return ['raw' => 'low_stock', 'label' => sprintf($this->_('Low ≤ %d'), $threshold), 'class' => 'is-pending'];
        }
        return ['raw' => 'in_stock', 'label' => $this->_('In stock'), 'class' => 'is-paid'];
    }

    protected function getProductStockPolicy(Page $product): string {
        $policy = $product->hasField('mrc_stock_policy') ? strtolower(trim((string) $product->mrc_stock_policy)) : '';
        return in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
    }

    protected function getProductPublicationStatus(Page $product): string {
        if ($product->isUnpublished()) {
            return 'unpublished';
        }
        if ($product->isHidden()) {
            return 'hidden';
        }
        return 'published';
    }

    protected function getProductLifecycleStatus(Page $product): string {
        $status = $product->hasField('mrc_product_status') ? strtolower(trim((string) $product->mrc_product_status)) : '';
        return in_array($status, ['active', 'archived', 'discontinued'], true) ? $status : 'active';
    }

    protected function getProductType(Page $product): string {
        $type = $product->hasField('mrc_product_type') ? strtolower(trim((string) $product->mrc_product_type)) : '';
        return in_array($type, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true) ? $type : 'physical';
    }

    protected function renderProductStockPolicy(Page $product): string {
        $policy = $this->getProductStockPolicy($product);
        $label = match ($policy) {
            'backorder' => $this->_('Backorder'),
            'preorder' => $this->_('Preorder'),
            default => $this->_('Deny'),
        };
        $class = $policy === 'deny' ? 'is-failed' : 'is-pending';
        return '<span class="uk-label mrc-admin-status ' . $class . '">' . $this->e($label) . '</span>';
    }

    protected function renderProductImportPanel(array $result = []): string {
        $sample = "title,name,sku,price,tax_rate,tax_code,shipping_price,stock,low_stock_threshold,stock_policy,product_type,product_status,stripe_price_id,download_limit,download_expiry_days,status,collections,image_urls,description,variant_options_json,variants_json\n"
            . "Sample Product,sample-product,MRC-SAMPLE,19.90,20,general,3.95,25,5,deny,physical,active,,0,0,published,Demo Essentials,/site/assets/import/sample.jpg,Short product description,[],[]";

        $out = '<div class="mrc-admin-subsection">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h3 class="uk-h4">' . $this->e($this->_('Import Products CSV')) . '</h3>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Paste CSV with headers. Existing products are updated by SKU first, then by page name. No products are deleted. image_urls accepts local paths or URLs separated by |; variant JSON columns round-trip the exact option and combination model.')) . '</p>';
        $out .= '</div>';
        $out .= '</div>';

        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '">';
            $out .= '<p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            if (!empty($result['errors'])) {
                $out .= '<ul>';
                foreach (array_slice((array) $result['errors'], 0, 8) as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }

        $out .= '<form method="post" action="' . $this->e($this->adminUrl('products/')) . '" class="mrc-import-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<textarea class="uk-textarea" name="mrc_products_csv" rows="8" spellcheck="false">' . $this->e((string) $this->wire('input')->post->textarea('mrc_products_csv')) . '</textarea>';
        $out .= '<div class="mrc-import-actions">';
        $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_import_products" value="preview"><i class="fa fa-eye uk-margin-small-right"></i>' . $this->e($this->_('Preview')) . '</button>';
        $out .= '<button class="uk-button uk-button-primary" type="submit" name="mrc_import_products" value="import"><i class="fa fa-upload uk-margin-small-right"></i>' . $this->e($this->_('Import')) . '</button>';
        $out .= '</div>';
        $out .= '<details class="mrc-import-sample"><summary>' . $this->e($this->_('Sample CSV')) . '</summary><pre>' . $this->e($sample) . '</pre></details>';
        $out .= '</form>';
        $out .= '</div>';

        return $out;
    }

}
