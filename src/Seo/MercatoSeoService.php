<?php
namespace ProcessWire;

final class MercatoSeoService extends Wire {
    public function __construct(private Mercato $commerce) { parent::__construct(); }

    public function metadata(Page $page, array $context = []): array {
        $pageNum = max(1, (int) ($context['page_num'] ?? $this->wire('input')->pageNum ?? 1));
        $canonical = MercatoSeoRules::normalizeUrl((string) ($context['canonical'] ?? $page->httpUrl), $pageNum);
        $query = (array) ($context['query'] ?? $_GET);
        $private = !empty($context['private']) || $this->isPrivatePage($page, $query);
        $redirect = MercatoSeoRules::normalizeUrl((string) ($context['redirect_url'] ?? ''), 1);
        if ($redirect !== '') { $canonical = $redirect; $private = true; }
        $productStatus = $page->template->name === 'mrc-product' && $page->hasField('mrc_product_status') ? strtolower(trim((string) $page->mrc_product_status)) : '';
        if ($productStatus !== '' && $productStatus !== 'active') $private = true;
        if ($page->isUnpublished() || $page->isHidden()) $private = true;

        $siteName = MercatoSeoRules::safeText((string) ($this->commerce->seo_site_name ?: $this->commerce->notification_sender_name ?: $this->wire('config')->httpHost), 80);
        $rawTitle = $page->hasField('mrc_seo_title') && trim((string) $page->mrc_seo_title) !== '' ? (string) $page->mrc_seo_title : (string) $page->title;
        $title = MercatoSeoRules::safeText($rawTitle, 65);
        if ($pageNum > 1) $title .= ' – Page ' . $pageNum;
        if ($siteName !== '' && !str_contains(strtolower($title), strtolower($siteName))) $title .= ' | ' . $siteName;
        $descriptionSource = $page->hasField('mrc_seo_description') && trim((string) $page->mrc_seo_description) !== '' ? (string) $page->mrc_seo_description : $this->fallbackDescription($page);
        $description = MercatoSeoRules::safeText($descriptionSource ?: (string) $this->commerce->seo_default_description, 160);
        $configuredRobots = $page->hasField('mrc_seo_robots') && trim((string) $page->mrc_seo_robots) !== '' ? (string) $page->mrc_seo_robots : (string) ($this->commerce->seo_default_robots ?: 'index,follow,max-image-preview:large');
        $image = $this->imageUrl($page, (string) ($context['image'] ?? $this->commerce->seo_social_image_url));
        $type = $page->template->name === 'mrc-product' ? 'product' : 'website';
        $metadata = ['title' => $title, 'description' => $description, 'canonical' => $canonical, 'robots' => MercatoSeoRules::normalizeRobots($configuredRobots, $private), 'private' => $private, 'sitemap' => !$private && $this->isSitemapEligible($page), 'open_graph' => ['og:type' => $type, 'og:title' => $title, 'og:description' => $description, 'og:url' => $canonical, 'og:site_name' => $siteName, 'og:image' => $image], 'twitter' => ['twitter:card' => $image !== '' ? 'summary_large_image' : 'summary', 'twitter:title' => $title, 'twitter:description' => $description, 'twitter:image' => $image], 'alternates' => $this->commerce->storefrontSeoAlternates([], $page), 'json_ld' => $this->structuredData($page, $canonical, $description, $image, $private)];
        return $this->commerce->storefrontSeoMetadata($metadata, $page, $context);
    }

