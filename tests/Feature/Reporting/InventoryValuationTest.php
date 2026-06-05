<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Reporting\InventoryService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\InventoryValuationDataset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Inventory Valuation. Wraps InventoryService::valuation();
 * reconciles by construction (Σ bucket cost == total at cost == in_stock cost).
 */
class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function item(int $shopId, string $category, string $metal, float $cost, float $retail, float $weight): void
    {
        $item = $this->createItem($shopId);
        DB::table('items')->where('id', $item->id)->update([
            'cost_price' => $cost, 'selling_price' => $retail, 'category' => $category,
            'metal_type' => $metal, 'status' => 'in_stock', 'net_metal_weight' => $weight,
        ]);
    }

    /** 105000 at cost across Rings(gold 100000) + Chains(silver 5000). */
    private function seedItems(int $shopId): void
    {
        $this->item($shopId, 'Rings', 'gold', 60000, 80000, 10);
        $this->item($shopId, 'Rings', 'gold', 40000, 55000, 8);
        $this->item($shopId, 'Chains', 'silver', 5000, 7000, 50);
        // Excluded: a sold item must not be valued.
        $sold = $this->createItem($shopId);
        DB::table('items')->where('id', $sold->id)->update(['cost_price' => 999999, 'status' => 'sold']);
    }

    private function keysFor(P $profile, bool $sensitive): array
    {
        $def = app(ReportRegistry::class)->definition(InventoryValuationDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitive);

        return app(ColumnPolicy::class)->resolve($def, $profile, $user, includeSensitive: $sensitive)->columnKeys;
    }

    private function request(int $shopId, F $format, bool $sensitive, P $profile = P::Detailed): ReportRequest
    {
        return new ReportRequest(
            definition: app(ReportRegistry::class)->definition(InventoryValuationDataset::KEY),
            shopId: $shopId, userId: 1, userName: 'Owner', profile: $profile, format: $format,
            filters: [], columnKeys: $this->keysFor($profile, $sensitive), includeSensitive: $sensitive,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: InventoryValuationDataset::KEY, reportVersion: InventoryValuationDataset::VERSION,
            title: 'Inventory Valuation', profileLabel: 'Detailed', format: $r->format->value,
            filtersApplied: ['As of' => 'today'], periodLabel: 'As on 15 Mar 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test', watermark: 'CONFIDENTIAL',
        );
    }

    private function build(int $shopId, ReportRequest $r)
    {
        return TenantContext::runFor($shopId, fn () => app(InventoryValuationDataset::class)->build($r, $this->meta($r)));
    }

    // ---- RECONCILIATION + BUCKET SUMS -----------------------------------

    public function test_buckets_reconcile_to_grand_total_and_items_aggregate(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedItems($shop->id);

        $dataset = $this->build($shop->id, $this->request($shop->id, F::Csv, sensitive: true));
        $cat = $dataset->section('by_category');
        $metal = $dataset->section('by_metal');

        // Canonical service grand total.
        $data = TenantContext::runFor($shop->id, fn () => app(InventoryService::class)->valuation($shop->id));
        $this->assertEqualsWithDelta(105000.0, $data->totalAtCost, 0.01);

        // Bucket subtotals sum exactly to the grand total.
        $this->assertEqualsWithDelta($data->totalAtCost, $cat->totals['cost_value'], 0.01, 'by-category Σ == total');
        $this->assertEqualsWithDelta($data->totalAtCost, $metal->totals['cost_value'], 0.01, 'by-metal Σ == total');

        // And both equal the independent items.cost_price aggregate (in_stock only).
        $raw = TenantContext::runFor($shop->id, fn () => (float) DB::table('items')
            ->where('shop_id', $shop->id)->where('status', 'in_stock')->sum('cost_price'));
        $this->assertEqualsWithDelta($raw, $cat->totals['cost_value'], 0.01);

        // Per-section: rows sum to the section subtotal.
        $catRowSum = array_sum(array_map(fn ($r) => (float) $r['cost_value'], $cat->rows));
        $this->assertEqualsWithDelta($cat->totals['cost_value'], $catRowSum, 0.01);
    }

    // ---- VALIDATOR (reports:validate VAL-1/2/3) -------------------------

    public function test_reports_validate_inventory_path_passes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedItems($shop->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('VAL-1 valuation by-category cost == total at cost')
            ->expectsOutputToContain('VAL-3 total at cost == in_stock cost (independent recompute)')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedItems($shop->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request($shop->id, $format, sensitive: true);
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS -----------------------------------------------------

    public function test_margin_is_sensitive_gated(): void
    {
        $this->assertNotContains('margin', $this->keysFor(P::Detailed, sensitive: false));
        $this->assertContains('margin', $this->keysFor(P::Detailed, sensitive: true));
    }

    public function test_summary_profile_is_mandatory_only(): void
    {
        $summary = $this->keysFor(P::Summary, sensitive: false);
        $this->assertContains('cost_value', $summary);
        $this->assertNotContains('retail_value', $summary); // optional excluded in Summary
        $this->assertNotContains('margin', $summary);       // sensitive not opted in
    }

    // ---- PERFORMANCE -----------------------------------------------------

    public function test_build_is_query_bounded(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        for ($i = 0; $i < 20; $i++) {
            $this->item($shop->id, 'Rings', 'gold', 1000, 1500, 5);
        }

        $request = $this->request($shop->id, F::Csv, sensitive: true);

        DB::enableQueryLog();
        $this->build($shop->id, $request);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // valuation() runs a small fixed set of aggregates regardless of item count.
        $this->assertLessThanOrEqual(6, $queries, "Expected O(1) aggregates, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
