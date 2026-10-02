<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Turbo Drive evaluates the Categories page's inline script again on every
 * visit to the page, in the same global scope. A top-level `const` or `let`
 * can be declared there only once, so the second visit threw "Identifier
 * 'categoryBaseUrl' has already been declared" and the whole script was
 * skipped. The page then ran on what the FIRST visit had left behind: a save
 * the server rejected (a duplicate name) came back with its modal closed and
 * its message hidden inside it, because the call that reopens the modal sits
 * in the script that never ran.
 *
 * A browser showed the failure and the fix (three visits, no error; the
 * rejected save reopens its modal). A PHP test cannot run the script, so this
 * pins the cause: nothing at the script's top level may be a declaration that
 * cannot be repeated, and the listeners it puts on the document are replaced,
 * not added again, on each run.
 */
class CategoryPageRevisitTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_page_script_can_be_evaluated_on_every_visit(): void
    {
        $script = $this->pageScript();

        $this->assertSame([], $this->topLevelDeclarations($script),
            'A top-level const/let/class throws "already been declared" the second time Turbo evaluates this script.');
    }

    public function test_each_run_of_the_script_replaces_its_document_listeners(): void
    {
        $script = $this->pageScript();

        // One listener per kind, however many times the page is visited.
        foreach (['turbo:load', 'keydown'] as $event) {
            $this->assertSame(1, substr_count($script, "document.addEventListener('{$event}'"), "{$event}: added once per run");
            $this->assertSame(1, substr_count($script, "document.removeEventListener('{$event}'"), "{$event}: the previous run's listener is not removed first");
        }
    }

    public function test_a_rejected_save_reopens_its_modal_once_per_response(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);

        // The server refuses a second "Rings" and sends the page back with the attempt.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->from(route('categories.index'))
            ->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'rings'])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasErrors('name'));

        // The page it sends back reopens the Add modal, behind a mark it sets on the page
        // itself. Turbo's cached copy of the page carries that mark, so Back does not
        // reopen a modal the user closed; before the script could re-run, nothing reopened it.
        $this->assertMatchesRegularExpression(
            "/if \(page\.dataset\.intentShown !== '1'\) \{\s*page\.dataset\.intentShown = '1';\s*openAddCategoryModal\(\);/",
            $this->pageScript($owner)
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The inline script the Categories page owns (the layout has others). */
    private function pageScript(?User $owner = null): string
    {
        $owner ??= $this->createRetailerTenant()[0];
        $html = $this->actingAs($owner)->get(route('categories.index'))->assertOk()->getContent();

        preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $html, $m);
        $own = array_values(array_filter($m[1], fn (string $js) => str_contains($js, 'initCategoriesPage')));
        $this->assertCount(1, $own, 'the Categories page script was not found');

        return $own[0];
    }

    /**
     * Declarations at brace depth 0 that JavaScript refuses to repeat in one
     * global scope. `var` and `function` may repeat, so they are not listed.
     *
     * @return list<string>
     */
    private function topLevelDeclarations(string $js): array
    {
        // Strings and comments first, whichever starts earlier, so a brace or a keyword inside one does not count.
        $js = preg_replace_callback(
            '~("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`)|/\*.*?\*/|//[^\n]*~s',
            fn (array $m) => isset($m[1]) && $m[1] !== '' ? '""' : '',
            $js
        );

        $depth = 0;
        $found = [];
        foreach (preg_split('~([{}()\[\]])~', $js, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if (in_array($part, ['{', '(', '['], true)) {
                $depth++;
            } elseif (in_array($part, ['}', ')', ']'], true)) {
                $depth--;
            } elseif ($depth === 0 && preg_match_all('~\b(?:const|let|class)\s+[A-Za-z_$][\w$]*~', $part, $m)) {
                array_push($found, ...$m[0]);
            }
        }

        return $found;
    }
}
