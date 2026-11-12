<?php

namespace Tests\Feature\Categories;

use Tests\TestCase;

/**
 * F03 (docs/ui-audits/2026-11-12-remaining-ui.md): the category popups must be
 * native modal dialogs, not visual overlays. Reads the Blade source only; no
 * database is needed.
 */
class CategoryDialogsTest extends TestCase
{
    private const DIALOG_IDS = [
        'addCategoryModal',
        'addSubCategoryModal',
        'editCategoryModal',
        'editSubCategoryModal',
    ];

    private function source(): string
    {
        $path = base_path('resources/views/categories/index.blade.php');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_every_popup_is_a_native_dialog_named_by_its_visible_heading(): void
    {
        $source = $this->source();

        foreach (self::DIALOG_IDS as $id) {
            $this->assertSame(
                1,
                preg_match('/<dialog\b[^>]*\bid="'.$id.'"[^>]*>(.*?)<\/dialog>/s', $source, $m, PREG_OFFSET_CAPTURE),
                "#{$id} must be a <dialog> element"
            );

            $openTag = substr($m[0][0], 0, strpos($m[0][0], '>') + 1);
            $this->assertSame(1, preg_match('/\baria-labelledby="([^"]+)"/', $openTag, $label), "#{$id} needs aria-labelledby");

            $inner = $m[1][0];
            $this->assertSame(
                1,
                preg_match('/<h[1-6]\b[^>]*\bid="'.preg_quote($label[1], '/').'"[^>]*>/', $inner),
                "aria-labelledby of #{$id} must point at a heading inside it"
            );
            $this->assertStringContainsString('<form', $inner);
        }
    }

    public function test_the_old_overlay_pattern_is_gone(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('categories-modal-overlay', $source);
        $this->assertDoesNotMatchRegularExpression('/<div\b[^>]*\bid="(?:'.implode('|', self::DIALOG_IDS).')"/', $source);
        $this->assertStringNotContainsString("classList.remove('hidden')", $source);
        $this->assertStringNotContainsString('categories-modal:not(.hidden)', $source);
    }

    public function test_open_uses_show_modal_and_close_uses_native_close(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('.showModal()', $source);
        $this->assertMatchesRegularExpression('/\.close\(\)/', $source);
    }

    public function test_cancel_and_backdrop_close_without_saving(): void
    {
        $source = $this->source();

        foreach (['closeAddCategoryModal', 'closeAddSubCategoryModal', 'closeEditCategoryModal', 'closeEditSubCategoryModal'] as $fn) {
            $this->assertStringContainsString('onclick="'.$fn.'()"', $source, "Cancel must call {$fn}()");
        }

        // Backdrop click: the click lands on the dialog element itself.
        $this->assertMatchesRegularExpression('/event\.target\s*===\s*this/', $source);
    }

    public function test_close_restores_focus_to_the_opener(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression('/__categoriesDialogOpener\s*=\s*document\.activeElement/', $source, 'the opener must be remembered');
        $this->assertMatchesRegularExpression('/__categoriesDialogOpener[^;\n]*\.focus\(\)|opener\.focus\(\)/', $source, 'the opener must be refocused');
        // Escape closes natively; focus return must hang off the dialog's close event.
        $this->assertMatchesRegularExpression('/\.onclose\s*=|addEventListener\(\s*[\'"]close[\'"]/', $source);
    }

    public function test_form_actions_and_fields_are_unchanged(): void
    {
        $source = $this->source();

        foreach ([
            "route('categories.store')",
            "route('sub-categories.store')",
            'name="_intent" value="add_category"',
            'name="_intent" value="add_sub_category"',
            'name="_intent" value="edit_category"',
            'name="_intent" value="edit_sub_category"',
            'id="editCategoryForm"',
            'id="editSubCategoryForm"',
            'id="subCategoryCategoryId"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }
}
