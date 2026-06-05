<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Definition\ColumnType;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Maps a native typed value + {@see ColumnType} to a human display string for
 * the PDF and Screen renderers (frozen §4.2, §5.2). This is the ONLY place that
 * applies ₹, Indian digit grouping, and pretty dates.
 *
 * CSV and Excel must NOT use this class — they emit raw/typed values (ISO dates,
 * decimals with '.', no grouping, booleans as true/false, real numeric cells).
 * See {@see CsvRenderer} and {@see ExcelRenderer}.
 *
 * Masking is applied UPSTREAM by the dataset service; the formatter only shapes
 * the already-resolved value it receives.
 */
final class ValueFormatter
{
    private const RUPEE = '₹';

    /** Pretty display string for PDF / Screen. Null/blank values render as "". */
    public function format(mixed $value, ColumnType $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            ColumnType::String   => $this->scalarToString($value),
            ColumnType::Integer  => $this->groupIndian((string) (int) $value),
            ColumnType::Decimal  => $this->money($value, withSymbol: false),
            ColumnType::Money    => $this->money($value, withSymbol: true),
            ColumnType::Weight   => $this->weight($value),
            ColumnType::Percent  => $this->percent($value),
            ColumnType::Date     => $this->date($value),
            ColumnType::DateTime => $this->dateTime($value),
            ColumnType::Boolean  => $this->boolean($value) ? 'Yes' : 'No',
        };
    }

    /** Money/Decimal → 2dp, Indian grouping; Money is prefixed with ₹. */
    private function money(mixed $value, bool $withSymbol): string
    {
        $number = $this->toFloat($value);
        $negative = $number < 0;
        $formatted = $this->groupIndianDecimal(abs($number), 2);

        $body = ($withSymbol ? self::RUPEE . "\u{00A0}" : '') . $formatted;

        return $negative ? '-' . $body : $body;
    }

    /** Weight → grams with 3 decimal places, Indian grouping on the integer part. */
    private function weight(mixed $value): string
    {
        $number = $this->toFloat($value);
        $negative = $number < 0;
        $formatted = $this->groupIndianDecimal(abs($number), 3);

        return ($negative ? '-' : '') . $formatted;
    }

    /** Percent → 2dp with a trailing % sign, e.g. "12.50%". */
    private function percent(mixed $value): string
    {
        $number = $this->toFloat($value);

        return number_format($number, 2, '.', '') . '%';
    }

    private function date(mixed $value): string
    {
        return $this->toCarbon($value)?->format('d M Y') ?? $this->scalarToString($value);
    }

    private function dateTime(mixed $value): string
    {
        return $this->toCarbon($value)?->format('d M Y, g:i A') ?? $this->scalarToString($value);
    }

    /**
     * Group a whole-number string in the Indian system (last 3 digits, then
     * groups of 2): 1234567 → 12,34,567. Handles a leading sign.
     */
    private function groupIndian(string $digits): string
    {
        $sign = '';
        if (str_starts_with($digits, '-')) {
            $sign = '-';
            $digits = substr($digits, 1);
        }

        if (strlen($digits) <= 3) {
            return $sign . $digits;
        }

        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return $sign . $rest . ',' . $last3;
    }

    /** Indian-grouped fixed-decimal string, e.g. 1234567.5 → 12,34,567.50. */
    private function groupIndianDecimal(float $number, int $decimals): string
    {
        $fixed = number_format($number, $decimals, '.', '');
        [$intPart, $fracPart] = array_pad(explode('.', $fixed), 2, '');

        $grouped = $this->groupIndian($intPart);

        return $decimals > 0 ? $grouped . '.' . $fracPart : $grouped;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 't'], true);
        }

        return (bool) $value;
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return (float) $value;
    }

    private function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return \Carbon\Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function scalarToString(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'scalarToString'], $value));
        }

        return (string) $value;
    }
}
