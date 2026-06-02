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

    public function test_mobile_uniqueness_must_be_global_not_per_shop(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // A user already holds this mobile globally (mirrors the orphaned staff
        // that had shop_id = NULL). DB has a GLOBAL unique on mobile_number.
        User::create([
            'name' => 'Existing', 'mobile_number' => '9876512345',
            'password' => Hash::make('Secret123!'),
        ]);

        // The fixed rule (global) correctly rejects the duplicate → friendly
        // validation error instead of a 500 on the DB constraint.
        $globalRule = \Illuminate\Support\Facades\Validator::make(
            ['mobile_number' => '9876512345'],
            ['mobile_number' => [\Illuminate\Validation\Rule::unique('users', 'mobile_number')]]
        );
        $this->assertTrue($globalRule->fails(), 'global unique must reject a duplicate mobile');

        // The OLD per-shop rule would have MISSED it (the bug that caused the 500):
        $perShopRule = \Illuminate\Support\Facades\Validator::make(
            ['mobile_number' => '9876512345'],
            ['mobile_number' => [\Illuminate\Validation\Rule::unique('users', 'mobile_number')->where('shop_id', $shop->id)]]
        );
        $this->assertFalse($perShopRule->fails(), 'per-shop rule missed the global duplicate (root cause of the 500)');
    }
}
