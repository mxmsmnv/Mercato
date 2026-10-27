<?php
namespace ProcessWire;

/**
 * Bounded RFC 4180-style CSV reader for catalog feeds.
 *
 * Unlike line-by-line str_getcsv(), fgetcsv() preserves quoted newlines. The
 * parser deliberately performs structural validation only; catalog schema and
 * PATCH/replace semantics belong to the catalog sync service.
 */
final class MercatoCatalogCsvParser {
    public const DEFAULT_MAX_BYTES = 10_485_760;
    public const DEFAULT_MAX_ROWS = 10_000;
    public const DEFAULT_MAX_COLUMNS = 128;

    /** @return array{header:array<int,string>,rows:array<int,array<string,string>>,errors:array<int,string>} */
    public function parse(
        string $csv,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        int $maxRows = self::DEFAULT_MAX_ROWS,
        int $maxColumns = self::DEFAULT_MAX_COLUMNS
    ): array {
        $errors = [];
        if ($csv === '') return ['header' => [], 'rows' => [], 'errors' => ['CSV content is empty.']];
        if (strlen($csv) > max(1, $maxBytes)) return ['header' => [], 'rows' => [], 'errors' => ['CSV content exceeds the configured byte limit.']];

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) return ['header' => [], 'rows' => [], 'errors' => ['CSV temporary stream could not be opened.']];
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $rawHeader = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($rawHeader)) return ['header' => [], 'rows' => [], 'errors' => ['CSV header row is missing.']];
            if (isset($rawHeader[0])) $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeader[0]) ?? (string) $rawHeader[0];
            if (count($rawHeader) > max(1, $maxColumns)) return ['header' => [], 'rows' => [], 'errors' => ['CSV header exceeds the configured column limit.']];

            $header = [];
            $seen = [];
            foreach ($rawHeader as $index => $value) {
                $name = strtolower(trim((string) $value));
                if ($name === '') $errors[] = sprintf('CSV header column %d is empty.', $index + 1);
                if ($name !== '' && isset($seen[$name])) $errors[] = sprintf('CSV header contains duplicate column "%s".', $name);
                $seen[$name] = true;
                $header[] = $name;
            }
            if ($errors) return ['header' => $header, 'rows' => [], 'errors' => $errors];

            $rows = [];
            $record = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $record++;
                if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) continue;
                if (count($rows) >= max(1, $maxRows)) {
                    $errors[] = sprintf('CSV exceeds the configured row limit of %d.', max(1, $maxRows));
                    break;
                }
                if (count($cells) !== count($header)) {
                    $errors[] = sprintf('CSV record %d has %d columns; expected %d.', $record, count($cells), count($header));
                    continue;
                }
                $row = [];
                foreach ($header as $index => $name) $row[$name] = trim((string) ($cells[$index] ?? ''));
                $rows[] = $row;
            }
            return ['header' => $header, 'rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($stream);
        }
    }
}
