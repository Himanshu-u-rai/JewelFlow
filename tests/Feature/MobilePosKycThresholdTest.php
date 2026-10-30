<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ShopPreferences;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The ₹2 lakh KYC rule on a mobile POS sale, for a walk-in saved on the spot
 * (name + mobile only, as the app's "New walk-in customer" form does).
 */
class MobilePosKycThresholdTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** @return array{0: int, 1: array<int>} walk-in customer id, item ids */
    private function walkInSale(float $eachItemPrice): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);
        $this->seedRetailerPricing($shop, $user);
        // Shop defaults: KYC on, ₹2,00,000 threshold, PAN + mobile mandatory.
        TenantContext::runFor($shop->id, fn () => ShopPreferences::updateOrCreate(['shop_id' => $shop->id], []));

        $customerId = $this->postJson('/api/mobile/customers', ['first_name' => 'Ravi', 'mobile' => '9876543210'])
            ->assertCreated()
            ->json('id');

        $itemIds = [
            $this->createItem($shop->id, null, ['selling_price' => $eachItemPrice])->id,
            $this->createItem($shop->id, null, ['selling_price' => $eachItemPrice])->id,
        ];

        return [$customerId, $itemIds];
    }

    private function sell(int $customerId, array $itemIds)
    {
        $total = $this->postJson('/api/mobile/pos/preview', ['customer_id' => $customerId, 'item_ids' => $itemIds])
            ->assertOk()
            ->json('total');

        return $this->postJson('/api/mobile/pos/sell', [
            'customer_id' => $customerId,
            'item_ids' => $itemIds,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ]);
    }

    public function test_a_walk_in_buying_over_two_lakh_must_give_pan_before_the_sale_completes(): void
    {
        [$customerId, $itemIds] = $this->walkInSale(110000); // ₹2,20,000 + GST

        $blocked = $this->sell($customerId, $itemIds)->assertStatus(422);
        $this->assertTrue($blocked->json('compliance_required'));
        // Name + mobile only: PAN and address are still missing (mobile is already there).
        $this->assertSame(['pan', 'address'], $blocked->json('missing_fields'));
        $this->assertEquals(200000, $blocked->json('threshold'));
        $this->assertSame(0, Invoice::withoutTenant()->count());

        // PHPUnit has no authed-request tenant for the {customer} binding (real HTTP does).
        TenantContext::set((int) \App\Models\Customer::withoutTenant()->findOrFail($customerId)->shop_id);
        $verify = $this->postJson("/api/mobile/customers/{$customerId}/verify-compliance", [
            'pan' => 'ABCDE1234F', 'address' => '12 MG Road, Jaipur 302001', 'consent' => true,
        ]);
        $this->assertSame(200, $verify->status(), $verify->getContent());

        $sale = $this->sell($customerId, $itemIds);
        $this->assertSame(200, $sale->status(), $sale->getContent());
        $this->assertSame(1, Invoice::withoutTenant()->count());
    }

    public function test_a_walk_in_under_two_lakh_needs_no_kyc(): void
    {
        [$customerId, $itemIds] = $this->walkInSale(90000); // ₹1,80,000 + GST = ₹1,85,400

        $this->sell($customerId, $itemIds)->assertOk()->assertJsonStructure(['invoice_id']);
    }
}
