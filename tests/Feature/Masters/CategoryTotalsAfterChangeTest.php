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
 * The Categories page shows three shop-wide totals, each card's own count of
 * sub-categories, and an empty state. All of it is computed in one place,
 * CategoryController@index, and drawn only when the page is rendered.
 *
 * Three changes were made in place instead, and left those numbers stale
 * until a reload. Add Category was answered with a Turbo Stream that appended
 * the new card (4 totals, 5 cards; in an empty shop there was no list to
 * append to, so the page went on saying "No Categories Yet"). The two deletes
 * went through the in-place handler (data-ajax-delete): a JSON answer, then
 * the row was removed in the browser, even when the server had refused.
 *
 * Renames and Add Sub-Category never had the problem: they are answered with
 * a redirect to the page. The three now are too, so every change comes back
 * as the page index() renders.
 */
class CategoryTotalsAfterChangeTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** What Turbo sends with a form it submits: it would accept a stream, and it is not an ajax call. */
    private const TURBO = ['Accept' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_confirmed_add_is_answered_with_the_page_and_its_totals(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'Chains'], self::TURBO)
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('success', 'Category created successfully!'));

        $this->assertSame(['2', '0', '2'], $this->totals($this->page($owner, $shop->id)));
    }

    public function test_the_first_category_replaces_the_empty_state_and_deleting_the_last_brings_it_back(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->assertStringContainsString('No Categories Yet', $this->page($owner, $shop->id));

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->followingRedirects()
            ->post(route('categories.store'), ['_intent' => 'add_category', 'name' => 'Chains'], self::TURBO)
            ->assertOk()->getContent());

        $this->assertSame(['1', '0', '1'], $this->totals($html));
        $this->assertStringContainsString('id="categories-list"', $html);
        $this->assertStringNotContainsString('No Categories Yet', $html);

        $chains = Category::withoutGlobalScopes()->where('shop_id', $shop->id)->sole();

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->followingRedirects()
            ->delete(route('categories.destroy', $chains), [], self::TURBO)
            ->assertOk()->getContent());

        $this->assertStringContainsString('No Categories Yet', $html);
        $this->assertSame([], $this->totals($html));
    }

    public function test_a_delete_is_sent_as_a_form_and_answered_with_the_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $rings = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        $plain = SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $rings->id, 'name' => 'Plain']);
        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Chains']);

        $html = $this->page($owner, $shop->id);
        $this->assertSame(['2', '1', '1'], $this->totals($html));
        $this->assertStringContainsString('1 sub-categories', $html);

        // Both deletes still ask first, and neither is handed to the in-place handler.
        $this->assertMatchesRegularExpression('~action="[^"]*/categories/'.$rings->id.'"\s+data-confirm-message="[^"]+"~', $html);
        $this->assertMatchesRegularExpression('~action="[^"]*/sub-categories/'.$plain->id.'"\s+data-confirm-message="[^"]+"~', $html);
        $this->assertStringNotContainsString('data-ajax-delete', $html);

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->followingRedirects()
            ->delete(route('sub-categories.destroy', $plain), [], self::TURBO)
            ->assertOk()->getContent());

        $this->assertStringContainsString('<meta name="flash-success" content="Sub-category deleted successfully!">', $html);
        $this->assertSame(['2', '0', '2'], $this->totals($html));
        $this->assertStringNotContainsString('1 sub-categories', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function page(User $owner, int $shopId): string
    {
        return TenantContext::runFor($shopId, fn () => $this->actingAs($owner)
            ->get(route('categories.index'))->assertOk()->getContent());
    }

    /**
     * Categories, Sub-Categories, Need Setup, as the page shows them (none in the empty state).
     *
     * @return list<string>
     */
    private function totals(string $html): array
    {
        preg_match_all('~<p class="categories-kpi-value">\s*([^<]*?)\s*</p>~', $html, $m);

        return $m[1];
    }
}
