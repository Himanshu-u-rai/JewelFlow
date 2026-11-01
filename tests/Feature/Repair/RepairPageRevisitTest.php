<?php

namespace Tests\Feature\Repair;

use App\Models\Repair;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Turbo Drive evaluates the Repairs list's and the repair edit form's inline
 * scripts again on every visit, all in one global scope. Both declared
 * `const REPAIR_PURITY_SUBS` at their top level, so a second visit to either
 * page (or the edit form after the list) threw "Identifier 'REPAIR_PURITY_SUBS'
 * has already been declared" and the whole script was skipped: the purity
 * dropdown stayed empty and the edit form's existing purity was not shown.
 *
 * A browser showed the failure and the fix (list, away, list again, the edit
 * form twice, Back: no error, the purity list filled each time, the same number
 * of document and window listeners after every visit). A PHP test cannot run
 * the scripts, so this pins the cause, as CategoryPageRevisitTest does for the
 * Categories page.
 */
class RepairPageRevisitTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** event => [target, the window property holding its current listener] */
    private const SLOTS = [
        'click'   => ['document', '__repairsPageClickHandler'],
        'keydown' => ['document', '__repairsPageEscapeHandler'],
        'scroll'  => ['window', '__repairsPageScrollHandler'],
        'resize'  => ['window', '__repairsPageResizeHandler'],
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_both_repairs_page_scripts_can_be_evaluated_on_every_visit(): void
    {
        foreach ($this->pageScripts() as $page => $script) {
            $this->assertSame([], $this->topLevelDeclarations($script),
                "{$page}: a top-level const/let/class throws \"already been declared\" the second time Turbo evaluates this script.");
        }
    }

    public function test_each_run_replaces_the_listeners_the_last_repairs_page_added(): void
    {
        foreach ($this->pageScripts() as $page => $script) {
            // A plain add would stack one more listener on every visit, and keep the edit
            // form's scroll handler (which closes pickers on phones too) alive on the list.
            $this->assertStringNotContainsString('document.addEventListener(', $script, "{$page}: a document listener is added again on every visit");
            $this->assertStringNotContainsString('window.addEventListener(', $script, "{$page}: a window listener is added again on every visit");

            // The two pages share the slots, so whichever ran last owns them.
            foreach (self::SLOTS as $event => [$target, $slot]) {
                $this->assertSame(1, substr_count($script, "replaceRepairsListener({$target}, '{$event}', '{$slot}'"),
                    "{$page}: the {$event} listener does not replace the one in {$slot}");
            }
        }
    }

    public function test_the_lists_escape_handler_survives_a_page_without_the_deliver_modal(): void
    {
        // The handler stays on the document after the user leaves the list, and Escape
        // on any other page reached closeDeliverModal() with no #deliverModal to close.
        $this->assertStringContainsString(
            "document.getElementById('deliverModal')?.close()",
            $this->pageScripts()['list']
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The inline script each repairs page owns (the layout has others).
     *
     * @return array{list: string, edit: string}
     */
    private function pageScripts(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->createRepair($owner, $shop);

        return [
            'list' => $this->pageScript($owner, $shop, route('repairs.index')),
            'edit' => $this->pageScript($owner, $shop, route('repairs.edit', $repair)),
        ];
    }

    private function pageScript(User $owner, Shop $shop, string $url): string
    {
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get($url)->assertOk()->getContent());

        preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $html, $m);
        $own = array_values(array_filter($m[1], fn (string $js) => str_contains($js, 'REPAIR_PURITY_SUBS')));
        $this->assertCount(1, $own, "the repairs page script was not found at {$url}");

        return $own[0];
    }

    private function createRepair(User $owner, Shop $shop): Repair
    {
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(route('repairs.store'), [
                'customer_id' => $customer->id,
                'item_description' => 'Gold chain clasp',
                'gross_weight' => 5.0,
            ]))->assertRedirect();

        return Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    /**
     * Declarations at brace depth 0 that JavaScript refuses to repeat in one
     * global scope. `var` and `function` may repeat, so they are not listed.
     * (The same reading as CategoryPageRevisitTest's.)
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
