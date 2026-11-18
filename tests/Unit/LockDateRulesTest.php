<?php

namespace Tests\Unit;

use App\Support\LockDateRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LockDateRulesTest extends TestCase
{
    private function at(string $ist): CarbonImmutable
    {
        return CarbonImmutable::parse($ist, 'Asia/Kolkata');
    }

    public function test_latest_lockable_is_yesterday_in_india(): void
    {
        $this->assertSame('2026-11-15', LockDateRules::latestLockable($this->at('2026-11-16 12:00')));
        // 00:30 IST is still the previous day in UTC; India's calendar decides.
        $this->assertSame('2026-11-16', LockDateRules::latestLockable($this->at('2026-11-17 00:30')->utc()));
        $this->assertSame('2026-11-16', LockDateRules::latestLockable($this->at('2026-11-17 23:59')));
    }

    public function test_parse_accepts_only_real_calendar_dates(): void
    {
        $this->assertSame('2024-02-29', LockDateRules::parse('2024-02-29'));
        foreach (['', 'x', '2026-02-30', '2026-1-5', '05-01-2026', '2026-04-31'] as $bad) {
            try {
                LockDateRules::parse($bad);
                $this->fail("accepted '{$bad}'");
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_last_financial_year_end(): void
    {
        $this->assertSame('2026-03-31', LockDateRules::lastFinancialYearEnd($this->at('2026-11-16 10:00')));
        $this->assertSame('2026-03-31', LockDateRules::lastFinancialYearEnd($this->at('2027-03-31 23:00')));
        $this->assertSame('2027-03-31', LockDateRules::lastFinancialYearEnd($this->at('2027-04-01 00:10')));
    }

    public function test_reason_must_have_ten_real_characters(): void
    {
        $this->assertTrue(LockDateRules::reasonIsEnough('Auditor request'));
        $this->assertFalse(LockDateRules::reasonIsEnough('   short   '));
        $this->assertFalse(LockDateRules::reasonIsEnough(''));
        $this->assertTrue(LockDateRules::reasonIsEnough('ठीक करना है गलती'));
    }
}
