<?php

namespace App\Services\Reporting\Definition;

/**
 * Presentation-agnostic value type (frozen §3.1/§5.2).
 * The dataset carries native typed values; each renderer formats per type —
 * CSV raw, Excel typed cells, PDF/Screen pretty (₹, Indian grouping, dates).
 */
enum ColumnType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';      // ₹ amount
    case Weight = 'weight';    // grams (fine/gross)
    case Percent = 'percent';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';

    /** Right-aligned, tabular-nums in PDF/screen (frozen §4.2). */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Integer, self::Decimal, self::Money, self::Weight, self::Percent => true,
            default => false,
        };
    }
}
