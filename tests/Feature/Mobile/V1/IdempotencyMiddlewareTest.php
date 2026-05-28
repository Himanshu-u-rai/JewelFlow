<?php

namespace Tests\Feature\Mobile\V1;

use App\Models\IdempotencyKey;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for App\Http\Middleware\EnsureIdempotency (M3).
 *
 * We register a throwaway POST/GET route guarded only by `mobile.idempotency`
 * + `auth:sanctum` and exercise the middleware directly. A counter on the
 * route lets us verify that replays do NOT invoke the controller.
 */
class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /** @var int Number of times the test route's controller body was actually executed. */
    private static int $invocations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$invocations = 0;

        // Test-only routes. Mounted at /test/idempotent-echo with the same
        // middleware stack the real v1 mutation routes will use.
        Route::middleware(['auth:sanctum', 'mobile.idempotency'])
            ->post('/test/idempotent-echo', function (Request $request) {
                self::$invocations++;
                return response()->json([
                    'invocations' => self::$invocations,
                    'payload' => $request->all(),
                ]);
            });

        Route::middleware(['auth:sanctum', 'mobile.idempotency'])
            ->get('/test/idempotent-echo', function () {
                self::$invocations++;
                return response()->json(['invocations' => self::$invocations]);
            });
    }

    public function test_missing_key_on_post_returns_422(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/test/idempotent-echo', ['amount' => 100]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'missing_idempotency_key');
        $this->assertSame(0, self::$invocations);
    }

    public function test_invalid_key_shape_returns_422(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // Too short
        $this->postJson('/test/idempotent-echo', ['amount' => 1], [
            'X-Idempotency-Key' => 'short',
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_idempotency_key');

        // Contains invalid character
        $this->postJson('/test/idempotent-echo', ['amount' => 1], [
            'X-Idempotency-Key' => 'has!bang!chars!1234',
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_idempotency_key');

        $this->assertSame(0, self::$invocations);
    }

    public function test_first_call_invokes_controller_and_caches(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $key = 'abcd1234efgh5678';
        $response = $this->postJson('/test/idempotent-echo', ['amount' => 250], [
            'X-Idempotency-Key' => $key,
        ]);

        $response->assertOk()
            ->assertJsonPath('invocations', 1);
        $response->assertHeaderMissing('X-Idempotent-Replay');

        $this->assertSame(1, self::$invocations);

        $this->assertDatabaseHas('idempotency_keys', [
            'shop_id' => $user->shop_id,
            'user_id' => $user->id,
            'key' => $key,
            'response_status' => 200,
        ]);
    }

    public function test_replay_with_same_key_and_payload_returns_cached_response(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $key = 'replay-key-12345';

        $first = $this->postJson('/test/idempotent-echo', ['amount' => 100], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();
        $this->assertSame(1, self::$invocations);

        $second = $this->postJson('/test/idempotent-echo', ['amount' => 100], [
            'X-Idempotency-Key' => $key,
        ]);

        $second->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('invocations', 1); // Body of the FIRST call

        // Controller body was NOT re-executed.
        $this->assertSame(1, self::$invocations);
        // Body shape matches the original response (JSON key order may differ
        // because the replay path re-encodes the cached jsonb).
        $this->assertEquals($first->json(), $second->json());
    }

    public function test_same_key_with_different_payload_returns_409_conflict(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $key = 'conflict-key-1234';

        $this->postJson('/test/idempotent-echo', ['amount' => 100], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();
        $this->assertSame(1, self::$invocations);

        $conflict = $this->postJson('/test/idempotent-echo', ['amount' => 999], [
            'X-Idempotency-Key' => $key,
        ]);

        $conflict->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'idempotency_key_conflict');

        // Controller was NOT invoked for the conflicting payload.
        $this->assertSame(1, self::$invocations);
    }

    public function test_same_key_across_different_users_does_not_collide(): void
    {
        $shop = $this->makeShop();
        $role = $this->makeRole($shop);
        $userA = $this->makeUserIn($shop, $role);
        $userB = $this->makeUserIn($shop, $role);

        $key = 'shared-user-key-1';

        Sanctum::actingAs($userA);
        $this->postJson('/test/idempotent-echo', ['amount' => 10], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson('/test/idempotent-echo', ['amount' => 20], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();

        $this->assertSame(2, self::$invocations);
        $this->assertSame(2, IdempotencyKey::where('key', $key)->count());
    }

    public function test_same_key_across_different_shops_does_not_collide(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $userA = $this->makeUserIn($shopA, $this->makeRole($shopA));
        $userB = $this->makeUserIn($shopB, $this->makeRole($shopB));

        $key = 'shared-shop-key-1';

        Sanctum::actingAs($userA);
        $this->postJson('/test/idempotent-echo', ['n' => 1], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson('/test/idempotent-echo', ['n' => 1], [
            'X-Idempotency-Key' => $key,
        ])->assertOk();

        $this->assertSame(2, self::$invocations);
        $this->assertSame(2, IdempotencyKey::where('key', $key)->count());
    }

    public function test_get_requests_pass_through_without_key(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/test/idempotent-echo')
            ->assertOk()
            ->assertJsonPath('invocations', 1);

        $this->assertSame(1, self::$invocations);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_body_field_idempotency_key_is_accepted_when_header_missing(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $key = 'body-field-key-99';

        $this->postJson('/test/idempotent-echo', [
            'amount' => 50,
            'idempotency_key' => $key,
        ])->assertOk();

        $this->assertDatabaseHas('idempotency_keys', [
            'shop_id' => $user->shop_id,
            'user_id' => $user->id,
            'key' => $key,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function makeShop(): Shop
    {
        return Shop::create([
            'name' => 'Idem Shop ' . fake()->unique()->numberBetween(1, 999999),
            'shop_type' => 'manufacturer',
            'phone' => '9000000000',
            'owner_first_name' => 'Owner',
            'owner_last_name' => 'Test',
            'owner_mobile' => fake()->unique()->numerify('9#########'),
            'gst_rate' => 3.00,
            'wastage_recovery_percent' => 100.00,
            'access_mode' => 'active',
            'is_active' => true,
        ]);
    }

    private function makeRole(Shop $shop): Role
    {
        $attributes = [
            'name' => 'staff',
            'display_name' => 'Staff',
        ];

        if (Schema::hasColumn('roles', 'shop_id')) {
            $attributes['shop_id'] = $shop->id;
        }

        $role = new Role();
        $role->forceFill($attributes);
        $role->save();

        return $role;
    }

    private function makeUserIn(Shop $shop, Role $role): User
    {
        return User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'mobile_number' => fake()->unique()->numerify('9#########'),
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function makeUser(): User
    {
        $shop = $this->makeShop();
        $role = $this->makeRole($shop);
        return $this->makeUserIn($shop, $role);
    }
}
