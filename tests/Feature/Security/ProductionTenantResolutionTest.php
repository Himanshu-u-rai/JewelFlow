<?php

namespace Tests\Feature\Security;

use App\Models\Karigar;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §M-01 follow-up — exercise the PRODUCTION tenant-resolution branch.
 *
 * WHAT THE OTHER SECURITY TESTS DO AND DO NOT PROVE
 * -------------------------------------------------
 * KycLegacyPublicDiskTest and KarigarInvoiceAttachmentTest call
 * TenantContext::runFor(...) before issuing the request. That pin is necessary
 * there because those routes use route-model binding, and bootstrap/app.php:83-84
 * runs SubstituteBindings BEFORE EnsureTenantUser. Under PHPUnit the binding
 * therefore falls through BelongsToShop::resolveTenantShopId()'s
 * app()->runningInConsole() guard and fail-closes to 404.
 *
 * Those pinned tests prove: GIVEN correct tenant context, authorization,
 * ownership and permission checks behave correctly.
 *
 * They do NOT prove: that a real request SELECTS the correct tenant. The pin
 * supplies the answer the production code is supposed to derive.
 *
 * This class closes that gap. Every test here uses routes WITHOUT route-model
 * binding, so EnsureTenantUser runs normally and performs the real resolution
 * (TenantContext::set(auth()->user()->shop_id)). Nothing is pre-pinned.
 * Authentication, scopes, permission middleware and the global shop scope are
 * all live.
 *
 * Per matrix §4.6, a login redirect or setup gate is not proof of object
 * authorization — so the own-shop cases assert 200 AND the presence of the
 * shop's own sentinel, not merely a non-error status.
 */
class ProductionTenantResolutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    /** A same-shop user on a separate role, holding exactly $permissions. */
    private function staffUser(int $shopId, array $permissions): User
    {
        $role = new Role();
        $role->forceFill([
            'shop_id' => $shopId,
            'name' => 'staff_'.uniqid(),
            'display_name' => 'Staff',
        ])->save();
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'shop_id' => $shopId,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** Seeded with a shop-unique sentinel name so leakage is unambiguous. */
    private function karigarNamed(int $shopId, string $name): Karigar
    {
        return TenantContext::runFor($shopId, fn () => Karigar::create([
            'shop_id' => $shopId,
            'name' => $name,
            'mobile' => '98'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]));
    }

    /**
     * T-prod-web-own / T-prod-web-foreign — one request, no pin. The listing a
     * user receives must be derived from their OWN shop_id.
     *
     * Production change that would break this: removing TenantContext::set()
     * from EnsureTenantUser, or dropping the BelongsToShop global scope.
     */
    public function test_web_listing_is_resolved_to_the_authenticated_users_own_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');
        $this->karigarNamed($shopB->id, 'KARIGAR_BBB_SENTINEL');

        // No TenantContext::runFor here — EnsureTenantUser must do the work.
        $responseA = $this->actingAs($ownerA)->get(route('karigars.index'));

        $responseA->assertOk();
        $responseA->assertSee('KARIGAR_AAA_SENTINEL');
        $responseA->assertDontSee('KARIGAR_BBB_SENTINEL');
    }

    /**
     * T-prod-web-bleed — A then B then guest, in one process. Context from an
     * earlier request must not survive into a later one.
     *
     * Production change that would break this: removing the
     * finally { TenantContext::clear() } from EnsureTenantUser, or the
     * TenantContext::clear() on the unauthenticated branch. TenantContext is a
     * static, so a missing clear leaks across requests within a worker.
     */
    public function test_tenant_context_does_not_persist_across_sequential_requests(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');
        $this->karigarNamed($shopB->id, 'KARIGAR_BBB_SENTINEL');

        $responseA = $this->actingAs($ownerA)->get(route('karigars.index'));
        $responseA->assertOk();
        $responseA->assertSee('KARIGAR_AAA_SENTINEL');
        $responseA->assertDontSee('KARIGAR_BBB_SENTINEL');

        // Switch principal. B must see B's data and none of A's.
        $responseB = $this->actingAs($ownerB)->get(route('karigars.index'));
        $responseB->assertOk();
        $responseB->assertSee('KARIGAR_BBB_SENTINEL');
        $responseB->assertDontSee('KARIGAR_AAA_SENTINEL');

        // Context must be cleared, not left holding B's shop.
        $this->assertNull(TenantContext::get(),
            'tenant context leaked out of the request lifecycle');

        // And a guest afterwards must receive no tenant data at all.
        app('auth')->forgetGuards();
        $guest = $this->get(route('karigars.index'));
        $this->assertNotEquals(200, $guest->status(),
            'a guest must not receive a tenant listing; got '.$guest->status());
        $this->assertStringNotContainsString('KARIGAR_AAA_SENTINEL', $guest->getContent());
        $this->assertStringNotContainsString('KARIGAR_BBB_SENTINEL', $guest->getContent());
    }

    /**
     * U-prod-web-perm — intra-shop permission boundary on the real resolution
     * path. Correct tenant is NOT sufficient; the permission gate must also hold.
     */
    public function test_same_shop_user_without_permission_is_refused_the_web_listing(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');

        $staff = $this->staffUser($shopA->id, ['customers.view']);

        $response = $this->actingAs($staff)->get(route('karigars.index'));

        $this->assertContains($response->status(), [403, 404],
            'a same-shop user lacking karigar.view must be refused; got '.$response->status());
        $this->assertStringNotContainsString('KARIGAR_AAA_SENTINEL', $response->getContent());
    }

    /**
     * U-prod-web-perm-positive — the control proving the test above failed for the
     * RIGHT reason (permission), not because the page was broken for everyone.
     */
    public function test_same_shop_user_with_permission_receives_the_web_listing(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');

        $staff = $this->staffUser($shopA->id, ['karigar.view']);

        $response = $this->actingAs($staff)->get(route('karigars.index'));

        $response->assertOk();
        $response->assertSee('KARIGAR_AAA_SENTINEL');
    }

    /**
     * M-prod-mobile-own / foreign — same resolution question on the Sanctum
     * surface. GET /api/mobile/v1/karigars has no route-model binding, so the
     * mobile stack resolves the tenant for real.
     */
    public function test_mobile_listing_is_resolved_to_the_token_owners_own_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');
        $this->karigarNamed($shopB->id, 'KARIGAR_BBB_SENTINEL');

        Sanctum::actingAs($ownerA);
        $responseA = $this->getJson('/api/mobile/v1/karigars');

        $responseA->assertOk();
        $body = $responseA->getContent();
        $this->assertStringContainsString('KARIGAR_AAA_SENTINEL', $body);
        $this->assertStringNotContainsString('KARIGAR_BBB_SENTINEL', $body);
    }

    /** M-prod-mobile-guest — an unauthenticated mobile call must get no data. */
    public function test_mobile_listing_refuses_an_unauthenticated_caller(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');

        $response = $this->getJson('/api/mobile/v1/karigars');

        $this->assertNotEquals(200, $response->status(),
            'an unauthenticated mobile caller must not receive tenant data; got '.$response->status());
        $this->assertStringNotContainsString('KARIGAR_AAA_SENTINEL', $response->getContent());
    }

    /** U-prod-mobile-perm — intra-shop permission boundary on the mobile surface. */
    public function test_same_shop_mobile_caller_without_permission_is_refused(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $this->karigarNamed($shopA->id, 'KARIGAR_AAA_SENTINEL');

        $staff = $this->staffUser($shopA->id, ['customers.view']);

        Sanctum::actingAs($staff);
        $response = $this->getJson('/api/mobile/v1/karigars');

        $this->assertContains($response->status(), [403, 404],
            'a same-shop mobile caller lacking karigar.view must be refused; got '.$response->status());
        $this->assertStringNotContainsString('KARIGAR_AAA_SENTINEL', $response->getContent());
    }
}
