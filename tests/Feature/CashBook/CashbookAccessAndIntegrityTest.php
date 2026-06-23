<?php

namespace Tests\Feature\CashBook;

use App\Models\CashTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Cashbook / Accounting (Module 16). Payment-mode recording, per-mode balances,
 * the drawer check, and mobile shop-scoping are already covered; this closes the
 * untested web surface: the cash.view / cash.create permission gates, the web
 * cashbook index shop-isolation, a valid web manual entry, and the append-only
 * cash_transactions ledger invariant (ImmutableLedger + DB trigger).
 */
class CashbookAccessAndIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    private function cashRow(Shop $shop, string $description, float $amount = 1000.0): CashTransaction
    {
        return TenantContext::runFor($shop->id, fn () => CashTransaction::record([
            'shop_id' => $shop->id, 'type' => 'in', 'amount' => $amount,
            'source_type' => 'counter_collection', 'payment_mode' => 'cash', 'description' => $description,
        ]));
    }

    // ── Permission & guest gating ───────────────────────────────────────────

    public function test_cashbook_index_renders_for_cash_view_user(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['cash.view'], '9822200001');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/cashbook'))->assertOk();
    }

    public function test_user_without_cash_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9822200002');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/cashbook'))->assertForbidden();
    }

    public function test_user_without_cash_create_cannot_open_or_store(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['cash.view'], '9822200003'); // view only

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/cashbook/create'))->assertForbidden();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->post(self::ERP . '/cashbook', [
                'type' => 'in', 'amount' => 100, 'source_type' => 'counter_collection',
            ]))->assertForbidden();
    }

    public function test_guest_cannot_reach_cashbook(): void
    {
        $res = $this->get(self::ERP . '/cashbook');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    // ── Web manual entry + shop isolation ───────────────────────────────────

    public function test_manual_cash_in_records_a_shop_scoped_row(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $clerk = $this->userWithPerms($shop, ['cash.view', 'cash.create'], '9822200004');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($clerk)
            ->post(self::ERP . '/cashbook', [
                'type' => 'in', 'amount' => 2500, 'source_type' => 'counter_collection', 'payment_mode' => 'upi',
            ]))->assertRedirect();

        $row = CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('source_type', 'counter_collection')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('in', $row->type);
        $this->assertSame('upi', $row->payment_mode);
        $this->assertEqualsWithDelta(2500.0, (float) $row->amount, 0.01);
    }

    public function test_cashbook_index_does_not_leak_another_shops_rows(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $this->cashRow($shopB, 'SHOPB-SECRET-MARKER', 91919.0);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/cashbook'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SHOPB-SECRET-MARKER', $html, 'must not surface another shop row');
        $this->assertStringNotContainsString('91,919', $html, 'must not surface another shop amount');
    }

    // ── Append-only ledger ──────────────────────────────────────────────────

    public function test_cash_transaction_is_append_only(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $row = $this->cashRow($shop, 'append-only probe');

        TenantContext::runFor($shop->id, function () use ($row) {
            try {
                $row->forceFill(['amount' => 999999])->save();
                $this->fail('a posted cash transaction must not be updatable');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
            }
            try {
                $row->delete();
                $this->fail('a posted cash transaction must not be deletable');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
            }
        });
    }
}
