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
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-17 — a queued export's ready-notification, on the real `database`
 * channel. No notification fake: the rows asserted are the rows written.
 *
 * Before: no migration created the `notifications` table, so every queued
 * export failed AFTER its file was written, and a notification failure turned
 * a generated export from done into failed. Nothing in the application read
 * the notifications back either.
 */
class ExportNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;


    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake((string) config('reporting.queue_disk', 'local'));
    }

    /** The queued-export payload, as ExportController::dispatchQueued() builds it. */
    private function queued(User $user, Shop $shop): array
    {
        return TenantContext::runFor((int) $shop->id, function () use ($user, $shop) {
            $definition = app(ReportRegistry::class)->definition('customers');
            $period = app(FilterResolver::class)->resolve(DatePreset::ThisMonth);
            $columns = app(ColumnPolicy::class)->resolve($definition, ReportProfile::Detailed, $user);
            $request = new ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $user->id,
                userName: (string) $user->name, profile: ReportProfile::Detailed, format: ExportFormat::Csv,
                filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
                columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
            $export = app(ExportAuditService::class)->recordQueued($request, false);

            return [$export->id, [
                'export_id' => $export->id, 'report_key' => 'customers', 'shop_id' => (int) $shop->id,
                'user_id' => (int) $user->id, 'user_name' => (string) $user->name,
                'profile' => ReportProfile::Detailed->value, 'format' => ExportFormat::Csv->value,
                'date_preset' => DatePreset::ThisMonth->value, 'date_from' => $period->from->toIso8601String(),
                'date_to' => $period->to->toIso8601String(), 'fy_name' => null, 'filters' => $request->filters,
                'column_keys' => $request->columnKeys, 'include_sensitive' => false, 'reveal_masked' => false,
                'filters_applied' => ['Period' => $period->label], 'watermark' => null,
                'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null],
            ]];
        });
    }

    private function export(int $id): ReportExport
    {
        return ReportExport::withoutGlobalScopes()->findOrFail($id);
    }

    /** @return \Illuminate\Support\Collection<int, object> notifications rows naming this export */
    private function notificationsFor(int $exportId)
    {
        return DB::table('notifications')->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->get();
    }

    private function as(User $user, Shop $shop, string $url)
    {
        return TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($user)->get($url));
    }

    public function test_a_finished_export_is_delivered_to_its_requester_and_shown_in_app(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer((int) $shop->id, ['first_name' => 'AlphaOnlyXR']);
        [$id, $payload] = $this->queued($owner, $shop);

        GenerateQueuedExportJob::dispatchSync($payload);

        $export = $this->export($id);
        $this->assertSame(ExportAuditService::STATUS_DONE, $export->status);
        $this->assertNotNull($export->notified_at);
        $this->assertNull($export->notification_error);
        $rows = $this->notificationsFor($id);
        $this->assertCount(1, $rows, 'one real notification row');
        $this->assertSame(ExportReadyNotification::class, $rows[0]->type);
        $this->assertSame((int) $owner->id, (int) $rows[0]->notifiable_id);
        $link = json_decode($rows[0]->data, true)['download_url'];

        // The consumer: the export panel lists it, and the link downloads the file.
        $this->as($owner, $shop, '/reports/customers/export')->assertOk()->assertSee('Ready to download');
        $download = $this->as($owner, $shop, $link);
        $download->assertOk();
        $this->assertStringContainsString('AlphaOnlyXR', $download->streamedContent());
        $this->assertNotNull(DB::table('notifications')->where('id', $rows[0]->id)->value('read_at'), 'downloading marks it read');
    }

    public function test_a_delivery_failure_keeps_the_export_done_and_a_retry_delivers_without_regenerating(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$id, $payload] = $this->queued($owner, $shop);
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('delivery refused (injected)'));

        GenerateQueuedExportJob::dispatchSync($payload);

        $failed = $this->export($id);
        $this->assertSame(ExportAuditService::STATUS_DONE, $failed->status, 'a delivery failure no longer fails the export');
        $this->assertNull($failed->notified_at);
        $this->assertSame('delivery refused (injected)', $failed->notification_error);
        $this->assertCount(0, $this->notificationsFor($id));
        $bytes = Storage::disk($failed->file_disk)->get($failed->file_path);

        Event::forget(NotificationSending::class);
        $this->assertSame(0, Artisan::call('reporting:notify-export'), 'listing is read-only');
        $this->assertStringContainsString("export {$id}", Artisan::output());
        $this->assertSame(0, Artisan::call('reporting:notify-export', ['export' => $id]));

        $retried = $this->export($id);
        $this->assertNotNull($retried->notified_at);
        $this->assertNull($retried->notification_error);
        $this->assertCount(1, $this->notificationsFor($id));
        $this->assertSame($bytes, Storage::disk($retried->file_disk)->get($retried->file_path), 'the file was not regenerated');
        $this->assertEquals($failed->finished_at, $retried->finished_at);
        $this->assertSame($failed->file_path, $retried->file_path);

        $this->assertSame(1, Artisan::call('reporting:notify-export', ['export' => $id]), 'an export already notified is refused');
        $this->assertCount(1, $this->notificationsFor($id));
    }

    public function test_the_original_missing_table_failure_leaves_the_export_done(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$id, $payload] = $this->queued($owner, $shop);
        Schema::drop('notifications');   // the S3-17 state; restored when the test's transaction rolls back

        GenerateQueuedExportJob::dispatchSync($payload);

        $export = $this->export($id);
        $this->assertSame(ExportAuditService::STATUS_DONE, $export->status);
        $this->assertNull($export->notified_at);
        $this->assertStringContainsString('relation "notifications" does not exist', $export->notification_error);
        $this->assertStringNotContainsString('signature=', $export->notification_error, 'the signed link is not stored');
    }

    public function test_a_job_rerun_after_its_worker_died_delivers_without_regenerating(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$id, $payload] = $this->queued($owner, $shop);
        // The worker died between markFinished and delivery: a finished row and
        // its file, and no delivery record of either kind.
        $disk = (string) config('reporting.queue_disk', 'local');
        $path = $this->export($id)->storageDirectory().'/customers-original.csv';
        Storage::disk($disk)->put($path, 'original bytes');
        TenantContext::runFor((int) $shop->id, fn () => app(ExportAuditService::class)
            ->markFinished($this->export($id), 1, $disk, $path, now()->addDays(7)));

        $this->assertSame(0, Artisan::call('reporting:notify-export'));
        $this->assertStringContainsString("export {$id}  shop {$shop->id}: no delivery attempt recorded", Artisan::output());

        GenerateQueuedExportJob::dispatchSync($payload);   // the queue's retry of the same job
        GenerateQueuedExportJob::dispatchSync($payload);   // and again

        $after = $this->export($id);
        $this->assertSame($path, $after->file_path, 'not regenerated');
        $this->assertSame('original bytes', Storage::disk($disk)->get($path));
        $this->assertNotNull($after->notified_at);
        $this->assertCount(1, $this->notificationsFor($id), 'delivered once');
    }

    public function test_the_notification_and_its_link_stay_with_the_requesting_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        [$id, $payload] = $this->queued($ownerA, $shopA);
        GenerateQueuedExportJob::dispatchSync($payload);
        $link = json_decode($this->notificationsFor($id)[0]->data, true)['download_url'];

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $ownerB->id)->count(), "nothing reaches shop B's owner");
        $this->as($ownerB, $shopB, '/reports/customers/export')->assertOk()->assertDontSee('Ready to download');
        $foreign = $this->as($ownerB, $shopB, $link);   // the scoped binding refuses before the controller runs
        $foreign->assertNotFound();
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $foreign->baseResponse);
    }

    public function test_a_permission_revoked_after_delivery_blocks_the_download(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$id, $payload] = $this->queued($owner, $shop);
        GenerateQueuedExportJob::dispatchSync($payload);
        $link = json_decode($this->notificationsFor($id)[0]->data, true)['download_url'];
        $this->as($owner, $shop, $link)->assertOk();

        $this->grantOnlyPermissions($owner, ['reports.view']);   // export permission withdrawn
        $owner->unsetRelation('role');

        $this->as($owner->fresh(), $shop, $link)->assertForbidden();
        $this->assertSame(ExportAuditService::STATUS_DONE, $this->export($id)->status);
    }
}
