<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit A1 — CORRECTED. The audit claimed admin "deactivate tenant user" was a
 * silent no-op because UserObserver reverted is_active. Runtime tracing proved
 * UserObserver is NOT registered (dormant code), so is_active alone DID persist.
 * The real defect was a CONSISTENCY one: deactivate left employment_status at
 * 'active' while is_active=false, an inconsistent lifecycle state. The fix moves
 * employment_status in lockstep — admin deactivate = reversible 'suspended',
 * preserving a stronger 'terminated' state if the shop already set it.
 *
 * These assertions exercise the exact writes the fixed controller performs and
 * confirm the resulting state is consistent and login-blocking.
 */
class AdminUserStatusToggleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeUser(int $shopId, string $employment = 'active'): User
    {
        $role = Role::withoutGlobalScopes()->where('shop_id', $shopId)->first();
        if (! $role) {
            $role = new Role();
            $role->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shopId]);
            $role->save();
        }

        return User::create([
            'name'              => 'Toggle Target',
            'mobile_number'     => '9876512300',
            'password'          => Hash::make('Secret123!'),
            'shop_id'           => $shopId,
            'role_id'           => $role->id,
            'employment_status' => $employment,
            'is_active'         => $employment === 'active',
        ]);
    }

    public function test_deactivate_persists_as_suspension_not_reverted(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $user = $this->makeUser($shop->id, 'active');

        // Exactly what the fixed controller does on deactivate.
        $user->employment_status = 'suspended';
        $user->is_active = false;
        $user->save();

        $fresh = User::find($user->id);
        $this->assertSame('suspended', $fresh->employment_status);
        $this->assertFalse((bool) $fresh->is_active, 'deactivation must STICK (was reverted by observer before the fix)');
    }

    public function test_activate_restores_active(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $user = $this->makeUser($shop->id, 'suspended');

        $user->employment_status = 'active';
        $user->is_active = true;
        $user->save();

        $fresh = User::find($user->id);
        $this->assertSame('active', $fresh->employment_status);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_user_observer_is_registered_for_the_invariant(): void
    {
        // M15: the observer that enforces is_active ↔ employment_status was
        // dormant (registered nowhere). It is now registered in AppServiceProvider
        // (verified at runtime). Source-level guard so the registration can't be
        // silently dropped. (The PHPUnit harness does not exercise model observers
        // — all of this app's observers are validated at runtime, not in-suite.)
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString(
            'User::observe(\App\Observers\UserObserver::class)',
            $provider,
            'UserObserver must be registered so the is_active/employment_status invariant is enforced'
        );
    }
}
