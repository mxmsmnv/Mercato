<?php
namespace ProcessWire;

final class MercatoReceiptPdfRenderer {
    private const PAGE_WIDTH = 595.0;
    private const PAGE_HEIGHT = 842.0;

    public static function render(array $document, array $theme): string {
        $theme = self::normalizeTheme($theme);
        $logo = self::jpegLogo($theme['logo_path']);
        $theme['has_logo'] = $logo !== null;
        $items = array_values(array_filter((array) ($document['items'] ?? []), 'is_array'));
        $firstItems = array_slice($items, 0, 5);
        $remaining = array_slice($items, 5);
        $pages = [self::renderFirstPage($document, $theme, $firstItems, count($items))];
        foreach (array_chunk($remaining, 12) as $index => $chunk) {
            $pages[] = self::renderItemsPage($document, $theme, $chunk, $index + 2, count($items));
        }
        return self::buildPdf($pages, $logo);
    }

    private static function normalizeTheme(array $theme): array {
        $color = static function(mixed $value, string $fallback): string {
            $value = strtoupper(trim((string) $value));
            return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
        };
        $logo = trim((string) ($theme['logo_path'] ?? ''));
        if ($logo === '' || !is_file($logo) || filesize($logo) > 5_000_000) $logo = '';
        return [
            'brand_name' => self::clean((string) ($theme['brand_name'] ?? 'Mercato')) ?: 'Mercato',
            'document_label' => self::clean((string) ($theme['document_label'] ?? 'Payment receipt')) ?: 'Payment receipt',
            'footer_text' => self::clean((string) ($theme['footer_text'] ?? 'Keep this receipt and order number for your records.')),
            'website' => self::clean((string) ($theme['website'] ?? '')),
            'logo_path' => $logo,
            'primary' => $color($theme['primary'] ?? '', '#173A37'),
            'accent' => $color($theme['accent'] ?? '', '#2E898E'),
            'success' => $color($theme['success'] ?? '', '#91D3B0'),
            'surface' => $color($theme['surface'] ?? '', '#F3F6F6'),
            'border' => $color($theme['border'] ?? '', '#D4DEDF'),
            'text' => $color($theme['text'] ?? '', '#172224'),
            'muted' => $color($theme['muted'] ?? '', '#566568'),
        ];
    }

    private static function renderFirstPage(array $document, array $theme, array $items, int $itemCount): string {
        $stream = '';
        self::rect($stream, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, $theme['surface']);
        self::rect($stream, 0, 774, self::PAGE_WIDTH, 68, '#FFFFFF');
        self::rect($stream, 0, 772, self::PAGE_WIDTH, 2, $theme['accent']);
        self::brandMark($stream, 42, 790, 34, $theme);
        self::text($stream, $theme['brand_name'], 84, 808, 16, 'F2', $theme['text']);
        self::textRight($stream, strtoupper($theme['document_label']), 553, 807, 8, 'F2', $theme['muted']);

        self::rect($stream, 0, 620, self::PAGE_WIDTH, 152, $theme['primary']);
        self::text($stream, 'ORDER ' . self::clean((string) ($document['invoice'] ?? '')), 42, 738, 8, 'F2', $theme['success']);
        self::text($stream, $theme['document_label'], 42, 700, 26, 'F2', '#FFFFFF');
        self::text($stream, 'Issued ' . self::clean((string) ($document['date'] ?? '')), 42, 676, 10, 'F1', '#D9E7E5');
        self::pill($stream, self::clean((string) ($document['payment_status'] ?? 'Paid')), 420, 722, 102, 25, $theme['success'], $theme['primary']);
        self::textRight($stream, 'TOTAL PAID', 553, 684, 8, 'F2', '#D9E7E5');
        self::textRight($stream, self::clean((string) ($document['total'] ?? '')), 553, 650, 25, 'F2', '#FFFFFF');

        $leftX = 42.0;
        $leftW = 330.0;
        $rightX = 390.0;
        $rightW = 163.0;
        $cardTop = 588.0;
        $rowHeight = 49.0;
        $itemsHeight = 70.0 + max(1, count($items)) * $rowHeight;
        self::card($stream, $leftX, $cardTop - $itemsHeight, $leftW, $itemsHeight, $theme);
        self::text($stream, 'PURCHASE DETAILS', $leftX + 18, $cardTop - 26, 7.5, 'F2', $theme['accent']);
        self::text($stream, 'Courses', $leftX + 18, $cardTop - 50, 16, 'F2', $theme['text']);
        self::textRight($stream, $itemCount . ' ' . ($itemCount === 1 ? 'item' : 'items'), $leftX + $leftW - 18, $cardTop - 47, 8, 'F1', $theme['muted']);
        self::line($stream, $leftX + 18, $cardTop - 62, $leftX + $leftW - 18, $cardTop - 62, $theme['border']);
        $rowY = $cardTop - 88;
        foreach ($items as $item) {
            self::drawItemRow($stream, $item, $leftX + 18, $rowY, $leftW - 36, $theme);
            $rowY -= $rowHeight;
        }
        if (!$items) self::text($stream, 'No receipt items are available.', $leftX + 18, $cardTop - 92, 10, 'F1', $theme['muted']);

        self::drawPaymentSummary($stream, $document, $rightX, 588, $rightW, $theme);
        self::drawCustomer($stream, $document, $rightX, 371, $rightW, $theme);

        $addressTop = $cardTop - $itemsHeight - 16;
        if ($addressTop > 142) self::drawAddresses($stream, $document, $leftX, $addressTop, $leftW, $theme);
        $totalPages = 1 + (int) ceil(max(0, $itemCount - 5) / 12);
        self::footer($stream, $theme, 1, $totalPages);
        return $stream;
    }

