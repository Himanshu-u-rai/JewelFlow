<?php

namespace Tests\Feature\Manufacture;

use App\Models\JobOrder;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Manufacture / Karigar / Job Orders (Module 12). The metal-source, retained,
 * transfer, over-consumption and payment-reversal logic is already covered by
 * the Material/JobOrder* suites; this closes the untested HTTP surface: karigar
 * create shop-binding, cross-shop karigar / job-order / karigar-invoice
 * isolation, the cross-shop-karigar guard on job-order create (karigar_id is
 * validated only as `integer`, so the controller's abort_unless is the sole
 * guard — pin it), the karigar.* / job_order.* permission gates, and the
 * retailer-edition gate.
 */
class JobOrderAccessAndIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    private function karigar(Shop $shop, string $name = 'K'): int
    {
        return TenantContext::runFor($shop->id, fn () => (int) DB::table('karigars')->insertGetId([
            'shop_id' => $shop->id, 'name' => $name, 'is_active' => DB::raw('true'),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** A labor-only job order payload (no metal legs) for $karigarId. */
    private function laborJobPayload(int $karigarId): array
    {
        return [
            'karigar_id' => $karigarId, 'metal_type' => 'gold', 'purity' => 22,
            'allowed_wastage_percent' => 5, 'issue_date' => now()->toDateString(),
            'metal_source' => 'none', 'job_type' => 'repair',
        ];
    }

    // ── Karigar create + isolation ──────────────────────────────────────────

    public function test_karigar_create_forces_auth_shop_id(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/karigars', ['name' => 'Ramesh', 'shop_id' => 999999]))
            ->assertRedirect();

        $k = Karigar::withoutGlobalScopes()->where('name', 'Ramesh')->first();
        $this->assertNotNull($k);
        $this->assertSame($shop->id, $k->shop_id, 'shop_id from auth, never request body');
    }

    public function test_cannot_view_another_shops_karigar(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $kB = $this->karigar($shopB, 'Bhai');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/karigars/' . $kB));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop karigar must not be viewable');
    }

    public function test_cannot_view_another_shops_job_order(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $kB = $this->karigar($shopB, 'Bee');
        TenantContext::runFor($shopB->id, fn () => $this->actingAs($ownerB)
            ->post(self::ERP . '/job-orders', $this->laborJobPayload($kB)))->assertRedirect();
        $jobB = JobOrder::withoutGlobalScopes()->where('shop_id', $shopB->id)->firstOrFail();

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/job-orders/' . $jobB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop job order must not be viewable');
    }

    public function test_cannot_view_another_shops_karigar_invoice(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $kB = $this->karigar($shopB, 'Cee');
        $invB = TenantContext::runFor($shopB->id, function () use ($shopB, $kB) {
            $inv = new KarigarInvoice();
            $inv->forceFill([
                'shop_id' => $shopB->id, 'karigar_id' => $kB,
                'karigar_invoice_number' => 'KI-B-1', 'karigar_invoice_date' => now()->toDateString(),
            ])->save();

            return $inv;
        });

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/karigar-invoices/' . $invB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop karigar invoice must not be viewable');
    }

    // ── Cross-shop karigar guard on job-order create (regression) ──────────

    public function test_job_order_create_rejects_another_shops_karigar(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $kB = $this->karigar($shopB, 'Foreign');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        // karigar_id is validated only as `integer`; the controller's
        // abort_unless(shop match + active) is the sole cross-shop guard.
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/job-orders', $this->laborJobPayload($kB)));

        $this->assertContains($res->getStatusCode(), [403, 404, 422], 'cross-shop karigar must be rejected');
        $this->assertSame(0, JobOrder::withoutGlobalScopes()->where('shop_id', $shopA->id)->count(), 'no job order created for shop A');
        $this->assertSame(0, JobOrder::withoutGlobalScopes()->where('karigar_id', $kB)->count(), 'shop B karigar never attached');
    }

    // ── Permission & edition gating ────────────────────────────────────────

    public function test_user_without_karigar_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9817700010');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/karigars'))->assertForbidden();
    }

    public function test_user_without_job_order_manage_cannot_create(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['job_order.view'], '9817700011'); // view only

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/job-orders/create'))->assertForbidden();
    }

    public function test_user_without_karigar_invoice_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['karigar.view'], '9817700012'); // no karigar_invoice.view

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/karigar-invoices'))->assertForbidden();
    }

    public function test_manufacturer_edition_cannot_reach_karigars(): void
    {
        // /karigars and /job-orders are inside an edition:retailer group.
        [$owner, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/karigars'))->assertForbidden();
    }

    public function test_guest_cannot_reach_karigars(): void
    {
        $res = $this->get(self::ERP . '/karigars');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
