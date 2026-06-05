<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * FY-first preset resolution (frozen §17). FY starts 1 April; presets resolve to
 * explicit, stamped, timezone-correct ranges.
 */
class FilterResolverTest extends TestCase
{
    private FilterResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new FilterResolver(4); // explicit FY start, no config() needed
    }

    private function at(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_this_fy_after_april(): void
    {
        $p = $this->resolver->resolve(DatePreset::ThisFy, now: $this->at('2026-06-04'));
        $this->assertSame('2026-04-01', $p->from->format('Y-m-d'));
        $this->assertSame('2027-03-31', $p->to->format('Y-m-d'));
        $this->assertSame('2026-27', $p->fyName);
        $this->assertSame('FY 2026-27', $p->label);
    }

    public function test_this_fy_before_april_uses_prior_start_year(): void
    {
        $p = $this->resolver->resolve(DatePreset::ThisFy, now: $this->at('2026-02-15'));
        $this->assertSame('2025-04-01', $p->from->format('Y-m-d'));
        $this->assertSame('2026-03-31', $p->to->format('Y-m-d'));
        $this->assertSame('2025-26', $p->fyName);
    }

    public function test_last_fy(): void
    {
        $p = $this->resolver->resolve(DatePreset::LastFy, now: $this->at('2026-06-04'));
        $this->assertSame('2025-04-01', $p->from->format('Y-m-d'));
        $this->assertSame('2026-03-31', $p->to->format('Y-m-d'));
    }

    public function test_this_and_last_month(): void
    {
        $this->assertSame('2026-06-01', $this->resolver->resolve(DatePreset::ThisMonth, now: $this->at('2026-06-04'))->from->format('Y-m-d'));
        $this->assertSame('2026-06-30', $this->resolver->resolve(DatePreset::ThisMonth, now: $this->at('2026-06-04'))->to->format('Y-m-d'));
        $this->assertSame('2026-05-01', $this->resolver->resolve(DatePreset::LastMonth, now: $this->at('2026-06-04'))->from->format('Y-m-d'));
        $this->assertSame('2026-05-31', $this->resolver->resolve(DatePreset::LastMonth, now: $this->at('2026-06-04'))->to->format('Y-m-d'));
    }

    public function test_today_is_single_day(): void
    {
        $p = $this->resolver->resolve(DatePreset::Today, now: $this->at('2026-06-04'));
        $this->assertSame('2026-06-04', $p->from->format('Y-m-d'));
        $this->assertSame('2026-06-04', $p->to->format('Y-m-d'));
        $this->assertSame('04 Jun 2026', $p->label);
    }

    public function test_fy_aligned_quarters(): void
    {
        // June is in the Apr–Jun quarter of FY 2026-27.
        $q = $this->resolver->resolve(DatePreset::ThisQuarter, now: $this->at('2026-06-04'));
        $this->assertSame('2026-04-01', $q->from->format('Y-m-d'));
        $this->assertSame('2026-06-30', $q->to->format('Y-m-d'));

        // Previous quarter is Jan–Mar 2026.
        $lq = $this->resolver->resolve(DatePreset::LastQuarter, now: $this->at('2026-06-04'));
        $this->assertSame('2026-01-01', $lq->from->format('Y-m-d'));
        $this->assertSame('2026-03-31', $lq->to->format('Y-m-d'));
    }

    public function test_named_fy(): void
    {
        $p = $this->resolver->resolve(DatePreset::NamedFy, fyName: '2024-25', now: $this->at('2026-06-04'));
        $this->assertSame('2024-04-01', $p->from->format('Y-m-d'));
        $this->assertSame('2025-03-31', $p->to->format('Y-m-d'));
    }

    public function test_fy_to_date(): void
    {
        $p = $this->resolver->resolve(DatePreset::FyToDate, now: $this->at('2026-06-04'));
        $this->assertSame('2026-04-01', $p->from->format('Y-m-d'));
        $this->assertSame('2026-06-04', $p->to->format('Y-m-d'));
        $this->assertSame('FY 2026-27 to date', $p->label);
    }

    public function test_custom_requires_valid_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolve(DatePreset::Custom, customFrom: $this->at('2026-06-10'), customTo: $this->at('2026-06-01'));
    }

    public function test_invalid_named_fy_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->namedFy('not-a-year');
    }
}
