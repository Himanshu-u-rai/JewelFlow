<?php

namespace Tests\Feature\Security;

use App\Jobs\Reporting\GenerateQueuedExportJob;
use App\Models\Reporting\ReportExport;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\Reporting\ExportReadyNotification;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, exports and background jobs — the queued export path.
 *
 * S3-18. GenerateQueuedExportJob stored every file at
 * reporting-exports/{report}-{Ymd-His}.{ext}: no shop, no export id. Two
 * exports of the same report and format finishing in the same second — any
 * two shops — got the same path; the second overwrote the first, and the
 * first shop's download then served the second shop's data. Reproduced first
 * in tests/Concurrency/queue_worker_tenant_reuse.php by a real worker, where
 * two exports landed in one second by chance; here the clock is frozen so it
 * is deterministic.
 *
 * Notifications are faked so the job can finish: in this schema the
 * `database` channel it uses has no table, which fails every queued export
 * after its file is written (S3-17, separate).
 */
class QueuedExportIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake((string) config('reporting.queue_disk', 'local'));
        Notification::fake();
        Carbon::setTestNow('2026-09-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shopWithCustomer(string $name): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer((int) $shop->id, ['first_name' => $name]);

        return [$owner, $shop];
    }

    /** An export row and its job payload, built as ExportController::dispatchQueued() builds them. */
    private function queued(User $owner, Shop $shop): array
    {
        return TenantContext::runFor((int) $shop->id, function () use ($owner, $shop) {
            $definition = app(ReportRegistry::class)->definition('customers');
            $period = app(FilterResolver::class)->resolve(DatePreset::ThisMonth);
            $columns = app(ColumnPolicy::class)->resolve($definition, ReportProfile::Detailed, $owner);
            $request = new ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $owner->id,
                userName: (string) $owner->name, profile: ReportProfile::Detailed, format: ExportFormat::Csv,
                filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
                columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
            $export = app(ExportAuditService::class)->recordQueued($request, false);

            return [$export->id, [
                'export_id' => $export->id, 'report_key' => 'customers', 'shop_id' => (int) $shop->id,
                'user_id' => (int) $owner->id, 'user_name' => (string) $owner->name,
                'profile' => ReportProfile::Detailed->value, 'format' => ExportFormat::Csv->value,
                'date_preset' => DatePreset::ThisMonth->value, 'date_from' => $period->from->toIso8601String(),
                'date_to' => $period->to->toIso8601String(), 'fy_name' => null, 'filters' => $request->filters,
                'column_keys' => $request->columnKeys, 'include_sensitive' => false, 'reveal_masked' => false,
                'filters_applied' => ['Period' => $period->label], 'watermark' => null,
                'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null],
            ]];
        });
    }

    private function row(int $id): ReportExport
    {
        return ReportExport::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_s3_18_two_shops_exports_in_the_same_second_keep_their_own_files(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [$ownerB, $shopB] = $this->shopWithCustomer('BravoOnlyXR');
        [$idA, $payloadA] = $this->queued($ownerA, $shopA);
        [$idB, $payloadB] = $this->queued($ownerB, $shopB);

        GenerateQueuedExportJob::dispatchSync($payloadA);
        GenerateQueuedExportJob::dispatchSync($payloadB);   // same second: the clock is frozen

        $a = $this->row($idA);
        $b = $this->row($idB);
        $this->assertSame(ExportAuditService::STATUS_DONE, $a->status);
        $this->assertSame(ExportAuditService::STATUS_DONE, $b->status);

        // What shop A's owner actually downloads: the signed link its
        // ready-notification carries.
        $link = (new ExportReadyNotification($a))->toDatabase($ownerA)['download_url'];
        $download = TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)->get($link));
        $download->assertOk();
        $body = $download->streamedContent();
        $this->assertStringContainsString('AlphaOnlyXR', $body);
        $this->assertStringNotContainsString('BravoOnlyXR', $body, "shop A's download must not contain shop B's customers");
        $this->assertNotSame($a->file_path, $b->file_path, 'each export has its own file');

        $this->assertStringContainsString('BravoOnlyXR', Storage::disk($b->file_disk)->get($b->file_path));
        $this->assertSame(basename($a->file_path), basename($b->file_path), 'the file name the user sees is unchanged');
    }

    /** §7e gap "no test hands a job a mismatched shop id": the job does nothing. */
    public function test_a_payload_naming_another_shops_export_writes_nothing(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [$ownerB, $shopB] = $this->shopWithCustomer('BravoOnlyXR');
        [, $payloadA] = $this->queued($ownerA, $shopA);
        [$idB] = $this->queued($ownerB, $shopB);

        GenerateQueuedExportJob::dispatchSync(['export_id' => $idB] + $payloadA);

        $b = $this->row($idB);
        $this->assertSame(ExportAuditService::STATUS_QUEUED, $b->status, "shop B's export row is untouched");
        $this->assertNull($b->file_path);
        $this->assertSame([], Storage::disk((string) config('reporting.queue_disk', 'local'))->allFiles());
    }
}
