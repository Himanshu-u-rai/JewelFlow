<?php

namespace Tests\Unit\Reporting\Render;

use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Render\ValueFormatter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The pretty-display formatter for PDF/Screen (frozen §4.2). Indian digit
 * grouping, ₹ on Money, 3dp grams on Weight, %, dates, booleans, and null.
 */
class ValueFormatterTest extends TestCase
{
    private ValueFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ValueFormatter();
    }

    public function test_null_and_empty_format_to_empty_string(): void
    {
        $this->assertSame('', $this->f->format(null, T::Money));
        $this->assertSame('', $this->f->format(null, T::String));
        $this->assertSame('', $this->f->format('', T::String));
    }

    public function test_money_uses_rupee_and_indian_grouping(): void
    {
        $out = $this->f->format(1234567.5, T::Money);
        // Non-breaking space between ₹ and the number.
        $this->assertStringContainsString('₹', $out);
        $this->assertStringContainsString('12,34,567.50', $out);
    }

    public function test_money_handles_small_amounts_and_negatives(): void
    {
        $this->assertStringContainsString('500.00', $this->f->format(500, T::Money));
        $this->assertStringStartsWith('-', $this->f->format(-1500.25, T::Money));
        $this->assertStringContainsString('1,500.25', $this->f->format(-1500.25, T::Money));
    }

    public function test_decimal_groups_without_symbol(): void
    {
        $out = $this->f->format(1234567.5, T::Decimal);
        $this->assertSame('12,34,567.50', $out);
        $this->assertStringNotContainsString('₹', $out);
    }

    public function test_integer_uses_indian_grouping(): void
    {
        $this->assertSame('12,34,567', $this->f->format(1234567, T::Integer));
        $this->assertSame('999', $this->f->format(999, T::Integer));
        $this->assertSame('1,000', $this->f->format(1000, T::Integer));
    }

    public function test_weight_three_decimals(): void
    {
        $this->assertSame('12.345', $this->f->format(12.345, T::Weight));
        $this->assertSame('8.500', $this->f->format(8.5, T::Weight));
        $this->assertSame('1,00,000.000', $this->f->format(100000, T::Weight));
    }

    public function test_percent_two_decimals_with_sign(): void
    {
        $this->assertSame('12.50%', $this->f->format(12.5, T::Percent));
        $this->assertSame('3.00%', $this->f->format(3, T::Percent));
    }

    public function test_date_format(): void
    {
        $this->assertSame('12 Apr 2025', $this->f->format(Carbon::create(2025, 4, 12), T::Date));
        $this->assertSame('01 Jan 2026', $this->f->format('2026-01-01', T::Date));
    }

    public function test_datetime_format(): void
    {
        $dt = Carbon::create(2025, 4, 12, 15, 5, 0);
        $this->assertSame('12 Apr 2025, 3:05 PM', $this->f->format($dt, T::DateTime));
    }

    public function test_boolean_yes_no(): void
    {
        $this->assertSame('Yes', $this->f->format(true, T::Boolean));
        $this->assertSame('No', $this->f->format(false, T::Boolean));
        $this->assertSame('Yes', $this->f->format(1, T::Boolean));
        $this->assertSame('No', $this->f->format(0, T::Boolean));
    }

    public function test_string_passes_through(): void
    {
        $this->assertSame('INV-001', $this->f->format('INV-001', T::String));
    }
}
