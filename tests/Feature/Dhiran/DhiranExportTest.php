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
 * Owner 2026-11-11: CSV export on Loans, Customers and every report. It takes the filters on screen, holds every
 * matching row (not just the page), opens cleanly in Excel (UTF-8 BOM for ₹) and cannot carry spreadsheet formulas.
 */
class DhiranExportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390009000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390009000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390009000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    private function loans(string $first, int $n, string $metal = 'gold', ?string $date = null): void
    {
        TenantContext::runFor($this->shop->id, function () use ($first, $n, $metal, $date) {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => $first, 'last_name' => 'K', 'mobile' => '98188' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT)]);
            for ($i = 1; $i <= $n; $i++) {
                app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Item', 'metal_type' => $metal, 'purity' => $metal === 'silver' ? 925 : 22,
                    'gross_weight' => $metal === 'silver' ? 5000 : 50, 'rate_per_gram_at_pledge' => $metal === 'silver' ? 90 : 6000,
                ]], ['principal_amount' => 10000 + $i, 'gold_rate_on_date' => 6000, 'silver_rate_on_date' => 90,
                    'created_by' => $this->owner->id, 'loan_date' => $date ?? now()->toDateString()]);
            }
        });
    }

    /** @return array{0: \Symfony\Component\HttpFoundation\Response, 1: list<list<string>>, 2: string} */
    private function export(string $method, array $query = []): array
    {
        return TenantContext::runFor($this->shop->id, function () use ($method, $query) {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/x', 'GET', $query + ['export' => 'csv']);
            $this->app->instance('request', $req);
            $res = app(DhiranController::class)->{$method}($req);
            ob_start();
            $res->sendContent();
            $raw = ob_get_clean();
            $rows = array_map('str_getcsv', array_filter(explode("\n", substr($raw, 3))));

            return [$res, $rows, $raw];
        });
    }

    public function test_loans_export_has_every_filtered_row_and_opens_in_excel(): void
    {
        $this->loans('Goldy', 23);
        $this->loans('Silvia', 2, 'silver');

        [$res, $rows, $raw] = $this->export('loans', ['metal' => 'gold']);
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=dhiran-loans-', $res->headers->get('Content-Disposition'));
        $this->assertSame("\xEF\xBB\xBF", substr($raw, 0, 3), 'UTF-8 BOM so Excel shows ₹ and names right');
        // A data export, not a copy of the on-screen columns: the loan's full detail.
        foreach (['Loan number', 'Status', 'Customer', 'Customer code', 'Mobile', 'Loan date', 'Maturity date', 'Metal',
            'Gross weight (g)', 'Fine weight (g)', 'Gold rate on date', 'Collateral value', 'LTV %', 'Principal', 'Processing fee',
            'Interest %/month', 'Outstanding principal', 'Outstanding interest', 'Outstanding penalty', 'Outstanding',
            'Interest collected', 'Principal collected', 'Payments', 'Last payment', 'Created by', 'Notes'] as $col) {
            $this->assertContains($col, $rows[0], "has {$col}");
        }
        $this->assertCount(24, $rows, 'header + all 23 gold loans, past the 20-row page');
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => str_contains(implode(',', $r), 'Silvia'))), 'filter applied');
        $col = array_flip($rows[0]);
        $this->assertSame('10023.00', $rows[1][$col['Principal']], 'plain numbers, no grouping, so Excel can add them up');
        $this->assertSame('Gold', $rows[1][$col['Metal']]);
        $this->assertSame('50.000', $rows[1][$col['Gross weight (g)']]);
        $this->assertSame('1', $rows[1][$col['#']]);
    }

    public function test_cells_cannot_become_formulas(): void
    {
        $this->loans('=HYPERLINK("http://x")', 1);
        [, $rows] = $this->export('loans');
        $this->assertStringStartsWith("'=HYPERLINK", $rows[1][array_flip($rows[0])['Customer']]);
    }

    public function test_cash_book_export_starts_and_ends_with_the_balances(): void
    {
        $this->loans('Early', 1, 'gold', now()->startOfMonth()->subDays(3)->toDateString());
        $this->loans('Now', 2);
        [, $rows] = $this->export('reportCashbook', ['period' => 'this_month']);
        $this->assertSame('Opening balance', $rows[1][1]);
        $this->assertSame('-10001.00', $rows[1][8], 'the earlier payout');
        $this->assertCount(5, $rows, 'header, opening, 2 payouts, closing');
        $this->assertSame('Closing balance', end($rows)[1]);
        $this->assertSame((string) number_format(-10001 - 10001 - 10002, 2, '.', ''), end($rows)[8]);
    }

    public function test_every_report_and_the_customers_list_export(): void
    {
        $this->loans('Asha', 2);
        $this->loans('Old', 1, 'gold', now()->subYears(2)->toDateString());
        $expect = [
            'reportActive' => 'Days to maturity', 'reportOverdue' => 'Days overdue', 'reportInterest' => 'Outstanding principal after',
            'reportForfeiture' => 'Written off', 'reportProfitability' => 'Profit', 'borrowers' => 'Total borrowed',
        ];
        foreach ($expect as $method => $column) {
            [$res, $rows] = $this->export($method);
            $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'), $method);
            $this->assertContains($column, $rows[0], "{$method} has {$column}");
        }
        [, $overdue] = $this->export('reportOverdue');
        $this->assertCount(2, $overdue, 'header + the one long-overdue loan');
    }

    public function test_pages_offer_the_export_with_their_filters(): void
    {
        $html = TenantContext::runFor($this->shop->id, function () {
            $this->actingAs($this->owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/loans', 'GET', ['metal' => 'silver', 'page' => 2]);
            $this->app->instance('request', $req);
            return app(DhiranController::class)->loans($req)->render();
        });
        $this->assertMatchesRegularExpression('/<a href="[^"]*metal=silver[^"]*export=csv[^"]*"[^>]*data-turbo="false"[^>]*>.*?Export CSV/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<a href="[^"]*page=2[^"]*export=csv/', $html, 'the export is every page, not page 2');
    }
}
