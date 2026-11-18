<?php

namespace Tests\Unit;

use App\Support\CreditNoteDeadline;
use App\Support\FinancialYear;
use PHPUnit\Framework\TestCase;

/**
 * GST (CGST Act s.34(2), as amended 2022): a credit note for a supply may be issued only up to 30 November after
 * the end of the financial year of that supply. After that, a returned piece is bought back instead.
 */
class CreditNoteDeadlineTest extends TestCase
{
    public function test_the_deadline_is_30_november_after_the_bills_year_ends(): void
    {
        $this->assertSame('2026-11-30', CreditNoteDeadline::for(FinancialYear::fromLabel('2025-26')));
        $this->assertSame('2027-11-30', CreditNoteDeadline::for(FinancialYear::fromLabel('2026-27')));
    }

    public function test_the_last_day_is_still_allowed_and_the_next_is_not(): void
    {
        $fy = FinancialYear::fromLabel('2025-26');

        $this->assertTrue(CreditNoteDeadline::allows($fy, '2026-11-30'));
        $this->assertFalse(CreditNoteDeadline::allows($fy, '2026-12-01'));
    }

    public function test_a_bill_from_this_year_is_always_allowed(): void
    {
        $this->assertTrue(CreditNoteDeadline::allows(FinancialYear::fromLabel('2026-27'), '2027-03-31'));
        $this->assertTrue(CreditNoteDeadline::allows(FinancialYear::fromLabel('2026-27'), '2026-04-01'));
    }

    public function test_a_two_year_old_bill_is_refused(): void
    {
        $this->assertFalse(CreditNoteDeadline::allows(FinancialYear::fromLabel('2024-25'), '2026-11-16'));
    }

    public function test_the_refusal_names_the_year_the_deadline_and_the_alternative(): void
    {
        $message = CreditNoteDeadline::refusal(FinancialYear::fromLabel('2025-26'));

        $this->assertStringContainsString('2025-26', $message);
        $this->assertStringContainsString('30 November 2026', $message);
        $this->assertStringContainsString('old-gold', $message);
    }
}
