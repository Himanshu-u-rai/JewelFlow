<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Every change on the Categories page is answered with the page drawn again,
 * so its totals are right (CategoryTotalsAfterChangeTest). Drawn again must not
 * mean starting over: each answer used to go to the bare list, so a change made
 * on page 2 of a search threw the user back to page 1 of everything.
 *
 * The answer now goes back to the view of the list the change was made from:
 * the same search, the same page. When that page no longer exists (its last
 * category was just deleted) the list itself sends the nearest page that does,
 * and the message survives that extra step.
 *
 * What the server cannot know, the scroll position and the cards a phone user
 * had opened, is carried across by the page's script; a browser showed that.
 */
class CategoryListPlaceKeptTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private User $owner;

    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();

        [$this->owner, $shop] = $this->createRetailerTenant();
        $this->shopId = $shop->id;
    }

    public function test_each_change_goes_back_to_the_search_and_page_it_was_made_from(): void
    {
        $bands = $this->categories(25, 'Band');          // "Band 01" … "Band 25": page 2 of the search holds 21-25
        $from = route('categories.index', ['q' => 'Band', 'page' => 2]);
        $sub = SubCategory::forceCreate(['shop_id' => $this->shopId, 'category_id' => $bands[21]->id, 'name' => 'Plain']);

        $changes = [
            'add category' => fn () => $this->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'Chains']),
            'rename category' => fn () => $this->put(route('categories.update', $bands[22]), ['name' => 'Band 22b']),
            'add sub-category' => fn () => $this->post(route('sub-categories.store'), ['category_id' => $bands[23]->id, 'name' => 'Broad']),
            'rename sub-category' => fn () => $this->put(route('sub-categories.update', $sub), ['name' => 'Plain gold']),
            'delete sub-category' => fn () => $this->delete(route('sub-categories.destroy', $sub)),
            'delete category' => fn () => $this->delete(route('categories.destroy', $bands[24])),
        ];

        foreach ($changes as $what => $change) {
            $response = $this->change($from, $change);
            $this->assertSame($from, $response->headers->get('Location'), "{$what}: answered somewhere else");
            $response->assertSessionHas('success');
        }

        // A delete the server refuses (the category still has a sub-category) and a save it
        // rejects (a name already taken) go back to the same place, with their message.
        SubCategory::forceCreate(['shop_id' => $this->shopId, 'category_id' => $bands[25]->id, 'name' => 'Kept']);
        $this->change($from, fn () => $this->delete(route('categories.destroy', $bands[25])))
            ->assertRedirect($from)->assertSessionHas('error');
        $this->change($from, fn () => $this->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'band 01']))
            ->assertRedirect($from)->assertSessionHasErrors('name');
    }

    public function test_a_change_made_from_anywhere_else_goes_to_the_plain_list(): void
    {
        $this->change(route('dashboard'), fn () => $this->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'Chains']))
            ->assertRedirect(route('categories.index'));

        // Only the search and the page are taken from where the request came from, nothing else.
        $this->change(route('categories.index', ['q' => 'Ch', 'page' => 3, 'sort' => 'x']),
            fn () => $this->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'Charms']))
            ->assertRedirect(route('categories.index', ['q' => 'Ch', 'page' => 3]));
    }

    public function test_deleting_the_last_category_on_a_page_lands_on_the_nearest_page_that_exists(): void
    {
        $bands = $this->categories(21, 'Band');          // page 2 holds "Band 21" alone
        $pageTwo = route('categories.index', ['q' => 'Band', 'page' => 2]);

        $html = TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->owner)->from($pageTwo)->followingRedirects()
            ->delete(route('categories.destroy', $bands[21]))->assertOk()->getContent());

        // Page 1 of the same search, and the message made it through the extra step.
        $this->assertSame(20, substr_count($html, 'class="categories-card '));
        $this->assertStringContainsString('Band 20', $html);
        $this->assertStringContainsString('value="Band"', $html);
        $this->assertStringContainsString('<meta name="flash-success" content="Category deleted successfully!">', $html);
        $this->assertStringNotContainsString('No matching categories', $html);

        // The same for an address typed by hand or kept from earlier.
        TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->owner)
            ->get(route('categories.index', ['q' => 'Band', 'page' => 9]))
            ->assertRedirect(route('categories.index', ['q' => 'Band', 'page' => 1])));

        // A search with no match has one page, an empty one: that is not a page past the end.
        TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->owner)
            ->get(route('categories.index', ['q' => 'nothing-like-this']))->assertOk()->assertSee('No matching categories'));
    }

    public function test_each_card_says_which_category_it_is_so_an_opened_card_can_be_reopened(): void
    {
        $bands = $this->categories(2, 'Band');

        $html = TenantContext::runFor($this->shopId, fn () => $this->actingAs($this->owner)->get(route('categories.index'))->assertOk()->getContent());

        foreach ($bands as $band) {
            $this->assertMatchesRegularExpression('~<div class="categories-card [^"]*" data-category-id="'.$band->id.'">~', $html);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<int, Category> keyed by the number in each name
     */
    private function categories(int $count, string $prefix): array
    {
        $made = [];
        foreach (range(1, $count) as $n) {
            $made[$n] = Category::forceCreate(['shop_id' => $this->shopId, 'name' => sprintf('%s %02d', $prefix, $n)]);
        }

        return $made;
    }

    /** A change sent the way the page sends it: from an address, as the signed-in owner. */
    private function change(string $from, callable $request)
    {
        return TenantContext::runFor($this->shopId, function () use ($from, $request) {
            $this->actingAs($this->owner)->from($from);

            return $request();
        });
    }
}
