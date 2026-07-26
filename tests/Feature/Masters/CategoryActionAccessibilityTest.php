<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — accessibility hardening for the unified Categories page.
 *
 * The per-card icon actions (toggle / add-sub / rename / delete, both parent and
 * child) previously rendered at 32×32 CSS px on mobile — below the 44×44 tap
 * target guideline. Sizing lives in app.css with a specificity the plain Tailwind
 * width utilities can't beat, so the Blade markup grows each clickable container
 * with the important variant `!h-11 !w-11` (44px) which clamps regardless of the
 * cascade, without enlarging the SVG. Each icon-only control also gets an
 * action-specific accessible name and a visible focus-visible ring.
 *
 * These are asserted against the REAL rendered HTML: every button inside
 * #categories-list must carry the enlarged classes, a non-empty aria-label, a
 * focus-visible ring, and must not nest another interactive control.
 */
class CategoryActionAccessibilityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function cat(int $shopId, string $name): Category
    {
        return Category::forceCreate(['shop_id' => $shopId, 'name' => $name]);
    }

    private function sub(int $shopId, int $categoryId, string $name): SubCategory
    {
        return SubCategory::forceCreate(['shop_id' => $shopId, 'category_id' => $categoryId, 'name' => $name]);
    }

    private function index(int $shopId)
    {
        return TenantContext::runFor($shopId, fn () => $this->get(route('categories.index')));
    }

    /** Return every <button> inside #categories-list from rendered HTML. */
    private function actionButtons(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $list = $dom->getElementById('categories-list');
        $this->assertNotNull($list, '#categories-list container must render');

        $buttons = [];
        foreach ($list->getElementsByTagName('button') as $btn) {
            $buttons[] = $btn;
        }

        return $buttons;
    }

    /** One parent + one child = all six icon actions render. */
    private function seedFullCard(int $shopId): void
    {
        $ring = $this->cat($shopId, 'Rings');
        $this->sub($shopId, $ring->id, 'Bridal');
    }

    public function test_every_card_icon_action_has_44px_touch_target_classes(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        $buttons = $this->actionButtons($this->index($shop->id)->getContent());

        // toggle + add-sub + rename + delete (parent) + rename + delete (child) = 6
        $this->assertCount(6, $buttons, 'Expected all six icon actions to render');

        foreach ($buttons as $btn) {
            $class = $btn->getAttribute('class');
            $this->assertStringContainsString('!h-11', $class, "Missing !h-11 on: {$class}");
            $this->assertStringContainsString('!w-11', $class, "Missing !w-11 on: {$class}");
        }
    }

    public function test_no_icon_action_relies_only_on_the_old_small_class(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        foreach ($this->actionButtons($this->index($shop->id)->getContent()) as $btn) {
            $class = $btn->getAttribute('class');
            // Any button still carrying a base icon class MUST also carry the
            // enlarged target — the old 32×32-only sizing may not stand alone.
            if (str_contains($class, 'categories-icon-btn') || str_contains($class, 'categories-sub-icon-btn')) {
                $this->assertStringContainsString('!h-11', $class, "Base icon class without enlarged target: {$class}");
            }
        }
    }

    public function test_every_icon_only_action_has_an_accessible_name(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        foreach ($this->actionButtons($this->index($shop->id)->getContent()) as $btn) {
            $label = trim($btn->getAttribute('aria-label'));
            $this->assertNotSame('', $label, 'Icon-only action button missing aria-label');
        }
    }

    public function test_accessible_names_carry_action_context(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        $response = $this->index($shop->id);

        // Names include both the action and the target for real context.
        $response->assertSee('Add sub-category to Rings');
        $response->assertSee('Rename category Rings');
        $response->assertSee('Delete category Rings');
        $response->assertSee('Rename sub-category Bridal');
        $response->assertSee('Delete sub-category Bridal');
    }

    public function test_every_icon_action_has_a_visible_focus_ring(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        foreach ($this->actionButtons($this->index($shop->id)->getContent()) as $btn) {
            $class = $btn->getAttribute('class');
            $this->assertStringContainsString('focus-visible:ring', $class, "No focus-visible ring on: {$class}");
        }
    }

    public function test_no_icon_action_nests_another_interactive_control(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        foreach ($this->actionButtons($this->index($shop->id)->getContent()) as $btn) {
            /** @var DOMElement $btn */
            $this->assertSame(0, $btn->getElementsByTagName('button')->length, 'Nested <button> found');
            $this->assertSame(0, $btn->getElementsByTagName('a')->length, 'Nested <a> found');
        }
    }

    public function test_dependency_warning_delete_copy_still_present(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->actingAs($user);

        $this->index($shop->id)->assertSee(
            'Delete this unused category? Categories linked to products or subcategories cannot be deleted.'
        );
    }

    public function test_view_only_user_gets_no_enlarged_write_controls(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->seedFullCard($shop->id);
        $this->grantOnlyPermissions($user, ['inventory.view']);
        $this->actingAs($user);

        $response = $this->index($shop->id);
        $response->assertOk();

        // Read-only: the only rendered card button is the toggle. No write
        // actions, therefore no rename/delete accessible names.
        $buttons = $this->actionButtons($response->getContent());
        $this->assertCount(1, $buttons, 'View-only user should see only the toggle button');
        $response->assertDontSee('Delete category Rings');
        $response->assertDontSee('Rename category Rings');
        $response->assertDontSee('Delete this unused category? Categories linked to products or subcategories cannot be deleted.');
    }
}
