<?php

namespace Tests\Unit\Admin;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Services\AdminImpersonationService;
use App\Services\PlatformAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * Platform Admin — unit level (Module 4). The audit-write contract and the
 * impersonation guard logic, in isolation. P0: a bug here is cross-shop /
 * impersonation abuse.
 */
class AdminUnitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'P', 'last_name' => 'A', 'name' => 'PA',
            'email' => 'a' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function shopUser(): User
    {
        $shop = Shop::create([
            'name' => 'S', 'shop_type' => 'retailer', 'phone' => '9000000301',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9000000302',
            'is_active' => true, 'access_mode' => 'active',
        ]);

        return User::create([
            'mobile_number' => '9000000303', 'password' => Hash::make('x'),
            'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id,
        ]);
    }

    // ── PlatformAuditService write contract ────────────────────────────────

    public function test_audit_log_writes_actor_action_and_target(): void
    {
        $admin = $this->admin();

        app(PlatformAuditService::class)->log(
            $admin, 'admin.something_happened', Shop::class, 42,
            ['before' => 1], ['after' => 2], 'because reasons',
        );

        $row = PlatformAuditLog::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_admin_id);
        $this->assertSame('admin.something_happened', $row->action);
        $this->assertSame(Shop::class, $row->target_type);
        $this->assertSame('42', (string) $row->target_id);
        $this->assertSame('because reasons', $row->reason);
    }

    public function test_audit_log_is_append_only(): void
    {
        $admin = $this->admin();
        app(PlatformAuditService::class)->log($admin, 'admin.x', Shop::class, 1);
        $row = PlatformAuditLog::query()->latest('id')->first();

        // Constitutional: platform audit log is immutable (no update / no delete).
        $threw = false;
        try {
            $row->update(['action' => 'tampered']);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'platform audit log must reject updates');
    }

    // ── isSuperAdmin ───────────────────────────────────────────────────────

    public function test_is_super_admin_flag(): void
    {
        $this->assertTrue($this->admin('super_admin')->isSuperAdmin());
        $this->assertFalse($this->admin('support')->isSuperAdmin());
    }

    // ── Impersonation guards ───────────────────────────────────────────────

    public function test_impersonation_requires_super_admin(): void
    {
        $support = $this->admin('support');
        $user = $this->shopUser();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only super admins can impersonate');
        app(AdminImpersonationService::class)->start($support, $user);
    }

    public function test_impersonation_requires_user_with_a_shop(): void
    {
        $admin = $this->admin('super_admin');
        $shopless = User::create([
            'mobile_number' => '9000000304', 'password' => Hash::make('x'),
            'realm' => 'erp', 'is_active' => true, // no shop_id
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not attached to a shop');
        app(AdminImpersonationService::class)->start($admin, $shopless);
    }
}
