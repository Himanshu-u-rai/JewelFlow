<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — legacy sub-category GET routes funnel to the unified page.
 *
 * Sub-categories are managed inline on the unified categories index page. The
 * four legacy GET routes (index/create/show/edit) have no controller methods —
 * a direct hit used to 500 (index) or hop through the broken index. Each now
 * redirects DIRECTLY to categories.index in a single 302, with route names,
 * HTTP methods, params and permission gates unchanged.
 */
class SubCategoryIndexRedirectTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function categoriesPath(): string
    {
        return parse_url(route('categories.index'), PHP_URL_PATH); // '/categories'
    }

    public function test_all_four_legacy_get_routes_redirect_directly_to_categories(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $category = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        $sub = SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Plain']);
        $this->actingAs($user);

        $targets = [
            route('sub-categories.index'),
            route('sub-categories.create'),
            TenantContext::runFor($shop->id, fn () => route('sub-categories.show', $sub)),
            TenantContext::runFor($shop->id, fn () => route('sub-categories.edit', $sub)),
        ];

        foreach ($targets as $url) {
            $response = TenantContext::runFor($shop->id, fn () => $this->get($url));

            // Exactly one 302, landing on /categories — never another legacy route.
            $response->assertStatus(302);
            $location = parse_url($response->headers->get('Location'), PHP_URL_PATH);
            $this->assertSame($this->categoriesPath(), $location, "Redirect for {$url} must land on /categories, got {$location}");
            $this->assertStringNotContainsString('/sub-categories', (string) $location);
        }
    }

    public function test_index_preserves_safe_q_query_param(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $response = $this->get(route('sub-categories.index', ['q' => 'gold']));

        $response->assertStatus(302);
        $this->assertStringContainsString('q=gold', (string) $response->headers->get('Location'));
        $this->assertStringStartsWith($this->categoriesPath(), parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_guest_is_still_auth_guarded_not_500(): void
    {
        $this->get(route('sub-categories.index'))->assertRedirect(route('login'));
        $this->get(route('sub-categories.create'))->assertRedirect(route('login'));
    }

    public function test_permission_gates_are_preserved_per_route(): void
    {
        // inventory.view only: read routes (index/show) allowed to redirect,
        // write routes (create/edit) forbidden by their can:catalog.manage gate.
        [$user, $shop] = $this->createManufacturerTenant();
        $sub = SubCategory::forceCreate([
            'shop_id' => $shop->id,
            'category_id' => Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings'])->id,
            'name' => 'Plain',
        ]);
        $this->grantOnlyPermissions($user, ['inventory.view']);
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->get(route('sub-categories.index')))->assertStatus(302);
        TenantContext::runFor($shop->id, fn () => $this->get(route('sub-categories.show', $sub)))->assertStatus(302);
        TenantContext::runFor($shop->id, fn () => $this->get(route('sub-categories.create')))->assertForbidden();
        TenantContext::runFor($shop->id, fn () => $this->get(route('sub-categories.edit', $sub)))->assertForbidden();
    }

    public function test_zero_permission_user_is_forbidden_from_read_routes(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grantOnlyPermissions($user, []);
        $this->actingAs($user);

        TenantContext::runFor($shop->id, fn () => $this->get(route('sub-categories.index')))->assertForbidden();
    }

    public function test_redirects_mutate_nothing(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $category = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Plain']);
        $this->actingAs($user);

        $catsBefore = Category::withoutTenant()->count();
        $subsBefore = SubCategory::withoutTenant()->count();

        $this->get(route('sub-categories.index'));
        $this->get(route('sub-categories.create'));

        $this->assertSame($catsBefore, Category::withoutTenant()->count());
        $this->assertSame($subsBefore, SubCategory::withoutTenant()->count());
    }
}
