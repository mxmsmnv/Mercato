<?php
namespace ProcessWire;

/**
 * Mercato install/uninstall
 *
 * Creates all required fields, templates, and pages.
 * Called from Mercato::install() and Mercato::uninstall().
 *
 * BUG-11 fix: Template filenames must be basenames only — PW resolves them
 *             relative to $config->paths->templates (/site/templates/).
 *             Template PHP files are copied there on install.
 *
 * BUG-12 fix: mrc-orders had noParents=1 which means "this template can never
 *             be used on any page". Removed entirely; default (0) allows it anywhere.
 *
 * BUG-20 fix: FieldtypeEmail may not be installed — fall back to FieldtypeText.
 */

function mercato_wire() {
    return function_exists('wire') ? wire() : \ProcessWire\wire();
}

function mercato_install(Mercato $module, bool $overwriteTemplateFiles = false): void {
    $wire = mercato_wire();

    mercato_store_schema_version($module);
    mercato_ensure_permissions();
    mercato_ensure_roles();

    // -----------------------------------------------------------------------
    // Copy template files to /site/templates/
    // PW resolves template filenames relative to $config->paths->templates.
    // -----------------------------------------------------------------------
    mercato_copy_template_files($module, $overwriteTemplateFiles);

    // -----------------------------------------------------------------------
    // Fields
    // -----------------------------------------------------------------------
    // BUG-20: FieldtypeEmail is optional; fall back to FieldtypeText if absent
    $emailType = $wire->modules->isInstalled('FieldtypeEmail') ? 'FieldtypeEmail' : 'FieldtypeText';
    $dimensionsEnabled = !empty($module->shipping_dimensions_enabled) || !empty($wire->input->post('shipping_dimensions_enabled'));
    $dimensionsFieldName = strtolower(trim((string) (
        $wire->input->post('shipping_dimensions_field') ?: ($module->shipping_dimensions_field ?? 'mrc_dimensions')
    )));
    $dimensionsFieldName = trim(preg_replace('/[^a-z0-9_]+/', '_', $dimensionsFieldName) ?: '', '_') ?: 'mrc_dimensions';

    $fieldDefs = [
        // Order fields
        ['name' => 'mrc_invoice_number',          'type' => 'FieldtypeText',     'label' => 'Invoice Number'],
        ['name' => 'mrc_invoice_date',             'type' => 'FieldtypeText',     'label' => 'Invoice Date'],
        ['name' => 'mrc_first_name',               'type' => 'FieldtypeText',     'label' => 'First Name'],
        ['name' => 'mrc_last_name',                'type' => 'FieldtypeText',     'label' => 'Last Name'],
        ['name' => 'mrc_email',                    'type' => $emailType,          'label' => 'Email'],
        ['name' => 'mrc_phone',                    'type' => 'FieldtypeText',     'label' => 'Phone'],
        ['name' => 'mrc_address',                  'type' => 'FieldtypeText',     'label' => 'Address'],
        ['name' => 'mrc_city',                     'type' => 'FieldtypeText',     'label' => 'City'],
        ['name' => 'mrc_zip',                      'type' => 'FieldtypeText',     'label' => 'ZIP / Postal Code'],
        ['name' => 'mrc_country',                  'type' => 'FieldtypeText',     'label' => 'Country'],
        ['name' => 'mrc_billing_address',          'type' => 'FieldtypeTextarea', 'label' => 'Billing Address (JSON)'],
        ['name' => 'mrc_shipping_address',         'type' => 'FieldtypeTextarea', 'label' => 'Shipping Address (JSON)'],
        ['name' => 'mrc_notes',                    'type' => 'FieldtypeTextarea', 'label' => 'Order Notes'],
        ['name' => 'mrc_payment_method',           'type' => 'FieldtypeText',     'label' => 'Payment Method'],
        ['name' => 'mrc_payment_status',           'type' => 'FieldtypeText',     'label' => 'Payment Status'],
        ['name' => 'mrc_payment_complete',         'type' => 'FieldtypeCheckbox', 'label' => 'Payment Complete'],
        ['name' => 'mrc_paid_date',                'type' => 'FieldtypeText',     'label' => 'Paid Date'],
        ['name' => 'mrc_payment_details',          'type' => 'FieldtypeTextarea', 'label' => 'Payment Details (JSON)'],
        ['name' => 'mrc_receipt_details',          'type' => 'FieldtypeTextarea', 'label' => 'Receipt Details (JSON)'],
        ['name' => 'mrc_status_token_seed',        'type' => 'FieldtypeText',     'label' => 'Order Status Token Seed'],
        ['name' => 'mrc_subscription_id',          'type' => 'FieldtypeText',     'label' => 'Subscription ID'],
        ['name' => 'mrc_subscription_status',      'type' => 'FieldtypeText',     'label' => 'Subscription Status'],
        ['name' => 'mrc_subscription_current_period_end', 'type' => 'FieldtypeText', 'label' => 'Subscription Current Period End'],
        ['name' => 'mrc_subscription_cancel_at_period_end', 'type' => 'FieldtypeCheckbox', 'label' => 'Subscription Cancel At Period End'],
        ['name' => 'mrc_subscription_canceled_at', 'type' => 'FieldtypeText',     'label' => 'Subscription Canceled At'],
        ['name' => 'mrc_subscription_cancel_details', 'type' => 'FieldtypeTextarea', 'label' => 'Subscription Cancel Details (JSON)'],
        ['name' => 'mrc_subscription_details',     'type' => 'FieldtypeTextarea', 'label' => 'Subscription Details (JSON)'],
        ['name' => 'mrc_subscription_renewal_details', 'type' => 'FieldtypeTextarea', 'label' => 'Subscription Renewal Details (JSON)'],
        ['name' => 'mrc_policy_accepted',          'type' => 'FieldtypeCheckbox', 'label' => 'Checkout Policies Accepted'],
        ['name' => 'mrc_policy_acceptance_details','type' => 'FieldtypeTextarea', 'label' => 'Policy Acceptance Details (JSON)'],
        ['name' => 'mrc_confirmation_sent_date',   'type' => 'FieldtypeText',     'label' => 'Confirmation Sent Date'],
        ['name' => 'mrc_confirmation_send_count',  'type' => 'FieldtypeInteger',  'label' => 'Confirmation Send Count'],
        ['name' => 'mrc_refunded_amount',          'type' => 'FieldtypeFloat',    'label' => 'Refunded Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_refund_pending_amount',    'type' => 'FieldtypeFloat',    'label' => 'Pending Refund Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_refunded_date',            'type' => 'FieldtypeText',     'label' => 'Last Refunded Date'],
        ['name' => 'mrc_refund_details',           'type' => 'FieldtypeTextarea', 'label' => 'Refund Details (JSON)'],
        ['name' => 'mrc_items',                    'type' => 'FieldtypeTextarea', 'label' => 'Cart Items (JSON)'],
        ['name' => 'mrc_download_details',         'type' => 'FieldtypeTextarea', 'label' => 'Download Details (JSON)'],
        ['name' => 'mrc_inventory_reserved',       'type' => 'FieldtypeCheckbox', 'label' => 'Inventory Reserved'],
        ['name' => 'mrc_inventory_reserved_until', 'type' => 'FieldtypeText',     'label' => 'Inventory Reserved Until'],
        ['name' => 'mrc_inventory_adjusted',       'type' => 'FieldtypeCheckbox', 'label' => 'Inventory Adjusted'],
        ['name' => 'mrc_inventory_refund_restored','type' => 'FieldtypeCheckbox', 'label' => 'Refund Inventory Restored'],
        ['name' => 'mrc_inventory_details',        'type' => 'FieldtypeTextarea', 'label' => 'Inventory Details (JSON)'],
        ['name' => 'mrc_fulfilment_status',        'type' => 'FieldtypeText',     'label' => 'Fulfilment Status'],
        ['name' => 'mrc_fulfilment_method',        'type' => 'FieldtypeText',     'label' => 'Fulfilment Method'],
        ['name' => 'mrc_fulfilment_label',         'type' => 'FieldtypeText',     'label' => 'Fulfilment Label'],
        ['name' => 'mrc_fulfilment_details',       'type' => 'FieldtypeTextarea', 'label' => 'Fulfilment Details (JSON)'],
        ['name' => 'mrc_fulfilment_tracking',      'type' => 'FieldtypeText',     'label' => 'Fulfilment Tracking'],
        ['name' => 'mrc_fulfilment_tracking_url',  'type' => 'FieldtypeText',     'label' => 'Fulfilment Tracking URL'],
        ['name' => 'mrc_fulfilment_notes',         'type' => 'FieldtypeTextarea', 'label' => 'Fulfilment Notes'],
        ['name' => 'mrc_fulfilled_date',           'type' => 'FieldtypeText',     'label' => 'Fulfilled Date'],
        ['name' => 'mrc_currency',                 'type' => 'FieldtypeText',     'label' => 'Currency'],
        ['name' => 'mrc_subtotal_amount',          'type' => 'FieldtypeFloat',    'label' => 'Subtotal Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_shipping_amount',          'type' => 'FieldtypeFloat',    'label' => 'Shipping Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_discount_total',           'type' => 'FieldtypeFloat',    'label' => 'Discount Total', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_discount_details',         'type' => 'FieldtypeTextarea', 'label' => 'Discount Details (JSON)'],
        ['name' => 'mrc_total_amount',             'type' => 'FieldtypeFloat',    'label' => 'Total Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_stripe_customer_id',       'type' => 'FieldtypeText',     'label' => 'Stripe Customer ID'],
        ['name' => 'mrc_stripe_payment_intent_id', 'type' => 'FieldtypeText',     'label' => 'Stripe PaymentIntent ID'],
        ['name' => 'mrc_mollie_payment_id',        'type' => 'FieldtypeText',     'label' => 'Mollie Payment ID'],
        // Product fields
        ['name' => 'mrc_price',       'type' => 'FieldtypeFloat',   'label' => 'Price (incl. tax)', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_tax_rate',    'type' => 'FieldtypeFloat',   'label' => 'Tax Rate (%)',      'extra' => ['precision' => 2]],
        ['name' => 'mrc_shipping_price', 'type' => 'FieldtypeFloat', 'label' => 'Shipping Price',    'extra' => ['precision' => 2]],
        ['name' => 'mrc_shipping_note', 'type' => 'FieldtypeText',   'label' => 'Shipping Note'],
        ['name' => 'mrc_images',      'type' => 'FieldtypeImage',   'label' => 'Product Images',    'extra' => ['extensions' => 'jpg jpeg png gif webp', 'maxFiles' => 0]],
        ['name' => 'mrc_sku',         'type' => 'FieldtypeText',    'label' => 'SKU'],
        ['name' => 'mrc_product_type', 'type' => 'FieldtypeText',    'label' => 'Product Type'],
        ['name' => 'mrc_product_status', 'type' => 'FieldtypeText', 'label' => 'Product Status'],
        ['name' => 'mrc_stripe_price_id', 'type' => 'FieldtypeText', 'label' => 'Stripe Price ID'],
        ['name' => 'mrc_digital_files', 'type' => 'FieldtypeFile',   'label' => 'Digital Files', 'extra' => ['extensions' => 'pdf zip txt epub mp3 mp4 mov wav jpg jpeg png webp', 'maxFiles' => 0]],
        ['name' => 'mrc_download_limit', 'type' => 'FieldtypeInteger', 'label' => 'Download Limit'],
        ['name' => 'mrc_download_expiry_days', 'type' => 'FieldtypeInteger', 'label' => 'Download Expiry Days'],
        ['name' => 'mrc_stock',       'type' => 'FieldtypeInteger', 'label' => 'Stock'],
        ['name' => 'mrc_low_stock_threshold', 'type' => 'FieldtypeInteger', 'label' => 'Low Stock Threshold'],
        ['name' => 'mrc_stock_policy', 'type' => 'FieldtypeText', 'label' => 'Stock Policy'],
        ['name' => 'mrc_collections', 'type' => 'FieldtypePage',    'label' => 'Collections'],
        ['name' => 'mrc_description', 'type' => 'FieldtypeTextarea','label' => 'Product Description'],
        // Discount fields
        ['name' => 'mrc_discount_code',        'type' => 'FieldtypeText',     'label' => 'Discount Code'],
        ['name' => 'mrc_discount_type',        'type' => 'FieldtypeText',     'label' => 'Discount Type'],
        ['name' => 'mrc_discount_amount',      'type' => 'FieldtypeFloat',    'label' => 'Discount Amount', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_discount_percent',     'type' => 'FieldtypeFloat',    'label' => 'Discount Percent', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_discount_active',      'type' => 'FieldtypeCheckbox', 'label' => 'Active'],
        ['name' => 'mrc_discount_starts',      'type' => 'FieldtypeText',     'label' => 'Starts At'],
        ['name' => 'mrc_discount_ends',        'type' => 'FieldtypeText',     'label' => 'Ends At'],
        ['name' => 'mrc_discount_usage_limit', 'type' => 'FieldtypeInteger',  'label' => 'Usage Limit'],
        ['name' => 'mrc_discount_customer_limit', 'type' => 'FieldtypeInteger',  'label' => 'Per-Customer Limit'],
        ['name' => 'mrc_discount_minimum_order', 'type' => 'FieldtypeFloat',  'label' => 'Minimum Order Total', 'extra' => ['precision' => 2]],
        ['name' => 'mrc_discount_products',    'type' => 'FieldtypePage',     'label' => 'Product Targets'],
        ['name' => 'mrc_discount_collections', 'type' => 'FieldtypePage',     'label' => 'Collection Targets'],
        ['name' => 'mrc_discount_customer_targets', 'type' => 'FieldtypeTextarea', 'label' => 'Customer Targets'],
        ['name' => 'mrc_discount_notes',       'type' => 'FieldtypeTextarea', 'label' => 'Discount Notes'],
    ];

    foreach ($fieldDefs as $def) {
        if ($wire->fields->get($def['name'])) continue;

        $fieldtype = $wire->modules->get($def['type'])
            ?: $wire->modules->get('FieldtypeText');

        $f        = new \ProcessWire\Field();
        $f->type  = $fieldtype;
        $f->name  = $def['name'];
        $f->label = $def['label'];

        if (!empty($def['extra'])) {
            foreach ($def['extra'] as $k => $v) $f->$k = $v;
        }

        $wire->fields->save($f);
    }
    if ($dimensionsEnabled && $wire->modules->isInstalled('FieldtypeDimensions') && !$wire->fields->get($dimensionsFieldName)) {
        $f = new \ProcessWire\Field();
        $f->type = $wire->modules->get('FieldtypeDimensions');
        $f->name = $dimensionsFieldName;
        $f->label = 'Shipping dimensions';
        $wire->fields->save($f);
    }

    // -----------------------------------------------------------------------
    // Templates
    // BUG-11: filename = basename only (PW resolves against /site/templates/)
    // BUG-12: mrc-orders no longer has noParents=1
    // BUG-V:  mrc-orders created FIRST so parentTemplates on mrc-order resolves
    // -----------------------------------------------------------------------

    // mrc-orders (parent) — must exist before mrc-order references it
    if (!$wire->templates->get('mrc-orders')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-orders';
        $fg->add($wire->fields->get('title'));
        $wire->fieldgroups->save($fg);

        $t                 = new \ProcessWire\Template();
        $t->name           = 'mrc-orders';
        $t->fieldgroup     = $fg;
        $t->label          = 'Mercato Orders';
        // childTemplates set after mrc-order is created (R4-23)
        // noParents intentionally omitted — default 0 = no restriction
        $t->filename       = 'mrc-orders.php';
        $wire->templates->save($t);
    }

    // mrc-order (child) — references mrc-orders as parent template
    if (!$wire->templates->get('mrc-order')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-order';
        foreach ([
            'title', 'mrc_invoice_number', 'mrc_invoice_date',
            'mrc_first_name', 'mrc_last_name', 'mrc_email',
            'mrc_phone', 'mrc_address', 'mrc_city', 'mrc_zip',
            'mrc_country', 'mrc_billing_address', 'mrc_shipping_address', 'mrc_notes', 'mrc_payment_method',
            'mrc_payment_status', 'mrc_payment_complete', 'mrc_paid_date',
            'mrc_payment_details', 'mrc_receipt_details', 'mrc_status_token_seed',
            'mrc_subscription_id', 'mrc_subscription_status', 'mrc_subscription_current_period_end',
            'mrc_subscription_cancel_at_period_end', 'mrc_subscription_canceled_at',
            'mrc_subscription_cancel_details', 'mrc_subscription_details', 'mrc_subscription_renewal_details',
            'mrc_policy_accepted', 'mrc_policy_acceptance_details', 'mrc_confirmation_sent_date', 'mrc_confirmation_send_count',
            'mrc_refunded_amount', 'mrc_refund_pending_amount', 'mrc_refunded_date',
            'mrc_refund_details', 'mrc_items', 'mrc_download_details', 'mrc_inventory_reserved',
            'mrc_inventory_reserved_until', 'mrc_inventory_adjusted',
            'mrc_inventory_refund_restored', 'mrc_inventory_details', 'mrc_fulfilment_status',
            'mrc_fulfilment_method', 'mrc_fulfilment_label', 'mrc_fulfilment_details',
            'mrc_fulfilment_tracking', 'mrc_fulfilment_tracking_url', 'mrc_fulfilment_notes',
            'mrc_fulfilled_date', 'mrc_currency',
            'mrc_stripe_customer_id', 'mrc_stripe_payment_intent_id', 'mrc_mollie_payment_id',
            'mrc_subtotal_amount', 'mrc_shipping_amount', 'mrc_discount_code',
            'mrc_discount_total', 'mrc_discount_details', 'mrc_total_amount',
        ] as $fn) {
            $field = $wire->fields->get($fn);
            if ($field) $fg->add($field);
        }
        $wire->fieldgroups->save($fg);

        $t                  = new \ProcessWire\Template();
        $t->name            = 'mrc-order';
        $t->fieldgroup      = $fg;
        $t->label           = 'Mercato Order';
        $t->noChildren      = 1;
        $t->noParents       = -1; // restrict to parentTemplates list
        $t->parentTemplates = [$wire->templates->get('mrc-orders')]; // now exists
        $t->filename        = 'mrc-order.php';
        $wire->templates->save($t);

        // Now update mrc-orders childTemplates to reference the saved mrc-order template
        $ordersTemplate = $wire->templates->get('mrc-orders');
        if ($ordersTemplate) {
            $ordersTemplate->childTemplates = [$wire->templates->get('mrc-order')];
            $wire->templates->save($ordersTemplate);
        }
    }

    // mrc-product
    if (!$wire->templates->get('mrc-product')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-product';
        foreach ([
            'title', 'mrc_images', 'mrc_price', 'mrc_tax_rate', 'mrc_shipping_price',
            'mrc_stock', 'mrc_low_stock_threshold', 'mrc_stock_policy', 'mrc_sku', 'mrc_product_type', 'mrc_product_status', 'mrc_stripe_price_id',
            'mrc_digital_files', 'mrc_download_limit', 'mrc_download_expiry_days', 'mrc_shipping_note', 'mrc_description',
        ] as $fn) {
            $field = $wire->fields->get($fn);
            if ($field) $fg->add($field);
        }
        $wire->fieldgroups->save($fg);
        mercato_configure_product_fieldgroup($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-product';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Product';
        $t->filename   = 'mrc-product.php';
        $wire->templates->save($t);
    }

    // mrc-products
    if (!$wire->templates->get('mrc-products')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-products';
        $title = $wire->fields->get('title');
        if ($title) $fg->add($title);
        $wire->fieldgroups->save($fg);

        $t = new \ProcessWire\Template();
        $t->name = 'mrc-products';
        $t->fieldgroup = $fg;
        $t->label = 'Mercato Products';
        $t->filename = 'mrc-products.php';
        $wire->templates->save($t);
    }
    $productsTemplate = $wire->templates->get('mrc-products');
    $productTemplate = $wire->templates->get('mrc-product');
    if ($productsTemplate && $productTemplate) {
        $productsTemplate->childTemplates = [$productTemplate];
        $wire->templates->save($productsTemplate);
        $productTemplate->parentTemplates = [$productsTemplate];
        $wire->templates->save($productTemplate);
    }

    // mrc-collections
    if (!$wire->templates->get('mrc-collections')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-collections';
        $title = $wire->fields->get('title');
        if ($title) $fg->add($title);
        $wire->fieldgroups->save($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-collections';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Collections';
        $t->filename   = 'mrc-collections.php';
        $wire->templates->save($t);
    }

    // mrc-collection
    if (!$wire->templates->get('mrc-collection')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-collection';
        foreach (['title', 'mrc_description'] as $fn) {
            $field = $wire->fields->get($fn);
            if ($field) $fg->add($field);
        }
        $wire->fieldgroups->save($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-collection';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Collection';
        $t->filename   = 'mrc-collection.php';
        $wire->templates->save($t);
    }
    $collectionTemplate = $wire->templates->get('mrc-collection');
    if ($collectionTemplate && (string) $collectionTemplate->filename !== 'mrc-collection.php') {
        $collectionTemplate->filename = 'mrc-collection.php';
        $wire->templates->save($collectionTemplate);
    }
    $collectionsTemplate = $wire->templates->get('mrc-collections');
    if ($collectionsTemplate && (string) $collectionsTemplate->filename !== 'mrc-collections.php') {
        $collectionsTemplate->filename = 'mrc-collections.php';
        $wire->templates->save($collectionsTemplate);
    }
    if ($collectionsTemplate && $collectionTemplate) {
        $collectionsTemplate->childTemplates = [$collectionTemplate];
        $wire->templates->save($collectionsTemplate);
        $collectionTemplate->parentTemplates = [$collectionsTemplate];
        $wire->templates->save($collectionTemplate);
    }

    // mrc-checkout
    if (!$wire->templates->get('mrc-checkout')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-checkout';
        $fg->add($wire->fields->get('title'));
        $wire->fieldgroups->save($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-checkout';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Checkout';
        $t->filename   = 'mrc-checkout.php';
        $wire->templates->save($t);
    }

    // mrc-success
    if (!$wire->templates->get('mrc-success')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-success';
        $fg->add($wire->fields->get('title'));
        $wire->fieldgroups->save($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-success';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Success';
        $t->filename   = 'mrc-success.php';
        $wire->templates->save($t);
    }

    // mrc-page
    if (!$wire->templates->get('mrc-page')) {
        $fg = new \ProcessWire\Fieldgroup();
        $fg->name = 'mrc-page';
        foreach (['title', 'mrc_description'] as $fn) {
            $field = $wire->fields->get($fn);
            if ($field) $fg->add($field);
        }
        $wire->fieldgroups->save($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-page';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Storefront Page';
        $t->filename   = 'mrc-page.php';
        $wire->templates->save($t);
    }

    // mrc-discount
    if (!$wire->templates->get('mrc-discount')) {
        $fg = $wire->fieldgroups->get('mrc-discount');
        if (!$fg || !$fg->id) {
            $fg = new \ProcessWire\Fieldgroup();
            $fg->name = 'mrc-discount';
        }
        foreach ([
            'title', 'mrc_discount_code', 'mrc_discount_active',
            'mrc_discount_type', 'mrc_discount_percent', 'mrc_discount_amount',
            'mrc_discount_usage_limit', 'mrc_discount_customer_limit', 'mrc_discount_minimum_order', 'mrc_discount_starts', 'mrc_discount_ends',
            'mrc_discount_products', 'mrc_discount_customer_targets', 'mrc_discount_notes',
        ] as $fn) {
            $field = $wire->fields->get($fn);
            if ($field) $fg->add($field);
        }
        $wire->fieldgroups->save($fg);
        mercado_configure_discount_fieldgroup($fg);

        $t             = new \ProcessWire\Template();
        $t->name       = 'mrc-discount';
        $t->fieldgroup = $fg;
        $t->label      = 'Mercato Discount';
        $t->noChildren = 1;
        $wire->templates->save($t);
    }

    // Repair existing installations by adding fields introduced after the
    // original template was created.
    $orderTemplate = $wire->templates->get('mrc-order');
    if ($orderTemplate) {
        $added = false;
        foreach (['mrc_billing_address', 'mrc_shipping_address', 'mrc_payment_status', 'mrc_mollie_payment_id', 'mrc_receipt_details', 'mrc_status_token_seed', 'mrc_subscription_id', 'mrc_subscription_status', 'mrc_subscription_current_period_end', 'mrc_subscription_cancel_at_period_end', 'mrc_subscription_canceled_at', 'mrc_subscription_cancel_details', 'mrc_subscription_details', 'mrc_subscription_renewal_details', 'mrc_stripe_customer_id', 'mrc_policy_accepted', 'mrc_policy_acceptance_details', 'mrc_confirmation_sent_date', 'mrc_confirmation_send_count', 'mrc_refunded_amount', 'mrc_refund_pending_amount', 'mrc_refunded_date', 'mrc_refund_details', 'mrc_download_details', 'mrc_subtotal_amount', 'mrc_shipping_amount', 'mrc_discount_code', 'mrc_discount_total', 'mrc_discount_details', 'mrc_total_amount', 'mrc_inventory_reserved', 'mrc_inventory_reserved_until', 'mrc_inventory_adjusted', 'mrc_inventory_refund_restored', 'mrc_inventory_details', 'mrc_fulfilment_status', 'mrc_fulfilment_method', 'mrc_fulfilment_label', 'mrc_fulfilment_details', 'mrc_fulfilment_tracking', 'mrc_fulfilment_tracking_url', 'mrc_fulfilment_notes', 'mrc_fulfilled_date'] as $fieldName) {
            if ($orderTemplate->fieldgroup->hasField($fieldName)) continue;
            $field = $wire->fields->get($fieldName);
            if ($field) {
                $orderTemplate->fieldgroup->add($field);
                $added = true;
            }
        }
        if ($added) {
            $wire->fieldgroups->save($orderTemplate->fieldgroup);
        }
    }

    $productTemplate = $wire->templates->get('mrc-product');
    if ($productTemplate) {
        $added = false;
        foreach ([
            'mrc_images', 'mrc_price', 'mrc_tax_rate', 'mrc_shipping_price',
            'mrc_stock', 'mrc_low_stock_threshold', 'mrc_stock_policy', 'mrc_sku', 'mrc_product_type', 'mrc_product_status', 'mrc_stripe_price_id',
            'mrc_digital_files', 'mrc_download_limit', 'mrc_download_expiry_days', 'mrc_collections', 'mrc_shipping_note', 'mrc_description',
        ] as $fieldName) {
            if ($productTemplate->fieldgroup->hasField($fieldName)) continue;
            $field = $wire->fields->get($fieldName);
            if ($field) {
                $productTemplate->fieldgroup->add($field);
                $added = true;
            }
        }
        if ($added) {
            $wire->fieldgroups->save($productTemplate->fieldgroup);
        }
        if ($dimensionsEnabled && ($dimensionsField = $wire->fields->get($dimensionsFieldName)) && !$productTemplate->fieldgroup->hasField($dimensionsFieldName)) {
            $productTemplate->fieldgroup->add($dimensionsField);
            $wire->fieldgroups->save($productTemplate->fieldgroup);
        }
        mercato_configure_product_fieldgroup($productTemplate->fieldgroup);
    }

    $discountTemplate = $wire->templates->get('mrc-discount');
    if ($discountTemplate) {
        $added = false;
        foreach ([
            "title", "mrc_discount_code", "mrc_discount_active",
            "mrc_discount_type", "mrc_discount_percent", "mrc_discount_amount",
            "mrc_discount_usage_limit", "mrc_discount_customer_limit", "mrc_discount_minimum_order", "mrc_discount_starts", "mrc_discount_ends",
            "mrc_discount_products", "mrc_discount_collections", "mrc_discount_customer_targets", "mrc_discount_notes",
        ] as $fieldName) {
            if ($discountTemplate->fieldgroup->hasField($fieldName)) continue;
            $field = $wire->fields->get($fieldName);
            if ($field) {
                $discountTemplate->fieldgroup->add($field);
                $added = true;
            }
        }
        if ($added) {
            $wire->fieldgroups->save($discountTemplate->fieldgroup);
        }
        mercado_configure_discount_fieldgroup($discountTemplate->fieldgroup);
    }

    // -----------------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------------

    // Orders parent
    $ordersName = trim((string) ($module->orders_parent ?? 'orders'), '/');
    if (!$ordersName) $ordersName = 'orders';

    mercato_ensure_page_path($ordersName, 'mrc-orders', 'Orders', true);

    // Success page
    $successPagePath = (string) ($module->success_page ?? 'checkout/success');
    if (!$successPagePath) $successPagePath = 'checkout/success';

    $checkoutPagePath = trim(dirname(trim($successPagePath, '/')), './');
    if ($checkoutPagePath === '' || $checkoutPagePath === '.') {
        $checkoutPagePath = trim((string) ($module->cancel_page ?? 'checkout'), '/');
    }
    if (!$checkoutPagePath) $checkoutPagePath = 'checkout';

    $checkoutPage = mercato_ensure_page_path($checkoutPagePath, 'mrc-checkout', 'Checkout', false);
    if ($checkoutPage && $checkoutPage->id && $checkoutPage->template->name !== 'mrc-checkout') {
        $checkoutTemplate = $wire->templates->get('mrc-checkout');
        if ($checkoutTemplate) {
            $checkoutPage->of(false);
            $checkoutPage->template = $checkoutTemplate;
            $checkoutPage->removeStatus(\ProcessWire\Page::statusHidden);
            $wire->pages->save($checkoutPage);
        }
    }

    mercato_ensure_page_path($successPagePath, 'mrc-success', 'Order Confirmed', false);
    mercado_ensure_storefront_home();
    mercado_ensure_policy_pages($module);
    mercado_ensure_storefront_pages($module);
    mercado_ensure_demo_collections();
    mercado_retire_legacy_demo_collections();
    mercato_ensure_demo_products();
    mercado_retire_legacy_demo_products();
    mercado_ensure_demo_discounts();
}

function mercato_permission_definitions(): array {
    return [
        'mercato-admin' => 'Access Mercato dashboard',
        'mercato-view-orders' => 'View Mercato orders',
        'mercato-edit-orders' => 'Edit Mercato orders and send order emails',
        'mercato-refund-orders' => 'Issue and reconcile Mercato refunds',
        'mercato-create-manual-orders' => 'Create Mercato manual orders',
        'mercato-manage-products' => 'Manage Mercato products and product imports',
        'mercato-manage-inventory' => 'Adjust and view Mercato inventory',
        'mercato-fulfil-orders' => 'Update Mercato fulfilment and send fulfilment emails',
        'mercato-view-customers' => 'View Mercato customers',
        'mercato-manage-customers' => 'Manage Mercato customer notes',
        'mercato-manage-recovery' => 'Manage Mercato abandoned checkout recovery',
        'mercato-view-reports' => 'View Mercato reports',
        'mercato-manage-discounts' => 'Manage Mercato discounts',
        'mercato-manage-webhooks' => 'View and simulate Mercato webhooks',
        'mercato-launch-tools' => 'Use Mercato launch and fixture tools',
    ];
}

function mercato_ensure_permissions(): void {
    $wire = mercato_wire();
    $permissions = $wire->permissions;

    foreach (mercato_permission_definitions() as $name => $title) {
        $permission = $permissions->get($name);
        if ($permission && $permission->id) {
            if ((string) $permission->title !== (string) $title) {
                $permission->of(false);
                $permission->title = $title;
                $permissions->save($permission);
            }
            continue;
        }

        $permission = new \ProcessWire\Permission();
        $permission->name = $name;
        $permission->title = $title;
        $permissions->save($permission);
    }
}

function mercato_role_definitions(): array {
    return [
        'mercato-support' => [
            'title' => 'Mercato Support',
            'permissions' => [
                'mercato-admin',
                'mercato-view-orders',
                'mercato-edit-orders',
                'mercato-view-customers',
                'mercato-manage-customers',
                'mercato-manage-recovery',
            ],
        ],
        'mercato-fulfilment' => [
            'title' => 'Mercato Fulfilment',
            'permissions' => [
                'mercato-admin',
                'mercato-view-orders',
                'mercato-fulfil-orders',
                'mercato-manage-inventory',
            ],
        ],
        'mercato-catalog' => [
            'title' => 'Mercato Catalog',
            'permissions' => [
                'mercato-admin',
                'mercato-manage-products',
                'mercato-manage-inventory',
                'mercato-manage-discounts',
                'mercato-view-reports',
            ],
        ],
        'mercato-manager' => [
            'title' => 'Mercato Manager',
            'permissions' => array_keys(mercato_permission_definitions()),
        ],
    ];
}

function mercato_ensure_roles(): void {
    $wire = mercato_wire();
    $roles = $wire->roles;

    foreach (mercato_role_definitions() as $name => $definition) {
        $role = $roles->get($name);
        if (!$role || !$role->id) {
            $role = $roles->add($name);
        }
        if (!$role || !$role->id) {
            continue;
        }

        $role->of(false);
        if (!empty($definition['title']) && (string) $role->title !== (string) $definition['title']) {
            $role->title = (string) $definition['title'];
        }
        foreach ((array) ($definition['permissions'] ?? []) as $permissionName) {
            $role->addPermission((string) $permissionName);
        }
        $roles->save($role);
    }
}

function mercato_ensure_page_path(string $path, string $finalTemplateName, string $finalTitle, bool $finalHidden = false) {
    $wire = mercato_wire();
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    if (!$parts) return null;

    $parent = $wire->pages->get('/');
    $fallbackTemplate = $wire->templates->get('basic-page')
        ?: $wire->templates->get('home')
        ?: $wire->templates->get($finalTemplateName);

    foreach ($parts as $index => $part) {
        $isFinal = $index === count($parts) - 1;
        $name = $wire->sanitizer->pageName($part);
        if ($name === '') {
            throw new \ProcessWire\WireException("Mercato install: invalid page path segment \"$part\" in \"$path\".");
        }

        $existing = $wire->pages->get($parent->path . $name . '/');
        if ($existing && $existing->id) {
            $parent = $existing;
            continue;
        }

        $template = $isFinal
            ? $wire->templates->get($finalTemplateName)
            : $fallbackTemplate;

        if (!$template) {
            throw new \ProcessWire\WireException("Mercato install: template \"$finalTemplateName\" not found.");
        }

        $p = new \ProcessWire\Page();
        $p->template = $template;
        $p->parent = $parent;
        $p->name = $name;
        $p->of(false);
        $p->title = $isFinal ? $finalTitle : ucfirst(str_replace(['-', '_'], ' ', $name));
        if (($isFinal && $finalHidden) || !$isFinal) {
            $p->addStatus(\ProcessWire\Page::statusHidden);
        }
        $wire->pages->save($p);
        $parent = $p;
    }

    return $parent;
}

function mercado_ensure_policy_pages(Mercato $module): array {
    $wire = mercato_wire();
    $template = $wire->templates->get('mrc-page') ?: $wire->templates->get('basic-page') ?: $wire->templates->get('home');
    if (!$template) return [];

    $defs = [
        'terms-of-use' => 'Terms of Use',
        'privacy-policy' => 'Privacy Policy',
        'refund-policy' => 'Refund Policy',
        'shipping-and-returns' => 'Shipping and Returns',
    ];
    $paths = [];

    foreach ($defs as $name => $title) {
        $page = $wire->pages->get('/' . $name . '/');
        if (!$page || !$page->id) {
            $page = new \ProcessWire\Page();
            $page->template = $template;
            $page->parent = $wire->pages->get('/');
            $page->name = $name;
            $page->of(false);
            $page->title = $title;
            $wire->pages->save($page);
        } elseif ($page && $page->id && $page->template->name !== $template->name) {
            $page->of(false);
            $page->template = $template;
            $wire->pages->save($page);
        }

        if ($page && $page->id && !$page->isUnpublished()) {
            $paths[] = trim($page->path, '/');
        }
    }

    if ($paths) {
        $config = (array) $wire->modules->getConfig('Mercato');
        $config['policy_pages'] = $paths;
        $wire->modules->saveConfig('Mercato', $config);
    }

    return $paths;
}

function mercado_ensure_storefront_home(): void {
    $wire = mercato_wire();
    $home = $wire->pages->get('/');
    if (!$home || !$home->id) {
        return;
    }
    if ((string) $home->title !== 'Arlberg Ceramics') {
        $home->of(false);
        $home->title = 'Arlberg Ceramics';
        $wire->pages->save($home);
    }
}

function mercado_ensure_storefront_pages(Mercato $module): array {
    $wire = mercato_wire();
    $template = $wire->templates->get('mrc-page') ?: $wire->templates->get('basic-page') ?: $wire->templates->get('home');
    if (!$template) return [];

    $defs = [
        'about-us' => [
            'title' => 'About Us',
            'body' => '<p>Arlberg Ceramics is the demo storefront for Mercato: a focused tableware shop built around real commerce scenarios instead of placeholder catalog noise.</p><p>The range combines physical products, limited studio stock, preorder pieces, digital care guides, discount codes, tax-inclusive prices, fulfilment notes, and checkout policy acceptance.</p>',
        ],
        'contact-us' => [
            'title' => 'Contact Us',
            'body' => '<p>Use this page to test customer-service touchpoints without publishing a fake phone number or street address.</p><p>For the demo flow, customer messages are represented through checkout notes, order emails, fulfilment updates, and public order status pages.</p>',
        ],
        'privacy-policy' => [
            'title' => 'Privacy Policy',
            'body' => '<p>This demo privacy policy explains how checkout data, order details, fulfilment notes, and payment provider references can be presented in a Mercato storefront.</p><p>Replace this copy with merchant-specific legal text before using the shop for production sales.</p>',
        ],
        'terms-of-use' => [
            'title' => 'Terms of Use',
            'body' => '<p>These demo terms cover product information, checkout, payments, discount usage, digital downloads, and order communication.</p><p>They are included so the storefront can demonstrate policy links and acceptance during checkout.</p>',
        ],
        'shipping-and-returns' => [
            'title' => 'Shipping and Returns',
            'body' => '<p>Physical products in this demo include free shipping, paid shipping, pickup-style notes, and low-stock inventory behavior.</p><p>Returns and refund pages connect to Mercato order status, refund records, and customer email workflows.</p>',
        ],
        'care-guide' => [
            'title' => 'Care Guide',
            'body' => '<p>Use the care guide as a realistic content page for digital-file testing, product support, and post-purchase customer education.</p><p>It keeps the store grounded in a real sector while still exercising Mercato download and fulfilment behavior.</p>',
        ],
    ];

    $paths = [];
    foreach ($defs as $name => $data) {
        $page = $wire->pages->get('/' . $name . '/');
        if (!$page || !$page->id) {
            $page = new \ProcessWire\Page();
            $page->template = $template;
            $page->parent = $wire->pages->get('/');
            $page->name = $name;
        } elseif ($page->template->name !== $template->name) {
            $page->template = $template;
        }
        $page->of(false);
        $page->title = $data['title'];
        if ($page->hasField('mrc_description')) {
            $page->mrc_description = $data['body'];
        }
        $page->removeStatus(\ProcessWire\Page::statusHidden);
        $wire->pages->save($page);
        $paths[] = trim($page->path, '/');
    }

    return $paths;
}

function mercato_store_schema_version(Mercato $module): void {
    $wire = mercato_wire();
    $config = (array) $wire->modules->getConfig('Mercato');
    $config['installed_schema_version'] = defined(Mercato::class . '::SCHEMA_VERSION')
        ? Mercato::SCHEMA_VERSION
        : 1;
    $wire->modules->saveConfig('Mercato', $config);
    if (method_exists($module, 'set')) {
        $module->set('installed_schema_version', $config['installed_schema_version']);
    }
}

function mercato_configure_product_fieldgroup(\ProcessWire\Fieldgroup $fg): void {
    $wire = mercato_wire();
    $orderedFields = [
        'title',
        'mrc_sku',
        'mrc_product_type',
        'mrc_product_status',
        'mrc_stripe_price_id',
        'mrc_digital_files',
        'mrc_download_limit',
        'mrc_download_expiry_days',
        'mrc_stock',
        'mrc_low_stock_threshold',
        'mrc_stock_policy',
        'mrc_price',
        'mrc_tax_rate',
        'mrc_shipping_price',
        'mrc_collections',
        'mrc_images',
        'mrc_description',
        'mrc_shipping_note',
    ];

    foreach ($orderedFields as $fieldName) {
        if ($fg->hasField($fieldName)) continue;
        $field = $wire->fields->get($fieldName);
        if ($field) $fg->add($field);
    }
    $wire->fieldgroups->save($fg);

    $previous = null;
    foreach ($orderedFields as $fieldName) {
        $field = $fg->get($fieldName);
        if (!$field) continue;
        if ($previous) {
            $fg->insertAfter($field, $previous);
        }
        $previous = $field;
    }
    $wire->fieldgroups->save($fg);

    $contexts = [
        'title' => ['columnWidth' => 50],
        'mrc_sku' => ['columnWidth' => 25],
        'mrc_product_type' => ['columnWidth' => 25],
        'mrc_product_status' => ['columnWidth' => 25],
        'mrc_stripe_price_id' => ['columnWidth' => 50],
        'mrc_digital_files' => ['columnWidth' => 100],
        'mrc_download_limit' => ['columnWidth' => 25],
        'mrc_download_expiry_days' => ['columnWidth' => 25],
        'mrc_stock' => ['columnWidth' => 25],
        'mrc_low_stock_threshold' => ['columnWidth' => 25],
        'mrc_stock_policy' => ['columnWidth' => 25],
        'mrc_price' => ['columnWidth' => 25],
        'mrc_tax_rate' => ['columnWidth' => 25],
        'mrc_shipping_price' => ['columnWidth' => 50],
        'mrc_collections' => ['columnWidth' => 50],
        'mrc_images' => ['columnWidth' => 40],
        'mrc_description' => ['columnWidth' => 60],
        'mrc_shipping_note' => ['columnWidth' => 100],
    ];

    foreach ($contexts as $fieldName => $settings) {
        $field = $fg->get($fieldName);
        if (!$field) continue;
        $context = $fg->getFieldContext($field);
        foreach ($settings as $key => $value) {
            $context->$key = $value;
        }
        $wire->fields->saveFieldgroupContext($context, $fg);
    }

    mercado_configure_page_reference_field(
        'mrc_collections',
        'mrc-collection',
        'template=mrc-collection, include=all, sort=title'
    );
}

function mercado_configure_discount_fieldgroup(\ProcessWire\Fieldgroup $fg): void {
    $wire = function_exists("ProcessWire\\mercato_wire") ? \ProcessWire\mercato_wire() : \ProcessWire\wire();
    $orderedFields = [
        "title",
        "mrc_discount_code",
        "mrc_discount_active",
        "mrc_discount_type",
        "mrc_discount_percent",
        "mrc_discount_amount",
        "mrc_discount_usage_limit",
        "mrc_discount_customer_limit",
        "mrc_discount_minimum_order",
        "mrc_discount_products",
        "mrc_discount_collections",
        "mrc_discount_customer_targets",
        "mrc_discount_starts",
        "mrc_discount_ends",
        "mrc_discount_notes",
    ];

    foreach ($orderedFields as $fieldName) {
        if ($fg->hasField($fieldName)) continue;
        $field = $wire->fields->get($fieldName);
        if ($field) $fg->add($field);
    }
    $wire->fieldgroups->save($fg);

    $contexts = [
        "title" => ["columnWidth" => 50],
        "mrc_discount_code" => ["columnWidth" => 25],
        "mrc_discount_active" => ["columnWidth" => 25],
        "mrc_discount_type" => ["columnWidth" => 25],
        "mrc_discount_percent" => ["columnWidth" => 25],
        "mrc_discount_amount" => ["columnWidth" => 25],
        "mrc_discount_usage_limit" => ["columnWidth" => 25],
        "mrc_discount_customer_limit" => ["columnWidth" => 25],
        "mrc_discount_minimum_order" => ["columnWidth" => 25],
        "mrc_discount_products" => ["columnWidth" => 50],
        "mrc_discount_collections" => ["columnWidth" => 50],
        "mrc_discount_customer_targets" => ["columnWidth" => 50],
        "mrc_discount_starts" => ["columnWidth" => 50],
        "mrc_discount_ends" => ["columnWidth" => 50],
        "mrc_discount_notes" => ["columnWidth" => 100],
    ];

    foreach ($contexts as $fieldName => $settings) {
        $field = $fg->get($fieldName);
        if (!$field) continue;
        $context = $fg->getFieldContext($field);
        foreach ($settings as $key => $value) {
            $context->$key = $value;
        }
        $wire->fields->saveFieldgroupContext($context, $fg);
    }

    mercado_configure_page_reference_field(
        "mrc_discount_products",
        "mrc-product",
        "template=mrc-product, include=all, sort=title"
    );
    mercado_configure_page_reference_field(
        "mrc_discount_collections",
        "mrc-collection",
        "template=mrc-collection, include=all, sort=title"
    );
}

function mercado_configure_page_reference_field(string $fieldName, string $templateName, string $selector): void {
    $wire = function_exists("ProcessWire\\mercato_wire") ? \ProcessWire\mercato_wire() : \ProcessWire\wire();
    $field = $wire->fields->get($fieldName);
    $template = $wire->templates->get($templateName);
    if (!$field || !$template) return;

    $field->inputfield = "InputfieldAsmSelect";
    $field->labelFieldName = "title";
    $field->template_id = (int) $template->id;
    $field->findPagesSelector = $selector;
    $field->derefAsPage = 0;
    $wire->fields->save($field);
}

function mercado_ensure_demo_collections(): void {
    $wire = mercato_wire();
    $collectionTemplate = $wire->templates->get('mrc-collection');
    if (!$collectionTemplate) return;

    $parent = $wire->pages->get('/collections/');
    if (!$parent || !$parent->id) {
        $template = $wire->templates->get('mrc-collections')
            ?: $wire->templates->get('basic-page')
            ?: $wire->templates->get('home')
            ?: $collectionTemplate;

        $parent = new \ProcessWire\Page();
        $parent->template = $template;
        $parent->parent = $wire->pages->get('/');
        $parent->name = 'collections';
        $parent->of(false);
        $parent->title = 'Collections';
        $wire->pages->save($parent);
    }
    $collectionsTemplate = $wire->templates->get('mrc-collections');
    if ($collectionsTemplate && $parent && $parent->id && $parent->template->name !== 'mrc-collections') {
        $parent->of(false);
        $parent->template = $collectionsTemplate;
        $parent->title = 'Collections';
        $parent->removeStatus(\ProcessWire\Page::statusHidden);
        $wire->pages->save($parent);
    }

    $collections = [
        'tableware' => ['title' => 'Tableware', 'description' => 'Everyday handmade plates, mugs, and cups for the table.'],
        'serveware' => ['title' => 'Serveware', 'description' => 'Bowls, sets, and larger pieces for hosting and display.'],
        'gifts' => ['title' => 'Gifts', 'description' => 'Giftable ceramics and digital gift cards.'],
        'limited-stock' => ['title' => 'Limited Stock', 'description' => 'Small-batch ceramics and preorder studio runs.'],
    ];

    foreach ($collections as $name => $data) {
        $page = $wire->pages->get($parent->path . $name . '/');
        if (!$page || !$page->id) {
            $page = new \ProcessWire\Page();
            $page->template = $collectionTemplate;
            $page->parent = $parent;
            $page->name = $name;
        }
        $page->of(false);
        $page->title = $data['title'];
        if ($page->hasField('mrc_description')) {
            $page->mrc_description = $data['description'];
        }
        $wire->pages->save($page);
    }
}

function mercato_ensure_demo_products(): void {
    $wire = mercato_wire();
    $productTemplate = $wire->templates->get('mrc-product');
    if (!$productTemplate) return;
    $productsTemplate = $wire->templates->get('mrc-products');

    $parent = $wire->pages->get('/products/');
    if (!$parent || !$parent->id) {
        $template = $productsTemplate
            ?: $wire->templates->get('basic-page')
            ?: $wire->templates->get('home')
            ?: $productTemplate;

        $parent = new \ProcessWire\Page();
        $parent->template = $template;
        $parent->parent = $wire->pages->get('/');
        $parent->name = 'products';
        $parent->of(false);
        $parent->title = 'Products';
        $wire->pages->save($parent);
    } elseif ($productsTemplate && $parent->template->name !== 'mrc-products') {
        $parent->of(false);
        $parent->template = $productsTemplate;
        $parent->removeStatus(\ProcessWire\Page::statusHidden);
        $wire->pages->save($parent);
    }

    $products = [
        [
            'name' => 'stoneware-mug',
            'title' => 'Speckled Stoneware Mug',
            'price' => 28.00,
            'tax_rate' => 20,
            'shipping_price' => 4.95,
            'shipping_note' => 'Fragile item, packed in a double-wall box.',
            'sku' => 'CER-MUG-SPECKLE',
            'stock' => 18,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A wheel-thrown stoneware mug with a speckled off-white glaze and raw clay foot.',
            'collections' => ['tableware', 'gifts'],
            'image_file' => 'stoneware-mug.jpg',
            'image_color' => [92, 55, 32],
        ],
        [
            'name' => 'oatmeal-dinner-plate',
            'title' => 'Oatmeal Dinner Plate',
            'price' => 34.00,
            'tax_rate' => 20,
            'shipping_price' => 5.50,
            'shipping_note' => 'Packed flat with recycled paper padding.',
            'sku' => 'CER-PLATE-OAT',
            'stock' => 24,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A shallow handmade dinner plate with a satin oatmeal glaze and gently irregular rim.',
            'collections' => ['tableware'],
            'image_file' => 'dinner-plate.jpg',
            'image_color' => [28, 79, 142],
        ],
        [
            'name' => 'terracotta-serving-bowl',
            'title' => 'Terracotta Serving Bowl',
            'price' => 48.00,
            'tax_rate' => 20,
            'shipping_price' => 6.95,
            'shipping_note' => 'Tracked parcel shipping for fragile ceramics.',
            'sku' => 'CER-BOWL-TERRA',
            'stock' => 12,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A medium serving bowl with a warm terracotta exterior and satin white glazed interior.',
            'collections' => ['serveware'],
            'image_file' => 'serving-bowl.jpg',
            'image_color' => [209, 83, 59],
        ],
        [
            'name' => 'espresso-cup-set',
            'title' => 'Espresso Cup Set',
            'price' => 54.00,
            'tax_rate' => 20,
            'shipping_price' => 5.95,
            'shipping_note' => 'Four cups wrapped individually for shipping.',
            'sku' => 'CER-CUPS-ESPRESSO',
            'stock' => 8,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A set of four small hand-thrown espresso cups in mixed natural glazes.',
            'collections' => ['tableware', 'gifts', 'limited-stock'],
            'image_file' => 'espresso-cups.jpg',
            'image_color' => [33, 116, 104],
        ],
        [
            'name' => 'charcoal-bud-vase',
            'title' => 'Charcoal Bud Vase',
            'price' => 42.00,
            'tax_rate' => 20,
            'shipping_price' => 4.95,
            'shipping_note' => 'Fragile item, packed with extra padding.',
            'sku' => 'CER-VASE-CHARCOAL',
            'stock' => 3,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A narrow bud vase with a matte charcoal glaze and raw clay base. Low stock for inventory testing.',
            'collections' => ['serveware', 'limited-stock'],
            'image_file' => 'bud-vase.jpg',
            'image_color' => [95, 73, 176],
        ],
        [
            'name' => 'dinnerware-starter-set',
            'title' => 'Dinnerware Starter Set',
            'price' => 96.00,
            'tax_rate' => 20,
            'shipping_price' => 8.95,
            'shipping_note' => 'Tracked parcel shipping with fragile handling.',
            'sku' => 'CER-SET-STARTER',
            'stock' => 6,
            'stock_policy' => 'deny',
            'product_type' => 'physical',
            'description' => 'A coordinated plate, side plate, and bowl set for testing higher-value orders and fulfilment.',
            'collections' => ['tableware', 'serveware', 'limited-stock'],
            'image_file' => 'dinnerware-set.jpg',
            'image_color' => [176, 40, 92],
        ],
        [
            'name' => 'ceramics-gift-card',
            'title' => 'Ceramics Gift Card',
            'price' => 50.00,
            'tax_rate' => 0,
            'shipping_price' => 0.00,
            'shipping_note' => 'Digital delivery by email.',
            'sku' => 'CER-GIFT-50',
            'stock' => 100,
            'stock_policy' => 'preorder',
            'product_type' => 'digital',
            'description' => 'A digital gift card for testing zero-tax, zero-shipping, and payment-link orders.',
            'collections' => ['gifts'],
            'image_file' => 'gift-card.jpg',
            'image_color' => [131, 77, 48],
        ],
        [
            'name' => 'preorder-pendant-lamp',
            'title' => 'Preorder Ceramic Pendant Lamp',
            'price' => 128.00,
            'tax_rate' => 20,
            'shipping_price' => 9.95,
            'shipping_note' => 'Preorder studio run, dispatch estimate shown by the merchant.',
            'sku' => 'CER-LAMP-PRE',
            'stock' => 0,
            'stock_policy' => 'preorder',
            'product_type' => 'physical',
            'description' => 'A handmade ceramic pendant lamp shade for validating preorder checkout behavior.',
            'collections' => ['limited-stock'],
            'image_file' => 'pendant-lamp.jpg',
            'image_color' => [221, 172, 64],
        ],
    ];

    foreach ($products as $data) {
        $existing = $wire->pages->get($parent->path . $data['name'] . '/');
        if ($existing && $existing->id) {
            $existing->of(false);
            $existing->title = $data['title'];
            foreach ([
                'mrc_price' => $data['price'],
                'mrc_tax_rate' => $data['tax_rate'],
                'mrc_shipping_price' => $data['shipping_price'],
                'mrc_shipping_note' => $data['shipping_note'],
                'mrc_sku' => $data['sku'],
                'mrc_stock' => $data['stock'],
                'mrc_stock_policy' => $data['stock_policy'],
                'mrc_product_type' => $data['product_type'],
                'mrc_product_status' => 'active',
                'mrc_description' => $data['description'],
            ] as $fieldName => $value) {
                if ($existing->hasField($fieldName)) {
                    $existing->set($fieldName, $value);
                }
            }
            mercado_assign_demo_product_collections($existing, (array) ($data['collections'] ?? []));
            $wire->pages->save($existing);
            mercato_add_demo_product_image($existing, $data['title'], $data['image_color'], (string) ($data['image_file'] ?? ''));
            continue;
        }

        $p = new \ProcessWire\Page();
        $p->template = $productTemplate;
        $p->parent = $parent;
        $p->name = $data['name'];
        $p->of(false);
        $p->title = $data['title'];
        $p->mrc_price = $data['price'];
        $p->mrc_tax_rate = $data['tax_rate'];
        $p->mrc_shipping_price = $data['shipping_price'];
        $p->mrc_shipping_note = $data['shipping_note'];
        $p->mrc_sku = $data['sku'];
        $p->mrc_stock = $data['stock'];
        if ($p->hasField('mrc_stock_policy')) $p->mrc_stock_policy = $data['stock_policy'];
        if ($p->hasField('mrc_product_type')) $p->mrc_product_type = $data['product_type'];
        if ($p->hasField('mrc_product_status')) $p->mrc_product_status = 'active';
        $p->mrc_description = $data['description'];
        mercado_assign_demo_product_collections($p, (array) ($data['collections'] ?? []));
        $wire->pages->save($p);
        mercato_add_demo_product_image($p, $data['title'], $data['image_color'], (string) ($data['image_file'] ?? ''));
    }
}

function mercado_assign_demo_product_collections(\ProcessWire\Page $product, array $collectionNames): void {
    if (!$product->hasField('mrc_collections')) return;
    $wire = mercato_wire();
    $product->mrc_collections->removeAll();
    foreach ($collectionNames as $collectionName) {
        $collectionName = trim((string) $collectionName, '/');
        if ($collectionName === '') continue;
        $collection = $wire->pages->get('/collections/' . $collectionName . '/');
        if ($collection && $collection->id && !$product->mrc_collections->has($collection)) {
            $product->mrc_collections->add($collection);
        }
    }
}

function mercado_retire_legacy_demo_collections(): void {
    $wire = mercato_wire();
    foreach ([
        'demo-essentials',
        'studio-essentials',
        'desk-essentials',
        'prints',
        'prints-and-kits',
        'digital',
        'demo-digital',
        'digital-goods',
        'limited-editions',
    ] as $name) {
        $collection = $wire->pages->get('/collections/' . $name . '/');
        if (!$collection || !$collection->id || !$collection->template || $collection->template->name !== 'mrc-collection') {
            continue;
        }
        $collection->of(false);
        $collection->addStatus(\ProcessWire\Page::statusHidden);
        $collection->addStatus(\ProcessWire\Page::statusUnpublished);
        $wire->pages->save($collection);
    }
}

function mercado_retire_legacy_demo_products(): void {
    $wire = mercato_wire();
    foreach ([
        'demo-coffee',
        'demo-notebook',
        'demo-gift-card',
        'studio-coffee',
        'a5-maker-notebook',
        'risograph-studio-print',
        'workshop-starter-kit',
        'digital-planner-pack',
        'studio-gift-card',
        'limited-ceramic-mug',
        'preorder-desk-lamp',
    ] as $name) {
        $product = $wire->pages->get('/products/' . $name . '/');
        if (!$product || !$product->id || !$product->template || $product->template->name !== 'mrc-product') {
            continue;
        }
        $product->of(false);
        if ($product->hasField('mrc_product_status')) {
            $product->mrc_product_status = 'archived';
        }
        $product->addStatus(\ProcessWire\Page::statusHidden);
        $product->addStatus(\ProcessWire\Page::statusUnpublished);
        $wire->pages->save($product);
    }
}

function mercado_ensure_demo_discounts(): void {
    $wire = mercato_wire();
    $discountTemplate = $wire->templates->get('mrc-discount');
    if (!$discountTemplate) return;

    $parent = $wire->pages->get("/discounts/");
    if (!$parent || !$parent->id) {
        $template = $wire->templates->get("basic-page")
            ?: $wire->templates->get("home")
            ?: $discountTemplate;

        $parent = new \ProcessWire\Page();
        $parent->template = $template;
        $parent->parent = $wire->pages->get("/");
        $parent->name = "discounts";
        $parent->of(false);
        $parent->title = "Discounts";
        $parent->addStatus(\ProcessWire\Page::statusHidden);
        $wire->pages->save($parent);
    }

    $existing = $wire->pages->get($parent->path . "welcome10/");
    if ($existing && $existing->id) {
        $existing->of(false);
        if ($existing->hasField("mrc_discount_customer_limit") && (int) $existing->mrc_discount_customer_limit === 0) {
            $existing->mrc_discount_customer_limit = 1;
            $wire->pages->save($existing);
        }
    } else {
        $page = new \ProcessWire\Page();
        $page->template = $discountTemplate;
        $page->parent = $parent;
        $page->name = "welcome10";
        $page->of(false);
        $page->title = "Welcome 10%";
        $page->mrc_discount_code = "WELCOME10";
        $page->mrc_discount_active = 1;
        $page->mrc_discount_type = "percentage";
        $page->mrc_discount_percent = 10;
        $page->mrc_discount_amount = 0;
        $page->mrc_discount_usage_limit = 0;
        $page->mrc_discount_customer_limit = 1;
        if ($page->hasField("mrc_discount_minimum_order")) {
            $page->mrc_discount_minimum_order = 0;
        }
        $page->mrc_discount_notes = "Demo coupon for validating the discount checkout flow.";
        $wire->pages->save($page);
    }

    $mug = $wire->pages->get("/products/stoneware-mug/");
    $targeted = $wire->pages->get($parent->path . "mug5/");
    if (!$targeted || !$targeted->id) {
        $targeted = new \ProcessWire\Page();
        $targeted->template = $discountTemplate;
        $targeted->parent = $parent;
        $targeted->name = "mug5";
        $targeted->of(false);
        $targeted->mrc_discount_code = "MUG5";
        $targeted->mrc_discount_active = 1;
        $targeted->mrc_discount_type = "fixed";
        $targeted->mrc_discount_usage_limit = 0;
        $targeted->mrc_discount_customer_limit = 0;
        if ($targeted->hasField("mrc_discount_minimum_order")) {
            $targeted->mrc_discount_minimum_order = 0;
        }
    } else {
        $targeted->of(false);
    }
    $targeted->title = "Mug £5 Off";
    $targeted->mrc_discount_amount = 5;
    $targeted->mrc_discount_percent = 0;
    $targeted->mrc_discount_notes = "Demo product-targeted coupon for Speckled Stoneware Mug.";
    if ($mug && $mug->id && $targeted->hasField("mrc_discount_products")) {
        $targeted->mrc_discount_products->removeAll();
        $targeted->mrc_discount_products->add($mug);
    }
    $wire->pages->save($targeted);

    $tableware = $wire->pages->get("/collections/tableware/");
    $collectionTargeted = $wire->pages->get($parent->path . "tableware10/");
    if (!$collectionTargeted || !$collectionTargeted->id) {
        $collectionTargeted = new \ProcessWire\Page();
        $collectionTargeted->template = $discountTemplate;
        $collectionTargeted->parent = $parent;
        $collectionTargeted->name = "tableware10";
        $collectionTargeted->of(false);
        $collectionTargeted->mrc_discount_code = "TABLEWARE10";
        $collectionTargeted->mrc_discount_active = 1;
        $collectionTargeted->mrc_discount_type = "percentage";
        $collectionTargeted->mrc_discount_usage_limit = 0;
        $collectionTargeted->mrc_discount_customer_limit = 0;
        if ($collectionTargeted->hasField("mrc_discount_minimum_order")) {
            $collectionTargeted->mrc_discount_minimum_order = 0;
        }
    } else {
        $collectionTargeted->of(false);
    }
    $collectionTargeted->title = "Tableware 10%";
    $collectionTargeted->mrc_discount_amount = 0;
    $collectionTargeted->mrc_discount_percent = 10;
    $collectionTargeted->mrc_discount_notes = "Demo collection-targeted coupon for Tableware.";
    if ($tableware && $tableware->id && $collectionTargeted->hasField("mrc_discount_collections")) {
        $collectionTargeted->mrc_discount_collections->removeAll();
        $collectionTargeted->mrc_discount_collections->add($tableware);
    }
    $wire->pages->save($collectionTargeted);

    foreach (['coffee5', 'essentials10'] as $legacyDiscountName) {
        $legacyDiscount = $wire->pages->get($parent->path . $legacyDiscountName . '/');
        if (!$legacyDiscount || !$legacyDiscount->id || !$legacyDiscount->hasField("mrc_discount_active")) {
            continue;
        }
        $legacyDiscount->of(false);
        $legacyDiscount->mrc_discount_active = 0;
        $legacyDiscount->addStatus(\ProcessWire\Page::statusHidden);
        $wire->pages->save($legacyDiscount);
    }
}

function mercato_add_demo_product_image(\ProcessWire\Page $page, string $title, array $rgb, string $assetFile = ''): void {
    if (!$page->template->hasField('mrc_images')) return;
    if (count($page->mrc_images)) return;

    $assetFile = basename($assetFile);
    $assetPath = $assetFile !== '' ? dirname(__DIR__) . '/assets/demo-products/' . $assetFile : '';
    if ($assetPath !== '' && is_file($assetPath)) {
        try {
            $page->of(false);
            $page->mrc_images->add($assetPath);
            mercato_wire()->pages->save($page);
            return;
        } catch (\Throwable $e) {
            // Fall through to the generated placeholder below.
        }
    }

    if (!extension_loaded('gd')) return;

    $safeName = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title));
    $path = sys_get_temp_dir() . '/mercato-' . trim($safeName, '-') . '.png';
    $image = imagecreatetruecolor(1200, 800);
    $bg = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    $fg = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, 1200, 800, $bg);
    imagestring($image, 5, 80, 80, $title, $fg);
    imagestring($image, 3, 80, 120, 'Mercato demo product', $fg);
    imagepng($image, $path);

    try {
        $page->of(false);
        $page->mrc_images->add($path);
        mercato_wire()->pages->save($page);
    } catch (\Throwable $e) {
        // Demo images are convenience assets; installation should not fail
        // when image processing is unavailable in a local environment.
    } finally {
        if (is_file($path)) @unlink($path);
    }
}

function mercato_copy_template_files(Mercato $module, bool $overwrite = false): array {
    $wire = mercato_wire();
    $srcDir = dirname(__DIR__) . '/templates/';
    $dstDir = $wire->config->paths->templates;
    $result = ['copied' => [], 'skipped' => []];

    foreach (['home.php', 'mrc-storefront.php', 'mrc-home.php', 'mrc-order.php', 'mrc-orders.php', 'mrc-products.php', 'mrc-product.php', 'mrc-collections.php', 'mrc-collection.php', 'mrc-page.php', 'mrc-checkout.php', 'mrc-success.php'] as $f) {
        $src = $srcDir . $f;
        $dst = $dstDir . $f;
        if (!file_exists($src)) {
            throw new \ProcessWire\WireException("Mercato install: source template file missing: $src");
        }
        if (file_exists($dst) && !$overwrite) {
            $result['skipped'][] = $f;
            continue;
        }
        if (!copy($src, $dst)) {
            throw new \ProcessWire\WireException("Mercato install: could not copy template file to $dst — check directory permissions.");
        }
        $result['copied'][] = $f;
    }

    return $result;
}

// -----------------------------------------------------------------------
// Uninstall
// -----------------------------------------------------------------------
function mercato_uninstall(Mercato $module): void {
    // Commerce records are business/accounting data. A normal ProcessWire
    // module uninstall must not delete orders, products, fields, templates, or
    // copied storefront template files. Future destructive cleanup tools should
    // be explicit, separately confirmed, and workflow-specific.
    unset($module);
}
