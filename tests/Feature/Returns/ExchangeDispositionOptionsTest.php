<?php

namespace Tests\Feature\Returns;

use App\Models\ShopPreferences;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: the unified exchange form (and its storeUnified validation)
 * still offered/accepted "sent_to_rework" after that disposition was retired
 * in M11 and removed from the returns flow. Both flows must offer the same
 * live dispositions so an exchange can't record a disposition the rest of
 * the system no longer supports.
 */
class ExchangeDispositionOptionsTest extends TestCase
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

    private function makeFinalizedInvoice(): array
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

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

        return [$user, $shop, (int) $sell->json('invoice_id')];
    }

    public function test_exchange_form_does_not_offer_retired_rework_disposition(): void
    {
        [$user, $shop, $invoiceId] = $this->makeFinalizedInvoice();

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/invoices/' . $invoiceId . '/exchange'));
        $res->assertOk();
        $res->assertDontSee('sent_to_rework');
        $res->assertDontSee('Send for rework');
        $res->assertSee('restocked');
        $res->assertSee('sent_to_melt');
        $res->assertSee('written_off');
    }

    public function test_exchange_store_rejects_retired_rework_disposition(): void
    {
        [$user, $shop, $invoiceId] = $this->makeFinalizedInvoice();

        $invoiceItemId = TenantContext::runFor($shop->id, fn () => (int) \DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)->value('id'));

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->post(self::ERP . '/invoices/' . $invoiceId . '/exchange', [
                'reason' => 'Customer wants a different design',
                'lines' => [[
                    'invoice_item_id' => $invoiceItemId,
                    'condition' => \App\Models\ReturnLineItem::CONDITION_GOOD,
                    'disposition' => \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK,
                ]],
                'new_item_barcodes' => 'DOES-NOT-MATTER',
                'valuation_basis_source' => \App\Models\ExchangeOrder::BASIS_SALE_DAY_RATE,
            ]));
        $res->assertSessionHasErrors('lines.0.disposition');
    }
}
