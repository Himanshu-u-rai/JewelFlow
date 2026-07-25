<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — parent Categories paginate (20/page), children eager-load
 * only for the visible parents, and ?q survives the pagination links.
 *
 * The N+1 guard is the important one: subcategories load in a SINGLE query for
 * the whole page, so the sub_categories SELECT count must stay at exactly 1
 * regardless of how many parents are on the page.
 */
class CategoryPaginationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Create N categories with deterministic, sortable names. */
    private function seedCategories(int $shopId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Category::forceCreate([
                'shop_id' => $shopId,
                'name' => 'Cat ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function page(int $shopId, array $params = [])
    {
        return TenantContext::runFor($shopId, fn () => $this->get(route('categories.index', $params)));
    }

    public function test_first_page_caps_at_twenty_and_advertises_more(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $this->seedCategories($shop->id, 25);

        $response = $this->page($shop->id);

        $response->assertOk();
        $response->assertSee('Cat 001');
        $response->assertSee('Cat 020');
        $response->assertDontSee('Cat 021'); // spilled to page 2
        $response->assertSee('?page=2', false); // paginator link rendered
    }

    public function test_second_page_shows_the_remainder(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $this->seedCategories($shop->id, 25);

        $response = $this->page($shop->id, ['page' => 2]);

        $response->assertOk();
        $response->assertSee('Cat 021');
        $response->assertSee('Cat 025');
        $response->assertDontSee('Cat 001'); // stayed on page 1 — stable order
    }

    public function test_query_param_is_retained_in_pagination_links(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        // 25 categories all matching 'cat' so search still paginates.
        $this->seedCategories($shop->id, 25);

        $response = $this->page($shop->id, ['q' => 'cat']);

        $response->assertOk();
        // withQueryString() must keep q on the next-page link.
        $response->assertSee('q=cat', false);
        $response->assertSee('page=2', false);
    }

    public function test_subcategory_match_yields_each_parent_once_across_pages(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        // One parent with several matching children — whereHas is EXISTS, so the
        // parent must appear exactly once, never fanned out per child.
        $rings = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        foreach (['Bridal Gold', 'Bridal Silver', 'Bridal Platinum'] as $name) {
            SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $rings->id, 'name' => $name]);
        }

        $response = $this->page($shop->id, ['q' => 'bridal']);

        $response->assertOk();
        // "Rings" heading appears once (single card), not three times.
        $this->assertSame(1, substr_count($response->getContent(), '>Rings</h3>'));
    }

    public function test_children_eager_load_in_one_query_no_n_plus_one(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        // 20 parents, each with children — an N+1 would fire 20 child SELECTs.
        for ($i = 1; $i <= 20; $i++) {
            $c = Category::forceCreate([
                'shop_id' => $shop->id,
                'name' => 'Cat ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
            SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $c->id, 'name' => 'Sub A']);
            SubCategory::forceCreate(['shop_id' => $shop->id, 'category_id' => $c->id, 'name' => 'Sub B']);
        }

        DB::enableQueryLog();
        $response = $this->page($shop->id);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        // Count SELECTs that read the sub_categories table. Eager loading pulls
        // every visible parent's children in ONE whereIn query; an N+1 would
        // make this grow with the parent count.
        $subQueries = collect($log)->filter(
            fn ($entry) => str_contains($entry['query'], 'sub_categories')
                && str_starts_with(ltrim(strtolower($entry['query'])), 'select')
        )->count();

        // 1 for the eager load. The controller's KPI totals also touch
        // sub_categories (a count + a doesntHave EXISTS), so allow a small
        // constant ceiling — the point is it does NOT scale with 20 parents.
        $this->assertLessThanOrEqual(3, $subQueries, "Expected constant sub_categories query count, got {$subQueries}");
    }

    public function test_all_visible_parents_have_children_eager_loaded(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $this->seedCategories($shop->id, 5);

        $response = $this->page($shop->id);
        $response->assertOk();

        // The view receives a paginator whose items each have subCategories
        // already loaded (accessing the relation must not lazy-query).
        $categories = $response->viewData('categories');
        foreach ($categories as $category) {
            $this->assertTrue(
                $category->relationLoaded('subCategories'),
                "Category {$category->name} rendered without eager-loaded subCategories"
            );
        }
    }
}
