<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Catalog/MercatoCatalogCsvParser.php';
require_once dirname(__DIR__) . '/src/Catalog/MercatoCatalogSourceReference.php';
require_once dirname(__DIR__) . '/src/Catalog/MercatoCatalogImageIngestor.php';

use ProcessWire\MercatoCatalogCsvParser;
use ProcessWire\MercatoCatalogImageIngestor;
use ProcessWire\MercatoCatalogSourceReference;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$parser = new MercatoCatalogCsvParser();
$parsed = $parser->parse("\xEF\xBB\xBFtitle,sku,description\nDesk,D-1,\"Line one\nLine two\"\n");
$assert($parsed['errors'] === [], 'Multiline CSV should parse without errors.');
$assert(($parsed['rows'][0]['description'] ?? '') === "Line one\nLine two", 'Quoted multiline field was not preserved.');
$jsonCsv = "title,variant_options_json\nChair,\"[{\"\"id\"\":\"\"finish\"\"}]\"\n";
$jsonParsed = $parser->parse($jsonCsv);
$assert(($jsonParsed['rows'][0]['variant_options_json'] ?? '') === '[{"id":"finish"}]', 'Quoted JSON cell was not preserved.');
$assert($parser->parse("title,title\nA,B\n")['errors'] !== [], 'Duplicate headers must be rejected.');
$assert($parser->parse("title,sku\nA\n")['errors'] !== [], 'Column mismatch must be rejected.');

$reference = new MercatoCatalogSourceReference();
$assert($reference->key(' Supplier API ', 'SKU/123') === $reference->key('supplier-api', 'SKU/123'), 'Source normalization must be stable.');
$assert($reference->payloadHash(['b' => 2, 'a' => 1]) === $reference->payloadHash(['a' => 1, 'b' => 2]), 'Payload hash must ignore object key order.');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$assert(is_string($png), 'PNG fixture failed to decode.');
$resolver = static fn(string $host): array => ['93.184.216.34'];
$transport = static fn(string $url, string $ip, int $max, int $timeout): array => ['status' => 200, 'headers' => ['Content-Type' => 'image/png'], 'body' => $png];
$ingestor = new MercatoCatalogImageIngestor();
$image = $ingestor->fetch('https://images.example.test/product.png', $resolver, $transport);
$assert($image['mime'] === 'image/png' && $image['width'] === 1 && !$image['duplicate'], 'Valid PNG was not accepted.');
$duplicate = $ingestor->fetch('https://images.example.test/product.png', $resolver, $transport, [$image['checksum']]);
$assert($duplicate['duplicate'] === true, 'Checksum replay must be detected.');

foreach ([
    fn() => $ingestor->fetch('http://images.example.test/a.png', $resolver, $transport),
    fn() => $ingestor->fetch('https://images.example.test/a.png', static fn(string $host): array => ['127.0.0.1'], $transport),
    fn() => $ingestor->fetch('https://images.example.test/a.png', $resolver, static fn(): array => ['status' => 200, 'headers' => ['Content-Type' => 'image/jpeg'], 'body' => $png]),
] as $failure) {
    try { $failure(); throw new RuntimeException('Unsafe image input was accepted.'); } catch (InvalidArgumentException|RuntimeException $e) { if ($e->getMessage() === 'Unsafe image input was accepted.') throw $e; }
}

echo "Mercato catalog import support tests passed.\n";
