<?php
namespace ProcessWire;
$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato SEO integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'mercato.test'; $_SERVER['SERVER_NAME'] = 'mercato.test'; $_SERVER['REQUEST_URI'] = '/products/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php'; $config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config); $wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser')); /** @var Mercato $commerce */ $commerce = $wire->modules->get('Mercato');
$product = $wire->pages->get('template=mrc-product, mrc_product_status=active, include=all'); $checkout = $wire->pages->get('template=mrc-checkout, include=all');
if (!$product->id || !$checkout->id) throw new \RuntimeException('SEO integration fixtures are missing.');
if (!$commerce->usesBuiltInSeo()) {
    if ($commerce->seoOwner() !== 'ichiban' || $commerce->seoService()->render($product) !== '' || $commerce->seoService()->sitemapXml() !== '' || $commerce->seoService()->diagnostics() !== []) throw new \RuntimeException('Ichiban SEO ownership did not suppress Mercato publication.');
    echo "Mercato SEO integration tests passed with Ichiban ownership.\n";
    exit(0);
}
$meta = $commerce->seoService()->metadata($product); $productSchema = null; foreach ($meta['json_ld'] as $entry) if (($entry['@type'] ?? '') === 'Product') $productSchema = $entry;
if (!$productSchema || ($productSchema['url'] ?? '') !== $meta['canonical'] || ($productSchema['offers']['priceCurrency'] ?? '') !== (string) $commerce->currency) throw new \RuntimeException('Product structured data is missing authoritative URL/currency.');
$definition = $commerce->variantService()->getDefinition($product); $activeVariants = array_values(array_filter($definition['variants'], static fn(array $variant): bool => ($variant['status'] ?? '') === 'active'));
if (count($activeVariants) > 1) { $prices = array_column($activeVariants, 'price'); if (($productSchema['offers']['@type'] ?? '') !== 'AggregateOffer' || (float) $productSchema['offers']['lowPrice'] !== (float) min($prices) || (float) $productSchema['offers']['highPrice'] !== (float) max($prices)) throw new \RuntimeException('AggregateOffer does not match variant prices.'); }
else { if (($productSchema['offers']['@type'] ?? '') !== 'Offer' || (float) $productSchema['offers']['price'] !== (float) ($activeVariants[0]['price'] ?? $product->mrc_price)) throw new \RuntimeException('Offer does not match displayed product price.'); }
$private = $commerce->seoService()->metadata($checkout); if (!str_starts_with($private['robots'], 'noindex') || $private['sitemap']) throw new \RuntimeException('Private checkout was indexable.');
$pageTwo = $commerce->seoService()->metadata($wire->pages->get('/products/'), ['page_num' => 2]); if (!str_ends_with($pageTwo['canonical'], '/page2/')) throw new \RuntimeException('Pagination canonical integration failed.');
$rendered = $commerce->seoService()->render($product);
if ($product->hasField('mrc_seo_title')) { $product->mrc_seo_title = '</title><script>alert(1)</script>Safe'; $rendered = $commerce->seoService()->render($product); if (str_contains(strtolower($rendered), '<script>alert(1)')) throw new \RuntimeException('SEO metadata allowed markup injection.'); }
$originalStatus = (string) $product->mrc_product_status; $product->mrc_product_status = 'discontinued'; $retired = $commerce->seoService()->metadata($product); $product->mrc_product_status = $originalStatus; if (!str_starts_with($retired['robots'], 'noindex') || $retired['sitemap']) throw new \RuntimeException('Unavailable product indexing behavior failed.');
$redirected = $commerce->seoService()->metadata($product, ['redirect_url' => 'https://mercato.test/products/replacement/?source=old']); if ($redirected['canonical'] !== 'https://mercato.test/products/replacement/' || !str_starts_with($redirected['robots'], 'noindex')) throw new \RuntimeException('Redirected product behavior failed.');
$xml = $commerce->seoService()->sitemapXml();
if ($commerce->seoOwner() !== 'mercato' || $rendered === '' || !str_contains($xml, '<urlset') || str_contains($xml, '/checkout/') || str_contains($xml, 'token=')) throw new \RuntimeException('Mercato fallback SEO ownership failed.');
echo "Mercato SEO integration tests passed for product {$product->id}.\n";
