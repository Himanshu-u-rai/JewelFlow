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
 * Notifications are faked here; delivery itself (S3-17) is tested on the real
 * `database` channel in ExportNotificationDeliveryTest.
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

    // ── Existing exports stored before the per-export layout ──────────────
    //
    // 11e91c3 protects NEW exports. A file written earlier sits at a flat
    // reporting-exports/{report}-{Ymd-His}.{ext}, and nothing recorded proves
    // whose bytes it holds: two rows may name it, or one row may name a file
    // that a later job overwrote and then crashed before recording its own
    // path. These cases are built directly — rows and bytes exactly as the
    // old job left them — and fetched through the real download route.

    /** A finished export row pointing at $path, as the old job recorded it. */
    private function legacyRow(User $owner, Shop $shop, string $path): ReportExport
    {
        [$id] = $this->queued($owner, $shop);
        TenantContext::runFor((int) $shop->id, fn () => app(ExportAuditService::class)->markFinished(
            $this->row($id), 1, (string) config('reporting.queue_disk', 'local'), $path,
            \Carbon\CarbonImmutable::now()->addDays(7)));

        return $this->row($id);
    }

    private function download(User $owner, Shop $shop, ReportExport $export)
    {
        $link = (new ExportReadyNotification($export))->toDatabase($owner)['download_url'];

        return TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get($link));
    }

    public function test_s3_18_a_legacy_file_shared_by_two_shops_is_not_served_to_either(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [$ownerB, $shopB] = $this->shopWithCustomer('BravoOnlyXR');
        $flat = 'reporting-exports/customers-20260101-100000.csv';
        $a = $this->legacyRow($ownerA, $shopA, $flat);
        $b = $this->legacyRow($ownerB, $shopB, $flat);
        $disk = Storage::disk((string) config('reporting.queue_disk', 'local'));
        $disk->put($flat, "Full Name\nBravoOnlyXR Customer\n");   // B's job wrote last

        $resA = $this->download($ownerA, $shopA, $a);
        // Only a served file can carry B's rows. (A refusal here is an HTML
        // error page, which in the debug test environment lists every query
        // the process ran — fixture inserts included — so it is not searched.)
        if ($resA->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $this->assertStringNotContainsString('BravoOnlyXR', $resA->streamedContent(), "shop A must not receive shop B's customers");
        }
        $resA->assertStatus(410);
        $this->download($ownerB, $shopB, $b)->assertStatus(410);

        // Evidence preserved: the file and both rows exactly as they were.
        $this->assertSame("Full Name\nBravoOnlyXR Customer\n", $disk->get($flat));
        $this->assertSame($flat, $this->row($a->id)->file_path);
        $this->assertSame(ExportAuditService::STATUS_DONE, $this->row($b->id)->status);
    }

    /**
     * Why the rule is not "refuse duplicates": here only ONE row names the
     * path, yet the bytes are another shop's — a later job overwrote the file
     * and failed before recording its own path. No metadata shows it.
     */
    public function test_s3_18_a_legacy_file_named_by_one_row_is_still_not_proof_of_ownership(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        $flat = 'reporting-exports/customers-20260101-110000.csv';
        $a = $this->legacyRow($ownerA, $shopA, $flat);
        Storage::disk((string) config('reporting.queue_disk', 'local'))->put($flat, "Full Name\nBravoOnlyXR Customer\n");

        $res = $this->download($ownerA, $shopA, $a);

        $res->assertStatus(410);
        $this->assertSame(1, ReportExport::withoutGlobalScopes()->where('file_path', $flat)->count(), 'no duplicate metadata exists');
    }

    public function test_s3_18_a_path_that_climbs_out_of_its_directory_is_refused(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [$id] = $this->queued($ownerA, $shopA);
        $climb = "reporting-exports/{$shopA->id}/{$id}/../../other/customers-20260101-120000.csv";
        $a = $this->legacyRow($ownerA, $shopA, $climb);
        // Where that path actually resolves: two levels up, outside its directory.
        Storage::disk((string) config('reporting.queue_disk', 'local'))->put('reporting-exports/other/customers-20260101-120000.csv', 'X');

        $this->download($ownerA, $shopA, $a)->assertStatus(410);
    }

    // ── The read-only audit for existing files (R10) ──────────────────────

    public function test_the_export_file_audit_groups_by_physical_file_and_separates_cross_shop_sharing(): void
    {
        // A second disk name for the same place, as a server's config might have.
        config(['filesystems.disks.exports_alias' => config('filesystems.disks.local')]);
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [$ownerB, $shopB] = $this->shopWithCustomer('BravoOnlyXR');

        $crossA = $this->legacyRow($ownerA, $shopA, 'reporting-exports/customers-20260101-090000.csv');
        $crossB = $this->legacyRow($ownerB, $shopB, 'reporting-exports//customers-20260101-090000.csv'); // same file, unnormalized
        $sameA1 = $this->legacyRow($ownerA, $shopA, 'reporting-exports/customers-20260102-090000.csv');
        $sameA2 = $this->legacyRow($ownerA, $shopA, 'reporting-exports/customers-20260102-090000.csv');
        $aliasA = $this->legacyRow($ownerA, $shopA, 'reporting-exports/customers-20260103-090000.csv');
        $aliasB = $this->legacyRow($ownerB, $shopB, 'reporting-exports/customers-20260103-090000.csv');
        TenantContext::runFor((int) $shopB->id, fn () => $aliasB->update(['file_disk' => 'exports_alias']));
        $before = ReportExport::withoutGlobalScopes()->orderBy('id')->get()->toArray();

        $exit = \Illuminate\Support\Facades\Artisan::call('reporting:audit-export-files');
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $exit, 'a cross-shop shared file fails the audit');
        $this->assertStringContainsString('shared files: 3 — same shop only: 1, CROSS-SHOP: 2', $out);
        $this->assertStringContainsString("export {$crossA->id}  shop {$shopA->id}", $out);
        $this->assertStringContainsString("export {$crossB->id}  shop {$shopB->id}", $out);
        $this->assertStringContainsString("export {$aliasB->id}  shop {$shopB->id}", $out, 'the alias disk resolves to the same file');
        $this->assertStringNotContainsString("export {$sameA1->id} ", $out, 'same-shop sharing is counted, not listed as cross-shop');
        $this->assertStringContainsString('outside their own directory (refused at download since S3-18): 6', $out);
        $this->assertSame($before, ReportExport::withoutGlobalScopes()->orderBy('id')->get()->toArray(), 'read-only');
    }

    public function test_the_export_file_audit_passes_when_every_file_is_its_own(): void
    {
        [$ownerA, $shopA] = $this->shopWithCustomer('AlphaOnlyXR');
        [, $payload] = $this->queued($ownerA, $shopA);
        GenerateQueuedExportJob::dispatchSync($payload);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('reporting:audit-export-files'));
        $this->assertStringContainsString('outside their own directory (refused at download since S3-18): 0', \Illuminate\Support\Facades\Artisan::output());
    }
}

