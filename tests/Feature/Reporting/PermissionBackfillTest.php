<?php

namespace Tests\Feature\Reporting;

use App\Models\Role;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The new reporting permissions reach owner/manager but not staff (H-1/H-2).
 * Validates the net guarantee TenantRoleService provides for shops after the
 * permission migrations ran; the existing-shop backfill uses the same per-shop
 * role-grant the migrations perform.
 */
class PermissionBackfillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_export_sensitive_and_audit_granted_to_owner_and_manager_not_staff(): void
    {
        $shop = $this->createShop('manufacturer');

        app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);

        TenantContext::runFor($shop->id, function () use ($shop) {
            $owner = Role::where('shop_id', $shop->id)->where('name', 'owner')->firstOrFail();
            $manager = Role::where('shop_id', $shop->id)->where('name', 'manager')->firstOrFail();
            $staff = Role::where('shop_id', $shop->id)->where('name', 'staff')->firstOrFail();

            foreach (['reports.export_sensitive', 'reports.audit'] as $permission) {
                $this->assertTrue($owner->hasPermission($permission), "owner should hold {$permission}");
                $this->assertTrue($manager->hasPermission($permission), "manager should hold {$permission}");
                $this->assertFalse($staff->hasPermission($permission), "staff must NOT hold {$permission}");
            }
        });
    }
}
