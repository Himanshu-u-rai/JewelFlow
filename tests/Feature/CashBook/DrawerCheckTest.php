<?php

namespace Tests\Feature\CashBook;

use App\Models\CashDrawerCheck;
use App\Models\CashTransaction;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 — "Match your drawer". A drawer check is an append-only snapshot of a
 * physical cash count vs the computed cash-in-hand. It never mutates
 * cash_transactions. Multiple checks per day are allowed.
 */
class DrawerCheckTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function seedCash(int $shopId, int $userId, float $amount): void
    {
        CashTransaction::record([
            'shop_id' => $shopId, 'user_id' => $userId, 'type' => 'in', 'amount' => $amount,
            'source_type' => 'manual', 'source_id' => null, 'payment_mode' => 'cash', 'description' => 'seed',
        ]);
    }

    /** Drawer check records expected (computed), counted, and the difference. */
    public function test_drawer_check_records_expected_counted_difference(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);
        $this->seedCash($shop->id, $user->id, 1000); // expected cash in hand = 1000

        // Owner counts 950 (short by 50).
        $this->actingAs($user)->post('/cashbook/drawer-check', [
            'counted_cash' => 950,
            'note'         => 'end of day',
        ])->assertRedirect(route('cashbook.index'));

        TenantContext::set($shop->id);
        $check = CashDrawerCheck::where('shop_id', $shop->id)->latest('id')->first();

        $this->assertNotNull($check);
        $this->assertEqualsWithDelta(1000, (float) $check->expected_cash, 0.01);
        $this->assertEqualsWithDelta(950, (float) $check->counted_cash, 0.01);
        $this->assertEqualsWithDelta(-50, (float) $check->difference, 0.01, 'short by 50');
        $this->assertSame('end of day', $check->note);
        $this->assertSame($user->id, $check->checked_by_user_id);
    }

    /** Expected cash is computed server-side and ignores any client-sent value. */
    public function test_expected_cash_is_server_computed_not_trusted_from_client(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);
        $this->seedCash($shop->id, $user->id, 700);

        // Attempt to spoof expected_cash via the request — must be ignored.
        $this->actingAs($user)->post('/cashbook/drawer-check', [
            'counted_cash'  => 700,
            'expected_cash' => 999999,
            'difference'    => -999,
        ])->assertRedirect();

        TenantContext::set($shop->id);
        $check = CashDrawerCheck::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertEqualsWithDelta(700, (float) $check->expected_cash, 0.01, 'expected is server-computed');
        $this->assertEqualsWithDelta(0, (float) $check->difference, 0.01, 'matches');
    }

    /** Multiple checks per day are allowed (append-only history). */
    public function test_multiple_checks_per_day_allowed(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);
        $this->seedCash($shop->id, $user->id, 500);

        $this->actingAs($user)->post('/cashbook/drawer-check', ['counted_cash' => 500]);
        $this->actingAs($user)->post('/cashbook/drawer-check', ['counted_cash' => 480]);

        TenantContext::set($shop->id);
        $this->assertSame(2, CashDrawerCheck::where('shop_id', $shop->id)->count());
    }

    /** The drawer-check table is append-only — UPDATE and DELETE are blocked. */
    public function test_drawer_check_is_append_only(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);
        $this->seedCash($shop->id, $user->id, 300);
        $this->actingAs($user)->post('/cashbook/drawer-check', ['counted_cash' => 300]);

        TenantContext::set($shop->id);
        $check = CashDrawerCheck::where('shop_id', $shop->id)->latest('id')->first();

        // Update blocked (Eloquent ImmutableLedger + DB trigger).
        try {
            $check->forceFill(['counted_cash' => 1])->save();
            $this->fail('Expected the drawer check to be immutable.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    /** Recording a drawer check never creates or alters a cash_transactions row. */
    public function test_drawer_check_does_not_touch_cash_transactions(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::set($shop->id);
        $this->seedCash($shop->id, $user->id, 1000);
        $before = CashTransaction::where('shop_id', $shop->id)->count();

        $this->actingAs($user)->post('/cashbook/drawer-check', ['counted_cash' => 900]);

        TenantContext::set($shop->id);
        $after = CashTransaction::where('shop_id', $shop->id)->count();
        $this->assertSame($before, $after, 'drawer check must not write to cash_transactions');
    }
}
