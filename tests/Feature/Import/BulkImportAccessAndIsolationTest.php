<?php

namespace Tests\Feature\Import;

use App\Models\Import;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Bulk Import access & isolation (Module 22). Dry-run no-write, strict rollback,
 * row isolation, duplicate detection, idempotency, lot-negative, financial-lock
 * and ledger-immutability are exhaustively covered by BulkImportSafety(14);
 * ImportPermissionGate(3) proves the routes use imports.manage. This adds the
 * untested slice: guest + no-permission gates and the per-action cross-shop
 * ownership guard (the abort_if on show/execute) + index shop-scoping.
 */
class BulkImportAccessAndIsolationTest extends TestCase
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

    private function import(Shop $shop, string $reference): Import
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $reference) {
            $i = new Import();
            $i->forceFill([
                'shop_id' => $shop->id, 'type' => Import::TYPE_CATALOG,
                'file_path' => 'imports/' . $reference . '.csv', 'import_reference' => $reference,
                'status' => Import::STATUS_PREVIEW,
            ])->save();

            return $i;
        });
    }

    public function test_guest_cannot_reach_imports(): void
    {
        $res = $this->get(self::ERP . '/imports');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_user_without_imports_manage_is_denied(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $noPerm = $this->userWithPerms($shop, ['inventory.view'], '9826600001'); // no imports.manage

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/imports'))->assertForbidden();
    }

    public function test_cannot_view_another_shops_import(): void
    {
        [, $shopB] = $this->createManufacturerTenant();
        $importB = $this->import($shopB, 'IMP-B-1');

        [$ownerA, $shopA] = $this->createManufacturerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/imports/' . $importB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop import must not be viewable');
    }

    public function test_cannot_execute_another_shops_import(): void
    {
        [, $shopB] = $this->createManufacturerTenant();
        $importB = $this->import($shopB, 'IMP-B-2');

        [$ownerA, $shopA] = $this->createManufacturerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/imports/' . $importB->id . '/execute', ['mode' => Import::MODE_STRICT]));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop execute must be blocked');
    }

    public function test_imports_index_does_not_leak_another_shops_import(): void
    {
        [, $shopB] = $this->createManufacturerTenant();
        $this->import($shopB, 'IMP-SHOPB-SECRET-REF');

        [$ownerA, $shopA] = $this->createManufacturerTenant();
        $this->import($shopA, 'IMP-SHOPA-REF');

        $html = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/imports'))->assertOk()->getContent();

        $this->assertStringNotContainsString('IMP-SHOPB-SECRET-REF', $html, 'another shop import must not leak into the list');
    }
}
