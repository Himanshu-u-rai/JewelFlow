<?php

namespace Tests\Feature\MakingCharge;

use App\Models\InvoiceItem;
use App\Services\SalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MC-3 persistence: the RESOLVED ₹ making is stamped onto invoice_items as the
 * accounting truth, alongside the mode snapshot (type+value). Proves historical
 * reproducibility — the finalized amount is frozen at sale time and a later
 * rate change can never alter it (invoice_items is immutable).
 */
class MakingChargePersistenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:int,1:int,2:int} [shopId, itemId, customerId] */
    private function fixture(): array
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        \App\Models\Shop::where('id', $shop->id)->update(['gst_rate' => 3, 'wastage_recovery_percent' => 100]);
        $lot  = $this->createMetalLot($shop->id, 100.0);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id, [
            'net_metal_weight' => 9.500, 'purity' => 22.00, 'wastage' => 0.0, 'metal_type' => 'gold',
        ]);
        $this->actingAs($owner);
        return [(int) $shop->id, (int) $item->id, (int) $customer->id];
    }

    private function lineFor(int $shopId, int $invoiceId): InvoiceItem
    {
        return TenantContext::runFor($shopId, fn () =>
            InvoiceItem::where('invoice_id', $invoiceId)->firstOrFail()
        );
    }

    public function test_percentage_sale_persists_resolved_amount_and_mode_snapshot(): void
    {
        [$shopId, $itemId, $customerId] = $this->fixture();

        $invoice = TenantContext::runFor($shopId, fn () => SalesService::sellItem(
            customerId: $customerId, itemId: $itemId, goldRate: 7200.00,
            making: 0, stone: 200.00, makingType: 'percentage', makingValue: 12.0,
        ));

        $line = $this->lineFor($shopId, $invoice->id);
        // Resolved ₹ is the accounting truth (12% of ₹62,700 = ₹7,524).
        $this->assertEqualsWithDelta(7524.00, (float) $line->making_charges, 0.001);
        // Mode metadata snapshotted (additive, not accounting truth).
        $this->assertSame('percentage', $line->making_charge_type);
        $this->assertEqualsWithDelta(12.00, (float) $line->making_charge_value, 0.001);
    }

    public function test_per_gram_sale_persists_resolved_amount(): void
    {
        [$shopId, $itemId, $customerId] = $this->fixture();

        $invoice = TenantContext::runFor($shopId, fn () => SalesService::sellItem(
            customerId: $customerId, itemId: $itemId, goldRate: 7200.00,
            making: 0, stone: 200.00, makingType: 'per_gram', makingValue: 350.0,
        ));

        $line = $this->lineFor($shopId, $invoice->id);
        $this->assertEqualsWithDelta(3325.00, (float) $line->making_charges, 0.001); // 350 × 9.5
        $this->assertSame('per_gram', $line->making_charge_type);
    }

    public function test_fixed_sale_is_byte_identical_and_has_null_mode(): void
    {
        [$shopId, $itemId, $customerId] = $this->fixture();

        $invoice = TenantContext::runFor($shopId, fn () => SalesService::sellItem(
            customerId: $customerId, itemId: $itemId, goldRate: 7200.00, making: 4800.00, stone: 200.00,
        ));

        $line = $this->lineFor($shopId, $invoice->id);
        $this->assertEqualsWithDelta(4800.00, (float) $line->making_charges, 0.001);
        $this->assertNull($line->making_charge_type, 'fixed mode persists NULL type (legacy-identical)');
        $this->assertNull($line->making_charge_value);
    }

    public function test_finalized_making_is_immutable_against_future_rate_change(): void
    {
        [$shopId, $itemId, $customerId] = $this->fixture();

        $invoice = TenantContext::runFor($shopId, fn () => SalesService::sellItem(
            customerId: $customerId, itemId: $itemId, goldRate: 7200.00,
            making: 0, stone: 200.00, makingType: 'percentage', makingValue: 12.0,
        ));
        $line = $this->lineFor($shopId, $invoice->id);
        $persisted = (float) $line->making_charges;

        // "Tomorrow's" gold rate changes — finalized line must NOT recompute.
        \App\Models\Shop::where('id', $shopId)->update(['gst_rate' => 5]);

        $reloaded = $this->lineFor($shopId, $invoice->id);
        $this->assertEqualsWithDelta($persisted, (float) $reloaded->making_charges, 0.001,
            'finalized making must be frozen — no live recomputation');
        $this->assertEqualsWithDelta(7524.00, (float) $reloaded->making_charges, 0.001);
    }
}
