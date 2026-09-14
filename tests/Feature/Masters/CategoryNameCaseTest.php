<?php

namespace Tests\Feature\Masters;

use App\Models\Category;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Capitalization the operator typed survives, and a duplicate is still refused.
 *
 * These two belong in one file because they are two halves of the same change.
 * `NormalizeHumanTextInput` used to force every name to Title Case, which
 * damaged real names ("RK Jewellers" -> "Rk Jewellers") but also, by accident,
 * made `Rule::unique('name')` behave case-insensitively — both sides of the
 * comparison had been folded to the same shape before it ran.
 *
 * Taking the damage away takes the accident away too, and for categories that
 * matters beyond tidiness: `categories` has a UNIQUE index on `normalized_name`
 * (`lower(trim(name))`). A case-sensitive validator would wave "RINGS" through
 * while the index still refused it, so a readable field error would have become
 * a 500. `UniqueNameIgnoringCase` is what keeps validation and the index
 * agreeing.
 *
 * The console-only tenant-scope quirks are the same ones documented at length
 * in CategoryDeleteGuardTest; forceCreate() and TenantContext::runFor() are
 * used here for those same reasons.
 */
class CategoryNameCaseTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function storeAs(int $shopId, string $name)
    {
        return TenantContext::runFor($shopId, fn () => $this->post(route('categories.store'), ['name' => $name]));
    }

    public function test_a_name_the_operator_capitalized_is_stored_as_typed(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->storeAs($shop->id, 'RK Bridal Sets')->assertSessionHasNoErrors();

        $category = Category::withoutGlobalScopes()->where('shop_id', $shop->id)->first();

        $this->assertNotNull($category);
        $this->assertSame('RK Bridal Sets', $category->name,
            'the category was renamed on the operator behind their back');
    }

    public function test_an_all_lowercase_name_is_still_tidied(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->storeAs($shop->id, '  bridal   sets ')->assertSessionHasNoErrors();

        $category = Category::withoutGlobalScopes()->where('shop_id', $shop->id)->first();

        $this->assertNotNull($category);
        $this->assertSame('Bridal Sets', $category->name);
    }

    /**
     * The regression guard. Before UniqueNameIgnoringCase this POST reached the
     * `categories_shop_normalized_unique` index and died there.
     */
    public function test_a_differently_cased_duplicate_is_refused_with_a_field_error(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);

        $this->storeAs($shop->id, 'RINGS')->assertSessionHasErrors('name');

        $this->assertSame(1, Category::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    /** Whitespace differences are not a new category either. */
    public function test_a_duplicate_differing_only_in_spacing_is_refused(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Bridal Sets']);

        $this->storeAs($shop->id, '  Bridal   Sets  ')->assertSessionHasErrors('name');

        $this->assertSame(1, Category::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    /**
     * The rule is scoped, not global. Another shop's category of the same name
     * is none of this tenant's business — and getting this wrong would be a
     * cross-tenant information leak, not just a wrong error message.
     */
    public function test_another_shops_category_of_the_same_name_does_not_block_this_one(): void
    {
        [, $otherShop] = $this->createManufacturerTenant();
        Category::forceCreate(['shop_id' => $otherShop->id, 'name' => 'Rings']);

        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $this->storeAs($shop->id, 'Rings')->assertSessionHasNoErrors();

        $this->assertSame(1, Category::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    // ── editing an existing record ──────────────────────────────────────────

    private function renameTo(int $shopId, Category $category, string $name)
    {
        return TenantContext::runFor($shopId, fn () => $this->put(
            route('categories.update', $category), ['name' => $name]
        ));
    }

    /**
     * THE CASE A CASE-INSENSITIVE UNIQUENESS RULE MOST EASILY BREAKS: fixing your
     * own record's capitalization. The row matches itself under LOWER(), so
     * without the ignoreId exclusion "rings" -> "Rings" is refused as a duplicate
     * of itself and the operator can never correct the very mangling this batch
     * exists to stop.
     */
    public function test_a_category_can_be_recased_without_colliding_with_itself(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        $category = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'rk bridal sets']);

        $this->renameTo($shop->id, $category, 'RK Bridal Sets')->assertSessionHasNoErrors();

        $this->assertSame('RK Bridal Sets', $category->fresh()->name);
        $this->assertSame(1, Category::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    /** The exclusion is for the row being edited, and for no other row. */
    public function test_a_rename_onto_another_categorys_name_is_still_refused(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Rings']);
        $chains = Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Chains']);

        $this->renameTo($shop->id, $chains, 'RINGS')->assertSessionHasErrors('name');

        $this->assertSame('Chains', $chains->fresh()->name);
    }

    // ── agreement with the database's own index ─────────────────────────────

    /**
     * The rule's comparison must be the index's expression, not an approximation
     * of it. `normalized_name` is lower(trim(collapse-whitespace)), so a stored
     * "Gold  Rings" (two spaces) IS "gold rings" to the index. A TRIM-only
     * comparison missed that row, let the POST through, and turned a field error
     * back into the 500 this whole rule exists to prevent. Fresh input cannot
     * reach that shape — the middleware collapses it — so the row is written
     * directly, which is exactly how the pre-middleware rows in production got
     * there.
     */
    public function test_a_legacy_row_with_doubled_spacing_still_blocks_a_new_duplicate(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);

        Category::forceCreate(['shop_id' => $shop->id, 'name' => 'Gold  Rings']);

        $this->storeAs($shop->id, 'Gold Rings')->assertSessionHasErrors('name');

        $this->assertSame(1, Category::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    /**
     * UniqueNameIgnoringCase queries with the raw query builder, which applies no
     * global scopes — including SoftDeletingScope. On a table WITH soft deletes
     * that would count rows the operator cannot see, refusing a name that looks
     * free (and, if the unique index excluded deleted rows, refusing one that
     * genuinely is). None of the five tables it is used on soft-delete today, so
     * the raw query and the index see the same rows. This fails the day that
     * stops being true, which is the day the rule needs a deleted_at clause.
     */
    public function test_no_table_this_rule_guards_uses_soft_deletes(): void
    {
        foreach (['categories', 'sub_categories', 'shop_payment_methods', 'reporting_presets'] as $table) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at'),
                "{$table} now soft-deletes; UniqueNameIgnoringCase must decide whether a trashed row still reserves its name."
            );
        }
    }
}
