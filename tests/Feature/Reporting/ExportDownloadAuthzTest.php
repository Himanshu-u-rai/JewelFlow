<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\Support\Reporting\StubReportService;
use Tests\TestCase;

/**
 * Queued-export download is signature ⊕ authz, never bearer-only (frozen §20, H-3).
 * A signed link alone is insufficient: the endpoint requires auth, the file's
 * tenant, and the report permission (plus sensitive permission for sensitive files).
 */
class ExportDownloadAuthzTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Storage::fake('local');
        app(ReportRegistry::class)->register(StubReportService::KEY, StubReportService::class);

        // Isolate the authz under test (auth + signature + tenant + permission);
        // the orthogonal app gates are exercised by their own suites.
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);
    }

    private function grant(User $user, array $names): void
    {
        $ids = Permission::whereIn('name', $names)->pluck('id');
        TenantContext::runFor((int) $user->shop_id, function () use ($user, $ids) {
            $user->role->permissions()->syncWithoutDetaching($ids);
        });
        $user->unsetRelation('role');
    }

    private function makeExport(int $shopId, array $overrides = []): ReportExport
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $overrides) {
            $path = 'reporting-exports/' . uniqid('exp_') . '.csv';
            Storage::disk('local')->put($path, "a\n100");

            return ReportExport::create(array_merge([
                'shop_id' => $shopId, 'user_id' => null,
                'report_key' => StubReportService::KEY, 'report_version' => 'stub-report@1',
                'profile' => 'detailed', 'format' => 'csv', 'filters' => [],
                'sensitive_included' => false, 'mode' => 'queued', 'status' => 'done',
                'row_count' => 1, 'file_disk' => 'local', 'file_path' => $path,
                'expires_at' => now()->addDays(7), 'generated_at' => now(),
            ], $overrides));
        });
    }

    private function signed(ReportExport $export): string
    {
        return URL::temporarySignedRoute('reporting.exports.download', now()->addHour(), ['export' => $export->id]);
    }

    /**
     * Drive the request under an explicit tenant (feature tests run in console,
     * so BelongsToShop has no auth-based tenant — production sets it via middleware).
     */
    private function hit(User $user, int $tenantShopId, string $url)
    {
        return TenantContext::runFor($tenantShopId, fn () => $this->actingAs($user)->get($url));
    }

    public function test_unsigned_url_is_rejected(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']);
        $export = $this->makeExport($shop->id);

        $unsigned = route('reporting.exports.download', ['export' => $export->id]);
        $this->hit($owner, $shop->id, $unsigned)->assertForbidden();
    }

    public function test_authorized_owner_downloads(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']);
        $export = $this->makeExport($shop->id);

        $this->hit($owner, $shop->id, $this->signed($export))->assertOk();
    }

    public function test_foreign_tenant_cannot_download(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->grant($ownerB, ['reports.view', 'reports.export']);

        $exportA = $this->makeExport($shopA->id);

        // Valid signature, wrong tenant → the global scope hides the row (404).
        $this->hit($ownerB, $shopB->id, $this->signed($exportA))->assertNotFound();
    }

    public function test_sensitive_file_requires_sensitive_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']); // NOT export_sensitive
        $export = $this->makeExport($shop->id, ['sensitive_included' => true]);

        $this->hit($owner, $shop->id, $this->signed($export))->assertForbidden();

        // Granting the sensitive permission unlocks it.
        $this->grant($owner, ['reports.export_sensitive']);
        $this->hit($owner, $shop->id, $this->signed($export))->assertOk();
    }

    public function test_expired_export_returns_410(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->grant($owner, ['reports.view', 'reports.export']);
        $export = $this->makeExport($shop->id, ['expires_at' => now()->subDay()]);

        $this->hit($owner, $shop->id, $this->signed($export))->assertStatus(410);
    }
}
