<?php

namespace App\Services\Historical;

use App\Support\Historical\HistoricalParseException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Reads a source date under an EXPLICITLY chosen format.
 *
 * There is no `parse($value)` on this class and there never will be. `04/05/2024`
 * is 4 May in every Indian accounting package and 5 April in every American one,
 * and a parser that picks for you moves a month of revenue into the wrong
 * financial year in a way nobody notices until an assessment. The format is a
 * mandatory, persisted property of the import profile; this class only applies it.
 *
 * `isAmbiguous()` exists so the mapping screen can prove the point to the
 * operator with their own data before they choose.
 */
class HistoricalDateParser
{
    public const FORMAT_DMY          = 'DD/MM/YYYY';
    public const FORMAT_MDY          = 'MM/DD/YYYY';
    public const FORMAT_ISO          = 'YYYY-MM-DD';
    public const FORMAT_EXCEL_SERIAL = 'EXCEL_SERIAL';

    public const FORMATS = [
        self::FORMAT_DMY          => 'Day / Month / Year — 04/05/2024 is 4 May 2024',
        self::FORMAT_MDY          => 'Month / Day / Year — 04/05/2024 is 5 April 2024',
        self::FORMAT_ISO          => 'Year-Month-Day — 2024-05-04',
        self::FORMAT_EXCEL_SERIAL => 'Excel serial number — 45416 is 4 May 2024',
    ];

    /**
     * Excel counts days from 1900-01-01 = 1 but also believes 1900 was a leap
     * year, so serials at or above 61 are one day ahead of reality. Two epochs,
     * not one fudge factor: serial 60 is Excel's non-existent 29 Feb 1900 and is
     * rejected rather than silently resolved to some neighbouring day.
     */
    private const EXCEL_EPOCH_EARLY = '1899-12-31'; // serial 1..59
    private const EXCEL_EPOCH_LATE  = '1899-12-30'; // serial 61..

    /** Separators an operator's file actually uses for the slash formats. */
    private const SEPARATORS = '[\/\-.]';

    public static function isSupported(string $format): bool
    {
        return array_key_exists($format, self::FORMATS);
    }

    /**
     * A value that reads as a valid, DIFFERENT date under both day-first and
     * month-first. These are the values the operator must resolve by choosing a
     * format — never by us picking one.
     */
    public static function isAmbiguous(mixed $value): bool
    {
        if (! self::matchSlashParts($value, $parts)) {
            return false;
        }

        [$a, $b] = $parts;

        return $a !== $b && $a >= 1 && $a <= 12 && $b >= 1 && $b <= 12;
    }

    /**
     * @throws HistoricalParseException
     */
    public function parse(mixed $value, string $format): CarbonImmutable
    {
        // A real spreadsheet cell may already be a date object; the format is
        // then irrelevant and applying it would be a second, lossy conversion.
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            throw new HistoricalParseException('The document date is empty. Every historical bill needs its printed date.');
        }

        return match ($format) {
            self::FORMAT_ISO          => $this->parseIso($raw),
            self::FORMAT_DMY          => $this->parseSlash($raw, dayFirst: true),
            self::FORMAT_MDY          => $this->parseSlash($raw, dayFirst: false),
            self::FORMAT_EXCEL_SERIAL => $this->parseExcelSerial($raw),
            default                   => throw new HistoricalParseException(
                "\"{$format}\" is not a supported date format. Choose one of: " . implode(', ', array_keys(self::FORMATS)) . '.'
            ),
        };
    }

    private function parseIso(string $raw): CarbonImmutable
    {
        // Tolerates the trailing " 00:00:00" every CSV export from a datetime
        // column carries, and nothing else.
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $raw, $m)) {
            throw new HistoricalParseException(
                "\"{$raw}\" is not a YYYY-MM-DD date. Check the date format selected for this import."
            );
        }

        return $this->build((int) $m[1], (int) $m[2], (int) $m[3], $raw);
    }

    private function parseSlash(string $raw, bool $dayFirst): CarbonImmutable
    {
        if (! self::matchSlashParts($raw, $parts, $year)) {
            throw new HistoricalParseException(
                "\"{$raw}\" is not a " . ($dayFirst ? 'DD/MM/YYYY' : 'MM/DD/YYYY')
                . ' date. Check the date format selected for this import.'
            );
        }

        [$a, $b] = $parts;

        return $dayFirst
            ? $this->build($year, $b, $a, $raw)
            : $this->build($year, $a, $b, $raw);
    }

    private function parseExcelSerial(string $raw): CarbonImmutable
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $raw)) {
            throw new HistoricalParseException(
                "\"{$raw}\" is not an Excel serial date number. Check the date format selected for this import."
            );
        }

        $serial = (int) floor((float) $raw);

        if ($serial < 1) {
            throw new HistoricalParseException("Excel serial date \"{$raw}\" is not a real date.");
        }

        if ($serial === 60) {
            throw new HistoricalParseException(
                'Excel serial 60 is 29 February 1900, a date that never existed. Correct this cell in the source file.'
            );
        }

        $epoch = $serial < 60 ? self::EXCEL_EPOCH_EARLY : self::EXCEL_EPOCH_LATE;

        return CarbonImmutable::createFromFormat('Y-m-d', $epoch)->startOfDay()->addDays($serial);
    }

    private function build(int $year, int $month, int $day, string $raw): CarbonImmutable
    {
        // checkdate, not Carbon's forgiving parser: 31/02/2024 must be an error
        // the operator fixes, not a silent roll forward into 2 March.
        if (! checkdate($month, $day, $year)) {
            throw new HistoricalParseException("\"{$raw}\" is not a real calendar date.");
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }

    /**
     * @param  array{0: int, 1: int}|null  $parts
     */
    private static function matchSlashParts(mixed $value, ?array &$parts = null, ?int &$year = null): bool
    {
        $parts = null;
        $year  = null;

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $pattern = '/^(\d{1,2})' . self::SEPARATORS . '(\d{1,2})' . self::SEPARATORS . '(\d{4})(?:[ T].*)?$/';

        if (! preg_match($pattern, trim((string) $value), $m)) {
            return false;
        }

        $parts = [(int) $m[1], (int) $m[2]];
        $year  = (int) $m[3];

        return true;
    }
}
