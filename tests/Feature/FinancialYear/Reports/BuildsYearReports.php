<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Filters\FilterResolver;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\FinancialYear\SnapshotTestHelpers;

/** Shared setup for the financial-year report tests: run a registered dataset for a named year, seed raw movements. */
trait BuildsYearReports
{
    use SnapshotTestHelpers;

    private function keysFor(string $key, bool $sensitive, P $profile = P::Detailed): array
    {
        $def = app(ReportRegistry::class)->definition($key);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitive);

        return app(ColumnPolicy::class)->resolve($def, $profile, $user, includeSensitive: $sensitive)->columnKeys;
    }

    private function runReport(string $key, int $shopId, string $fy = '2025-26', bool $sensitive = true, P $profile = P::Detailed): ReportDataset
    {
        $period = app(FilterResolver::class)->namedFy($fy);
        $request = new ReportRequest(
            definition: app(ReportRegistry::class)->definition($key),
            shopId: $shopId, userId: 1, userName: 'Owner', profile: $profile, format: F::Screen,
            filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
            columnKeys: $this->keysFor($key, $sensitive, $profile), includeSensitive: $sensitive,
        );
        $meta = new ReportMeta(
            reportKey: $key, reportVersion: $request->definition->version, title: $request->definition->title,
            profileLabel: 'Detailed', format: 'screen', filtersApplied: ['Period' => $period->label],
            periodLabel: $period->label, shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test',
        );

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService($key)->build($request, $meta));
    }

    /** A movement written straight into the ledger (insert is allowed; the table only refuses update/delete). */
    private function rawMovement(int $shopId, int $itemId, string $type, ?string $from, ?string $to, string $at, array $extra = []): void
    {
        DB::table('item_movements')->insert(array_merge([
            'shop_id' => $shopId, 'item_id' => $itemId, 'movement_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'gross_weight' => 10, 'net_metal_weight' => 9.5, 'purity' => 22, 'metal_type' => 'gold',
            'acquisition_cost' => null, 'occurred_at' => $at, 'created_at' => now(),
        ], $extra));
    }

    private function goldItem(int $shopId, string $barcode, ?float $cost = null, array $attrs = [])
    {
        return $this->createItem($shopId, null, array_merge([
            'barcode' => $barcode, 'gross_weight' => 10, 'net_metal_weight' => 9.5, 'purity' => 22, 'metal_type' => 'gold',
            'acquisition_cost' => $cost, 'acquisition_cost_source' => $cost === null ? null : 'purchase',
        ], $attrs));
    }

    private function rowsBy(ReportDataset $ds, string $section, string $key): array
    {
        $out = [];
        foreach ($ds->section($section)->rows as $r) {
            $out[$r[$key]] = $r;
        }

        return $out;
    }
}