    private static function renderItemsPage(array $document, array $theme, array $items, int $pageNumber, int $itemCount): string {
        $stream = '';
        self::rect($stream, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT, $theme['surface']);
        self::rect($stream, 0, 774, self::PAGE_WIDTH, 68, '#FFFFFF');
        self::rect($stream, 0, 772, self::PAGE_WIDTH, 2, $theme['accent']);
        self::brandMark($stream, 42, 790, 34, $theme);
        self::text($stream, $theme['brand_name'], 84, 808, 16, 'F2', $theme['text']);
        self::textRight($stream, 'ORDER ' . self::clean((string) ($document['invoice'] ?? '')), 553, 807, 8, 'F2', $theme['muted']);
        self::text($stream, 'Courses - continued', 42, 724, 24, 'F2', $theme['text']);
        self::text($stream, $itemCount . ' items in this order', 42, 700, 10, 'F1', $theme['muted']);
        $top = 666.0;
        $height = 54.0 + count($items) * 47.0;
        self::card($stream, 42, $top - $height, 511, $height, $theme);
        self::text($stream, 'COURSE', 60, $top - 28, 7.5, 'F2', $theme['muted']);
        self::textRight($stream, 'AMOUNT', 535, $top - 28, 7.5, 'F2', $theme['muted']);
        self::line($stream, 60, $top - 40, 535, $top - 40, $theme['border']);
        $rowY = $top - 66;
        foreach ($items as $item) {
            self::drawItemRow($stream, $item, 60, $rowY, 475, $theme);
            $rowY -= 47;
        }
        $totalPages = 2 + intdiv(max(0, $itemCount - 6), 12);
        self::footer($stream, $theme, $pageNumber, $totalPages);
        return $stream;
    }

    private static function drawItemRow(string &$stream, array $item, float $x, float $y, float $width, array $theme): void {
        $title = self::clean((string) ($item['title'] ?? 'Course')) ?: 'Course';
        $sku = self::clean((string) ($item['sku'] ?? ''));
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $amount = self::clean((string) ($item['amount'] ?? ''));
        $lines = self::wrap($title, 43);
        self::text($stream, $lines[0] ?? $title, $x, $y, 10, 'F2', $theme['text']);
        if (isset($lines[1])) self::text($stream, $lines[1], $x, $y - 12, 10, 'F2', $theme['text']);
        $meta = trim(($sku !== '' ? $sku . '  |  ' : '') . 'Qty ' . $quantity);
        self::text($stream, $meta, $x, $y - (isset($lines[1]) ? 25 : 15), 7.5, 'F1', $theme['muted']);
        self::textRight($stream, $amount, $x + $width, $y, 10, 'F2', $theme['text']);
    }

