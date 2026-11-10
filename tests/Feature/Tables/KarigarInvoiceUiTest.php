<?php

namespace Tests\Feature\Tables;

use App\Models\Karigar;
use App\Models\JobOrder;
use App\Models\JobOrderReceipt;
use App\Models\KarigarInvoice;
use App\Services\KarigarInvoiceService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class KarigarInvoiceUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function invoices(int $shopId, int $userId, int $count = 30): Karigar
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $userId, $count) {
            $karigar = Karigar::create(['shop_id' => $shopId, 'name' => 'Synthetic invoice workshop']);
            for ($i = 1; $i <= $count; $i++) {
                app(KarigarInvoiceService::class)->create([
                    'karigar_id' => $karigar->id, 'mode' => 'purchase',
                    'karigar_invoice_number' => sprintf('SYN-KI-%03d', $i),
                    'karigar_invoice_date' => now()->toDateString(),
                    'cgst_rate' => 1.5, 'sgst_rate' => 1.5,
                ], [['description' => 'Synthetic invoice ring', 'hsn_code' => '7113', 'pieces' => 2,
                    'gross_weight' => 12, 'stone_weight' => 2, 'net_weight' => 10,
                    'purity' => 22, 'rate_per_gram' => 1000, 'metal_amount' => 10000]], null, $shopId, $userId);
            }
            return $karigar;
        });
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

    private function snapshot(): array
    {
        $result = [];
        foreach (['karigar_invoices', 'karigar_invoice_lines', 'karigar_payments', 'metal_lots', 'metal_movements', 'job_orders', 'items'] as $table) $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        return $result;
    }

    public function test_register_has_one_popup_filter_header_metrics_and_continuing_indices(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->invoices($shop->id, $owner->id);
        $this->withoutVite();
        $before = $this->snapshot();
        foreach ([1 => range(1, 25), 2 => range(26, 30)] as $page => $indices) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.index', ['karigar_id' => $karigar->id, 'payment_status' => 'unpaid', 'page' => $page])))->assertOk();
            $xp = $this->xpath($response->getContent());
            $this->assertSame(4, $xp->query('//*[@data-ki-register-head]//dl[@data-ki-metric]')->length);
            $this->assertSame(1, $xp->query('//dialog[@id="karigar-invoice-filters"]//form[@method="GET"]')->length);
            $this->assertSame(1, $xp->query('//select[@name="payment_status"]')->length);
            $this->assertSame(1, $xp->query('//select[@name="karigar_id"]')->length);
            $this->assertSame(array_map('strval', $indices), array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//td[@class="jf-table-index"]'))));
            $this->assertSame(array_map('strval', $indices), array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//*[@data-ki-mobile-index]'))));
            $this->assertSame(count($indices), $xp->query('//table//a[@data-tip="View"]')->length);
            $this->assertSame(11, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
            $this->assertSame(count($indices), $xp->query('//table/tbody/tr[@role="row"]')->length);
            $this->assertSame(0, $xp->query('//table//td[@onclick]')->length, 'Cells must not become extra keyboard buttons around the View link');
            $this->assertSame(1, $xp->query('//*[@data-ki-pagination and not(ancestor::*[@data-list-scroll])]')->length);
            $response->assertSee('Paid / partial · page')->assertSee('Unpaid · page')->assertSee('₹10,300.00');
            if ($page === 1) $this->assertStringContainsString('payment_status=unpaid', $response->viewData('invoices')->nextPageUrl());
        }
        $this->assertSame($before, $this->snapshot(), 'List GETs must not modify invoices, metal or payments');
    }

    public function test_native_create_controls_preserve_post_contract_summary_and_edit_compatibility(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->invoices($shop->id, $owner->id, 1);
        $this->withoutVite();
        $before = $this->snapshot();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.create')))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//form[@method="POST" and @enctype="multipart/form-data" and @action="'.route('karigar-invoices.store').'"]')->length);
        $this->assertSame(1, $xp->query('//select[@name="karigar_id" and @required]')->length);
        $this->assertSame(1, $xp->query('//select[@name="mode"]')->length);
        $this->assertSame(1, $xp->query('//aside[@aria-label="Invoice summary"]')->length);
        $this->assertSame(1, $xp->query('//input[@name="invoice_file" and @accept=".pdf,.jpg,.jpeg,.png"]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"ki-form-shell")]//select[@data-enhance-selects]')->length);
        $invoice = TenantContext::runFor($shop->id, fn () => KarigarInvoice::first());
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigar-invoices.edit', $invoice)))->assertOk()->assertSee('Update Invoice')->assertSee('Synthetic invoice ring');
        $this->assertSame($before, $this->snapshot(), 'Opening either form must not change invoice amounts or payments');
    }

    public function test_same_time_invoices_have_stable_pages_without_duplicate_or_missing_records(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->travelTo(now()->startOfSecond());
        $this->invoices($shop->id, $owner->id);
        $this->travelBack();
        $expectedIds = TenantContext::runFor($shop->id, fn () => KarigarInvoice::orderByDesc('id')->pluck('id')->all());
        $this->withoutVite();
        $actualIds = [];
        foreach ([1, 2] as $page) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.index', ['page' => $page])))->assertOk();
            $actualIds = [...$actualIds, ...$response->viewData('invoices')->pluck('id')->all()];
        }
        $this->assertSame($expectedIds, $actualIds, 'Equal creation times must fall back to newest invoice ID so pages neither repeat nor omit bills');
        $this->assertCount(30, array_unique($actualIds));
    }

    public function test_archived_workshop_remains_selected_for_its_existing_job_and_receipt(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$karigar, $job, $receipt] = TenantContext::runFor($shop->id, function () use ($shop) {
            $karigar = Karigar::create(['shop_id' => $shop->id, 'name' => 'Synthetic archived workshop']);
            $job = JobOrder::create([
                'shop_id' => $shop->id, 'karigar_id' => $karigar->id,
                'job_order_number' => 'SYN-ARCHIVE-JO', 'challan_number' => 'SYN-ARCHIVE-DC',
                'metal_type' => 'gold', 'purity' => 22, 'issue_date' => now()->toDateString(),
            ]);
            $receipt = JobOrderReceipt::create(['shop_id' => $shop->id, 'job_order_id' => $job->id,
                'receipt_number' => 'SYN-ARCHIVE-RECEIPT', 'receipt_date' => now()->toDateString()]);
            return [$karigar, $job, $receipt];
        });
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->patch(route('karigars.archive', $karigar)))->assertRedirect();
        $this->withoutVite();
        $standalone = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.create')))->assertOk();
        $this->assertSame(0, $this->xpath($standalone->getContent())->query('//select[@name="karigar_id"]/option[@value="'.$karigar->id.'"]')->length);
        foreach ([['job_order' => $job->id], ['receipt' => $receipt->id]] as $query) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.create', $query)))->assertOk();
            $xp = $this->xpath($response->getContent());
            $this->assertSame(1, $xp->query('//select[@name="karigar_id"]/option[@value="'.$karigar->id.'"]')->length, 'Historical settlement must retain the linked workshop even after it is disabled');
            $response->assertSee('Synthetic archived workshop')->assertSee('SYN-ARCHIVE-JO');
            $this->assertSame((string) $job->id, $xp->evaluate('string(//input[@name="job_order_id"]/@value)'));
        }
    }

    public function test_viewers_filters_and_foreign_shops_remain_isolated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->invoices($shop->id, $owner->id, 1);
        [$otherOwner, $otherShop] = $this->createRetailerTenant();
        $this->invoices($otherShop->id, $otherOwner->id, 1);
        TenantContext::runFor($otherShop->id, fn () => Karigar::create(['shop_id' => $otherShop->id, 'name' => 'Foreign private workshop']));
        $this->withoutVite();
        $this->grantOnlyPermissions($owner, ['karigar_invoice.view']);
        $owner->unsetRelation('role');
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigar-invoices.index')))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(3, $xp->query('//*[@data-ki-register-head]//dl[@data-ki-metric]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/karigar-invoices/create")]')->length);
        $this->assertSame(1, $response->viewData('invoices')->total());
        $response->assertDontSee('Foreign private workshop')->assertDontSee('This page value');
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigar-invoices.create')))->assertForbidden();
        TenantContext::runFor($shop->id, fn () => $this->get(route('karigar-invoices.index', ['karigar_id' => $karigar->id, 'payment_status' => 'paid'])))->assertOk()->assertSee('No karigar invoices match your filters.');
    }
}
