<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\Repair;
use App\Models\ShopPreferences;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A customer's history reads the same on the web and the app (web leads):
 * total spent (finished bills only), opening balance, loyalty points' worth at
 * the shop's own point value, and the full lists of invoices / quick bills /
 * repairs — the app's history page, now on the web too, behind the same
 * permissions as each list.
 */
class CustomerHistoryParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** A retail shop with a customer who bought one ₹10,000 item (₹10,300 with GST). */
    private function shopWithASale(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner);
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Arti', 'last_name' => 'Sharma']);
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase']);
        $sale = $this->actingAs($owner, 'web')->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$item->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 10300]],
        ])->assertOk();

        return [$owner, $shop, $customer, (int) $sale->json('invoice_id')];
    }

    private function web($user, string $url)
    {
        return TenantContext::runFor((int) $user->shop_id, fn () => $this->actingAs($user, 'web')->get(self::ERP . $url));
    }

    public function test_total_spent_counts_finished_bills_only_on_both(): void
    {
        [$owner, $shop, $customer] = $this->shopWithASale();
        // A draft bill is not a sale.
        TenantContext::runFor($shop->id, function () use ($shop, $customer) {
            $draft = new Invoice();
            $draft->forceFill(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_number' => 'DRAFT-1',
                'status' => Invoice::STATUS_DRAFT, 'total' => 5000, 'subtotal' => 5000, 'gst' => 0, 'gold_rate' => 0])->save();
        });

        $this->web($owner, "/customers/{$customer->id}")->assertOk()->assertSee('₹10,300.00', false)->assertDontSee('₹15,300.00', false);

        Sanctum::actingAs($owner);
        TenantContext::set($shop->id);
        $this->assertEqualsWithDelta(10300, $this->getJson("/api/mobile/customers/{$customer->id}/context")->json('summary.lifetime_spend'), 0.01);
    }

    public function test_loyalty_worth_uses_the_shops_point_value_on_both(): void
    {
        [$owner, $shop, $customer] = $this->shopWithASale();
        TenantContext::runFor($shop->id, function () use ($shop, $customer) {
            ShopPreferences::firstOrNew(['shop_id' => $shop->id])->forceFill(['shop_id' => $shop->id, 'loyalty_point_value' => 1.5])->save();
            $customer->forceFill(['loyalty_points' => 200])->save();
        });

        $this->web($owner, "/customers/{$customer->id}")->assertOk()->assertSee('Worth ₹300.00', false); // was ₹50.00 (0.25 fixed)

        Sanctum::actingAs($owner);
        TenantContext::set($shop->id);
        $this->assertEqualsWithDelta(300, $this->getJson("/api/mobile/customers/{$customer->id}/context")->json('summary.loyalty_points_value'), 0.01);
    }

    public function test_the_app_gets_the_opening_balance_the_web_shows(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE, 'customer_id' => $customer->id, 'amount' => 2500,
        ])->assertSessionHasNoErrors();
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));

        $this->web($user, "/customers/{$customer->id}")->assertOk()->assertSee('₹2,500.00 Dr', false);
        Sanctum::actingAs($user);
        TenantContext::set($shop->id);
        $this->assertEqualsWithDelta(2500, $this->getJson("/api/mobile/customers/{$customer->id}/context")->json('summary.opening_balance'), 0.01);
    }

    public function test_the_web_has_the_apps_full_history_for_one_customer(): void
    {
        [$owner, $shop, $customer, $invoiceId] = $this->shopWithASale();
        $other = $this->createCustomer($shop->id, ['first_name' => 'Other', 'last_name' => 'Person']);
        $item = $this->createItem($shop->id, null, ['selling_price' => 5000, 'source' => 'purchase']);
        $this->actingAs($owner, 'web')->postJson('/pos/sell', [
            'customer_id' => $other->id, 'item_ids' => [$item->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 5150]],
        ])->assertOk();
        TenantContext::runFor($shop->id, fn () => Repair::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'item_description' => 'Gold chain',
            'description' => 'Clasp', 'gross_weight' => 8.5, 'estimated_cost' => 450, 'status' => 'received',
        ]));
        $mine = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($invoiceId)->invoice_number);
        $theirs = TenantContext::runFor($shop->id, fn () => Invoice::where('customer_id', $other->id)->value('invoice_number'));

        $this->web($owner, "/customers/{$customer->id}")->assertSee(route('customers.history', $customer), false);
        $this->web($owner, "/customers/{$customer->id}/history")->assertOk()
            ->assertSee($mine)->assertDontSee($theirs)->assertSee('₹10,300.00', false);
        $this->web($owner, "/customers/{$customer->id}/history?tab=repairs")->assertOk()->assertSee('Gold chain');
        $this->web($owner, "/customers/{$customer->id}/history?tab=quick-bills")->assertOk();
    }

    public function test_each_history_tab_needs_its_lists_permission(): void
    {
        [$owner, $shop, $customer] = $this->shopWithASale();
        $clerk = TenantContext::runFor($shop->id, function () use ($shop) {
            $role = (new \App\Models\Role())->forceFill(['name' => 'clerk', 'display_name' => 'Clerk', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(\App\Models\Permission::whereIn('name', ['customers.view', 'sales.view'])->pluck('id'));

            return \App\Models\User::factory()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'is_active' => true]);
        });

        $this->web($clerk, "/customers/{$customer->id}/history")->assertOk()->assertDontSee('?tab=repairs', false);
        $this->web($clerk, "/customers/{$customer->id}/history?tab=repairs")->assertForbidden(); // no repairs.view

        TenantContext::runFor($shop->id, fn () => ShopPreferences::firstOrNew(['shop_id' => $shop->id])
            ->forceFill(['shop_id' => $shop->id, 'quick_bill_enabled' => false])->save());
        $this->web($owner, "/customers/{$customer->id}/history?tab=quick-bills")->assertForbidden(); // quick bills switched off
    }
}
