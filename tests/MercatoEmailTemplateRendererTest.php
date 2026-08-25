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
}
echo "Mercato email template renderer tests passed.\n";
