<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The customer workspace's loyalty and EMI tabs and the per-customer history
 * must show each row on exactly one page when the sort values tie.
 */
class CustomerSubListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_loyalty_tab_with_equal_points_shows_each_customer_on_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))->map(fn ($n) => $this->createCustomer($shop->id, [
            'first_name' => 'Loyal', 'last_name' => 'Tie ' . $n, 'loyalty_points' => 100,
        ])->id)->all());
        $this->tieAndScramble('customers', $ids, ['loyalty_points' => 100]);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/customers?view=loyalty&loyalty_page=' . $p)->assertOk()
            ->viewData('loyaltyData')['loyaltyCustomers'])->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'loyalty');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public function test_emi_tab_with_equal_created_at_shows_each_plan_on_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);

            return collect(range(1, 45))->map(function () use ($shop, $customer) {
                $invoice = new Invoice();
                $invoice->forceFill(['shop_id' => $shop->id, 'invoice_number' => 'INV-PG-' . uniqid(), 'customer_id' => $customer->id,
                    'gold_rate' => 6000, 'subtotal' => 10000, 'gst' => 300, 'total' => 10300, 'status' => 'finalized'])->save();
                $plan = new InstallmentPlan(['total_amount' => 12000, 'principal_amount' => 10000, 'total_payable' => 10000, 'remaining_amount' => 8000, 'emi_amount' => 1000, 'total_emis' => 12,
                    'emis_paid' => 2, 'status' => 'active', 'next_due_date' => now()->addMonth()]);
                $plan->forceFill(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id])->save();

                return $plan->id;
            })->all();
        });
        $this->tieAndScramble('installment_plans', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/customers?view=emi&emi_page=' . $p)->assertOk()
            ->viewData('installmentData')['installmentPlans'])->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'emi tab');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public function test_customer_history_repairs_with_equal_created_at_show_each_row_on_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        [$customer, $ids] = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);

            return [$customer, collect(range(1, 60))->map(fn ($n) => Repair::create([
                'shop_id' => $shop->id, 'customer_id' => $customer->id, 'item_description' => 'Chain ' . $n,
                'description' => 'Clasp', 'gross_weight' => 8.5, 'estimated_cost' => 450, 'status' => 'received',
            ])->id)->all()];
        });
        $this->tieAndScramble('repairs', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get("/customers/{$customer->id}/history?tab=repairs&page=" . $p)->assertOk()
            ->viewData('rows'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 20, 'history repairs');
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $page(1));
    }
}
