<?php

namespace Tests\Feature\Inventory;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit M6 (2026-11-13): the retailer item create and edit pages each carried the same four quick-add dialogs
 * (supplier, category, sub-category, purity) as copied script, with the same two faults:
 *  - a dialog could be closed while its save was on the way; reopening it enabled the button again (a duplicate
 *    supplier), and the late answer closed the new dialog and changed the picker underneath;
 *  - choosing "+ New vendor / karigar…" blanked the picker but kept the hidden vendor_id/karigar_id, so cancelling
 *    saved the old supplier while the screen showed none (category, sub-category and purity blanked likewise).
 * The script now lives once (inventory/items/_quick_add_script) and both pages render it.
 *
 * PHP cannot run the dialogs; a browser checked them. These pin the shape that makes them right.
 */
class ItemQuickAddDialogsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, string> page => html */
    private function pages(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $item = $this->createItem($shop->id, null, ['selling_price' => 1000, 'source' => 'purchase']);
        $this->withoutVite()->actingAs($user);

        $pages = [];
        foreach (['create' => '/inventory/items/create', 'edit' => "/inventory/items/{$item->id}/edit"] as $page => $path) {
            $res = TenantContext::runFor($shop->id, fn () => $this->get('https://jewelflows.com' . $path));
            $this->assertSame(200, $res->status(), "{$page} page: " . substr(strip_tags((string) $res->getContent()), 0, 200));
            $pages[$page] = $res->getContent();
        }

        return $pages;
    }

    public function test_both_item_pages_render_the_one_shared_quick_add_script(): void
    {
        foreach ($this->pages() as $page => $html) {
            $this->assertSame(1, substr_count($html, 'var quickAdd = {'), "{$page}: the shared dialog script, once");
            $refresher = $page === 'create' ? 'refreshCreateDropdown' : 'refreshEditDropdown';
            $this->assertStringContainsString("{$refresher}(picker)", $html, "{$page}: its own dropdown refresher");
        }
    }

    public function test_a_dialog_cannot_be_closed_or_sent_twice_while_saving(): void
    {
        foreach ($this->pages() as $page => $html) {
            foreach (['closeNewSupplierModal' => 'newSupplierModal', 'closeNewCategoryModal' => 'newCategoryModal',
                'closeNewSubCategoryModal' => 'newSubCategoryModal', 'closeCustomPurityModal' => 'customPurityModal'] as $close => $modal) {
                $this->assertStringContainsString("function {$close}(force = false) {\n            if (force !== true && quickAdd.saving('{$modal}')) return;", $html, "{$page} {$close}");
                $this->assertStringNotContainsString("addEventListener('click', {$close});", $html, "{$page}: a click must not count as force");
            }
            $this->assertSame(4, substr_count($html, "if (!quickAdd.begin(btn.closest('[id$=\"Modal\"]').id)) return;"), "{$page}: every save begins once");
        }
    }

    /**
     * Found while breaking M6 in a browser: the edit page placed the dialogs AFTER its script, so on a full page load
     * the script bound its buttons to nothing (Cancel, backdrop, Escape and the save button did nothing; the dialog
     * could not be closed). Turbo visits hid it. Every dialog element now comes before the script that binds it.
     */
    public function test_the_dialogs_exist_before_the_script_that_binds_them(): void
    {
        foreach ($this->pages() as $page => $html) {
            $script = strpos($html, 'var quickAdd = {');
            foreach (['newSupplierModal', 'newSupplierCancel', 'newSupplierConfirm', 'newCategoryModal', 'newCategoryConfirm',
                'newSubCategoryModal', 'newSubCategoryConfirm', 'customPurityModal', 'customPurityConfirm'] as $id) {
                $element = strpos($html, 'id="' . $id . '"');
                $this->assertNotFalse($element, "{$page}: #{$id} is on the page");
                $this->assertLessThan($script, $element, "{$page}: #{$id} must come before the script that binds it");
            }
        }
    }

    public function test_choosing_a_new_or_custom_option_keeps_the_previous_choice(): void
    {
        foreach ($this->pages() as $page => $html) {
            $this->assertDoesNotMatchRegularExpression("/(this|categorySelect)\\.value = '';\\s*refresh\\w+Dropdown\\(\\w+\\);\\s*open\\w+Modal\\(\\)/", $html, "{$page}: no select is blanked for a dialog");
            $this->assertSame(4, preg_match_all('/undoActionOption\((this|categorySelect)\);/', $html), "{$page}: four action options undo themselves");
            $this->assertStringContainsString("undoActionOption(this);\n                    syncSupplierHiddenFields(this.value);", $html, "{$page}: hidden supplier fields follow the picker");
        }
    }
}
