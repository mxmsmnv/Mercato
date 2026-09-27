<?php

$root = dirname(__DIR__);
$files = glob($root . '/languages/*.csv') ?: [];
sort($files);

$expectedLanguages = [
    'Bulgarian' => 'bg', 'Croatian' => 'hr', 'Czech' => 'cs', 'Danish' => 'da',
    'Dutch' => 'nl', 'Estonian' => 'et', 'Finnish' => 'fi', 'French' => 'fr',
    'German' => 'de', 'Greek' => 'el', 'Hungarian' => 'hu', 'Irish' => 'ga',
    'Italian' => 'it', 'Latvian' => 'lv', 'Lithuanian' => 'lt', 'Maltese' => 'mt',
    'Polish' => 'pl', 'Portuguese' => 'pt', 'Romanian' => 'ro', 'Slovak' => 'sk',
    'Slovenian' => 'sl', 'Spanish' => 'es', 'Swedish' => 'sv',
];

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tokens = static function (string $value): array {
    preg_match_all('/%(?:\d+\$)?[+\-0 ]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]/', $value, $printf);
    preg_match_all('/\{[a-z][a-z0-9_]*\}/i', $value, $named);
    $all = array_merge($printf[0], $named[0]);
    sort($all);
    return $all;
};

$tagShape = static function (string $value): array {
    preg_match_all('/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $value, $matches, PREG_SET_ORDER);
    return array_map(
        static fn(array $match): string => (str_starts_with($match[0], '</') ? '/' : '') . strtolower($match[1]),
        $matches
    );
};

$expect(count($files) === count($expectedLanguages), 'The translation catalog must contain exactly the 23 supported non-English EU languages.');
$baseline = null;
$rowCount = null;

foreach ($files as $file) {
    $language = pathinfo($file, PATHINFO_FILENAME);
    $expect(isset($expectedLanguages[$language]), sprintf('Unexpected translation catalog: %s.', basename($file)));
    $handle = fopen($file, 'rb');
    $expect($handle !== false, sprintf('Cannot read %s.', basename($file)));
    $header = fgetcsv($handle, null, ',', '"', '');
    $expect(
        $header === ['en', $expectedLanguages[$language], 'description', 'file', 'hash'],
        sprintf('%s has an invalid CSV header or language code.', basename($file))
    );

    $keys = [];
    $rows = 0;
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows++;
        $expect(count($row) === 5, sprintf('%s row %d does not have five columns.', basename($file), $rows + 1));
        [$english, $translation, $description, $source, $hash] = $row;
        $expect(trim($english) !== '', sprintf('%s row %d has an empty source string.', basename($file), $rows + 1));
        $expect(trim($translation) !== '', sprintf('%s row %d has an empty translation.', basename($file), $rows + 1));
        $expect($hash === md5($english), sprintf('%s row %d has a stale source hash.', basename($file), $rows + 1));
        $expect(str_starts_with($source, 'site/modules/Mercato/'), sprintf('%s row %d references a file outside Mercato.', basename($file), $rows + 1));
        $expect($tokens($english) === $tokens($translation), sprintf('%s row %d changes interpolation tokens.', basename($file), $rows + 1));
        $expect($tagShape($english) === $tagShape($translation), sprintf('%s row %d changes HTML tag structure.', basename($file), $rows + 1));

        $key = $english . "\0" . $source . "\0" . $hash;
        $expect(!isset($keys[$key]), sprintf('%s row %d duplicates an existing source entry.', basename($file), $rows + 1));
        $keys[$key] = true;
    }
    fclose($handle);

    $rowCount ??= $rows;
    $expect($rows === $rowCount, sprintf('%s has %d entries; expected %d.', basename($file), $rows, $rowCount));
    if ($baseline === null) {
        $baseline = array_keys($keys);
    } else {
        $expect(array_keys($keys) === $baseline, sprintf('%s does not match the canonical source-entry order.', basename($file)));
    }
}

$expect($rowCount !== null && $rowCount > 0, 'Translation catalogs are empty.');
echo sprintf("Mercato localization catalog tests passed (23 languages, %d entries each).\n", $rowCount);
