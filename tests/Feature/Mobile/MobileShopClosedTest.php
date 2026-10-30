<?php

namespace Tests\Feature\Mobile;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Closing the shop (or a read-only shop) blocks staff from operational work on
 * every app endpoint, legacy and v1 alike, as on the web, while signing out
 * and checking one's own session always work: as the web's own logout, which
 * every gate lets through.
 */
class MobileShopClosedTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function send(?string $token, string $method, string $uri, int $shopId, array $body = [])
    {
        $this->app['auth']->forgetGuards();
        TenantContext::set($shopId);
        $headers = ['Accept' => 'application/json', 'X-Idempotency-Key' => 'closed-' . Str::uuid()];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->json($method, $uri, $body, $headers);
    }

    /** [shop, owner token, manager token]; the manager holds cash + customer + returns permissions. */
    private function shopWithManager(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $role = (new Role())->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', ['cash.view', 'cash.create', 'customers.view', 'customers.edit', 'returns.view', 'sales.view'])->pluck('id'));
            User::create(['name' => 'Manager', 'mobile_number' => '9876512345', 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id]);
        });
        $login = fn (string $mobile) => $this->send(null, 'POST', '/api/mobile/auth/login', $shop->id, ['mobile_number' => $mobile, 'password' => 'password'])->json('token');

        return [$shop, $login($owner->mobile_number), $login('9876512345')];
    }

    private function closeShop(Shop $shop): void
    {
        TenantContext::runFor($shop->id, fn () => ShopPreferences::updateOrCreate(['shop_id' => $shop->id], ['shop_access_enabled' => false]));
    }

    public function test_staff_sign_out_ends_the_session_while_the_shop_is_closed(): void
    {
        [$shop, , $manager] = $this->shopWithManager();
        $this->closeShop($shop);

        $this->send($manager, 'DELETE', '/api/mobile/v1/device/push-token', $shop->id, ['token' => 'ExponentPushToken[x]'])->assertSuccessful();
        $this->send($manager, 'POST', '/api/mobile/auth/logout', $shop->id)->assertSuccessful();
        $this->assertNull(PersonalAccessToken::findToken($manager), 'the token must be dead after sign-out');
    }

    public function test_the_session_check_at_launch_works_while_the_shop_is_closed(): void
    {
        [$shop, , $manager] = $this->shopWithManager();
        $this->closeShop($shop);

        $this->send($manager, 'GET', '/api/mobile/auth/me', $shop->id)->assertOk();
        $this->send($manager, 'GET', '/api/mobile/v1/sessions/me', $shop->id)->assertOk();
        $this->send($manager, 'GET', '/api/mobile/bootstrap', $shop->id)->assertOk();
    }

    public function test_v1_operational_endpoints_are_closed_to_staff_too(): void
    {
        [$shop, $owner, $manager] = $this->shopWithManager();
        $customer = $this->createCustomer($shop->id);
        $this->closeShop($shop);

        foreach ([['GET', '/api/mobile/v1/cashbook'], ['GET', '/api/mobile/v1/returns'], ['PATCH', "/api/mobile/v1/customers/{$customer->id}"],
            ['POST', '/api/mobile/v1/cashbook'], ['GET', '/api/mobile/customers']] as [$method, $uri]) {
            $this->send($manager, $method, $uri, $shop->id)->assertForbidden()->assertJsonPath('error', 'shop_closed');
        }
        $this->send($owner, 'GET', '/api/mobile/v1/cashbook', $shop->id)->assertOk(); // the owner keeps access
    }

    public function test_sign_out_works_in_a_read_only_shop(): void
    {
        [$shop, , $manager] = $this->shopWithManager();
        DB::table('shops')->where('id', $shop->id)->update(['access_mode' => 'read_only']);

        $this->send($manager, 'POST', '/api/mobile/auth/logout', $shop->id)->assertSuccessful();
        $this->assertNull(PersonalAccessToken::findToken($manager));
    }
}
