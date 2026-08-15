<?php

namespace App\Services\Historical;

use App\Support\Historical\HistoricalParseException;
use Generator;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use ZipArchive;

/**
 * Reads an uploaded CSV or XLSX safely, in bounded memory.
 *
 * SAFETY POSTURE
 * - Formulas are never executed. XLSX is read with `setReadDataOnly(true)`, which
 *   returns the cached value the producing application already wrote and never
 *   instantiates PhpSpreadsheet's calculation engine. A CSV cell beginning `=`,
 *   `+`, `-` or `@` is source text and is stored as source text.
 * - External links, DDE and defined names are never resolved for the same reason.
 * - The archive is opened with ZipArchive first, so a truncated or non-zip file
 *   fails as "this file is not a readable workbook" instead of somewhere deep
 *   inside an XML parser.
 * - Sheet count, row count and column count are checked against the workbook's
 *   own metadata BEFORE any cell is loaded, so an oversized file is rejected
 *   without ever being expanded.
 *
 * CAPACITY (Release 1, synchronous — see HistoricalImportService)
 * CSV streams row by row through fgetcsv and is capped at 50,000 rows. XLSX
 * cannot stream: even filtered, PhpSpreadsheet must walk the sheet XML, so it is
 * capped far lower and the operator is told to re-export as CSV. Nothing here is
 * queued, because no deployed worker consumes a historical-imports queue.
 */
class HistoricalSourceFileReader
{
    public const EXT_CSV  = 'csv';
    public const EXT_XLSX = 'xlsx';

    public const SUPPORTED_EXTENSIONS = [self::EXT_CSV, self::EXT_XLSX];

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_SHEETS     = 20;
    public const MAX_COLUMNS    = 200;
    public const MAX_ROWS_CSV   = 50_000;
    public const MAX_ROWS_XLSX  = 5_000;

    /** How many data rows the mapping screen samples for its preview. */
    public const SAMPLE_ROWS = 20;

    /** Values that look like a spreadsheet formula. Stored literally, flagged. */
    private const FORMULA_LIKE = '/^[=+\-@\t\r]/';

    /**
     * Sheet metadata, cheap: read from the workbook's own index, no cells loaded.
     *
     * @return array<int, array{name: string, rows: int, columns: int, hidden: bool}>
     *
     * @throws HistoricalParseException
     */
    public function inspect(string $absolutePath, string $extension): array
    {
        $this->assertReadable($absolutePath);

        if ($extension === self::EXT_CSV) {
            return [[
                'name'    => '',
                'rows'    => $this->countCsvRows($absolutePath),
                'columns' => count($this->csvHeaderRow($absolutePath, 1)),
                'hidden'  => false,
            ]];
        }

        $hidden = $this->hiddenSheetNames($absolutePath);

        $info = (new XlsxReader())->listWorksheetInfo($absolutePath);

        if ($info === []) {
            throw new HistoricalParseException('This workbook contains no sheets.');
        }

        if (count($info) > self::MAX_SHEETS) {
            throw new HistoricalParseException(sprintf(
                'This workbook has %d sheets; the limit is %d. Split it or delete the sheets you are not importing.',
                count($info),
                self::MAX_SHEETS
            ));
        }

        $sheets = [];
        $seen   = [];

        foreach ($info as $sheet) {
            $name = (string) $sheet['worksheetName'];

            // Duplicate names make "which sheet did the operator pick?"
            // unanswerable, and the answer decides which rows become bills.
            if (isset($seen[$name])) {
                throw new HistoricalParseException(
                    "This workbook has two sheets named \"{$name}\". Rename one before importing."
                );
            }
            $seen[$name] = true;

            $rows    = (int) $sheet['totalRows'];
            $columns = (int) $sheet['totalColumns'];

            if ($columns > self::MAX_COLUMNS) {
                throw new HistoricalParseException(sprintf(
                    'Sheet "%s" has %d columns; the limit is %d.',
                    $name,
                    $columns,
                    self::MAX_COLUMNS
                ));
            }

            if ($rows > self::MAX_ROWS_XLSX) {
                throw new HistoricalParseException(sprintf(
                    'Sheet "%s" has %s rows. This release imports up to %s rows from an .xlsx file in one batch. '
                    . 'Re-export the data as .csv (up to %s rows) or split it into smaller files.',
                    $name,
                    number_format($rows),
                    number_format(self::MAX_ROWS_XLSX),
                    number_format(self::MAX_ROWS_CSV)
                ));
            }

            $sheets[] = [
                'name'    => $name,
                'rows'    => $rows,
                'columns' => $columns,
                'hidden'  => in_array($name, $hidden, true),
            ];
        }

        return $sheets;
    }

