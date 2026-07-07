<?php

namespace Tests\Feature\Returns;

use App\Models\ShopPreferences;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: the return-create form offered "Send for rework (parked)"
 * (sent_to_rework) even though store() retired that disposition in M11 and
 * rejects it — so a cashier picking the offered option always hit
 * "The selected lines.0.disposition is invalid." The form must only offer
 * dispositions store() accepts.
 */
class ReturnCreateDispositionOptionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

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

    public function test_return_form_does_not_offer_retired_rework_disposition(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        // Configured return policy so the create-form gate passes.
        TenantContext::runFor($shop->id, function () use ($shop) {
            ShopPreferences::firstOrNew(['shop_id' => $shop->id])->forceFill([
                'shop_id' => $shop->id,
                'refund_making_charges' => true,
                'refund_stone_charges' => true,
                'refund_gst' => true,
                'wear_loss_pct' => 0,
                'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
                'return_policy_configured_at' => now(),
            ])->save();
        });

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();
        $invoiceId = (int) $sell->json('invoice_id');

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/invoices/' . $invoiceId . '/returns/create'));
        $res->assertOk();
        // Every offered disposition must be one store() accepts.
        $res->assertDontSee('sent_to_rework');
        $res->assertDontSee('Send for rework');
        $res->assertSee('restocked');
        $res->assertSee('sent_to_melt');
        $res->assertSee('written_off');
    }
}
