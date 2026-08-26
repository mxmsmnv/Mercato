<?php
require_once __DIR__ . '/../src/Seo/MercatoSeoRules.php';
require_once __DIR__ . '/../src/Seo/MercatoSeoOwnership.php';
use ProcessWire\MercatoSeoRules;
use ProcessWire\MercatoSeoOwnership;

if (MercatoSeoRules::normalizeUrl('HTTPS://Shop.Example/products/?utm_source=x#top') !== 'https://shop.example/products/') throw new RuntimeException('Canonical normalization failed.');
if (MercatoSeoRules::normalizeUrl('https://shop.example/products/', 3) !== 'https://shop.example/products/page3/') throw new RuntimeException('Pagination canonical failed.');
if (MercatoSeoRules::normalizeUrl('javascript:alert(1)') !== '') throw new RuntimeException('Unsafe canonical URL was accepted.');
if (!MercatoSeoRules::isPrivatePath('/checkout/') || !MercatoSeoRules::isPrivatePath('/products/mug/', ['token' => 'signed']) || MercatoSeoRules::isPrivatePath('/products/mug/')) throw new RuntimeException('Private-page exclusion rules failed.');
if (MercatoSeoRules::normalizeRobots('INDEX,follow,invalid') !== 'index,follow' || !str_starts_with(MercatoSeoRules::normalizeRobots('index,follow', true), 'noindex')) throw new RuntimeException('Robots normalization failed.');
$safe = MercatoSeoRules::safeText('<script>alert(1)</script><b>Safe title</b>', 20);
if (str_contains($safe, '<') || !str_contains($safe, 'Safe title')) throw new RuntimeException('Metadata escaping fallback failed.');
if (MercatoSeoOwnership::resolve(true) !== MercatoSeoOwnership::ICHIBAN) throw new RuntimeException('Ichiban did not take SEO ownership when installed.');
if (MercatoSeoOwnership::resolve(false) !== MercatoSeoOwnership::MERCATO) throw new RuntimeException('Mercato did not retain fallback SEO ownership without Ichiban.');
echo "Mercato SEO rules tests passed.\n";
