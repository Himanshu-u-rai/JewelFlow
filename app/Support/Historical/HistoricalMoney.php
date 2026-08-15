<?php

namespace App\Support\Historical;

/**
 * Reads a number out of a source cell under EXPLICITLY declared separators.
 *
 * `125.000,00` is one hundred twenty-five thousand in Germany and one hundred
 * twenty-five in India. No amount of cleverness distinguishes them from the value
 * alone, and a parser that sniffs per-cell will read some rows one way and some
 * the other inside a single file. The separators are a persisted property of the
 * import profile; this class only applies them.
 */
final class HistoricalMoney
{
    /** Symbols that are decoration, never data. */
    private const CURRENCY = '/(₹|RS\.?|INR|\p{Sc})/iu';

    /**
     * @throws HistoricalParseException
     */
    public static function parse(
        mixed $value,
        string $decimalSeparator = '.',
        ?string $thousandsSeparator = ',',
    ): float {
        // Spreadsheet cells arrive already typed often enough that re-stringifying
        // them would introduce the locale problem this class exists to avoid.
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            throw new HistoricalParseException('This amount is empty.');
        }

        if ($decimalSeparator === $thousandsSeparator) {
            throw new HistoricalParseException(
                'The decimal and thousands separators in this import profile are the same character, '
                . 'which makes every amount ambiguous. Correct the profile.'
            );
        }

        $clean = self::strip($raw, $decimalSeparator, $thousandsSeparator);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new HistoricalParseException(sprintf(
                '"%s" is not a number under this import profile (decimal "%s", thousands "%s").',
                $raw,
                $decimalSeparator,
                $thousandsSeparator ?? 'none'
            ));
        }

        return (float) $clean;
    }

    /**
     * The same rules, but "the source did not say" is a legitimate answer.
     *
     * NULL, not 0.0. A missing tax figure is unknown; writing zero would assert
     * the bill carried no tax, which is a claim the source never made.
     */
    public static function parseOptional(
        mixed $value,
        string $decimalSeparator = '.',
        ?string $thousandsSeparator = ',',
    ): ?float {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return self::parse($value, $decimalSeparator, $thousandsSeparator);
    }

    /** Money comparison, in paise, so 0.1 + 0.2 never decides a reconciliation. */
    public static function differsBy(float $a, float $b): float
    {
        return abs(round($a, 2) - round($b, 2));
    }

    public static function equal(float $a, float $b): bool
    {
        return self::differsBy($a, $b) < 0.005;
    }

    private static function strip(string $raw, string $decimalSeparator, ?string $thousandsSeparator): string
    {
        $clean = (string) preg_replace(self::CURRENCY, '', $raw);

        // Accounting exports print negatives as (1,200.00).
        $negative = false;
        if (preg_match('/^\((.*)\)$/', trim($clean), $m)) {
            $negative = true;
            $clean    = $m[1];
        }

        // A trailing CR/DR suffix is a direction marker, not part of the number.
        $clean = (string) preg_replace('/\s*(CR|DR)\.?$/i', '', trim($clean));

        if ($thousandsSeparator !== null && $thousandsSeparator !== '') {
            $clean = str_replace($thousandsSeparator, '', $clean);
        }

        // Non-breaking and ordinary spaces survive as grouping in some exports;
        // they are never significant inside a number.
        $clean = (string) preg_replace('/[\p{Zs}\s]/u', '', $clean);

        if ($decimalSeparator !== '.') {
            $clean = str_replace($decimalSeparator, '.', $clean);
        }

        return ($negative ? '-' : '') . $clean;
    }
}
