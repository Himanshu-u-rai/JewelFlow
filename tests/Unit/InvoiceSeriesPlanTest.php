<?php

namespace Tests\Unit;

use App\Services\InvoiceSeriesPlan;
use App\Support\FinancialYear;
use DomainException as ValidationException; // the pure rule throws DomainException; apply() turns it into a form error
use PHPUnit\Framework\TestCase;

/** Every transition between a continuous and a yearly invoice series, in the year 2026-27. */
class InvoiceSeriesPlanTest extends TestCase
{
    private function decide(?string $from, ?string $until, ?string $choice, bool $billed, bool $token = true): array
    {
        return InvoiceSeriesPlan::decide(FinancialYear::fromLabel('2026-27'), $from, $until, $choice, $billed, $token);
    }

    /** @dataProvider transitions */
    public function test_transition(?string $from, ?string $until, ?string $choice, bool $billed, ?string $wantFrom, ?string $wantUntil): void
    {
        $this->assertSame(['from' => $wantFrom, 'until' => $wantUntil], $this->decide($from, $until, $choice, $billed));
    }

    public static function transitions(): array
    {
        return [
            // continuous → yearly
            'start now: nothing billed this year'      => [null, null, 'yearly', false, '2026-27', null],
            'start next April: already billed'         => [null, null, 'yearly', true, '2027-28', null],
            'already planned stays planned'            => ['2027-28', null, 'yearly', true, '2027-28', null],
            'already running stays running'            => ['2025-26', null, 'yearly', true, '2025-26', null],
            'running with planned end: end cancelled'  => ['2025-26', '2027-28', 'yearly', true, '2025-26', null],
            'ended earlier: restarts next April'       => ['2024-25', '2025-26', 'yearly', true, '2027-28', null],
            'ended earlier: restarts now if unbilled'  => ['2024-25', '2025-26', 'yearly', false, '2026-27', null],
            // yearly → continuous
            'running and billed: ends next April'      => ['2025-26', null, 'continuous', true, '2025-26', '2027-28'],
            'running, unbilled this year: ends now'    => ['2025-26', null, 'continuous', false, '2025-26', '2026-27'],
            'started this year, unbilled: cleared'     => ['2026-27', null, 'continuous', false, null, null],
            'planned for next year: cleared'           => ['2027-28', null, 'continuous', true, null, null],
            'already continuous: nothing'              => [null, null, 'continuous', true, null, null],
            'end already planned: kept'                => ['2025-26', '2027-28', 'continuous', true, '2025-26', '2027-28'],
            // no choice sent: unchanged
            'no choice keeps running series'           => ['2025-26', null, null, true, '2025-26', null],
            'no choice keeps continuous'               => [null, null, null, true, null, null],
        ];
    }

    public function test_choosing_yearly_without_the_year_token_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->decide(null, null, 'yearly', false, token: false);
    }

    public function test_keeping_a_running_yearly_series_without_the_token_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->decide('2025-26', null, null, true, token: false);
    }

    public function test_a_planned_yearly_series_also_needs_the_token(): void
    {
        $this->expectException(ValidationException::class);
        $this->decide('2027-28', null, null, true, token: false);
    }

    public function test_dropping_the_token_is_refused_while_this_years_series_is_still_yearly(): void
    {
        // Ending the yearly series next April: this year still shows the year, so the token is still needed.
        $this->expectException(ValidationException::class);
        $this->decide('2025-26', null, 'continuous', true, token: false);
    }

    public function test_dropping_the_token_with_no_yearly_series_left_is_fine(): void
    {
        $this->assertSame(['from' => null, 'until' => null], $this->decide('2027-28', null, 'continuous', true, token: false));
    }
}
