<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Owner 2026-11-08: reports loaded one page of rows but showed no page links, and their cards added up only that page.
 * Every report now pages, and its cards and totals cover every matching row. The cash book starts from the balance
 * before the period and carries its running balance across pages.
 */
class DhiranReportTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390006000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390006000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390006000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    /** $n loans of 10,000 + i rupees each, on the given date. */
    private function loans(int $n, string $date, int $offset = 0): void
    {
        TenantContext::runFor($this->shop->id, function () use ($n, $date, $offset) {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Bulk', 'last_name' => (string) $offset, 'mobile' => '98' . str_pad((string) (15550000 + $offset), 8, '0', STR_PAD_LEFT)]);
            for ($i = 1; $i <= $n; $i++) {
                app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 10000 + $offset + $i, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'loan_date' => $date, 'processing_fee' => 0]);
            }
        });
    }

    private function render(string $method, array $query = []): string
    {
        return TenantContext::runFor($this->shop->id, function () use ($method, $query) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/reports/x', 'GET', $query);
            $this->app->instance('request', $req);

            return app(DhiranController::class)->{$method}($req)->render();
        });
    }

    public function test_active_report_cards_count_every_loan_and_the_page_links_show(): void
    {
        $this->loans(22, now()->subDays(3)->toDateString());
        $principal = array_sum(range(10001, 10022));

        $html = $this->render('reportActive');
        $this->assertStringContainsString('data-report-total="count">22<', $html);
        $this->assertStringContainsString('data-report-total="principal">' . number_format($principal, 2) . '<', $html);
        $this->assertStringContainsString('page=2', $html, 'page links');
        $this->assertStringContainsString('data-report-total="count">22<', $this->render('reportActive', ['page' => 2]), 'same cards on page 2');
    }

    public function test_cash_book_has_an_opening_balance_and_carries_the_running_balance_across_pages(): void
    {
        $from = now()->startOfMonth();
        $this->loans(3, $from->copy()->subDays(5)->toDateString(), 100);            // before the period: 3 payouts
        $this->loans(32, $from->toDateString(), 200);                               // in the period: 32 payouts, 30 per page
        $opening = -array_sum(range(10101, 10103));
        $period = array_sum(range(10201, 10232));

        $page1 = $this->render('reportCashbook', ['from' => $from->toDateString(), 'to' => now()->toDateString(), 'per_page' => 20]);
        $this->assertStringContainsString('data-report-total="opening">' . number_format($opening, 2) . '<', $page1);
        $this->assertStringContainsString('data-report-total="out">' . number_format($period, 2) . '<', $page1);
        $this->assertStringContainsString('data-report-total="closing">' . number_format($opening - $period, 2) . '<', $page1);
        $this->assertStringContainsString('page=2', $page1);

        // Page 2's first row continues from the 20 rows before it.
        $page2 = $this->render('reportCashbook', ['from' => $from->toDateString(), 'to' => now()->toDateString(), 'per_page' => 20, 'page' => 2]);
        $afterRow21 = $opening - array_sum(range(10201, 10221));
        $this->assertStringContainsString('data-running-balance>' . number_format($afterRow21, 2) . '<', $page2);
    }

    public function test_profitability_has_a_period_picker_pages_and_totals_every_loan(): void
    {
        $this->loans(22, now()->toDateString(), 300);
        $html = $this->render('reportProfitability');
        $this->assertStringContainsString('name="from"', $html);
        $this->assertStringContainsString('name="to"', $html);
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('data-report-total="principal">' . number_format(array_sum(range(10301, 10322)), 2) . '<', $html);
    }

    public function test_overdue_and_interest_reports_page(): void
    {
        $this->loans(22, now()->subYears(2)->toDateString(), 400); // long past maturity
        $html = $this->render('reportOverdue');
        $this->assertStringContainsString('data-report-total="count">22<', $html);
        $this->assertStringContainsString('page=2', $html);
    }
}
