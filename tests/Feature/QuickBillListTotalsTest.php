<?php

namespace Tests\Feature;

use App\Models\QuickBill;
use App\Models\Shop;
use App\Models\User;
use App\Services\QuickBillService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The two money totals above the quick bill list (web and app): today's sales
 * and what customers still owe. Only ISSUED bills count — a draft is not a
 * sale yet and nobody owes its amount; a void bill never counts.
 */
class QuickBillListTotalsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0: User, 1: float, 2: float} owner, issued total today, issued due */
    private function billsOfEveryKind(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();

        return TenantContext::runFor((int) $shop->id, function () use ($owner, $shop) {
            $svc = app(QuickBillService::class);
            $bill = fn (string $save, array $payments, float $rate) => $svc->create($shop, $owner, [
                'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0,
                'customer_name' => 'Walk-in', 'save_action' => $save,
                'items' => [['description' => 'Gold ring', 'metal_type' => 'gold', 'net_weight' => 1, 'rate' => $rate]],
                'payments' => $payments,
            ]);

            $paid = $bill('issue', [['payment_mode' => 'cash', 'amount' => 1000]], 1000);
            $part = $bill('issue', [['payment_mode' => 'cash', 'amount' => 1500]], 2000);
            $bill('draft', [], 50000);
            $svc->void($bill('issue', [['payment_mode' => 'cash', 'amount' => 700]], 700), $owner, 'test');

            $issued = QuickBill::whereIn('id', [$paid->id, $part->id])->get();
            $this->assertSame(['issued', 'issued'], $issued->pluck('status')->all());

            return [$owner, (float) $issued->sum('total_amount'), (float) $issued->sum('due_amount')];
        });
    }

    public function test_the_app_list_counts_only_issued_bills_in_todays_sales_and_outstanding(): void
    {
        [$owner, $issuedTotal, $issuedDue] = $this->billsOfEveryKind();
        Sanctum::actingAs($owner);

        $stats = $this->getJson('/api/mobile/quick-bills')->assertOk()->json('stats');

        $this->assertEquals(3000.0, $issuedTotal);
        $this->assertEquals($issuedTotal, (float) $stats['today_total']);
        $this->assertEquals(500.0, $issuedDue);
        $this->assertEquals($issuedDue, (float) $stats['outstanding_total']);
        $this->assertSame(1, $stats['draft_count']); // drafts still show as a count
    }

    public function test_the_web_list_counts_only_issued_bills_in_todays_sales_and_outstanding(): void
    {
        [$owner, $issuedTotal, $issuedDue] = $this->billsOfEveryKind();

        $response = TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->get(route('quick-bills.index'))->assertOk());

        $stats = $response->viewData('stats');
        $this->assertEquals($issuedTotal, (float) $stats['today_total']);
        $this->assertEquals($issuedDue, (float) $stats['outstanding_total']);
        $response->assertSee("Today's sales", false); // static label text, not escaped
    }
}
