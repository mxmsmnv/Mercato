<?php
$root = dirname(__DIR__);
$templates = glob($root . '/templates/*.php') ?: [];
if ($templates === []) throw new RuntimeException('No storefront templates were found.');

$buttons = 0;
$forms = 0;
foreach ($templates as $template) {
    $source = (string) file_get_contents($template);
    $relative = substr($template, strlen($root) + 1);
    $markup = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/', 'PHP', $source) ?? $source;

    if (preg_match('/tabindex\s*=\s*["\']\s*[1-9]/i', $markup)) {
        throw new RuntimeException("$relative contains a positive tabindex that overrides natural keyboard order.");
    }
    if (preg_match('/<(?:div|span|li)[^>]+(?:onclick\s*=|role\s*=\s*["\']button["\'])/i', $markup)) {
        throw new RuntimeException("$relative uses a non-semantic clickable element instead of a native button/link.");
    }
    if (preg_match('/<a\b[^>]*href\s*=\s*["\']#["\']/i', $markup)) {
        throw new RuntimeException("$relative contains a keyboard-hostile placeholder link.");
    }

    preg_match_all('/<button\b[^>]*>/i', $markup, $buttonMatches);
    foreach ($buttonMatches[0] as $button) {
        $buttons++;
        if (!preg_match('/\btype\s*=\s*["\'](?:button|submit|reset)["\']/i', $button)) {
            throw new RuntimeException("$relative contains a button without an explicit semantic type: $button");
        }
        if (preg_match('/>\s*<\/button>$/i', $button) && !preg_match('/\baria-label\s*=/i', $button)) {
            throw new RuntimeException("$relative contains an empty icon button without an accessible name.");
        }
    }

    preg_match_all('/<form\b[^>]*>/i', $markup, $formMatches);
    $forms += count($formMatches[0]);
    foreach ($formMatches[0] as $form) {
        if (!preg_match('/\bmethod\s*=\s*["\'](?:get|post)["\']/i', $form)) {
            throw new RuntimeException("$relative contains a form without an explicit HTTP method.");
        }
    }
}

if ($buttons < 20 || $forms < 10) throw new RuntimeException('Storefront markup inventory unexpectedly shrank; review the contract thresholds.');
$products = (string) file_get_contents($root . '/templates/mrc-products.php');
$product = (string) file_get_contents($root . '/templates/mrc-product.php');
$checkout = (string) file_get_contents($root . '/templates/mrc-checkout.php');
$storefront = (string) file_get_contents($root . '/templates/mrc-storefront.php');
$adminHelpers = (string) file_get_contents($root . '/src/Admin/ProcessMercatoAdminHelpers.php');
$adminProducts = (string) file_get_contents($root . '/src/Admin/ProcessMercatoProductPanels.php');
$expectations = [
    [str_contains($products, 'id="mrc-catalog-results"') && str_contains($products, 'aria-busy="false"'), 'Catalog results do not expose a deterministic loading state.'],
    [str_contains($products, 'data-mrc-loading-status') && str_contains($products, "status.textContent = 'Loading products…'"), 'Catalog filter navigation lacks an announced loading state.'],
    [str_contains($products, '<p role="status">No products are available yet.</p>'), 'Catalog empty state is not announced.'],
    [substr_count($products, 'overflow-wrap: anywhere') >= 1 && substr_count($product, 'overflow-wrap: anywhere') >= 1, 'Long localized product content lacks an explicit wrapping contract.'],
    [!str_contains($checkout, '<p class="mrc-checkout-actions">'), 'Checkout actions use a paragraph container that can produce invalid nested paragraphs.'],
    [str_contains($storefront, "header('Cache-Control: private, no-store, max-age=0, must-revalidate')") && str_contains($storefront, "header('Vary: Cookie', false)"), 'Session-specific storefront markup is not protected from browser/history caches.'],
    [str_contains($adminHelpers, '<tr class="mrc-skeleton-row" aria-hidden="true">'), 'Decorative admin skeleton rows are exposed as empty table content.'],
    [substr_count($adminProducts, 'mrc-admin-table-wrap" tabindex="0"') >= 3, 'Scrollable product administration tables are not keyboard focusable.'],
];
foreach ($expectations as [$condition, $message]) if (!$condition) throw new RuntimeException($message);
$privateTemplates = ['mrc-products.php', 'mrc-product.php', 'mrc-collections.php', 'mrc-collection.php', 'mrc-page.php', 'mrc-checkout.php', 'mrc-success.php', 'mrc-account.php', 'mrc-my-quotes.php'];
foreach ($privateTemplates as $privateTemplate) {
    $source = (string) file_get_contents($root . '/templates/' . $privateTemplate);
    if (!str_contains($source, 'mrc_storefront_private_headers();')) throw new RuntimeException("$privateTemplate can replay stale session-specific markup from browser cache.");
}
echo "Mercato storefront markup contract tests passed: " . count($templates) . " templates, $buttons buttons, $forms forms.\n";
