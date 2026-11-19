<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A shop's platform billing invoices issued in the same second must each show
 * on exactly one page of Settings > Plan & Billing and of /subscription.
 */
class BillingInvoiceListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: array<int, int>} */
    private function tenantWithInvoices(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $plan = $this->createPlan('retailer');
        $ids = [];
        foreach (range(1, 25) as $n) {
            $ids[] = DB::table('platform_invoices')->insertGetId([
                'shop_id' => $shop->id, 'plan_id' => $plan->id, 'invoice_number' => 'PI-' . $n, 'invoice_sequence' => $n,
                'billing_cycle' => 'monthly', 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-30',
                'amount_before_tax' => 100, 'gst_rate' => 18, 'gst_amount' => 18, 'total_amount' => 118,
                'status' => 'issued', 'issued_at' => '2026-11-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // issued_at is immutable by design: rows are tied at insert; only the physical order is shuffled (status is rewritten with its own value).
        $this->tieAndScramble('platform_invoices', $ids, ['status' => 'issued'], 'updated_at');
        rsort($ids);

        return [$owner, $shop, $ids];
    }

    public function test_settings_plan_and_billing_tab_lists_each_invoice_once(): void
    {
        $this->skipIfNotPostgres();
        $this->withoutVite();
        [$owner, , $ids] = $this->tenantWithInvoices();

        $page = fn (int $p) => $this->actingAs($owner)->get('/settings?tab=subscription&page=' . $p)
            ->assertOk()->viewData('subscriptionData')['invoices']->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 10, 'settings billing');
        $this->assertSame(array_slice($ids, 0, 10), $page(1));
    }

    public function test_subscription_status_page_lists_each_invoice_once(): void
    {
        $this->skipIfNotPostgres();
        $this->withoutVite();
        [$owner, $shop, $ids] = $this->tenantWithInvoices();
        // status() renders the list itself only for a shop that cannot reach the ERP.
        DB::table('shops')->where('id', $shop->id)->update(['access_mode' => 'read_only']);

        $page = fn (int $p) => $this->actingAs($owner)->get('/subscription?page=' . $p)
            ->assertOk()->viewData('invoices')->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 10, 'subscription status');
        $this->assertSame(array_slice($ids, 0, 10), $page(1));
    }
}
