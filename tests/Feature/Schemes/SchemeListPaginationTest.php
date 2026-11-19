<?php

namespace Tests\Feature\Schemes;

use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Schemes, scheme enrollments and EMI plans created in the same second must
 * each show on exactly one page of their list.
 */
class SchemeListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_schemes_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))->map(fn ($n) => Scheme::create([
            'name' => 'Scheme ' . $n, 'type' => 'gold_savings', 'is_active' => true,
            'start_date' => today(), 'total_installments' => 11,
        ])->id)->all());
        $this->tieAndScramble('schemes', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/schemes?page=' . $p)->assertOk()->viewData('schemes'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'schemes');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public function test_scheme_enrollments_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        [$scheme, $ids] = TenantContext::runFor($shop->id, function () use ($shop) {
            $scheme = Scheme::create(['name' => 'Gold plan', 'type' => 'gold_savings', 'is_active' => true,
                'start_date' => today(), 'total_installments' => 11]);

            return [$scheme, collect(range(1, 45))->map(function () use ($shop, $scheme) {
                $e = new SchemeEnrollment();
                $e->forceFill([
                    'shop_id' => $shop->id, 'scheme_id' => $scheme->id,
                    'customer_id' => $this->createCustomer($shop->id)->id,
                    'start_date' => now()->subMonth()->toDateString(), 'monthly_amount' => 1000,
                    'total_paid' => 2000, 'installments_paid' => 2, 'total_installments' => 11, 'status' => 'active',
                ])->save();

                return $e->id;
            })->all()];
        });
        $this->tieAndScramble('scheme_enrollments', $ids, ['created_at' => '2026-11-01 10:00:00']);

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get("/schemes/{$scheme->id}?page=" . $p)->assertOk()->viewData('enrollments'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'enrollments');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }

    public function test_installment_plans_with_the_same_created_at_each_appear_on_exactly_one_page(): void
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
            ->get('/installments?page=' . $p)->assertOk()->viewData('plans'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 15, 'installments');
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $page(1));
    }
}
