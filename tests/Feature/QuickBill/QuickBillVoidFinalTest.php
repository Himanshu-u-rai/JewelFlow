<?php

namespace Tests\Feature\QuickBill;

use App\Models\QuickBill;
use App\Services\QuickBillService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N2 (2026-11-13): void locked the bill, edit/issue did not and decided on the copy loaded with the page. An
 * edit landing after a void wrote status "issued" back (voided_at still set): a voided bill revived with new values.
 */
class QuickBillVoidFinalTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_an_edit_from_a_page_loaded_before_the_void_cannot_revive_the_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor((int) $shop->id, function () use ($owner, $shop) {
            $svc = app(QuickBillService::class);
            $payload = fn (float $rate) => [
                'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0,
                'customer_name' => 'Walk-in', 'save_action' => 'issue',
                'items' => [['description' => 'Gold ring', 'metal_type' => 'gold', 'net_weight' => 1, 'rate' => $rate]],
                'payments' => [['payment_mode' => 'cash', 'amount' => $rate]],
            ];
            $bill = $svc->create($shop, $owner, $payload(1000));
            $stale = $bill->fresh();
            $svc->void($bill, $owner, 'wrong customer');

            try {
                $svc->update($stale, $shop, $owner, $payload(5000));
                $this->fail('A voided bill was edited back to issued.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('bill', $e->errors());
            }

            $fresh = QuickBill::findOrFail($bill->id);
            $this->assertSame(QuickBill::STATUS_VOID, $fresh->status);
            $this->assertEqualsWithDelta(0, (float) $fresh->paid_amount, 0.001);
            $this->assertSame(0, $fresh->payments()->count());
        });
    }
}
