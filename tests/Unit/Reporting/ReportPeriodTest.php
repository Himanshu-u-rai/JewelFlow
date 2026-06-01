<?php

namespace Tests\Unit\Reporting;

use App\Reporting\Money;
use App\Reporting\ReportPeriod;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    public function test_month_clamps_out_of_range_input(): void
    {
        $period = ReportPeriod::month(2026, 13);
        // Month clamps to 12 → December 2026.
        $this->assertSame('2026-12-01', $period->start()->toDateString());
        $this->assertSame('2026-12-31', $period->end()->toDateString());
    }

    public function test_day_boundaries_are_inclusive(): void
    {
        $period = ReportPeriod::day('2026-03-15');
        $this->assertSame('2026-03-15 00:00:00', $period->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15 23:59:59', $period->end()->format('Y-m-d H:i:s'));
    }

    public function test_range_falls_back_and_never_inverts(): void
    {
        // Inverted range: end before start → end is clamped to start's day.
        $period = ReportPeriod::range('2026-03-10', '2026-03-01');
        $this->assertTrue($period->end()->greaterThanOrEqualTo($period->start()));
    }

    public function test_bad_date_string_does_not_throw(): void
    {
        $period = ReportPeriod::day('not-a-date');
        // Falls back to today without raising.
        $this->assertNotNull($period->start());
    }

    public function test_money_aggregation_has_no_float_drift(): void
    {
        $sum = Money::zero();
        // 0.1 + 0.2 in float is famously 0.30000000000000004; paisa integers are exact.
        $sum = $sum->add(Money::fromRupees(0.1))->add(Money::fromRupees(0.2));
        $this->assertSame(30, $sum->paisa());
        $this->assertSame(0.3, $sum->rupees());
    }
}
