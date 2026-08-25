<?php
namespace ProcessWire;

final class MercatoEmailTemplateRenderer {
    public static function render(string $subjectTemplate, string $textTemplate, string $htmlTemplate, array $values): array {
        $textValues = self::normalizeValues($values, false);
        $htmlValues = self::normalizeValues($values, true);
        $subject = preg_replace('/[\r\n]+/', ' ', strtr($subjectTemplate, $textValues)) ?: '';
        $text = strtr($textTemplate, $textValues);
        $html = $htmlTemplate !== '' ? strtr($htmlTemplate, $htmlValues) : self::textToHtml($text);
        $html = self::sanitizeHtml($html);
        return ['subject' => trim($subject), 'text' => trim($text), 'html' => $html];
    }

    public static function normalizeLocale(string $locale): string {
        $locale = str_replace('-', '_', strtolower(trim($locale)));
        return preg_match('/^[a-z]{2}(?:_[a-z]{2})?$/', $locale) ? $locale : 'en';
    }

    public static function sanitizeHtml(string $html): string {
        $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html) ?: '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?: '';
        $html = preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*(?:javascript|data):.*?\2/i', ' $1="#"', $html) ?: '';
        return strip_tags($html, '<!doctype><html><head><meta><title><body><div><table><tbody><tr><td><p><br><h1><h2><h3><strong><b><em><i><a><ul><ol><li><span><small><hr>');
    }

    private static function normalizeValues(array $values, bool $html): array {
        $out = [];
        foreach ($values as $key => $value) {
            $placeholder = str_starts_with((string) $key, '{') ? (string) $key : '{' . $key . '}';
            $string = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out[$placeholder] = $html ? htmlspecialchars((string) $string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : strip_tags((string) $string);
        }
        return $out;
    }

    private static function textToHtml(string $text): string {
        return '<!doctype html><html><body><div style="font-family:Arial,sans-serif;line-height:1.5;color:#2c2521">' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div></body></html>';
    }
}