    /**
     * The header row, verbatim. Blank trailing headers are dropped; blank headers
     * BETWEEN populated ones are kept as positional placeholders, because their
     * column still holds data the operator has to decide about.
     *
     * @return array<int, string>
     *
     * @throws HistoricalParseException
     */
    public function headers(string $absolutePath, string $extension, ?string $sheet, int $headerRow): array
    {
        $this->assertReadable($absolutePath);

        $headers = $extension === self::EXT_CSV
            ? $this->csvHeaderRow($absolutePath, $headerRow)
            : $this->xlsxRowValues($absolutePath, $sheet, $headerRow);

        while ($headers !== [] && trim((string) end($headers)) === '') {
            array_pop($headers);
        }

        if ($headers === []) {
            throw new HistoricalParseException(
                "Row {$headerRow} of this file is empty, so it cannot be the header row."
            );
        }

        $seen = [];
        foreach ($headers as $index => $header) {
            $header = trim((string) $header);

            if ($header === '') {
                $headers[$index] = '(column ' . ($index + 1) . ')';

                continue;
            }

            // Repeated headers are the whole reason mapping is by NAME: two
            // "Amount" columns silently collapse to one and half the money
            // disappears. Disambiguate positionally instead of guessing.
            $key = mb_strtolower($header);
            if (isset($seen[$key])) {
                $headers[$index] = $header . ' (column ' . ($index + 1) . ')';
            }
            $seen[$key] = true;

            $headers[$index] = (string) $headers[$index];
        }

        return array_values($headers);
    }

    /**
     * Every data row after the header, as header => raw cell value.
     *
     * A generator on purpose: the caller writes each row to staging and moves on,
     * so peak memory is one row regardless of file size.
     *
     * @param  array<int, string>  $headers
     * @return Generator<int, array{row_number: int, cells: array<string, mixed>}>
     *
     * @throws HistoricalParseException
     */
    public function rows(
        string $absolutePath,
        string $extension,
        ?string $sheet,
        int $headerRow,
        array $headers,
        ?int $limit = null,
    ): Generator {
        $this->assertReadable($absolutePath);

        $source = $extension === self::EXT_CSV
            ? $this->csvRows($absolutePath, $headerRow)
            : $this->xlsxRows($absolutePath, $sheet, $headerRow);

        $emitted = 0;

        foreach ($source as $rowNumber => $values) {
            if (self::isBlankRow($values)) {
                continue;
            }

            $cells = [];
            foreach ($headers as $index => $header) {
                $cells[$header] = $values[$index] ?? null;
            }

            yield ['row_number' => $rowNumber, 'cells' => $cells];

            if ($limit !== null && ++$emitted >= $limit) {
                return;
            }
        }
    }

    /** True when a cell's text would be interpreted as a formula by a spreadsheet. */
    public static function looksLikeFormula(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match(self::FORMULA_LIKE, $value) === 1;
    }

    // ------------------------------------------------------------------- csv

    private function csvHeaderRow(string $path, int $headerRow): array
    {
        $handle = $this->openCsv($path);
        $number = 0;

        try {
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (++$number === $headerRow) {
                    return $values === [null] ? [] : array_map(static fn ($v) => (string) ($v ?? ''), $values);
                }
            }
        } finally {
            fclose($handle);
        }

