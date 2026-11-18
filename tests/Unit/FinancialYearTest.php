<?php

namespace Tests\Unit;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The Indian financial year (1 April – 31 March, IST): the one rule every year-aware feature uses. */
class FinancialYearTest extends TestCase
{
    public function test_the_last_second_of_march_belongs_to_the_closing_year(): void
    {
        $fy = FinancialYear::forInstant(CarbonImmutable::parse('2027-03-31 23:59:59', 'Asia/Kolkata'));

        $this->assertSame('2026-27', $fy->label());
    }

    public function test_the_first_second_of_april_starts_the_new_year(): void
    {
        $fy = FinancialYear::forInstant(CarbonImmutable::parse('2027-04-01 00:00:00', 'Asia/Kolkata'));

        $this->assertSame('2027-28', $fy->label());
        $this->assertSame('27-28', $fy->short());
    }

    public function test_an_instant_in_another_zone_is_judged_by_indian_time(): void
    {
        // 18:30 UTC on 31 March is already 00:00 on 1 April in India.
        $this->assertSame('2027-28', FinancialYear::forInstant(CarbonImmutable::parse('2027-03-31 18:30:00', 'UTC'))->label());
        $this->assertSame('2026-27', FinancialYear::forInstant(CarbonImmutable::parse('2027-03-31 18:29:59', 'UTC'))->label());
    }

    public function test_a_plain_date_needs_no_zone(): void
    {
        $this->assertSame('2026-27', FinancialYear::forDate('2027-03-31')->label());
        $this->assertSame('2027-28', FinancialYear::forDate('2027-04-01')->label());
        $this->assertSame('2026-27', FinancialYear::forDate('2026-04-01')->label());
    }

    public function test_bounds_are_the_first_of_april_and_the_last_of_march(): void
    {
        $fy = FinancialYear::fromLabel('2026-27');

        $this->assertSame('2026-04-01', $fy->startDate());
        $this->assertSame('2027-03-31', $fy->endDate());
        $this->assertTrue($fy->contains('2026-04-01'));
        $this->assertTrue($fy->contains('2027-03-31'));
        $this->assertFalse($fy->contains('2026-03-31'));
        $this->assertFalse($fy->contains('2027-04-01'));
    }

    public function test_neighbours_and_ordering(): void
    {
        $fy = FinancialYear::fromLabel('2026-27');

        $this->assertSame('2027-28', $fy->next()->label());
        $this->assertSame('2025-26', $fy->previous()->label());
        $this->assertTrue($fy->isBefore($fy->next()));
        $this->assertFalse($fy->next()->isBefore($fy));
        $this->assertTrue($fy->equals(FinancialYear::fromLabel('2026-27')));
    }

    public function test_the_turn_of_a_century_keeps_two_digit_short_form(): void
    {
        $fy = FinancialYear::forDate('2099-04-01');

        $this->assertSame('2099-00', $fy->label());
        $this->assertSame('99-00', $fy->short());
        $this->assertSame('2100-01', $fy->next()->label());
    }

    /** @dataProvider badLabels */
    public function test_malformed_labels_are_refused(string $label): void
    {
        $this->expectException(InvalidArgumentException::class);
        FinancialYear::fromLabel($label);
    }

    public static function badLabels(): array
    {
        return [
            'gap of two years' => ['2026-28'],
            'short form'       => ['26-27'],
            'slash'            => ['2026/27'],
            'empty'            => [''],
            'words'            => ['FY 2026-27'],
            'backwards'        => ['2026-25'],
        ];
    }
}
