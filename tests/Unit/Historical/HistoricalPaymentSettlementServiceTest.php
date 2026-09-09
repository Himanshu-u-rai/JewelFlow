<?php

namespace Tests\Unit\Historical;

use App\Services\Historical\HistoricalPaymentSettlementService;
use Tests\TestCase;

/**
 * Payment-row aggregation / settlement math, HISTORICAL-BATCH-3-UX-CONTRACT-V2
 * §7/§8 + the owner-locked mismatch-warning decision.
 */
class HistoricalPaymentSettlementServiceTest extends TestCase
{
    private HistoricalPaymentSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HistoricalPaymentSettlementService();
    }

    public function test_suggested_paid_total_sums_plain_amounts(): void
    {
        $this->assertSame(1500.0, $this->service->suggestedPaidTotal([1000.0, 500.0]));
    }

    public function test_suggested_paid_total_sums_array_rows(): void
    {
        $rows = [['amount' => 1000.0], ['amount' => 500.0]];
        $this->assertSame(1500.0, $this->service->suggestedPaidTotal($rows));
    }

    public function test_no_mismatch_within_one_paisa_rounding_tolerance(): void
    {
        $this->assertFalse($this->service->hasMismatch(1500.00, 1500.01));
    }

    public function test_mismatch_detected_when_override_disagrees_with_row_sum(): void
    {
        $this->assertTrue($this->service->hasMismatch(1500.00, 1800.00));
        $this->assertSame(300.0, $this->service->mismatchAmount(1500.00, 1800.00));
    }

    public function test_settle_routes_underpayment_to_outstanding(): void
    {
        $this->assertSame(
            ['outstanding' => 200.0, 'advance_credit' => 0.0],
            $this->service->settle(1000.0, 800.0)
        );
    }

    public function test_settle_routes_overpayment_to_advance_credit_never_negative_outstanding(): void
    {
        $this->assertSame(
            ['outstanding' => 0.0, 'advance_credit' => 200.0],
            $this->service->settle(1000.0, 1200.0)
        );
    }

    public function test_paid_status_unpaid_when_nothing_recorded(): void
    {
        $this->assertSame('unpaid', $this->service->paidStatus(1000.0, 0.0));
    }

    public function test_paid_status_partial_when_something_but_not_all_recorded(): void
    {
        $this->assertSame('partial', $this->service->paidStatus(1000.0, 400.0));
    }

    public function test_paid_status_full_when_fully_or_over_paid(): void
    {
        $this->assertSame('full', $this->service->paidStatus(1000.0, 1000.0));
        $this->assertSame('full', $this->service->paidStatus(1000.0, 1200.0));
    }
}
