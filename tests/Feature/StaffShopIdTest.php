<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: staff were created with NULL shop_id because 'shop_id' was missing
 * from User::$fillable, so StaffController::store()'s create() silently dropped
 * the server-supplied shop_id. A shop-less user is treated as a brand-new signup
 * on login and bounced to subscription/plans instead of the workspace.
 */
class StaffShopIdTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_shop_id_is_fillable(): void
    {
        $this->assertContains('shop_id', (new User())->getFillable());
    }

    public function test_creating_a_user_persists_shop_id(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Exactly the array shape StaffController::store() passes.
        $staff = User::create([
            'name'          => 'Test Staff',
            'mobile_number' => '9876500001',
            'email'         => null,
            'password'      => Hash::make('Secret123!'),
            'shop_id'       => $shop->id,
            'role_id'       => $owner->role_id,
        ]);

        // The bug: shop_id came back NULL. The fix: it persists.
        $this->assertSame((int) $shop->id, (int) $staff->fresh()->shop_id,
            'staff must be created with the shop_id (was silently dropped before)');
    }
}
