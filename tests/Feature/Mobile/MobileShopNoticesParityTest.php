<?php

namespace Tests\Feature\Mobile;

use App\Jobs\Reporting\GenerateQueuedExportJob;
use App\Models\Shop;
use App\Models\User;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app's Shop notices follow the web's one bell (2026-11-14; web leads, app follows): items needing a price review
 * (one row, opening Stock filtered to them) and the user's finished exports, which the app downloads with its own
 * token (the web's signed link needs a web session) and Clear marks read.
 */
class MobileShopNoticesParityTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake((string) config('reporting.queue_disk', 'local'));
    }

    private function quietShop(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => $shop->preferences()->updateOrCreate(['shop_id' => $shop->id], ['low_stock_threshold' => 0]));

        return [$owner, $shop];
    }

    /** A finished background export of the customers report for $user: a real file and its ready-notification. */
    private function finishedExport(User $user, Shop $shop): int
    {
        $payload = TenantContext::runFor((int) $shop->id, function () use ($user, $shop) {
            $definition = app(ReportRegistry::class)->definition('customers');
            $period = app(FilterResolver::class)->resolve(DatePreset::ThisMonth);
            $columns = app(ColumnPolicy::class)->resolve($definition, ReportProfile::Detailed, $user);
            $request = new ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $user->id,
                userName: (string) $user->name, profile: ReportProfile::Detailed, format: ExportFormat::Csv,
                filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
                columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
            $export = app(ExportAuditService::class)->recordQueued($request, false);

            return [
                'export_id' => $export->id, 'report_key' => 'customers', 'shop_id' => (int) $shop->id,
                'user_id' => (int) $user->id, 'user_name' => (string) $user->name,
                'profile' => ReportProfile::Detailed->value, 'format' => ExportFormat::Csv->value,
                'date_preset' => DatePreset::ThisMonth->value, 'date_from' => $period->from->toIso8601String(),
                'date_to' => $period->to->toIso8601String(), 'fy_name' => null, 'filters' => $request->filters,
                'column_keys' => $request->columnKeys, 'include_sensitive' => false, 'reveal_masked' => false,
                'filters_applied' => ['Period' => $period->label], 'watermark' => null,
                'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null],
            ];
        });
        GenerateQueuedExportJob::dispatchSync($payload);

        return $payload['export_id'];
    }

    /**
     * A request as the signed-in user's shop. In a real request the shop scope falls back to the signed-in user
     * (auth:sanctum runs before route binding); under PHPUnit (console) it reads only TenantContext.
     */
    private function asShop(Shop $shop, \Closure $request)
    {
        return TenantContext::runFor((int) $shop->id, $request);
    }

    private function unread(User $user): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->whereNull('read_at')->count();
    }

    public function test_the_dashboard_counts_items_needing_a_price_review_for_stock_users_only(): void
    {
        [$owner, $shop] = $this->quietShop();
        foreach ([1, 2, 3] as $n) {
            $this->createItem($shop->id, null, ['barcode' => "REVIEW-{$n}", 'pricing_review_required' => true]);
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/mobile/dashboard')->assertOk()->assertJsonPath('alerts.pricing_review_count', 3);

        $this->grantOnlyPermissions($owner, []);
        Sanctum::actingAs($owner->fresh());
        $this->getJson('/api/mobile/dashboard')->assertOk()->assertJsonPath('alerts.pricing_review_count', 0);
    }

    public function test_stock_can_show_just_the_items_needing_a_price_review(): void
    {
        [$owner, $shop] = $this->quietShop();
        $this->createItem($shop->id, null, ['barcode' => 'REVIEW-1', 'pricing_review_required' => true]);
        $this->createItem($shop->id, null, ['barcode' => 'FINE-1']);

        Sanctum::actingAs($owner);
        $barcodes = collect($this->getJson('/api/mobile/stock?pricing_review=1')->assertOk()->json('data'))->pluck('barcode')->all();

        $this->assertSame(['REVIEW-1'], $barcodes);
    }

    public function test_the_dashboard_lists_the_users_own_ready_downloads(): void
    {
        [$owner, $shop] = $this->quietShop();
        $colleague = $this->createOwnerUser($shop, \App\Models\Role::withoutGlobalScopes()->findOrFail($owner->role_id));
        $exportId = $this->finishedExport($owner, $shop);

        Sanctum::actingAs($owner);
        $downloads = $this->getJson('/api/mobile/dashboard')->assertOk()->json('alerts.downloads');
        $this->assertCount(1, $downloads);
        $this->assertSame($exportId, $downloads[0]['export_id']);
        $this->assertSame('Customers is ready to download', $downloads[0]['title']);
        $this->assertNotEmpty($downloads[0]['file_name']);
        $this->assertArrayNotHasKey('href', $downloads[0], 'the web link needs a web session: the app downloads by id');

        Sanctum::actingAs($colleague);
        $this->assertSame([], $this->getJson('/api/mobile/dashboard')->assertOk()->json('alerts.downloads'));
    }

    public function test_the_app_downloads_an_export_with_its_token_and_that_marks_it_read(): void
    {
        [$owner, $shop] = $this->quietShop();
        $exportId = $this->finishedExport($owner, $shop);
        $this->assertSame(1, $this->unread($owner));

        Sanctum::actingAs($owner);
        $file = $this->asShop($shop, fn () => $this->get("/api/mobile/exports/{$exportId}/download"));

        $file->assertOk();
        $this->assertStringContainsString('attachment', (string) $file->headers->get('Content-Disposition'));
        $this->assertSame(0, $this->unread($owner));
    }

    public function test_another_shop_or_a_user_without_the_report_cannot_download_it(): void
    {
        [$owner, $shop] = $this->quietShop();
        $exportId = $this->finishedExport($owner, $shop);

        [$stranger, $strangerShop] = $this->createRetailerTenant();
        Sanctum::actingAs($stranger);
        $this->assertContains($this->asShop($strangerShop, fn () => $this->get("/api/mobile/exports/{$exportId}/download"))->status(), [403, 404]);

        $this->grantOnlyPermissions($owner, ['inventory.view']);
        Sanctum::actingAs($owner->fresh());
        $this->asShop($shop, fn () => $this->get("/api/mobile/exports/{$exportId}/download"))->assertForbidden();
    }

    public function test_clear_marks_only_this_users_downloads_read(): void
    {
        [$owner, $shop] = $this->quietShop();
        $colleague = $this->createOwnerUser($shop, \App\Models\Role::withoutGlobalScopes()->findOrFail($owner->role_id));
        $this->finishedExport($owner, $shop);
        $this->finishedExport($colleague, $shop);

        Sanctum::actingAs($owner);
        $this->postJson('/api/mobile/notices/downloads/clear')->assertOk();

        $this->assertSame(0, $this->unread($owner));
        $this->assertSame(1, $this->unread($colleague));
    }
}