    public function render(Page $page, array $context = []): string {
        if (!$this->commerce->usesBuiltInSeo()) return '';
        $meta = $this->metadata($page, $context); $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<title>' . $h((string) $meta['title']) . '</title>' . "\n";
        if ((string) $meta['description'] !== '') $out .= '<meta name="description" content="' . $h((string) $meta['description']) . '">' . "\n";
        $out .= '<link rel="canonical" href="' . $h((string) $meta['canonical']) . '">' . "\n";
        $out .= '<meta name="robots" content="' . $h((string) $meta['robots']) . '">' . "\n";
        foreach (['open_graph' => 'property', 'twitter' => 'name'] as $group => $attribute) foreach ((array) $meta[$group] as $name => $value) if ((string) $value !== '') $out .= '<meta ' . $attribute . '="' . $h((string) $name) . '" content="' . $h((string) $value) . '">' . "\n";
        foreach ((array) $meta['alternates'] as $locale => $url) { $url = MercatoSeoRules::normalizeUrl((string) $url); if ($url !== '') $out .= '<link rel="alternate" hreflang="' . $h((string) $locale) . '" href="' . $h($url) . '">' . "\n"; }
        foreach ((array) $meta['json_ld'] as $jsonLd) $out .= '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>' . "\n";
        return $out;
    }

    public function isSitemapEligible(Page $page): bool {
        if (!$page->id || $page->isUnpublished() || $page->isHidden() || $this->isPrivatePage($page, [])) return false;
        if (!in_array($page->template->name, ['home', 'mrc-home', 'mrc-products', 'mrc-product', 'mrc-collections', 'mrc-collection', 'mrc-page'], true)) return false;
        return $page->template->name !== 'mrc-product' || !$page->hasField('mrc_product_status') || in_array(strtolower((string) $page->mrc_product_status), ['', 'active'], true);
    }

    public function sitemapXml(): string {
        if (!$this->commerce->usesBuiltInSeo()) return '';
        $selector = 'template=home|mrc-home|mrc-products|mrc-product|mrc-collections|mrc-collection|mrc-page, include=all, sort=path';
        $urls = []; foreach ($this->wire('pages')->find($selector) as $page) if ($this->isSitemapEligible($page)) $urls[] = ['loc' => MercatoSeoRules::normalizeUrl((string) $page->httpUrl), 'lastmod' => date('c', (int) $page->modified)];
        $urls = $this->commerce->storefrontSitemapEntries($urls);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $entry) { $loc = MercatoSeoRules::normalizeUrl((string) ($entry['loc'] ?? '')); if ($loc === '') continue; $xml .= '<url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'; if (!empty($entry['lastmod'])) $xml .= '<lastmod>' . htmlspecialchars((string) $entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>'; $xml .= '</url>'; }
        return $xml . '</urlset>';
    }

    public function diagnostics(int $limit = 500): array {
        if (!$this->commerce->usesBuiltInSeo()) return [];
        $rows = []; foreach ($this->wire('pages')->find('template=mrc-products|mrc-product|mrc-collections|mrc-collection|mrc-page, include=all, limit=' . max(1, $limit)) as $page) { $meta = $this->metadata($page); $issues = []; if (mb_strlen((string) $meta['title']) > 65) $issues[] = 'title_too_long'; if ((string) $meta['description'] === '') $issues[] = 'missing_description'; if ($page->template->name === 'mrc-product' && $this->imageUrl($page, '') === '') $issues[] = 'missing_product_image'; if ((string) $meta['canonical'] === '') $issues[] = 'invalid_canonical'; $rows[] = ['page_id' => (int) $page->id, 'title' => (string) $page->title, 'url' => (string) $page->url, 'robots' => (string) $meta['robots'], 'sitemap' => (bool) $meta['sitemap'], 'issues' => $issues]; }
        return $rows;
    }

    private function structuredData(Page $page, string $canonical, string $description, string $image, bool $private): array {
        if ($private) return [];
        $data = [$this->breadcrumbs($page, $canonical)];
        if ($page->template->name === 'mrc-product') $data[] = $this->productSchema($page, $canonical, $description, $image);
        if ((string) $page->path === '/' || in_array($page->template->name, ['mrc-home', 'mrc-products'], true)) {
            $root = MercatoSeoRules::normalizeUrl((string) $this->wire('pages')->get(1)->httpUrl);
            $data[] = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => (string) ($this->commerce->seo_site_name ?: $this->commerce->notification_sender_name), 'url' => $root, 'logo' => (string) $this->commerce->seo_organization_logo_url];
            $data[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => (string) ($this->commerce->seo_site_name ?: $this->commerce->notification_sender_name), 'url' => $root, 'potentialAction' => ['@type' => 'SearchAction', 'target' => rtrim($root, '/') . '/api/mercato/read?resource=products&q={search_term_string}', 'query-input' => 'required name=search_term_string']];
        }
        return array_values(array_filter($data));
    }

