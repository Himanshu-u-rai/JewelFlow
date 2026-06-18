<?php

namespace Tests\Feature\Reporting;

use App\Models\Customer;
use App\Models\Karigar;
use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 1 Data Exports — the new bulk data-dump datasets (customers, products,
 * inventory-items, stock-purchases, karigars, karigar-invoices) routed through
 * the canonical reporting pipeline. Verifies each builds, renders all formats,
 * is tenant-isolated, and that PII columns are gated behind the sensitive
 * permission.
 */
class DataExportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const KEYS = [
        // Phase 1
        'customers', 'products', 'inventory-items', 'stock-purchases', 'karigars', 'karigar-invoices',
        // Phase 2
        'job-orders', 'returns', 'credit-notes', 'repairs', 'installment-plans',
        'scheme-enrollments', 'store-credit', 'loyalty', 'old-gold',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Build a ReportRequest; $sensitivePermission controls whether PII is included. */
    private function request(string $key, int $shopId, F $format = F::Csv, bool $includeSensitive = false, bool $sensitivePermission = false): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition($key);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitivePermission);
        $resolution = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user, $includeSensitive);

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: 1, userName: 'Owner', profile: P::Detailed,
            format: $format, filters: [], columnKeys: $resolution->columnKeys,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: [],
            periodLabel: 'All', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(string $key, int $shopId, bool $includeSensitive = false, bool $perm = false)
    {
        $r = $this->request($key, $shopId, F::Csv, $includeSensitive, $perm);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService($key)->build($r, $this->meta($r)));
    }

    private function seedCustomers(int $shopId, int $n = 3): void
    {
        // Customer::create generates customer_code via BusinessIdentifierService,
        // which writes shop_counters keyed off TenantContext — so seed inside it.
        TenantContext::runFor($shopId, function () use ($shopId, $n) {
            for ($i = 0; $i < $n; $i++) {
                Customer::create([
                    'shop_id' => $shopId, 'first_name' => 'Cust', 'last_name' => (string) $i,
                    'mobile' => '90000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'email' => "c{$i}@ex.com", 'address' => 'Addr ' . $i,
                ]);
            }
        });
    }

    private function seedKarigar(int $shopId, string $name = 'Ramesh', ?string $mobile = null): void
    {
        TenantContext::runFor($shopId, fn () => Karigar::create([
            'shop_id' => $shopId, 'name' => $name, 'mobile' => $mobile,
            'opening_balance' => 0, 'is_active' => true,
        ]));
    }

    // ── Every dataset builds + renders all 3 file formats ────────────────

    public function test_every_dataset_builds_and_renders_all_formats(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCustomers($shop->id);
        $this->seedKarigar($shop->id);

        foreach (self::KEYS as $key) {
            $ds = $this->build($key, $shop->id);
            $this->assertNotEmpty($ds->sections, "$key produced no sections");

            foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
                $req = $this->request($key, $shop->id, $format);
                $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($req, $this->meta($req)));
                $this->assertGreaterThan(0, $result->output->byteSize(), "$key/{$format->value} rendered empty");
            }
        }
    }

    // ── Customers: rows + the spend aggregate ────────────────────────────

    public function test_customers_dataset_returns_rows(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCustomers($shop->id, 4);

        $section = $this->build('customers', $shop->id)->sections[0];
        $this->assertCount(4, $section->rows);
        $this->assertArrayHasKey('total_spent', $section->totals);
    }

    // ── PII sensitive gating (the owner's "hide mobile before sharing") ──

    public function test_customer_pii_excluded_without_sensitive_permission(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedCustomers($shop->id, 2);

        // No permission, no opt-in → mobile/email/address NOT in the column keys.
        $req = $this->request('customers', $shop->id, F::Csv, includeSensitive: true, sensitivePermission: false);
        foreach (['mobile', 'email', 'address', 'date_of_birth'] as $pii) {
            $this->assertNotContains($pii, $req->columnKeys, "$pii must be gated without permission");
        }

        // With permission + opt-in → PII present.
        $req2 = $this->request('customers', $shop->id, F::Csv, includeSensitive: true, sensitivePermission: true);
        $this->assertContains('mobile', $req2->columnKeys, 'mobile should appear with permission + opt-in');
    }

    public function test_karigar_pii_is_sensitive(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedKarigar($shop->id, 'Suresh', '9012345678');

        $req = $this->request('karigars', $shop->id, F::Csv, includeSensitive: true, sensitivePermission: false);
        $this->assertNotContains('mobile', $req->columnKeys);
        $this->assertContains('outstanding', $req->columnKeys, 'non-sensitive columns stay');
    }

    // ── Tenant isolation ─────────────────────────────────────────────────

    public function test_customers_dataset_is_tenant_isolated(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->seedCustomers($shopA->id, 3);
        $this->seedCustomers($shopB->id, 7); // B noise must never reach A

        $section = $this->build('customers', $shopA->id)->sections[0];
        $this->assertCount(3, $section->rows, 'shop A must not see shop B customers');
    }

    // ── The /export hub + multi-sheet backup (HTTP) ─────────────────────

    private function bypassTenantMiddleware(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);
    }

    public function test_export_hub_renders_with_cards(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->bypassTenantMiddleware();

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/export'))
            ->assertOk()
            ->assertSee('Data Exports')
            ->assertSee('Export everything')
            ->assertSee(route('reporting.export.panel', ['report' => 'customers']))
            ->assertSee(route('reporting.export.panel', ['report' => 'karigar-invoices']))
            ->assertSee(route('reporting.export.panel', ['report' => 'returns']))
            ->assertSee(route('reporting.export.panel', ['report' => 'old-gold']));
    }

    public function test_backup_downloads_multisheet_xlsx(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedCustomers($shop->id, 2);
        $this->seedKarigar($shop->id);
        $this->bypassTenantMiddleware();

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/export/all'));
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
