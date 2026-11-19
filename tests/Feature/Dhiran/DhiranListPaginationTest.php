<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dhiran lists sorted by a date or a name (borrowers, active / overdue / profitability loans, interest payments)
 * must keep one order across pages when many rows share that value: each row on exactly one page.
 */
class DhiranListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    private const PER_PAGE = 20;
    private const ROWS = 45;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390009000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390009000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390009000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    /** @return array<int, int> loan ids, created in id order, all on the same loan_date */
    private function loans(string $date): array
    {
        return TenantContext::runFor($this->shop->id, function () use ($date) {
            $ids = [];
            for ($i = 1; $i <= self::ROWS; $i++) {
                $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Same', 'last_name' => 'Borrower' . $i, 'mobile' => '98170' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
                $ids[] = app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 10000 + $i, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id,
                    'loan_date' => $date, 'tenure_months' => 6])->id;
            }

            return $ids;
        });
    }

    /** Rows get rewritten in shuffled order (updated_at to itself), so heap order no longer follows id. */
    private function scramble(string $table, array $ids): void
    {
        $this->tieAndScramble($table, $ids, ['updated_at' => '2026-11-01 10:00:00']);
    }

    private function viewData(string $method, string $key, int $page): array
    {
        return TenantContext::runFor($this->shop->id, function () use ($method, $key, $page) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/x', 'GET', ['page' => $page]);
            $this->app->instance('request', $req);

            return app(DhiranController::class)->{$method}($req)->getData()[$key]->pluck('id')->all();
        });
    }

    private function assertStable(array $ids, string $method, string $key, bool $idsAscending, string $list): void
    {
        $page = fn (int $p) => $this->viewData($method, $key, $p);
        $this->assertEachOnExactlyOnePage($ids, $page, self::PER_PAGE, $list);
        $expected = $idsAscending ? $ids : array_reverse($ids);
        $this->assertSame(array_slice($expected, 0, self::PER_PAGE), $page(1), "{$list}: page 1 follows the id tie-breaker");
    }

    public function test_borrowers_with_the_same_first_name_each_appear_on_exactly_one_page(): void
    {
        $loanIds = $this->loans(now()->toDateString());
        $ids = Customer::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderBy('id')->pluck('id')->all();
        $this->assertCount(self::ROWS, $loanIds);
        $this->scramble('customers', $ids);

        $this->assertStable($ids, 'borrowers', 'borrowers', true, 'borrowers');
    }

    public function test_active_loans_with_the_same_loan_date_each_appear_on_exactly_one_page(): void
    {
        $ids = $this->loans('2026-11-01');
        $this->scramble('dhiran_loans', $ids);

        $this->assertStable($ids, 'reportActive', 'loans', false, 'active loans');
    }

    public function test_overdue_loans_with_the_same_maturity_date_each_appear_on_exactly_one_page(): void
    {
        $ids = $this->loans('2025-01-01');
        $this->assertSame(self::ROWS, DhiranLoan::withoutGlobalScopes()->whereIn('id', $ids)->where('maturity_date', '<', now())->count());
        $this->scramble('dhiran_loans', $ids);

        $this->assertStable($ids, 'reportOverdue', 'loans', true, 'overdue loans');
    }

    public function test_profitability_loans_with_the_same_loan_date_each_appear_on_exactly_one_page(): void
    {
        $ids = $this->loans(now()->toDateString());
        $this->scramble('dhiran_loans', $ids);

        $this->assertStable($ids, 'reportProfitability', 'loans', false, 'profitability loans');
    }

    public function test_interest_payments_with_the_same_payment_date_each_appear_on_exactly_one_page(): void
    {
        $loanIds = $this->loans('2026-01-01');
        // Payments are an immutable ledger: they cannot be rewritten, so the tie is the shared payment date (today).
        $ids = TenantContext::runFor($this->shop->id, function () use ($loanIds) {
            $this->actingAs($this->owner);

            return array_map(fn (int $id) => app(DhiranService::class)
                ->recordInterestPayment(DhiranLoan::findOrFail($id), 100)->id, $loanIds);
        });

        $this->assertStable($ids, 'reportInterest', 'payments', false, 'interest payments');
    }
}
