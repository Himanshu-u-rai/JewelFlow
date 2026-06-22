<?php

namespace Tests\Feature\Reporting;

use App\Reporting\InventoryService;
use App\Reporting\KarigarService;
use App\Reporting\ReceivablesService;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\DuesAgingDataset;
use App\Services\Reporting\Reports\EmiDataset;
use App\Services\Reporting\Reports\KarigarSettlementDataset;
use App\Services\Reporting\Reports\MetalExchangeDataset;
use App\Services\Reporting\Reports\PurchaseEfficiencyDataset;
use App\Services\Reporting\Reports\SchemeLiabilityDataset;
use App\Services\Reporting\Reports\ShrinkageDataset;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * GAP 2 tail — golden reconciliation. Each migrated dataset wraps its canonical
 * service VERBATIM, so the dataset's section row count and numeric totals must
 * equal the service's own figures. This proves there is no number drift between
 * the spine report and the source of truth (the money-sensitive guarantee), and
 * holds regardless of how much data exists in the period.
 */
class Gap2TailReconcileTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function periodFilter(): array
    {
        return ['period' => [
            'from' => CarbonImmutable::parse('2026-04-01'),
            'to' => CarbonImmutable::parse('2026-06-30'),
        ]];
    }

    private function request(string $datasetClass, int $shopId, array $columnKeys): ReportRequest
    {
        return new ReportRequest(
            definition: app($datasetClass)->definition(),
            shopId: $shopId,
            userId: null,
            userName: 'Tester',
            profile: ReportProfile::Detailed,
            format: ExportFormat::Csv,
            filters: $this->periodFilter(),
            columnKeys: $columnKeys,
        );
    }

    private function meta(): ReportMeta
    {
        return new ReportMeta(
            reportKey: 'k', reportVersion: 'k@1', title: 'T', profileLabel: 'Detailed',
            format: 'csv', filtersApplied: [], periodLabel: 'P', shopLegalName: 'Shop',
            shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Tester', generatedAt: CarbonImmutable::now(), generatorTag: 't',
            watermark: null,
        );
    }

    private function asOf(): Carbon
    {
        return Carbon::parse('2026-06-30');
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::range('2026-04-01', '2026-06-30');
    }

    public function test_all_seven_registered(): void
    {
        $registry = app(ReportRegistry::class);
        foreach (['dues-aging', 'emi', 'scheme-liability', 'karigar-settlement', 'shrinkage', 'purchase-efficiency', 'metal-exchange'] as $key) {
            $this->assertTrue($registry->has($key), "{$key} should be registered");
        }
    }

    public function test_dues_aging_reconciles(): void
    {
        [, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(ReceivablesService::class)->duesAging($shop->id, $this->asOf());
            $ds = app(DuesAgingDataset::class)->build(
                $this->request(DuesAgingDataset::class, $shop->id, ['customer', 'mobile', 'invoices', 'current', 'd3160', 'd6190', 'd90plus', 'total']),
                $this->meta(),
            );
            $section = $ds->sections[0];
            $this->assertSame($svc->rows->count(), count($section->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('total'), (float) ($section->totals['total'] ?? 0), 0.01);
            $this->assertEqualsWithDelta((float) $svc->rows->sum('current'), (float) ($section->totals['current'] ?? 0), 0.01);
        });
    }

    public function test_emi_reconciles(): void
    {
        [, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(ReceivablesService::class)->emiVisibility($shop->id, $this->asOf());
            $ds = app(EmiDataset::class)->build(
                $this->request(EmiDataset::class, $shop->id, ['customer', 'invoice', 'total_payable', 'paid', 'remaining', 'emis', 'next_due', 'overdue_days']),
                $this->meta(),
            );
            $section = $ds->sections[0];
            $this->assertSame($svc->rows->count(), count($section->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('remaining'), (float) ($section->totals['remaining'] ?? 0), 0.01);
        });
    }

    public function test_scheme_liability_reconciles(): void
    {
        [, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(ReceivablesService::class)->schemeLiability($shop->id);
            $ds = app(SchemeLiabilityDataset::class)->build(
                $this->request(SchemeLiabilityDataset::class, $shop->id, ['customer', 'scheme', 'status', 'contributed', 'bonus', 'balance', 'maturity']),
                $this->meta(),
            );
            $section = $ds->sections[0];
            $this->assertSame($svc->rows->count(), count($section->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('current_balance'), (float) ($section->totals['balance'] ?? 0), 0.01);
        });
    }

    public function test_karigar_settlement_reconciles(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(KarigarService::class)->settlement($shop->id);
            $ds = app(KarigarSettlementDataset::class)->build(
                $this->request(KarigarSettlementDataset::class, $shop->id, ['karigar', 'open_jobs', 'issued', 'received', 'wastage', 'outstanding', 'invoiced', 'paid', 'payable']),
                $this->meta(),
            );
            $section = $ds->sections[0];
            $this->assertSame($svc->rows->count(), count($section->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('outstanding_fine'), (float) ($section->totals['outstanding'] ?? 0), 0.0001);
            $this->assertEqualsWithDelta((float) $svc->rows->sum('outstanding_payable'), (float) ($section->totals['payable'] ?? 0), 0.01);
        });
    }

    public function test_shrinkage_reconciles(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(KarigarService::class)->shrinkage($shop->id, $this->period());
            $ds = app(ShrinkageDataset::class)->build(
                $this->request(ShrinkageDataset::class, $shop->id, ['karigar', 'jobs', 'issued', 'in_items', 'leftover', 'wastage', 'wastage_pct', 'unaccounted', 'metal']),
                $this->meta(),
            );
            [$byKarigar, $byMetal] = $ds->sections;
            $this->assertSame($svc->rows->count(), count($byKarigar->rows));
            $this->assertSame($svc->byMetal->count(), count($byMetal->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('wastage_fine'), (float) ($byKarigar->totals['wastage'] ?? 0), 0.0001);
            $this->assertEqualsWithDelta((float) $svc->rows->sum('unaccounted_fine'), (float) ($byKarigar->totals['unaccounted'] ?? 0), 0.0001);
        });
    }

    public function test_purchase_efficiency_reconciles(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(InventoryService::class)->purchaseEfficiency($shop->id, $this->period());
            $ds = app(PurchaseEfficiencyDataset::class)->build(
                $this->request(PurchaseEfficiencyDataset::class, $shop->id, ['metal', 'lines', 'lines_no_market', 'gross', 'paid', 'market', 'premium', 'premium_pct']),
                $this->meta(),
            );
            $section = $ds->sections[0];
            $this->assertSame($svc->rows->count(), count($section->rows));
            $this->assertEqualsWithDelta((float) $svc->rows->sum('premium'), (float) ($section->totals['premium'] ?? 0), 0.01);
            $this->assertEqualsWithDelta((float) $svc->rows->sum('purchase_cost'), (float) ($section->totals['paid'] ?? 0), 0.01);
        });
    }

    // Data-bearing: with real karigar jobs + invoices, the dataset section
    // totals equal the known service figures (not just 0 == 0).
    public function test_karigar_settlement_reconciles_with_data(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $k1 = (int) \DB::table('karigars')->insertGetId(['shop_id' => $shop->id, 'name' => 'Ramesh', 'created_at' => now(), 'updated_at' => now()]);
            // Open job: issued 50, returned 45, wastage 2, leftover 1 → outstanding 2.
            \DB::table('job_orders')->insert([
                'shop_id' => $shop->id, 'karigar_id' => $k1, 'job_order_number' => 'JO-' . fake()->unique()->numerify('######'),
                'metal_type' => 'gold', 'purity' => 22, 'issue_date' => now()->subDays(10)->toDateString(),
                'status' => 'issued', 'issued_fine_weight' => 50, 'returned_fine_weight' => 45,
                'actual_wastage_fine' => 2, 'leftover_returned_fine_weight' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            \DB::table('karigar_invoices')->insert([
                'shop_id' => $shop->id, 'karigar_id' => $k1, 'karigar_invoice_number' => 'KI-' . fake()->unique()->numerify('######'),
                'karigar_invoice_date' => now()->toDateString(), 'total_after_tax' => 5000, 'amount_paid' => 3000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $svc = app(KarigarService::class)->settlement($shop->id);
            $ds = app(KarigarSettlementDataset::class)->build(
                $this->request(KarigarSettlementDataset::class, $shop->id, ['karigar', 'open_jobs', 'issued', 'received', 'wastage', 'outstanding', 'invoiced', 'paid', 'payable']),
                $this->meta(),
            );
            $section = $ds->sections[0];

            // Known figures (mirrors KarigarSettlementReportTest), now via the spine dataset.
            $this->assertCount(1, $section->rows);
            $this->assertEqualsWithDelta(50.0, (float) ($section->totals['issued'] ?? 0), 0.0001);
            $this->assertEqualsWithDelta(2.0, (float) ($section->totals['outstanding'] ?? 0), 0.0001);
            $this->assertEqualsWithDelta(2000.0, (float) ($section->totals['payable'] ?? 0), 0.01);
            // And still reconciles to the service.
            $this->assertEqualsWithDelta((float) $svc->totalOutstandingFine, (float) ($section->totals['outstanding'] ?? 0), 0.0001);
            $this->assertEqualsWithDelta((float) $svc->totalOutstandingPayable, (float) ($section->totals['payable'] ?? 0), 0.01);
        });
    }

    public function test_metal_exchange_reconciles(): void
    {
        [, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $svc = app(SalesService::class)->metalExchange($shop->id, '2026-04-01', '2026-06-30');
            $ds = app(MetalExchangeDataset::class)->build(
                $this->request(MetalExchangeDataset::class, $shop->id, ['date', 'invoice', 'customer', 'metal', 'purity', 'gross', 'fine', 'amount', 'count']),
                $this->meta(),
            );
            [$summary, $transactions] = $ds->sections;
            // Transaction rows reconcile to the service rows.
            $this->assertSame($svc->rows->count(), count($transactions->rows));
            // Summary section reconciles to the gold/silver summaries.
            $expectedValue = (float) $svc->goldSummary['value'] + (float) $svc->silverSummary['value'];
            $this->assertEqualsWithDelta($expectedValue, (float) ($summary->totals['amount'] ?? 0), 0.01);
            $expectedFine = (float) $svc->goldSummary['fine'] + (float) $svc->silverSummary['fine'];
            $this->assertEqualsWithDelta($expectedFine, (float) ($summary->totals['fine'] ?? 0), 0.001);
        });
    }
}
