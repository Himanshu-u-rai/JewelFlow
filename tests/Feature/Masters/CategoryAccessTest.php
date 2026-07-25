<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — access matrix for the unified categories page, plus the
 * Gate-3 delete-confirmation copy proof.
 *
 * Read is gated by can:inventory.view; every write control on the page is gated
 * by can:catalog.manage (the header button, per-card edit/delete/add-sub). A
 * read-only *shop* still renders the page (GET is allowed) but the write POST is
 * refused by subscription.active middleware — write controls are hidden by
 * PERMISSION, blocked by SUBSCRIPTION; the two are deliberately independent.
 */
class CategoryAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    // Exact per-card write control from _category-card.blade.php — present only
    // under @can('catalog.manage').
    private const WRITE_CONTROL = 'Delete this unused category? Categories linked to products or subcategories cannot be deleted.';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function cat(int $shopId, string $name = 'Rings'): Category
    {
        return Category::forceCreate(['shop_id' => $shopId, 'name' => $name]);
    }

    private function index(int $shopId)
    {
        return TenantContext::runFor($shopId, fn () => $this->get(route('categories.index')));
    }

    public function test_owner_sees_page_with_write_controls(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id);
        $this->actingAs($user);

        $response = $this->index($shop->id);

        $response->assertOk();
        $response->assertSee('Rings');
        $response->assertSee(self::WRITE_CONTROL);
        $response->assertDontSee('view-only-banner');
    }

    public function test_view_only_staff_sees_page_without_write_controls(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id);
        $this->grantOnlyPermissions($user, ['inventory.view']);
        $this->actingAs($user);

        $response = $this->index($shop->id);

        $response->assertOk();
        $response->assertSee('Rings');
        $response->assertDontSee(self::WRITE_CONTROL); // no delete/edit controls
        $response->assertSee('view-only-banner');       // told it's read-only for them
    }

    public function test_catalog_manage_staff_sees_write_controls(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id);
        // Real write staff hold both read and write catalog permissions.
        $this->grantOnlyPermissions($user, ['inventory.view', 'catalog.manage']);
        $this->actingAs($user);

        $response = $this->index($shop->id);

        $response->assertOk();
        $response->assertSee(self::WRITE_CONTROL);
        $response->assertDontSee('view-only-banner');
    }

    public function test_zero_permission_user_is_forbidden(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->grantOnlyPermissions($user, []);
        $this->actingAs($user);

        $this->index($shop->id)->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('categories.index'))->assertRedirect(route('login'));
    }

    public function test_cross_shop_categories_are_never_rendered(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->cat($shopB->id, 'ShopBOnly');
        $this->actingAs($userA);

        $response = $this->index($shopA->id);

        $response->assertOk();
        $response->assertDontSee('ShopBOnly');
    }

    public function test_read_only_shop_can_view_but_write_is_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $shop->forceFill(['access_mode' => 'read_only'])->save();
        $this->cat($shop->id);
        $this->actingAs($user);

        // GET still works — read-only shops are not locked out of reading.
        $this->index($shop->id)->assertOk();

        // The actual write is refused by subscription.active middleware, not by
        // hiding the button. Category count must be unchanged.
        $before = Category::withoutTenant()->where('shop_id', $shop->id)->count();
        TenantContext::runFor($shop->id, fn () => $this->post(route('categories.store'), [
            '_intent' => 'add_category',
            'name' => 'Blocked Cat',
        ]));
        $after = Category::withoutTenant()->where('shop_id', $shop->id)->count();

        $this->assertSame($before, $after, 'Read-only shop must not create categories');
    }

    /**
     * GATE 3 — the delete confirmation copy must warn about dependencies and NOT
     * make the old "sub-categories will be cascade-deleted" promise (the guard
     * now blocks such deletes instead of cascading).
     */
    public function test_delete_confirmation_copy_warns_about_dependencies(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id);
        $this->actingAs($user);

        $response = $this->index($shop->id);

        $response->assertOk();
        $response->assertSee(self::WRITE_CONTROL);
        // Old cascade promise must be gone.
        $response->assertDontSee('cascade');
        $response->assertDontSee('will also delete');
        $response->assertDontSee('sub-categories will be deleted');
    }
}
