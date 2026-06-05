<?php

namespace Tests\Feature\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\ExportAuditService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\Reporting\StubReportService;
use Tests\TestCase;

/**
 * Export-audit trail (frozen §16): sync / queued / failed each write exactly one
 * report_exports row with the right status + sensitive flag, and provenance is
 * immutable once written.
 */
class ExportAuditTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function request(int $shopId, bool $includeSensitive = false): ReportRequest
    {
        $definition = (new StubReportService())->definition();

        return new ReportRequest(
            definition: $definition,
            shopId: $shopId,
            userId: null,
            userName: 'Tester',
            profile: ReportProfile::Detailed,
            format: ExportFormat::Csv,
            filters: ['period' => ['from' => CarbonImmutable::parse('2026-04-01'), 'to' => CarbonImmutable::parse('2026-06-30')]],
            columnKeys: ['a'],
            includeSensitive: $includeSensitive,
        );
    }

    public function test_sync_queued_and_failed_each_write_one_row(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $audit = app(ExportAuditService::class);
            $audit->recordSync($this->request($shop->id, includeSensitive: true), sensitiveIncluded: true, rowCount: 42);
            $audit->recordQueued($this->request($shop->id), sensitiveIncluded: false);
            $audit->recordFailure($this->request($shop->id), sensitiveIncluded: false, mode: 'queued', error: 'boom');
        });

        $rows = ReportExport::withoutTenant()->where('shop_id', $shop->id)->get();
        $this->assertCount(3, $rows);

        $sync = $rows->firstWhere('mode', 'sync');
        $this->assertSame('done', $sync->status);
        $this->assertTrue($sync->sensitive_included);
        $this->assertSame(42, $sync->row_count);
        $this->assertSame('stub-report', $sync->report_key);
        $this->assertSame('stub-report@1', $sync->report_version);

        $this->assertSame('queued', $rows->firstWhere('status', 'queued')->status);

        $failed = $rows->firstWhere('status', 'failed');
        $this->assertSame('boom', $failed->error);
    }

    public function test_mark_finished_transitions_lifecycle(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $audit = app(ExportAuditService::class);
            $export = $audit->recordQueued($this->request($shop->id), sensitiveIncluded: false);

            $audit->markFinished($export, rowCount: 99, fileDisk: 'local', filePath: 'reporting-exports/x.csv', expiresAt: CarbonImmutable::now()->addDays(7));

            $fresh = ReportExport::withoutTenant()->find($export->id);
            $this->assertSame('done', $fresh->status);
            $this->assertSame(99, $fresh->row_count);
            $this->assertSame('reporting-exports/x.csv', $fresh->file_path);
        });
    }

    public function test_provenance_is_immutable(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $export = app(ExportAuditService::class)->recordSync($this->request($shop->id), sensitiveIncluded: false, rowCount: 1);

            $this->expectException(LogicException::class);
            $export->update(['report_key' => 'tampered']);
        });
    }
}
