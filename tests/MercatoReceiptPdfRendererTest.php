<?php
require_once __DIR__ . '/../src/Pdf/MercatoReceiptPdfRenderer.php';

use ProcessWire\MercatoReceiptPdfRenderer;

$document = [
    'invoice' => 'MRC-1001',
    'date' => 'August 27, 2026',
    'date_short' => 'Aug 27, 2026',
    'payment_status' => 'Paid',
    'total' => '$ 600.00',
    'customer_name' => 'Example Customer',
    'customer_email' => 'customer@example.test',
    'fulfilment_label' => 'Digital access',
    'billing_address' => ['Example Customer', '100 Test Street'],
    'shipping_address' => ['PU-TEST'],
    'items' => [[
        'title' => 'Magnetic Particle Testing (MT) - Level I',
        'sku' => 'NDT-MT-L1',
        'quantity' => 1,
        'amount' => '$ 600.00',
    ]],
    'summary' => [
        ['label' => 'Subtotal', 'value' => '$ 600.00'],
        ['label' => 'Total paid', 'value' => '$ 600.00', 'total' => true],
    ],
];
$theme = ['brand_name' => 'Test Store', 'primary' => '#173A37', 'accent' => '#2E898E'];
$pdf = MercatoReceiptPdfRenderer::render($document, $theme);
if (!str_starts_with($pdf, '%PDF-1.4') || !str_contains($pdf, '/BaseFont /Helvetica-Bold')) {
    throw new RuntimeException('Structured receipt PDF was not generated.');
}
if (!str_contains($pdf, '(Payment receipt)') || !str_contains($pdf, '(Magnetic Particle Testing')) {
    throw new RuntimeException('Receipt hierarchy or item content is missing.');
}
if (substr_count($pdf, '/Type /Page ') !== 1) {
    throw new RuntimeException('Single-item receipt should fit on one page.');
}

$many = $document;
$many['items'] = array_fill(0, 18, $document['items'][0]);
$multipage = MercatoReceiptPdfRenderer::render($many, $theme);
if (substr_count($multipage, '/Type /Page ') !== 3 || !str_contains($multipage, '(Courses - continued)')) {
    throw new RuntimeException('Long receipts must paginate with continuation pages.');
}

echo "Mercato receipt PDF renderer tests passed.\n";
