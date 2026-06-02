<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\ReceivablesService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Receivables & liability pack (Phase 2 M3) — #8 Customer dues aging.
 * Locks the bucket math: outstanding on finalized invoices, aged by accounting
 * date, only positive balances counted; fully-paid/over-collected excluded.
 */
class ReceivablesReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invoice(int $shopId, ?int $customerId, float $total, int $ageDays): int
    {
        $dt = Carbon::now()->subDays($ageDays)->setTime(10, 0);
        return (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $total, 'discount' => 0, 'gst' => 0, 'gst_rate' => 0,
            'total' => $total, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => $dt, 'updated_at' => $dt, 'finalized_at' => $dt,
        ]);
    }

    private function pay(int $shopId, int $invoiceId, float $amount): void
    {
        DB::table('invoice_payments')->insert([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => 'cash', 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dues_aging_buckets_and_totals(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);

        // One unpaid invoice in each age bucket.
        $this->invoice($shop->id, $c->id, 1000, 10);                 // current (0-30)
        $this->invoice($shop->id, $c->id, 2000, 45);                 // 31-60
        $this->invoice($shop->id, $c->id, 3000, 75);                 // 61-90
        $this->invoice($shop->id, $c->id, 4000, 120);                // 90+
        // Partially paid: total 5000 - 1000 paid = 4000 outstanding (current).
        $partial = $this->invoice($shop->id, $c->id, 5000, 5);
        $this->pay($shop->id, $partial, 1000);
        // Fully paid → excluded.
        $paid = $this->invoice($shop->id, $c->id, 6000, 20);
        $this->pay($shop->id, $paid, 6000);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->duesAging($shop->id)
        );

        $this->assertEqualsWithDelta(5000.0, $data->bucketCurrent, 0.01, 'current = 1000 + 4000 partial');
        $this->assertEqualsWithDelta(2000.0, $data->bucket3160, 0.01);
        $this->assertEqualsWithDelta(3000.0, $data->bucket6190, 0.01);
        $this->assertEqualsWithDelta(4000.0, $data->bucket90plus, 0.01);
        $this->assertEqualsWithDelta(14000.0, $data->totalOutstanding, 0.01, 'fully-paid 6000 excluded');

        // Buckets must sum to the total (the core invariant).
        $this->assertEqualsWithDelta(
            $data->totalOutstanding,
            $data->bucketCurrent + $data->bucket3160 + $data->bucket6190 + $data->bucket90plus,
            0.01
        );

        // 5 unpaid/partial invoices across 1 customer.
        $this->assertSame(5, $data->invoiceCount);
        $this->assertSame(1, $data->customerCount);
        $this->assertEqualsWithDelta(14000.0, (float) $data->rows->sum('total'), 0.01,
            'per-customer rows sum to the total outstanding');
    }

    public function test_no_dues_when_all_paid(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);
        $inv = $this->invoice($shop->id, $c->id, 1000, 10);
        $this->pay($shop->id, $inv, 1000);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(ReceivablesService::class)->duesAging($shop->id)
        );

        $this->assertEqualsWithDelta(0.0, $data->totalOutstanding, 0.01);
        $this->assertSame(0, $data->invoiceCount);
        $this->assertTrue($data->rows->isEmpty());
    }
}
