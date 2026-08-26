<?php
require_once __DIR__ . '/../src/Notification/MercatoEmailEventCatalog.php';
require_once __DIR__ . '/../src/Notification/MercatoEmailTemplateRenderer.php';

use ProcessWire\MercatoEmailEventCatalog;
use ProcessWire\MercatoEmailTemplateRenderer;

if (MercatoEmailTemplateRenderer::normalizeLocale('PT-BR') !== 'pt_br' || MercatoEmailTemplateRenderer::normalizeLocale('../../secret') !== 'en') throw new RuntimeException('Locale normalization failed.');
foreach (MercatoEmailEventCatalog::EVENTS as $event) {
    $definition = MercatoEmailEventCatalog::get($event);
    $rendered = MercatoEmailTemplateRenderer::render($definition['subject'], $definition['text'], '<p>{customer}</p><a href="{order_status_link}" onclick="steal()">Status</a><script>steal()</script>', ['customer' => '<img src=x onerror=steal()>', 'order_status_link' => 'https://example.test/order?a=1&token=signed']);
    if ($rendered['text'] === '' || $rendered['html'] === '' || str_contains(strtolower($rendered['html']), '<script') || str_contains(strtolower($rendered['html']), 'onclick=')) throw new RuntimeException('Unsafe or empty rendering for ' . $event);
    if (!str_contains($rendered['html'], 'token=signed') || !str_contains($rendered['html'], '&amp;')) throw new RuntimeException('Signed link was not preserved and escaped for ' . $event);
    if (!isset(MercatoEmailEventCatalog::metadata()[$event])) throw new RuntimeException('Notification metadata is missing for ' . $event);
    if (!in_array('store_name', MercatoEmailEventCatalog::variables($event), true)) throw new RuntimeException('Shared store variable is missing for ' . $event);
}
foreach (['subtotal', 'fulfilment_details', 'discount', 'receipt_link', 'order_status_link'] as $variable) {
    if (!in_array($variable, MercatoEmailEventCatalog::variables('order_confirmation'), true)) throw new RuntimeException('Order confirmation variable is missing: ' . $variable);
}
$layout = MercatoEmailTemplateRenderer::render(
    'Update from {store_name}',
    'Hello {customer}',
    '<p>Hello <strong>{customer}</strong></p>',
    ['store_name' => 'Example & Co', 'customer' => 'Alex <Customer>'],
    '<table><tr><td><img src="https://example.test/logo.png" alt="{store_name}"></td></tr></table>',
    '<p><a href="vbscript:unsafe">Contact {store_name}</a></p>'
);
if (!str_contains($layout['html'], '<table>') || !str_contains($layout['html'], 'Example &amp; Co')) throw new RuntimeException('Shared email layout was not rendered.');
if (str_contains(strtolower($layout['html']), 'vbscript:')) throw new RuntimeException('Unsafe shared-layout URL survived sanitization.');

$designerSource = (string) file_get_contents(__DIR__ . '/../src/Admin/ProcessMercatoNotificationTemplates.php');
$designerScript = (string) file_get_contents(__DIR__ . '/../assets/admin-notifications.js');
foreach (['InputfieldTinyMCE', 'saveNotificationTemplate', 'data-mrc-preview-frame', 'notification_header_html', 'Plain-text fallback'] as $needle) {
    if (!str_contains($designerSource, $needle)) throw new RuntimeException('Notification designer is missing ' . $needle . '.');
}
foreach (['data-mrc-variable', 'tinymce.triggerSave', 'data-mrc-layout-preview', 'replaceVariables'] as $needle) {
    if (!str_contains($designerScript, $needle)) throw new RuntimeException('Notification preview behavior is missing ' . $needle . '.');
}
echo "Mercato email template renderer tests passed.\n";