        throw new HistoricalParseException("This file has fewer than {$headerRow} rows.");
    }

    /** @return Generator<int, array<int, string|null>> */
    private function csvRows(string $path, int $headerRow): Generator
    {
        $handle = $this->openCsv($path);
        $number = 0;

        try {
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $number++;

                if ($number <= $headerRow) {
                    continue;
                }

                if ($number - $headerRow > self::MAX_ROWS_CSV) {
                    throw new HistoricalParseException(sprintf(
                        'This file has more than %s data rows. Split it into smaller files and import them as separate batches.',
                        number_format(self::MAX_ROWS_CSV)
                    ));
                }

                yield $number => $values === [null] ? [] : $values;
            }
        } finally {
            fclose($handle);
        }
    }

    private function countCsvRows(string $path): int
    {
        $handle = $this->openCsv($path);
        $count  = 0;

        try {
            while (fgetcsv($handle, 0, ',', '"', '') !== false) {
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /** @return resource */
    private function openCsv(string $path)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new HistoricalParseException('This file could not be opened.');
        }

        // A UTF-8 BOM makes the first header literally "\u{FEFF}Invoice No",
        // which matches no alias and confuses the operator about why.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    // ------------------------------------------------------------------ xlsx

    private function xlsxRowValues(string $path, ?string $sheet, int $row): array
    {
        foreach ($this->xlsxRows($path, $sheet, $row - 1, $row) as $values) {
            return $values;
        }

        throw new HistoricalParseException("Row {$row} of this sheet is empty, so it cannot be the header row.");
    }

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function xlsxRows(string $path, ?string $sheet, int $afterRow, ?int $untilRow = null): Generator
    {
        $reader = new XlsxReader();
        // No calculation engine, no styles, no formula evaluation.
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        if ($sheet !== null && $sheet !== '') {
            $reader->setLoadSheetsOnly($sheet);
        }

        $reader->setReadFilter(new class($afterRow + 1, $untilRow) implements IReadFilter {
            public function __construct(private int $from, private ?int $to) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->from && ($this->to === null || $row <= $this->to);
            }
        });

        $spreadsheet = $reader->load($path);
        $worksheet   = $sheet !== null && $sheet !== ''
            ? $spreadsheet->getSheetByName($sheet)
            : $spreadsheet->getSheet(0);

        if ($worksheet === null) {
            $spreadsheet->disconnectWorksheets();

            throw new HistoricalParseException("This workbook has no sheet named \"{$sheet}\".");
        }

        try {
            foreach ($worksheet->getRowIterator($afterRow + 1) as $row) {
                $number = $row->getRowIndex();

                if ($untilRow !== null && $number > $untilRow) {
                    break;
                }

                $values   = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(false);

                foreach ($iterator as $cell) {
                    $value = $cell->getValue();

                    // A date cell is a serial number plus a number format. Resolve
                    // it here, where the format is visible, rather than handing the
                    // operator a five-digit "date" to explain.
                    if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
                        $value = ExcelDate::excelToDateTimeObject((float) $value);
                    }

                    $values[] = $value;
                }

                yield $number => $values;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * Hidden sheets, read straight out of the archive index. A hidden sheet is
     * usually a scratch pad, and importing one as if it were the invoice register
     * is a silent data disaster — so it is surfaced, never auto-selected.
     *
     * @return array<int, string>
     */
    private function hiddenSheetNames(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new HistoricalParseException(
                'This file is not a readable .xlsx workbook. It may be corrupt or saved in another format.'
            );
        }

        try {
            $xml = $zip->getFromName('xl/workbook.xml');
        } finally {
            $zip->close();
        }

        if ($xml === false) {
            throw new HistoricalParseException('This .xlsx file is missing its workbook index and cannot be read.');
        }

        $hidden = [];

        // Deliberately a regex and not simplexml_load_string: this file is
        // attacker-controlled and there is no reason to hand it to an XML parser
        // for a question this small.
        if (preg_match_all('/<sheet\b[^>]*>/i', $xml, $matches) === false) {
            return [];
        }

        foreach ($matches[0] ?? [] as $tag) {
            if (! preg_match('/\bstate="(hidden|veryHidden)"/i', $tag)) {
                continue;
            }

            if (preg_match('/\bname="([^"]*)"/i', $tag, $nameMatch)) {
                $hidden[] = html_entity_decode($nameMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $hidden;
    }

    // ------------------------------------------------------------------ misc

    private function assertReadable(string $absolutePath): void
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new HistoricalParseException('The uploaded source file is no longer available.');
        }

        $size = filesize($absolutePath);

        if ($size === false || $size === 0) {
            throw new HistoricalParseException('This file is empty.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw new HistoricalParseException(sprintf(
                'This file is %s; the limit is %s.',
                self::humanBytes($size),
                self::humanBytes(self::MAX_FILE_BYTES)
            ));
        }
    }

    private static function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) (is_scalar($value) ? $value : '.')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function humanBytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
