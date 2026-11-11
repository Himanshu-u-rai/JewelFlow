<?php

namespace Tests\Feature\Tables;

use App\Models\CashTransaction;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class CashbookUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['cash_transactions', 'cash_drawer_checks', 'invoices', 'invoice_items', 'karigar_invoices', 'karigar_payments', 'metal_lots', 'metal_movements'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $rows;
    }

    private function seedMoney(int $shopId, int $userId): void
    {
        TenantContext::runFor($shopId, function () use ($shopId, $userId) {
            foreach ([['in', 1000, 'cash', '2026-10-31'], ['in', 300, 'cash', '2026-11-14'],
                ['out', 100, 'cash', '2026-11-14'], ['in', 200, 'upi', '2026-11-14'],
                ['in', 700, 'bank', '2026-11-14'], ['in', 120, 'cash', '2026-11-15'],
                ['in', 80, 'upi', '2026-11-15'], ['out', 20, 'cash', '2026-11-15']] as [$type, $amount, $mode, $date]) {
                CashTransaction::record(['shop_id' => $shopId, 'user_id' => $userId, 'type' => $type,
                    'amount' => $amount, 'payment_mode' => $mode, 'source_type' => 'manual',
                    'description' => 'Synthetic cashbook review', 'created_at' => $date.' 10:00:00', 'updated_at' => $date.' 10:00:00']);
            }
        });
    }

    public function test_activity_and_mode_balances_are_distinct_and_entry_filters_do_not_relabel_them(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-15 12:00:00'));
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedMoney($shop->id, $owner->id);
        $this->withoutVite();
        $before = $this->snapshot();
        foreach ([[], ['payment_mode' => 'upi', 'type' => 'out'], ['from_date' => '2026-11-15', 'to_date' => '2026-11-15'], ['from_date' => '2026-11-14', 'to_date' => '2026-11-14']] as $query) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('cashbook.index', $query)))->assertOk();
            $xp = $this->xpath($response->getContent());
            foreach (['today-in' => '₹200.00', 'today-out' => '₹20.00', 'today-net' => '+₹180.00', 'month-net' => '+₹1,280.00'] as $key => $amount) {
                $this->assertSame($amount, trim($xp->evaluate('string(//*[@data-cb-snapshot="'.$key.'"]/dd)')));
            }
            $expectedRows = match ($query['from_date'] ?? '') {
                '2026-11-14' => ['cash' => ['₹1,000.00', '₹300.00', '₹100.00', '₹1,200.00'], 'upi' => ['₹0.00', '₹200.00', '₹0.00', '₹200.00'], 'bank' => ['₹0.00', '₹700.00', '₹0.00', '₹700.00']],
                '2026-11-15' => ['cash' => ['₹1,200.00', '₹120.00', '₹20.00', '₹1,300.00'], 'upi' => ['₹200.00', '₹80.00', '₹0.00', '₹280.00'], 'bank' => ['₹700.00', '₹0.00', '₹0.00', '₹700.00']],
                default => ['cash' => ['₹1,000.00', '₹420.00', '₹120.00', '₹1,300.00'], 'upi' => ['₹0.00', '₹280.00', '₹0.00', '₹280.00'], 'bank' => ['₹0.00', '₹700.00', '₹0.00', '₹700.00']],
            };
            foreach ($expectedRows as $mode => $amounts) {
                $this->assertSame($amounts, array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//*[@data-cb-balance="'.$mode.'"]/td'))));
            }
            $this->assertSame(($query['from_date'] ?? '') === '2026-11-14' ? '₹2,100.00' : '₹2,280.00', trim($xp->evaluate('string(//*[@data-cb-total-closing])')));
            $this->assertSame('₹1,300.00', trim($xp->evaluate('string(//dialog[@id="cashbook-drawer"]//dl/dd)')), 'Drawer matching must always use today, not the historical balance period');
            $this->assertSame(1, $xp->query('//dialog[@id="cashbook-drawer"]//form[@method="POST" and @action="'.route('cashbook.drawer-check').'"]//input[@name="_token"]')->length);
            $response->assertSee('All payment modes')->assertSee('Search, direction, payment mode and account filters do not change these balances.')->assertSee('excluding opening balances')->assertDontSee("Today's cash in");
            if (($query['from_date'] ?? '') === '2026-11-15') {
                $response->assertSee('15 Nov 2026')->assertSee('₹1,200.00');
            }
            if (isset($query['payment_mode'])) $this->assertSame(0, $response->viewData('transactions')->total());
        }
        $this->assertSame($before, $this->snapshot(), 'Browsing and filtering must not alter financial or drawer records');
        $this->travelBack();
    }

    public function test_populated_register_has_continuing_indices_and_single_accessible_filter_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => ShopPaymentMethod::create(['shop_id' => $shop->id, 'type' => 'upi', 'name' => 'Synthetic UPI', 'is_active' => true]));
        $this->travelTo(now()->startOfSecond());
        TenantContext::runFor($shop->id, function () use ($owner, $shop) {
            for ($i = 1; $i <= 30; $i++) CashTransaction::record(['shop_id' => $shop->id, 'user_id' => $owner->id,
                'type' => 'in', 'amount' => $i, 'source_type' => 'manual', 'payment_mode' => 'cash', 'description' => 'Synthetic entry '.$i]);
        });
        $this->travelBack();
        $this->withoutVite();
        $ids = [];
        foreach ([1 => range(1, 25), 2 => range(26, 30)] as $page => $indices) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('cashbook.index', ['page' => $page, 'type' => 'in'])))->assertOk();
            $xp = $this->xpath($response->getContent());
            $this->assertSame(array_map('strval', $indices), array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//td[@class="jf-table-index"]'))));
            $this->assertSame(1, $xp->query('//dialog[@id="cashbook-filters"]//form[@method="GET"]')->length);
            foreach (['search', 'type', 'payment_mode', 'payment_method_id', 'from_date', 'to_date'] as $name) {
                $this->assertSame(1, $xp->query('//dialog[@id="cashbook-filters"]//*[@name="'.$name.'" and @id]')->length);
            }
            $this->assertSame('From date', $xp->evaluate('string(//input[@name="from_date"]/@aria-label)'), 'The themed calendar copies this name onto its visible alternative input');
            $this->assertSame('To date', $xp->evaluate('string(//input[@name="to_date"]/@aria-label)'));
            $this->assertSame(8, $xp->query('//table[@aria-label="Transaction entries"]/thead/tr/th[@scope="col"]')->length);
            $this->assertSame(1, $xp->query('//*[@data-cb-pagination and not(ancestor::*[@data-list-scroll])]')->length);
            $ids = [...$ids, ...$response->viewData('transactions')->pluck('id')->all()];
        }
        $this->assertCount(30, array_unique($ids), 'Same-second records must not repeat across pages');
        $this->assertSame(TenantContext::runFor($shop->id, fn () => CashTransaction::orderByDesc('id')->pluck('id')->all()), $ids);
    }

    public function test_view_only_cannot_open_drawer_mutation_and_foreign_money_is_not_rendered(): void
    {
        [$viewer, $shop] = $this->createRetailerTenant();
        [$other, $otherShop] = $this->createRetailerTenant();
        TenantContext::runFor($otherShop->id, fn () => CashTransaction::record(['shop_id' => $otherShop->id, 'user_id' => $other->id,
            'type' => 'in', 'amount' => 98765, 'source_type' => 'manual', 'description' => 'Foreign confidential cash', 'payment_mode' => 'cash']));
        $this->grantOnlyPermissions($viewer, ['cash.view']);
        $viewer->unsetRelation('role');
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)->get(route('cashbook.index')))->assertOk();
        $response->assertDontSee('Foreign confidential cash')->assertDontSee('₹98,765.00');
        $xp = $this->xpath($response->getContent());
        $this->assertSame(0, $xp->query('//dialog[@id="cashbook-drawer"]')->length);
        $this->assertSame(0, $xp->query('//a[@href="'.route('cashbook.create').'"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->post(route('cashbook.drawer-check'), ['counted_cash' => 100]))->assertForbidden();
    }
}