    private static function drawPaymentSummary(string &$stream, array $document, float $x, float $top, float $width, array $theme): void {
        self::card($stream, $x, $top - 201, $width, 201, $theme);
        self::text($stream, 'PAYMENT SUMMARY', $x + 16, $top - 27, 7.5, 'F2', $theme['accent']);
        $rows = array_values(array_filter((array) ($document['summary'] ?? []), 'is_array'));
        $y = $top - 58;
        foreach ($rows as $row) {
            $isTotal = !empty($row['total']);
            if ($isTotal) self::line($stream, $x + 16, $y + 12, $x + $width - 16, $y + 12, $theme['border']);
            self::text($stream, self::clean((string) ($row['label'] ?? '')), $x + 16, $y, $isTotal ? 9 : 8, $isTotal ? 'F2' : 'F1', $isTotal ? $theme['text'] : $theme['muted']);
            self::textRight($stream, self::clean((string) ($row['value'] ?? '')), $x + $width - 16, $y, $isTotal ? 10 : 8, 'F2', $theme['text']);
            $y -= $isTotal ? 29 : 23;
            if ($y < $top - 186) break;
        }
    }

    private static function drawCustomer(string &$stream, array $document, float $x, float $top, float $width, array $theme): void {
        self::card($stream, $x, $top - 178, $width, 178, $theme);
        self::text($stream, 'CUSTOMER', $x + 16, $top - 27, 7.5, 'F2', $theme['accent']);
        self::text($stream, self::clean((string) ($document['customer_name'] ?? 'Customer')), $x + 16, $top - 53, 10, 'F2', $theme['text']);
        foreach (self::wrap((string) ($document['customer_email'] ?? ''), 27) as $index => $line) {
            if ($index > 1) break;
            self::text($stream, $line, $x + 16, $top - 72 - ($index * 11), 7.5, 'F1', $theme['muted']);
        }
        self::line($stream, $x + 16, $top - 101, $x + $width - 16, $top - 101, $theme['border']);
        self::text($stream, 'ORDER', $x + 16, $top - 121, 7, 'F2', $theme['muted']);
        self::textRight($stream, self::clean((string) ($document['invoice'] ?? '')), $x + $width - 16, $top - 121, 8, 'F2', $theme['text']);
        self::text($stream, 'DATE', $x + 16, $top - 145, 7, 'F2', $theme['muted']);
        self::textRight($stream, self::clean((string) ($document['date_short'] ?? '')), $x + $width - 16, $top - 145, 8, 'F2', $theme['text']);
    }

    private static function drawAddresses(string &$stream, array $document, float $x, float $top, float $width, array $theme): void {
        $billing = array_values(array_filter((array) ($document['billing_address'] ?? []), 'strlen'));
        $shipping = array_values(array_filter((array) ($document['shipping_address'] ?? []), 'strlen'));
        if (!$billing && !$shipping) return;
        self::card($stream, $x, $top - 116, $width, 116, $theme);
        self::text($stream, 'ADDRESS DETAILS', $x + 18, $top - 25, 7.5, 'F2', $theme['accent']);
        $columns = $billing && $shipping ? 2 : 1;
        $columnWidth = ($width - 44) / $columns;
        $sets = $billing && $shipping
            ? [['Billing', $billing], [self::clean((string) ($document['fulfilment_label'] ?? 'Delivery')), $shipping]]
            : [[$billing ? 'Billing' : self::clean((string) ($document['fulfilment_label'] ?? 'Delivery')), $billing ?: $shipping]];
        foreach ($sets as $index => [$label, $lines]) {
            $columnX = $x + 18 + ($index * ($columnWidth + 8));
            self::text($stream, strtoupper($label), $columnX, $top - 48, 7, 'F2', $theme['muted']);
            foreach (array_slice($lines, 0, 4) as $lineIndex => $line) {
                self::text($stream, self::clean((string) $line), $columnX, $top - 66 - ($lineIndex * 11), 7.5, 'F1', $theme['text']);
            }
        }
    }

    private static function footer(string &$stream, array $theme, int $page, int $pages): void {
        self::line($stream, 42, 72, 553, 72, $theme['border']);
        self::text($stream, $theme['footer_text'], 42, 49, 7.5, 'F1', $theme['muted']);
        if ($theme['website'] !== '') self::text($stream, $theme['website'], 42, 34, 7.5, 'F2', $theme['accent']);
        self::textRight($stream, 'Page ' . $page . ' of ' . $pages, 553, 42, 7.5, 'F1', $theme['muted']);
    }

    private static function card(string &$stream, float $x, float $y, float $width, float $height, array $theme): void {
        self::rect($stream, $x, $y, $width, $height, '#FFFFFF');
        self::strokeRect($stream, $x, $y, $width, $height, $theme['border'], 0.7);
        self::rect($stream, $x, $y + $height - 3, $width, 3, $theme['accent']);
    }

