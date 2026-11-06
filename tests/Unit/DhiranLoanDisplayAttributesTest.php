<?php

namespace Tests\Unit;

use App\Models\Dhiran\DhiranLoan;
use PHPUnit\Framework\TestCase;

/**
 * Dhiran views read $loan->total_outstanding and $loan->ltv_percent, which did not exist on the model (the method is
 * totalOutstanding(), the column ltv_ratio_applied). Every Total Outstanding (dashboard, loan list, borrower loans,
 * loan page, the Close Loan confirmation) showed ₹0.00 and the loan page and printed receipt showed LTV 0.0%.
 */
class DhiranLoanDisplayAttributesTest extends TestCase
{
    public function test_views_get_the_real_outstanding_total_and_applied_ltv(): void
    {
        $loan = new DhiranLoan();
        $loan->forceFill([
            'outstanding_principal' => '90000.00',
            'outstanding_interest' => '1350.00',
            'outstanding_penalty' => '25.50',
            'ltv_ratio_applied' => '75.00',
        ]);

        $this->assertSame(91375.5, $loan->total_outstanding);
        $this->assertEquals(75.0, $loan->ltv_percent);
    }
}