    private function productSchema(Page $product, string $canonical, string $description, string $image): array {
        $variants = array_values(array_filter($this->commerce->variantService()->getDefinition($product)['variants'], static fn(array $variant): bool => ($variant['status'] ?? '') === 'active'));
        $prices = $variants ? array_map(static fn(array $variant): float => (float) $variant['price'], $variants) : [(float) $product->mrc_price];
        $currency = (string) $this->commerce->currency; $variantOffers = [];
        foreach ($variants as $variant) { $purchase = $this->commerce->getProductPurchasability($product, 1, 0, 0, (string) $variant['id']); $variantOffers[] = ['@type' => 'Offer', 'sku' => (string) ($variant['sku'] ?? ''), 'price' => (float) $variant['price'], 'priceCurrency' => $currency, 'availability' => !empty($purchase['ok']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $canonical]; }
        $purchase = $variants ? null : $this->commerce->getProductPurchasability($product, 1);
        $availability = $variants ? (count(array_filter($variantOffers, static fn(array $entry): bool => $entry['availability'] === 'https://schema.org/InStock')) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock') : (!empty($purchase['ok']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock');
        $offer = count($prices) > 1 ? ['@type' => 'AggregateOffer', 'lowPrice' => min($prices), 'highPrice' => max($prices), 'offerCount' => count($prices), 'priceCurrency' => $currency, 'offers' => $variantOffers, 'url' => $canonical] : ['@type' => 'Offer', 'price' => $prices[0], 'priceCurrency' => $currency, 'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $canonical];
        return array_filter(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => (string) $product->title, 'description' => $description, 'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '', 'image' => $image !== '' ? [$image] : [], 'url' => $canonical, 'offers' => $offer], static fn(mixed $value): bool => $value !== '' && $value !== []);
    }

    private function breadcrumbs(Page $page, string $canonical): array {
        $items = []; $position = 1; foreach ($page->parents()->append($page) as $ancestor) { if (!$ancestor->id || $ancestor->isHidden()) continue; $items[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => (string) ($ancestor->title ?: 'Home'), 'item' => $ancestor->id === $page->id ? $canonical : MercatoSeoRules::normalizeUrl((string) $ancestor->httpUrl)]; }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    private function fallbackDescription(Page $page): string {
        foreach (['mrc_description', 'body', 'summary'] as $field) if ($page->hasField($field) && trim((string) $page->get($field)) !== '') return (string) $page->get($field);
        return (string) $page->title;
    }

    private function imageUrl(Page $page, string $fallback): string {
        $url = ''; if ($page->hasField('mrc_images') && count($page->mrc_images)) $url = (string) $page->mrc_images->first()->httpUrl;
        if ($url === '') $url = $fallback;
        if (str_starts_with($url, '/')) $url = rtrim((string) $this->wire('pages')->get(1)->httpUrl, '/') . $url;
        return MercatoSeoRules::normalizeUrl($url);
    }

    private function isPrivatePage(Page $page, array $query): bool {
        return in_array($page->template->name, ['mrc-checkout', 'mrc-success', 'mrc-order', 'mrc-orders', 'mrc-quote', 'mrc-quotes', 'mrc-my-quotes'], true) || MercatoSeoRules::isPrivatePath((string) $page->path, $query);
    }
}
