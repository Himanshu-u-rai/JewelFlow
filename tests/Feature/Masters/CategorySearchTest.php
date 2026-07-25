<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 2 — bounded server-side search on the unified categories page.
 *
 * The index now accepts ?q and searches BOTH parent Category names and child
 * SubCategory names (a subcategory hit returns its parent once, with the
 * parent's complete child list). Blank q behaves as the normal index. Bindings
 * only — no raw user SQL, so quotes/wildcards can't error or inject.
 *
 * Two console details this suite works around:
 *  - BelongsToShop's global scope fails closed to `1=0` with no TenantContext,
 *    so every GET is wrapped in TenantContext::runFor (see CategoryDeleteGuardTest).
 *  - The page's static modal placeholder text ("e.g., Rings, Necklaces, Bangles")
 *    renders on EVERY load, so name assertions use distinctive names that never
 *    appear in the template and match the rendered card title `>Name</h3>`.
 */
class CategorySearchTest extends TestCase
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

    private function search(int $shopId, ?string $q)
    {
        $params = $q === null ? [] : ['q' => $q];

        return TenantContext::runFor($shopId, fn () => $this->get(route('categories.index', $params)));
    }

    /** The card title renders as `<h3 ...>Name</h3>` — scope negatives to it. */
    private function cardTitle(string $name): string
    {
        return '>' . $name . '</h3>';
    }

    public function test_category_name_match_is_returned(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->cat($shop->id, 'Amethyst');
        $this->cat($shop->id, 'Sapphire');

        $response = $this->search($shop->id, 'amethyst');

        $response->assertOk();
        $response->assertSee($this->cardTitle('Amethyst'), false);
        $response->assertDontSee($this->cardTitle('Sapphire'), false);
    }

    public function test_subcategory_match_returns_parent_with_all_siblings(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $amethyst = $this->cat($shop->id, 'Amethyst');
        $this->sub($shop->id, $amethyst->id, 'Bezel Set');   // the match
        $this->sub($shop->id, $amethyst->id, 'Pave Style');  // sibling — must also show
        $this->cat($shop->id, 'Sapphire');

        $response = $this->search($shop->id, 'bezel');

        $response->assertOk();
        $response->assertSee($this->cardTitle('Amethyst'), false); // parent surfaced
        $response->assertSee('Bezel Set');                          // the matched child
        $response->assertSee('Pave Style');                         // complete child list
        $response->assertDontSee($this->cardTitle('Sapphire'), false);
    }

    public function test_unrelated_records_are_excluded(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->cat($shop->id, 'Amethyst');
        $topaz = $this->cat($shop->id, 'Topaz');
        $this->sub($shop->id, $topaz->id, 'Cushion Cut');

        $response = $this->search($shop->id, 'amethyst');

        $response->assertOk();
        $response->assertSee($this->cardTitle('Amethyst'), false);
        $response->assertDontSee($this->cardTitle('Topaz'), false);
        $response->assertDontSee('Cushion Cut');
    }

    public function test_blank_query_behaves_as_normal_index(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->cat($shop->id, 'Amethyst');
        $this->cat($shop->id, 'Sapphire');

        $response = $this->search($shop->id, '');

        $response->assertOk();
        $response->assertSee($this->cardTitle('Amethyst'), false);
        $response->assertSee($this->cardTitle('Sapphire'), false);
    }

    public function test_special_characters_are_safe_and_match_literally(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        // A quote would break naive string SQL; bindings keep it literal.
        $this->cat($shop->id, "O'Brien Custom");
        $this->cat($shop->id, 'Sapphire');

        $response = $this->search($shop->id, "O'Brien");

        $response->assertOk(); // no 500 / SQL error
        $response->assertSee($this->cardTitle('O&#039;Brien Custom'), false);
        $response->assertDontSee($this->cardTitle('Sapphire'), false);
    }

    public function test_no_results_state_is_distinct_from_empty_shop(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->cat($shop->id, 'Amethyst'); // shop is NOT empty

        $response = $this->search($shop->id, 'zzz-no-such-thing');

        $response->assertOk();
        // Search-miss card, not the "No Categories Yet" empty-shop card.
        $response->assertSee('No matching categories');
        $response->assertDontSee('No Categories Yet');
    }

    public function test_cross_shop_categories_never_appear_in_results(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->actingAs($userA);

        $this->cat($shopB->id, 'SecretGemstone'); // shop B's data

        $response = $this->search($shopA->id, 'gemstone');

        $response->assertOk();
        $response->assertDontSee('SecretGemstone');
    }
}
