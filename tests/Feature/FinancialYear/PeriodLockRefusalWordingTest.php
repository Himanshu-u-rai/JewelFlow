<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StockPurchase;
use App\Models\User;
use App\Support\NotAvailable;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The period lock refuses everyone the same way; only the words differ. The owner, who set the lock, is told the
 * cutoff date; anyone else is told only that the entry can't be saved (owner's rule 2026-10-30: a refusal never
 * explains itself to a non-owner).
 */
class PeriodLockRefusalWordingTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase, SetsPeriodLock;

    private int $shopId;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        [$owner, $shop] = $this->createRetailerTenant();
        $this->shopId = (int) $shop->id;
        $this->users = ['owner' => $owner, 'manager' => $this->staff('manager', '9812400001'), 'cashier' => $this->staff('cashier', '9812400002')];
        // Every role may make and confirm purchases, so the lock is the only thing that can refuse them.
        foreach ($this->users as $user) {
            Role::withoutTenant()->findOrFail($user->role_id)->permissions()
                ->syncWithoutDetaching(Permission::whereIn('name', ['inventory.view', 'inventory.create', 'inventory.edit'])->pluck('id'));
        }
        $this->setLock($this->shopId);
    }

    private function staff(string $roleName, string $mobile): User
    {
        return TenantContext::runFor($this->shopId, function () use ($roleName, $mobile) {
            $role = (new Role())->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $this->shopId]);
            $role->save();

            return User::create([
                'name' => ucfirst($roleName), 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $this->shopId, 'role_id' => $role->id,
            ]);
        });
    }

    private function as(string $who, callable $fn)
    {
        return TenantContext::runFor($this->shopId, fn () => $fn($this->actingAs($this->users[$who])));
    }

    private function draftPurchase(string $date): StockPurchase
    {
        $purchase = new StockPurchase();
        $purchase->forceFill([
            'shop_id' => $this->shopId, 'supplier_name' => 'Acme', 'purchase_number' => 'PUR-'.uniqid(),
            'purchase_date' => $date, 'status' => 'draft', 'total_amount' => 0,
        ])->save();
        TenantContext::runFor($this->shopId, fn () => $purchase->lines()->create([
            'shop_id' => $this->shopId, 'line_type' => 'ornament', 'design' => 'Ring', 'metal_type' => 'gold',
            'purity' => 22, 'gross_weight' => 5, 'net_metal_weight' => 5,
        ]));

        return $purchase;
    }

    private function expected(string $who): string
    {
        return $who === 'owner' ? "Financial lock is active through {$this->lockDate()}." : NotAvailable::LOCKED_ENTRY;
    }

    public static function roles(): array
    {
        return ['owner' => ['owner'], 'manager' => ['manager'], 'cashier' => ['cashier']];
    }

    /** @dataProvider roles */
    public function test_a_new_entry_dated_inside_the_lock_is_refused_with_the_role_s_wording_and_nothing_is_written(string $who): void
    {
        $before = StockPurchase::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();

        $this->as($who, fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => $this->insideLock()]))
            ->assertSessionHasErrors(['purchase_date' => $this->expected($who)]);

        $this->assertSame($before, StockPurchase::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());
    }

    /** @dataProvider roles */
    public function test_confirming_a_purchase_inside_the_lock_is_refused_with_the_role_s_wording_and_nothing_changes(string $who): void
    {
        $purchase = $this->draftPurchase($this->insideLock());
        $items = DB::table('items')->where('shop_id', $this->shopId)->count();

        $this->as($who, fn ($t) => $t->patch(route('inventory.purchases.confirm', $purchase)))
            ->assertSessionHas('error', $this->expected($who));

        $this->assertSame('draft', $purchase->fresh()->status);
        $this->assertSame($items, DB::table('items')->where('shop_id', $this->shopId)->count());
    }

    /** @dataProvider roles */
    public function test_entries_dated_after_the_cutoff_still_save(string $who): void
    {
        $this->as($who, fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'After lock', 'purchase_date' => $this->dayAfterLock()]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, StockPurchase::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('supplier_name', 'After lock')->count());

        $purchase = $this->draftPurchase($this->dayAfterLock());
        $this->as($who, fn ($t) => $t->patch(route('inventory.purchases.confirm', $purchase)))->assertSessionMissing('error');
        $this->assertSame('confirmed', $purchase->fresh()->status);
    }
}
