<?php

namespace Tests\Feature\Tables;

use App\Models\JobOrder;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\MetalLot;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class JobOrderRecordsUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function job(string $metal = 'gold'): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $job = TenantContext::runFor($shop->id, function () use ($owner, $shop, $metal) {
            $karigar = Karigar::create(['shop_id' => $shop->id, 'name' => 'Synthetic record workshop']);
            Karigar::create(['shop_id' => $shop->id, 'name' => 'Synthetic alternative workshop']);
            $lot = MetalLot::create([
                'shop_id' => $shop->id, 'source' => 'purchase', 'metal_type' => $metal,
                'purity' => $metal === 'silver' ? 925 : 24, 'fine_weight_total' => 200,
                'fine_weight_remaining' => 200, 'cost_per_fine_gram' => 0,
            ]);
            return app(JobOrderService::class)->issue([
                'karigar_id' => $karigar->id, 'metal_type' => $metal, 'purity' => $lot->purity,
                'allowed_wastage_percent' => 5, 'issue_date' => now()->toDateString(),
                'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 100,
                    'fine_weight' => $metal === 'silver' ? 92.5 : 100, 'purity' => $lot->purity]],
            ], $shop->id, $owner->id);
        });
        $this->withoutVite();
        return [$owner, $shop, $job];
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];
        foreach (['job_orders', 'job_order_issuances', 'job_order_receipts', 'job_order_receipt_items', 'metal_lots', 'metal_movements', 'items', 'karigar_invoices', 'karigar_payments'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $snapshot;
    }

    public function test_detail_preserves_exact_values_receipts_invoices_and_all_gets_are_non_mutating(): void
    {
        [$owner, $shop, $job] = $this->job();
        TenantContext::runFor($shop->id, function () use ($owner, $shop, $job) {
            app(JobOrderService::class)->receive($job, [
                'receipt_date' => now()->toDateString(), 'retained_fine_weight' => 10,
                'items' => [['description' => 'Synthetic receipt ring', 'hsn_code' => '7113', 'pieces' => 2,
                    'gross_weight' => 72, 'stone_weight' => 2, 'net_weight' => 70, 'purity' => 24]],
            ], $owner->id);
            KarigarInvoice::create([
                'shop_id' => $shop->id, 'karigar_id' => $job->karigar_id, 'job_order_id' => $job->id,
                'mode' => 'job_work', 'karigar_invoice_number' => 'SYN-REVIEW-INV',
                'karigar_invoice_date' => now()->toDateString(), 'total_before_tax' => 1234.56,
                'total_after_tax' => 1234.56, 'payment_status' => 'unpaid',
            ]);
        });
        $before = $this->financialSnapshot();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.show', $job)))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame('100.000g', $xp->evaluate('string(//*[@data-job-position="issued"]/dd)'));
        $this->assertSame('70.000g', $xp->evaluate('string(//*[@data-job-position="returned"]/dd)'));
        $this->assertSame('20.000g', $xp->evaluate('string(//*[@data-job-position="wastage"]/dd)'));
        $response->assertSee('5.000g (5.00%)')->assertSee('Synthetic receipt ring')->assertSee('1,234.56')->assertSee('EXCESS WASTAGE');
        $this->assertSame(3, $xp->query('//*[@role="region" and @tabindex="0"]//table')->length);
        $this->assertSame(3, $xp->query('//table//td[@class="jf-table-index" and text()="1"]')->length);
        $this->assertSame(19, $xp->query('//table//th[@scope="col"]')->length);
        foreach (['job-orders.leftover', 'job-orders.reassign', 'job-orders.acknowledge'] as $route) {
            $this->assertSame(1, $xp->query('//form[@action="'.route($route, $job).'"]')->length);
        }
        $reassign = $xp->query('//select[@name="to_karigar_id"]')->item(0);
        $this->assertNotNull($reassign);
        $this->assertFalse($reassign->hasAttribute('data-enhance-selects'), 'Keep reassign native: the shared custom select does not rehydrate its listeners on Turbo restoration');
        $this->assertStringContainsString('Acknowledge discrepancies', $xp->evaluate('string(//form[@action="'.route('job-orders.acknowledge', $job).'"]/@onsubmit)'));
        $receive = TenantContext::runFor($shop->id, fn () => $this->get(route('job-orders.receive.form', $job)))->assertOk();
        $rx = $this->xpath($receive->getContent());
        $this->assertSame('0.000g', $rx->evaluate('string(//*[@data-receive-position="outstanding"]/dd)'));
        $this->assertSame($before, $this->financialSnapshot(), 'Viewing either page must not modify metal, items, invoices or receipts');
    }

    public function test_receive_keeps_field_contract_editable_net_and_tolerance_warning(): void
    {
        [$owner, $shop, $job] = $this->job('silver');
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.receive.form', $job)))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//form[@method="POST" and @action="'.route('job-orders.receive.store', $job).'"]')->length);
        $this->assertSame(1, $xp->query('//form[contains(@class,"job-receive-form")]//input[@name="_token"]')->length);
        $this->assertSame('0', $xp->evaluate('string(//input[@name="retained_fine_weight"]/@value)'));
        $this->assertSame('0.001', $xp->evaluate('string(//input[@name="retained_fine_weight"]/@step)'));
        foreach (['receipt_date' => 'job-receipt-date', 'notes' => 'job-receipt-notes', 'retained_fine_weight' => 'job-retained'] as $name => $id) {
            $this->assertSame(1, $xp->query('//input[@name="'.$name.'" and @id="'.$id.'"]')->length);
            $this->assertSame(1, $xp->query('//label[@for="'.$id.'"]')->length);
        }
        $response->assertSee('not wastage')->assertSee('you can still submit and acknowledge later.');
        $this->assertSame(9, $xp->query('//table//th[@scope="col"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->get(route('job-orders.show', $job)))->assertOk()->assertSee('925‰')->assertDontSee('925K');
    }

    public function test_view_only_users_keep_printing_but_no_mutation_links_or_forms(): void
    {
        [$owner, $shop, $job] = $this->job();
        $this->grantOnlyPermissions($owner, ['job_order.view']);
        $owner->unsetRelation('role');
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.show', $job)))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(0, $xp->query('//*[contains(@class,"job-record-page")]//form[@method="POST"]')->length);
        $this->assertSame(0, $xp->query('//a[@href="'.route('job-orders.receive.form', $job).'"]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/karigar-invoices/create")]')->length);
        $this->assertSame(1, $xp->query('//a[@href="'.route('job-orders.challan', $job).'"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->get(route('job-orders.receive.form', $job)))->assertForbidden();
    }

    public function test_closed_jobs_and_cross_shop_urls_cannot_open_a_receipt_form(): void
    {
        [$owner, $shop, $job] = $this->job();
        TenantContext::runFor($shop->id, fn () => app(JobOrderService::class)->receive($job, [
            'receipt_date' => now()->toDateString(),
            'items' => [['description' => 'Synthetic full return', 'pieces' => 1, 'gross_weight' => 100, 'net_weight' => 100, 'purity' => 24]],
        ], $owner->id));
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.show', $job)))->assertOk()->assertDontSee('Receive Items');
        TenantContext::runFor($shop->id, fn () => $this->get(route('job-orders.receive.form', $job)))->assertStatus(422);
        [$foreignOwner, $foreignShop] = $this->createRetailerTenant();
        foreach (['job-orders.show', 'job-orders.receive.form'] as $route) {
            $response = TenantContext::runFor($foreignShop->id, fn () => $this->actingAs($foreignOwner)->get(route($route, $job)));
            $this->assertContains($response->status(), [403, 404]);
            $response->assertDontSee('Synthetic record workshop');
        }
    }
}
