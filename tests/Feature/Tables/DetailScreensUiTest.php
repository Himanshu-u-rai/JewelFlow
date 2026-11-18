<?php

namespace Tests\Feature\Tables;

use App\Models\{Karigar, KarigarInvoice, Scheme, StockPurchase};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class DetailScreensUiTest extends TestCase
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

    public function test_scheme_edit_has_compact_basics_without_losing_update_fields_or_values(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $scheme = TenantContext::runFor($shop->id, fn () => Scheme::create([
            'name' => 'Review <scheme>', 'type' => 'gold_savings', 'start_date' => '2026-11-01',
            'end_date' => '2027-11-01', 'total_installments' => 11, 'bonus_month_value' => 1234.56,
            'is_active' => true, 'description' => 'Keep description', 'terms' => 'Keep terms',
        ]));
        $before = $scheme->fresh()->getRawOriginal();
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('schemes.edit', $scheme)))->assertOk();
        $xp = $this->xpath($response->getContent());
        $basics = $xp->query('//section[@id="scheme-basics"]')->item(0);
        $this->assertNotNull($basics);
        foreach (['name', 'type', 'start_date', 'end_date', 'description'] as $name) $this->assertSame(1, $xp->query('.//*[@name="'.$name.'"]', $basics)->length);
        $this->assertSame(0, $xp->query('//h2[text()="Schedule"]')->length);
        $this->assertSame('PUT', $xp->evaluate('string(//input[@name="_method"]/@value)'));
        $this->assertSame('2026-11-01', $xp->evaluate('string(//input[@name="start_date"]/@value)'));
        $this->assertSame('1234.56', $xp->evaluate('string(//input[@name="bonus_month_value"]/@value)'));
        foreach (['terms', 'discount_type', 'discount_value', 'max_discount_amount', 'min_purchase_amount', 'priority', 'applies_to', 'applies_to_value', 'max_uses_per_customer', 'total_installments'] as $name) $this->assertSame(1, $xp->query('//*[@name="'.$name.'"]')->length);
        $this->assertSame(1, $xp->query('//main')->length);
        $this->assertSame($before, $scheme->fresh()->getRawOriginal());
    }

    public function test_karigar_profile_preserves_balances_and_invoice_links_with_numbered_contained_tables(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = TenantContext::runFor($shop->id, function () use ($shop) {
            $karigar = Karigar::create(['name' => 'Review <workshop>', 'opening_balance' => 100, 'shop_id' => $shop->id]);
            KarigarInvoice::create(['karigar_id' => $karigar->id, 'mode' => 'purchase',
                'karigar_invoice_number' => 'DETAIL-001', 'karigar_invoice_date' => today(),
                'total_before_tax' => 1234.56, 'total_after_tax' => 1234.56, 'payment_status' => 'unpaid']);
            return $karigar;
        });
        $before = DB::table('karigar_invoices')->get()->toJson();
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.show', $karigar)))->assertOk();
        $response->assertSee('₹1,334.56')->assertSee('DETAIL-001')->assertSee('Review &lt;workshop&gt;', false);
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//*[@data-detail-scroll and @role="region" and @tabindex="0"]//table')->length);
        $this->assertSame(1, $xp->query('//td[@class="jf-table-index" and text()="1"]')->length);
        $this->assertSame(5, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
        $this->assertSame($before, DB::table('karigar_invoices')->get()->toJson());
    }

    public function test_purchase_detail_retains_exact_totals_and_status_gated_actions_without_mutations(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        foreach (['draft', 'confirmed', 'stocked'] as $status) {
            $purchase = TenantContext::runFor($shop->id, fn () => StockPurchase::create([
                'purchase_number' => 'DETAIL-'.$status, 'supplier_name' => 'Supplier <review>',
                'purchase_date' => today(), 'status' => $status, 'subtotal_amount' => 1000,
                'cgst_amount' => 15, 'sgst_amount' => 15, 'total_amount' => 1030,
            ]));
            TenantContext::runFor($shop->id, fn () => $purchase->lines()->create([
                'shop_id' => $shop->id,
                'line_type' => 'ornament', 'design' => 'Review ring', 'metal_type' => 'gold',
                'purity' => 22, 'gross_weight' => 2, 'net_metal_weight' => 1.5,
                'purchase_rate_per_gram' => 500, 'making_charges' => 250, 'purchase_line_amount' => 1000,
            ]));
            $before = $purchase->fresh()->getRawOriginal();
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('inventory.purchases.show', $purchase)))->assertOk();
            $response->assertSee('₹1,030.00')->assertSee('2.000 g')->assertSee('1.500 g')->assertSee('Supplier &lt;review&gt;', false);
            $xp = $this->xpath($response->getContent());
            $this->assertSame(1, $xp->query('//*[@data-detail-scroll and @role="region" and @tabindex="0"]//table')->length);
            $this->assertSame(11, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
            foreach (['confirm' => $status === 'draft', 'stock' => $status === 'confirmed', 'reverse' => $status !== 'draft'] as $action => $visible) {
                $this->assertSame($visible ? 1 : 0, $xp->query('//form[@action="'.route('inventory.purchases.'.$action, $purchase).'"]')->length);
            }
            $this->assertSame($before, $purchase->fresh()->getRawOriginal());
        }
    }
}
