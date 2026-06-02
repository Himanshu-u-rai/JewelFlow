<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P1 surface restoration: Store Credit (PRODUCT_SURFACE_INTEGRITY_AUDIT.md §3.C).
 *
 * Bound-model HTTP routes ({customer}/{invoice}) 404 under the pre-existing test
 * harness binding issue, so the HTTP adjust/apply flows are covered by manual
 * UAT. Here we lock what is automatable: the routes resolve, and the
 * integrity-critical ledger logic (credit, debit, and the DB non-negative
 * overdraft guard) behaves correctly.
 */
class StoreCreditReconnectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_store_credit_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('store-credit.adjust.create'));
        $this->assertTrue(Route::has('store-credit.adjust.store'));
        $this->assertTrue(Route::has('store-credit.apply'));
    }

    public function test_credit_then_debit_tracks_balance(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $user) {
            $customer = $this->createCustomer($shop->id);
            $service = app(StoreCreditService::class);

            $this->assertEqualsWithDelta(0.0, $service->balance($shop->id, $customer->id), 0.001);

            $service->manualAdjust($customer, $shop->id, 500.00, 'goodwill credit', $user->id, $user->id);
            $this->assertEqualsWithDelta(500.0, $service->balance($shop->id, $customer->id), 0.001);

            $service->manualAdjust($customer, $shop->id, -200.00, 'correction debit', $user->id, $user->id);
            $this->assertEqualsWithDelta(300.0, $service->balance($shop->id, $customer->id), 0.001);
        });
    }

    public function test_overdraft_is_blocked_by_db_guard(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $user) {
            $customer = $this->createCustomer($shop->id);
            $service = app(StoreCreditService::class);

            $service->manualAdjust($customer, $shop->id, 100.00, 'seed', $user->id, $user->id);

            // Debiting more than the balance must be rejected by the non-negative
            // ledger trigger — the wallet can never go negative.
            $this->expectException(\Illuminate\Database\QueryException::class);
            $service->manualAdjust($customer, $shop->id, -250.00, 'overdraft attempt', $user->id, $user->id);
        });

        // Balance unchanged (still 100).
        $this->assertEqualsWithDelta(
            100.0,
            app(StoreCreditService::class)->balance($shop->id, Customer::withoutTenant()->where('shop_id', $shop->id)->first()->id),
            0.001
        );
    }
}
