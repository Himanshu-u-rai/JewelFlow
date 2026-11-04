<?php

namespace Tests\Feature\Tables;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\SubCategory;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class ReorderDialogTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_index_has_one_editor_with_real_fallback_links_and_shop_scoped_choices(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        TenantContext::runFor($otherShop->id, function () {
            Category::create(['name' => 'Private foreign category']);
            Vendor::create(['name' => 'Private foreign vendor']);
            ReorderRule::create(['category' => 'Private foreign rule', 'min_stock_threshold' => 3]);
        });
        $rule = TenantContext::runFor($shop->id, function () {
            $category = Category::create(['name' => 'Synthetic Rings']);
            SubCategory::create(['category_id' => $category->id, 'name' => 'Synthetic Bands']);
            Vendor::create(['name' => 'Synthetic supplier']);

            return ReorderRule::create(['category' => $category->name, 'sub_category' => 'Synthetic Bands', 'min_stock_threshold' => 5]);
        });
        $this->withoutVite();
        $response = $this->actingAs($owner)->get(route('reorder.index'))->assertOk();
        $response->assertSee('id="reorder-rule-dialog"', false)
            ->assertSee('data-reorder-open', false)->assertSee('data-reorder-edit', false)
            ->assertSee('Synthetic supplier')->assertSee('Synthetic Bands')
            ->assertDontSee('Private foreign');
        $this->assertSame(1, substr_count($response->getContent(), 'data-reorder-rule-form'));
        $response->assertSee('href="'.route('reorder.edit', $rule).'"', false);
        $this->get(route('reorder.create'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->get(route('reorder.edit', $rule)))->assertOk();
    }

    public function test_invalid_create_returns_to_index_with_values_and_reopened_dialog(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $this->actingAs($owner)->from(route('reorder.index'))->post(route('reorder.store'), [
            'min_stock_threshold' => '0', '_reorder_rule_id' => '', '_reorder_editor' => '1',
        ])->assertRedirect(route('reorder.index'))->assertSessionHasErrors('min_stock_threshold');
        $this->get(route('reorder.index'))->assertOk()
            ->assertSee('data-reopen="true"', false)
            ->assertSee('value="0"', false)
            ->assertSee('role="alert"', false);
        $this->assertDatabaseCount('reorder_rules', 0);
    }

    public function test_invalid_edit_keeps_the_target_and_form_input_then_update_refreshes_the_list(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $rule = TenantContext::runFor($shop->id, fn () => ReorderRule::create(['min_stock_threshold' => 5]));
        $this->withoutVite();
        $this->actingAs($owner)->get(route('reorder.index'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->from(route('reorder.index'))->put(route('reorder.update', $rule), [
            'min_stock_threshold' => '0', 'is_active' => '0', '_reorder_rule_id' => $rule->id, '_reorder_editor' => '1',
        ]))->assertRedirect(route('reorder.index'))->assertSessionHasErrors('min_stock_threshold');
        $this->get(route('reorder.index'))->assertOk()
            ->assertSee('action="'.route('reorder.update', $rule).'"', false)
            ->assertSee('data-reopen="true"', false)->assertSee('value="0"', false);
        $this->assertSame(5, TenantContext::runFor($shop->id, fn () => $rule->fresh()->min_stock_threshold));
        TenantContext::runFor($shop->id, fn () => $this->put(route('reorder.update', $rule), ['min_stock_threshold' => '7', 'is_active' => '0']))
            ->assertRedirect(route('reorder.index'))->assertSessionHas('success', 'Reorder rule updated.');
        $this->assertSame(7, TenantContext::runFor($shop->id, fn () => $rule->fresh()->min_stock_threshold));
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $rule->fresh()->is_active));
        $this->assertDatabaseCount('reorder_rules', 1);
    }

    public function test_create_uses_existing_validation_and_success_redirect_without_making_a_purchase(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->actingAs($owner)->from(route('reorder.index'))->post(route('reorder.store'), ['min_stock_threshold' => '4'])
            ->assertRedirect(route('reorder.index'))->assertSessionHas('success', 'Reorder rule created.');
        $this->assertDatabaseHas('reorder_rules', ['shop_id' => $shop->id, 'min_stock_threshold' => 4]);
        $this->assertTrue(TenantContext::runFor($shop->id, fn () => ReorderRule::firstOrFail()->is_active));
        $this->assertDatabaseCount('stock_purchases', 0);
    }

    public function test_view_only_users_have_no_dialog_or_mutation_and_cross_shop_edits_stay_blocked(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = TenantContext::runFor($otherShop->id, fn () => ReorderRule::create(['min_stock_threshold' => 5]));
        $this->withoutVite();
        $this->actingAs($owner)->get(route('reorder.index'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('reorder.edit', $foreign)))->assertNotFound();
        TenantContext::runFor($shop->id, fn () => $this->put(route('reorder.update', $foreign), ['min_stock_threshold' => 99]))->assertNotFound();
        $this->assertSame(5, TenantContext::runFor($otherShop->id, fn () => $foreign->fresh()->min_stock_threshold));
        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $owner->unsetRelation('role');
        $this->get(route('reorder.index'))->assertOk()->assertDontSee('id="reorder-rule-dialog"', false);
        $this->post(route('reorder.store'), ['min_stock_threshold' => 2])->assertForbidden();
    }
}
