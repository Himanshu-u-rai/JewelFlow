<?php

namespace Tests\Feature\Masters;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShopMetalPurityProfile;
use App\Services\ShopPricingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 4 — Purity entry-point + access lock-in.
 *
 * The Masters Hub "Metal Purity Profiles" card is a discoverability shortcut into
 * the EXISTING Pricing Settings purity-profile editor. Pricing Settings remains the
 * single source of truth: no new controller, route, model, table or permission.
 *
 * The card's gate mirrors the pricing entry point EXACTLY — `$shop->isRetailer()`
 * (retailer edition) + `can:pricing.update`. These tests lock that in, and prove the
 * hidden card is NOT the security boundary: the mutation routes stay server-gated,
 * tenant-scoped and read-only-safe regardless of what the hub renders.
 */
class PurityMastersAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function syncPermissions(int $roleId, array $names): void
    {
        $role = Role::withoutTenant()->findOrFail($roleId);
        $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id'));
    }

    private function cardSlugs(string $html): array
    {
        preg_match_all('/data-masters-card="([a-z]+)"/', $html, $m);
        sort($m[1]);

        return $m[1];
    }

    private function seedProfile($shop, $user): ShopMetalPurityProfile
    {
        // Daily rates auto-materialise per-purity profiles; grab the gold-22K one.
        $this->seedRetailerPricing($shop, $user);

        return app(ShopPricingService::class)->profileForPurity($shop, 'gold', 22);
    }

    // ---- Card visibility mirrors the pricing entry point ----------------

    public function test_retailer_with_pricing_update_sees_purity_card(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['pricing.update']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertContains('purity', $slugs);
    }

    public function test_purity_card_links_to_the_pricing_purity_section(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['pricing.update']);
        $this->actingAs($user);

        $html = $this->get(route('masters.index'))->assertOk()->getContent();

        // Destination = existing Pricing tab, deep-linked to the purity section —
        // NOT the generic settings landing page.
        $expected = e(route('settings.edit', ['tab' => 'pricing']) . '#purity-profiles');
        $this->assertMatchesRegularExpression(
            '/href="' . preg_quote($expected, '/') . '"\s+data-masters-card="purity"/',
            $html
        );
    }

    public function test_retailer_without_pricing_update_does_not_see_purity_card(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['inventory.view']); // no pricing.update
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertNotContains('purity', $slugs);
    }

    public function test_manufacturer_shop_never_sees_purity_card_even_with_pricing_update(): void
    {
        // Purity profiles are a retailer-pricing concept; the card mirrors the
        // pricing entry point's `$shop->isRetailer()` gate exactly.
        [$user] = $this->createManufacturerTenant();
        $this->syncPermissions($user->role_id, ['pricing.update']);
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertNotContains('purity', $slugs);
    }

    public function test_read_only_retailer_still_sees_the_navigation_card(): void
    {
        // The card is pure GET navigation — a read-only shop still discovers it.
        // (The write gate, tested below, is the real boundary.)
        [$user, $shop] = $this->createRetailerTenant();
        $this->syncPermissions($user->role_id, ['pricing.update']);
        $shop->forceFill(['access_mode' => 'read_only'])->save();
        $this->actingAs($user);

        $slugs = $this->cardSlugs($this->get(route('masters.index'))->assertOk()->getContent());

        $this->assertContains('purity', $slugs);
    }

    // ---- The card gate mirrors the write-route + entry-point gate --------

    public function test_card_gate_matches_the_purity_write_route_permission(): void
    {
        // Card `can` must equal the purity mutation routes' own middleware, so the
        // card only advertises an editor the user could actually write in.
        foreach (['settings.pricing.profiles.store', 'settings.pricing.profiles.update'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Purity write route {$name} must exist.");
            $this->assertContains('can:pricing.update', $route->gatherMiddleware());
        }
    }

    // ---- Hidden button is NOT the boundary: server-side mutation gates ---

    public function test_direct_profile_update_rejected_without_pricing_update(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $profile = $this->seedProfile($shop, $user);
        $this->syncPermissions($user->role_id, ['inventory.view']); // strip pricing.update

        $status = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->patch(
            route('settings.pricing.profiles.update', $profile),
            ['metal_type' => 'gold', 'purity_value' => 22, 'is_active' => 1],
        )->status());

        $this->assertSame(403, $status);
    }

    public function test_cross_shop_profile_update_is_tenant_isolated(): void
    {
        [$userA, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $profileB = $this->seedProfile($shopB, $userA);
        $this->syncPermissions($userA->role_id, ['pricing.update']);

        // userA (tenant A) targets shop B's profile id → BelongsToShop scope +
        // controller shop_id guard → 404, never a cross-shop mutation.
        $status = TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)->patch(
            route('settings.pricing.profiles.update', $profileB),
            ['metal_type' => 'gold', 'purity_value' => 22, 'is_active' => 1],
        )->status());

        $this->assertSame(404, $status);
    }

    public function test_read_only_shop_cannot_mutate_a_purity_profile(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $profile = $this->seedProfile($shop, $user);
        $this->syncPermissions($user->role_id, ['pricing.update']);
        $original = (float) $profile->purity_value;
        $shop->forceFill(['access_mode' => 'read_only'])->save();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->patch(
            route('settings.pricing.profiles.update', $profile),
            ['metal_type' => 'gold', 'purity_value' => 18, 'is_active' => 1],
        ));

        // Whatever the deny response, the row must be untouched.
        $this->assertSame(
            $original,
            (float) ShopMetalPurityProfile::withoutTenant()->findOrFail($profile->id)->purity_value
        );
    }

    public function test_authorized_retailer_can_update_a_purity_profile(): void
    {
        // Existing Pricing Settings behavior still works end-to-end (regression).
        [$user, $shop] = $this->createRetailerTenant();
        $profile = $this->seedProfile($shop, $user);
        $this->syncPermissions($user->role_id, ['pricing.update']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->patch(
            route('settings.pricing.profiles.update', $profile),
            ['metal_type' => 'gold', 'label' => 'Updated 22K', 'purity_value' => 22, 'is_active' => 1],
        ))->assertRedirect(route('settings.edit', ['tab' => 'pricing']));

        $this->assertSame(
            'Updated 22K',
            ShopMetalPurityProfile::withoutTenant()->findOrFail($profile->id)->label
        );
    }
}
