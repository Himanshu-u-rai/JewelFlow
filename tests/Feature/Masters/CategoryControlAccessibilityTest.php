<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — accessibility hardening for the PAGE-LEVEL Category controls
 * (Add Category, Search, modal Cancel/submit) and the shared global confirm
 * dialog. Sibling suite CategoryActionAccessibilityTest covers the per-card icon
 * actions; this one covers everything else the workflow touches.
 *
 * The header/primary-action buttons sat at 34-42px because app.css pins
 * `.categories-page-header .categories-add-btn { min-height: 34px }` at (0,2,0)
 * specificity, which plain `min-h-[44px]` (0,1,0) can't beat — so the Blade
 * grows each control with the important variant `!min-h-[44px]` plus a
 * focus-visible ring. Assertions run against the REAL rendered HTML.
 *
 * The global dialog lives in resources/js/app.js (not scanned by Blade tests),
 * so its Cancel/Continue markup is asserted directly against the source file:
 * the data-* selectors and visible text must survive, and the 44px + focus
 * classes must be present.
 */
class CategoryControlAccessibilityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const APP_JS = __DIR__ . '/../../../resources/js/app.js';

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

    private function renderIndex(int $shopId): string
    {
        return TenantContext::runFor($shopId, fn () => $this->get(route('categories.index')))->getContent();
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return $dom;
    }

    public function test_add_category_and_search_buttons_have_44px_touch_target(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id, 'Rings');
        $this->actingAs($user);

        $dom = $this->dom($this->renderIndex($shop->id));

        // Every visible primary/secondary workflow button on the page carries the
        // enlarged target + focus ring. Icon actions (h-11/w-11) are excluded —
        // they are asserted by CategoryActionAccessibilityTest.
        foreach ($dom->getElementsByTagName('button') as $btn) {
            $class = $btn->getAttribute('class');
            $isWorkflow = str_contains($class, 'categories-add-btn')
                || str_contains($class, 'categories-primary-action')
                || str_contains($class, 'border-slate-200'); // modal Cancel

            if (! $isWorkflow) {
                continue;
            }

            $this->assertStringContainsString('!min-h-[44px]', $class, "Workflow button missing !min-h-[44px]: {$class}");
            $this->assertStringContainsString('focus-visible:ring', $class, "Workflow button missing focus ring: {$class}");
        }
    }

    public function test_search_input_has_a_programmatic_label(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id, 'Rings'); // search form only renders once the shop has data
        $this->actingAs($user);

        $dom = $this->dom($this->renderIndex($shop->id));

        $input = $dom->getElementById('categorySearchInput');
        $this->assertNotNull($input, 'Search input must carry a stable id for its label');

        // A <label for="categorySearchInput"> must exist and point at the input.
        $matched = false;
        foreach ($dom->getElementsByTagName('label') as $label) {
            if ($label->getAttribute('for') === 'categorySearchInput') {
                $matched = true;
                $this->assertNotSame('', trim($label->textContent), 'Search label must have text');
            }
        }
        $this->assertTrue($matched, 'No <label for="categorySearchInput"> associated with the search input');
    }

    public function test_edit_modal_labels_are_associated_with_their_inputs(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $dom = $this->dom($this->renderIndex($shop->id));

        foreach (['editCategoryName', 'editSubCategoryName'] as $inputId) {
            $this->assertNotNull($dom->getElementById($inputId), "Missing input #{$inputId}");

            $matched = false;
            foreach ($dom->getElementsByTagName('label') as $label) {
                if ($label->getAttribute('for') === $inputId) {
                    $matched = true;
                }
            }
            $this->assertTrue($matched, "No <label for=\"{$inputId}\"> associated with the input");
        }
    }

    public function test_view_only_user_still_sees_accessible_search_but_no_write_controls(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->cat($shop->id, 'Rings');
        $this->grantOnlyPermissions($user, ['inventory.view']);
        $this->actingAs($user);

        $html = $this->renderIndex($shop->id);
        $dom = $this->dom($html);

        // Search stays fully labelled for read-only users.
        $this->assertNotNull($dom->getElementById('categorySearchInput'));

        // No @can('catalog.manage')-gated write controls: the header Add button
        // and the per-card delete-confirm control are both absent. (The static,
        // hidden edit modals are always in the DOM but unreachable without their
        // gated trigger buttons — so they are not a meaningful write signal.)
        $this->assertStringNotContainsString('categories-add-btn', $html);
        $this->assertStringNotContainsString(
            'Delete this unused category? Categories linked to products or subcategories cannot be deleted.',
            $html
        );
    }

    /**
     * The shared confirm dialog is built in resources/js/app.js. Its behaviour is
     * bound to data-* selectors, so a class-only accessibility change must not
     * disturb the selectors or the visible button text.
     */
    public function test_global_confirm_dialog_buttons_keep_selectors_text_and_gain_44px(): void
    {
        $js = file_get_contents(self::APP_JS);
        $this->assertNotFalse($js, 'app.js must be readable');

        // Behaviour anchors — the selectors JS binds click handlers to.
        $this->assertStringContainsString('data-confirm-cancel="true"', $js);
        $this->assertStringContainsString('data-confirm-accept="true"', $js);

        // Visible accessible names preserved.
        $this->assertMatchesRegularExpression('/data-confirm-cancel="true">Cancel</', $js);
        $this->assertMatchesRegularExpression('/data-confirm-accept="true">Continue</', $js);

        // Each dialog button gained the 44px target + focus ring. Isolate the two
        // <button> tags carrying the confirm data-attributes and check each.
        preg_match_all('/<button[^>]*data-confirm-(?:cancel|accept)="true"[^>]*>/', $js, $m);
        $this->assertCount(2, $m[0], 'Expected exactly two confirm dialog buttons');
        foreach ($m[0] as $buttonTag) {
            $this->assertStringContainsString('!min-h-[44px]', $buttonTag, "Dialog button missing !min-h-[44px]: {$buttonTag}");
            $this->assertStringContainsString('focus-visible:ring', $buttonTag, "Dialog button missing focus ring: {$buttonTag}");
        }
    }

    /**
     * Guardrail: the class-only change must not have removed the click-handler
     * wiring. If the data-selectors that resolve the promise vanish, cancel/accept
     * stop working — so pin the exact listener lines.
     */
    public function test_global_confirm_dialog_behaviour_wiring_is_untouched(): void
    {
        $js = file_get_contents(self::APP_JS);

        $this->assertStringContainsString("cancelButton.addEventListener('click', () => close(false));", $js);
        $this->assertStringContainsString("acceptButton.addEventListener('click', () => close(true));", $js);
        $this->assertStringContainsString('[data-confirm-cancel="true"]', $js);
        $this->assertStringContainsString('[data-confirm-accept="true"]', $js);
    }
}
