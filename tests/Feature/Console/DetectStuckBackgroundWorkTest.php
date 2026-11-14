<?php

namespace Tests\Feature\Console;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Import;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ExportAuditService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N6/N20 (2026-11-13): an import, a Historical publish or a report export that died left its row "queued",
 * "running" or "publishing", and shop:detect-stuck looked at none of them. The causes are fixed (nothing of the kind
 * is committed any more, and the queue's give-up marks an export failed); the check finds rows left from before,
 * or from a cause not yet known. Read-only, as the rest of the command.
 */
class DetectStuckBackgroundWorkTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function import(int $shopId, string $status): Import
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $status) {
            $import = new Import();
            $import->forceFill(['shop_id' => $shopId, 'type' => Import::TYPE_STOCK, 'file_path' => 'imports/x.csv',
                'import_reference' => 'IMP-' . uniqid(), 'status' => $status, 'valid_rows' => 1])->save();

            return $import;
        });
    }

    private function export(int $shopId, int $userId, string $status): void
    {
        DB::table('report_exports')->insert([
            'shop_id' => $shopId, 'user_id' => $userId, 'report_key' => 'customers', 'report_version' => '1',
            'profile' => 'detailed', 'format' => 'csv', 'mode' => 'queued', 'status' => $status,
            'sensitive_included' => DB::raw('false'), 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_work_left_waiting_for_over_an_hour_is_reported_and_nothing_is_changed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->travel(-2)->hours();
        $import = $this->import($shop->id, Import::STATUS_QUEUED);
        $batch = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = new HistoricalImportBatch();
            $batch->forceFill(['shop_id' => $shop->id, 'label' => 'FY', 'source_system' => 'Manual', 'status' => HistoricalImportBatch::STATUS_REVIEW])->save();
            $batch->forceFill(['status' => HistoricalImportBatch::STATUS_PUBLISHING])->save();

            return $batch;
        });
        $this->export($shop->id, $owner->id, ExportAuditService::STATUS_QUEUED);
        $this->travelBack();

        $this->import($shop->id, Import::STATUS_QUEUED);                // fresh: not stuck
        $this->export($shop->id, $owner->id, ExportAuditService::STATUS_QUEUED);

        $this->artisan('shop:detect-stuck', ['--shop' => $shop->id])
            ->expectsOutputToContain('1 import(s) left queued or running')
            ->expectsOutputToContain('1 Historical batch(es) left publishing')
            ->expectsOutputToContain('1 report export(s) left queued')
            ->assertExitCode(1);

        $this->assertSame(Import::STATUS_QUEUED, $import->fresh()->status, 'read-only');
        $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHING, DB::table('historical_import_batches')->where('id', $batch->id)->value('status'));
    }
}
