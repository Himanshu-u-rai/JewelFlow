<?php

namespace Tests\Feature\Tables;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * One rule per stock group: two rules for the same category / sub-category gave two contradictory
 * minimums and two alerts for one shortage.
 */
class ReorderRuleDuplicateTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function shopWithRings(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () {
            $rings = Category::create(['name' => 'Rings']);
            SubCategory::create(['category_id' => $rings->id, 'name' => 'Bands']);
            Category::create(['name' => 'Chains']);
        });

        return [$owner, $shop];
    }

    public function test_a_second_rule_for_the_same_group_is_refused_and_the_dialog_reopens(): void
    {
        [$owner, $shop] = $this->shopWithRings();
        TenantContext::runFor($shop->id, fn () => ReorderRule::create(['category' => 'Rings', 'min_stock_threshold' => 5]));
        $this->withoutVite();

        $this->actingAs($owner)->from(route('reorder.index'))->post(route('reorder.store'), [
            'category' => 'Rings', 'min_stock_threshold' => '4', '_reorder_editor' => '1', '_reorder_rule_id' => '',
        ])->assertRedirect(route('reorder.index'))->assertSessionHasErrors('category');

        $this->assertSame(1, TenantContext::runFor($shop->id, fn () => ReorderRule::count()));
        $this->get(route('reorder.index'))->assertOk()->assertSee('data-reopen="true"', false);

        // the same check covers "all categories" (no category) rules
        TenantContext::runFor($shop->id, fn () => ReorderRule::create(['category' => null, 'min_stock_threshold' => 20]));
        $this->post(route('reorder.store'), ['category' => '', 'min_stock_threshold' => '10'])->assertSessionHasErrors('category');
    }

    public function test_a_different_sub_category_of_the_same_category_is_its_own_group(): void
    {
        [$owner, $shop] = $this->shopWithRings();
        TenantContext::runFor($shop->id, fn () => ReorderRule::create(['category' => 'Rings', 'min_stock_threshold' => 5]));

        $this->actingAs($owner)->post(route('reorder.store'), ['category' => 'Rings', 'sub_category' => 'Bands', 'min_stock_threshold' => '2'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, TenantContext::runFor($shop->id, fn () => ReorderRule::count()));
    }

    public function test_moving_a_rule_onto_another_rules_group_is_refused(): void
    {
        [$owner, $shop] = $this->shopWithRings();
        [$rings, $chains] = TenantContext::runFor($shop->id, fn () => [
            ReorderRule::create(['category' => 'Rings', 'min_stock_threshold' => 5]),
            ReorderRule::create(['category' => 'Chains', 'min_stock_threshold' => 3]),
        ]);

        $this->actingAs($owner);
        TenantContext::runFor($shop->id, fn () => $this->put(route('reorder.update', $chains), ['category' => 'Rings', 'min_stock_threshold' => '3', 'is_active' => '1']))
            ->assertSessionHasErrors('category');

        $this->assertSame('Chains', TenantContext::runFor($shop->id, fn () => $chains->fresh()->category));
    }

    public function test_an_existing_duplicate_can_still_be_edited_without_changing_its_group(): void
    {
        [$owner, $shop] = $this->shopWithRings();
        [, $second] = TenantContext::runFor($shop->id, fn () => [
            ReorderRule::create(['category' => 'Rings', 'min_stock_threshold' => 5]),
            ReorderRule::create(['category' => 'Rings', 'min_stock_threshold' => 4]),
        ]);

        $this->actingAs($owner);
        TenantContext::runFor($shop->id, fn () => $this->put(route('reorder.update', $second), ['category' => 'Rings', 'min_stock_threshold' => '6', 'is_active' => '1']))
            ->assertRedirect(route('reorder.index'))->assertSessionHasNoErrors();

        $this->assertSame(6, (int) TenantContext::runFor($shop->id, fn () => $second->fresh()->min_stock_threshold));
    }
}