    private static function pill(string &$stream, string $label, float $x, float $y, float $width, float $height, string $fill, string $text): void {
        self::rect($stream, $x, $y, $width, $height, $fill);
        self::textCentered($stream, strtoupper($label), $x + ($width / 2), $y + 8, 8, 'F2', $text);
    }

    private static function buildPdf(array $pageStreams, ?array $logo): string {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $next = 5;
        $logoObject = 0;
        if ($logo) {
            $logoObject = $next++;
            $objects[$logoObject] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($logo['data']) . " >>\nstream\n" . $logo['data'] . "\nendstream";
        }
        $kids = [];
        foreach ($pageStreams as $stream) {
            $pageObject = $next++;
            $contentObject = $next++;
            $kids[] = $pageObject . ' 0 R';
            $resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>';
            if ($logoObject) $resources .= ' /XObject << /Logo ' . $logoObject . ' 0 R >>';
            $resources .= ' >>';
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources ' . $resources . ' /Contents ' . $contentObject . ' 0 R >>';
            $objects[$contentObject] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($number = 1; $number <= $max; $number++) $pdf .= sprintf('%010d 00000 n ', $offsets[$number] ?? 0) . "\n";
        return $pdf . 'trailer' . "\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function jpegLogo(string $path): ?array {
        if ($path === '' || !function_exists('imagecreatefromstring')) return null;
        $bytes = @file_get_contents($path);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if (!$source) return null;
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);
        return $jpeg !== '' ? ['data' => $jpeg, 'width' => $width, 'height' => $height] : null;
    }

    private static function logo(string &$stream, float $x, float $y, float $size): void {
        $stream .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q\n", $size, $size, $x, $y);
    }

    private static function brandMark(string &$stream, float $x, float $y, float $size, array $theme): void {
        if (!empty($theme['has_logo'])) {
            self::logo($stream, $x, $y, $size);
            return;
        }
        self::rect($stream, $x, $y, $size, $size, $theme['accent']);
        self::textCentered($stream, substr($theme['brand_name'], 0, 1), $x + ($size / 2), $y + 10, 14, 'F2', '#FFFFFF');
    }

    private static function rect(string &$stream, float $x, float $y, float $width, float $height, string $color): void {
        $stream .= self::rgb($color) . sprintf(" rg %.2F %.2F %.2F %.2F re f\n", $x, $y, $width, $height);
    }

    private static function strokeRect(string &$stream, float $x, float $y, float $width, float $height, string $color, float $lineWidth): void {
        $stream .= self::rgb($color) . sprintf(" RG %.2F w %.2F %.2F %.2F %.2F re S\n", $lineWidth, $x, $y, $width, $height);
    }

    private static function line(string &$stream, float $x1, float $y1, float $x2, float $y2, string $color): void {
        $stream .= self::rgb($color) . sprintf(" RG .7 w %.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    private static function text(string &$stream, string $text, float $x, float $y, float $size, string $font, string $color): void {
        $stream .= "BT /{$font} " . sprintf('%.2F', $size) . ' Tf ' . self::rgb($color) . ' rg ' . sprintf('%.2F %.2F Td ', $x, $y) . '(' . self::escape(self::clean($text)) . ") Tj ET\n";
    }

    private static function textRight(string &$stream, string $text, float $right, float $y, float $size, string $font, string $color): void {
        self::text($stream, $text, $right - self::textWidth($text, $size, $font), $y, $size, $font, $color);
    }

    private static function textCentered(string &$stream, string $text, float $center, float $y, float $size, string $font, string $color): void {
        self::text($stream, $text, $center - (self::textWidth($text, $size, $font) / 2), $y, $size, $font, $color);
    }

    private static function textWidth(string $text, float $size, string $font): float {
        return strlen(self::clean($text)) * $size * ($font === 'F2' ? 0.55 : 0.5);
    }

    private static function wrap(string $text, int $characters): array {
        $wrapped = wordwrap(self::clean($text), max(8, $characters), "\n", true);
        return array_values(array_filter(explode("\n", $wrapped), static fn(string $line): bool => $line !== ''));
    }

    private static function clean(string $text): string {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        return trim((string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]+/', '', $text));
    }

    private static function escape(string $text): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function rgb(string $hex): string {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }
}
