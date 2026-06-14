<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopPreferences;
use App\Services\Returns\RefundPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Return Policy → refund MATH. The settings tab persistence is covered by
 * ReturnPolicySettingsTest; this proves each policy flag actually changes the
 * refund the customer receives (RefundPolicyResolver), with exact rupee values.
 * Financial-correctness guard for the most money-sensitive setting in the app.
 *
 * Also documents a known limitation: refund_hallmark_charges is currently inert
 * for regular-invoice returns (hallmark is not itemised on invoice_items — it
 * is folded into line_total). If that wiring lands, the documenting test below
 * will start failing and must be updated.
 */
class RefundPolicyMathTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private RefundPolicyResolver $resolver;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->resolver = app(RefundPolicyResolver::class);
    }

    /** A sample sold line: metal 20000 + making 2000 + stone 1500 + GST 1200. */
    private function line(): InvoiceItem
    {
        $i = new InvoiceItem();
        $i->forceFill([
            'making_charges' => 2000, 'stone_amount' => 1500,
            'gst_amount' => 1200, 'line_total' => 24700,
            'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
            'weight' => 10, 'rate' => 2000, 'metal_type' => 'gold', 'purity' => 22,
        ]);
        return $i;
    }

    /** A line that also carries a hallmark charge: line_total 25500 includes hallmark 800. */
    private function lineWithHallmark(): InvoiceItem
    {
        $i = new InvoiceItem();
        $i->forceFill([
            'making_charges' => 2000, 'stone_amount' => 1500, 'hallmark_charges' => 800,
            'gst_amount' => 1200, 'line_total' => 25500,
            'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
            'weight' => 10, 'rate' => 2000, 'metal_type' => 'gold', 'purity' => 22,
        ]);
        return $i;
    }

    private function policy(bool $making, bool $stone, bool $gst, float $wear, float $restock, bool $hallmark = true): ShopPreferences
    {
        $p = new ShopPreferences();
        $p->refund_making_charges = $making;
        $p->refund_stone_charges  = $stone;
        $p->refund_hallmark_charges = $hallmark;
        $p->refund_gst = $gst;
        $p->wear_loss_pct = $wear;
        $p->restocking_fee_pct = $restock;
        return $p;
    }

    private function refund(ShopPreferences $p, ?InvoiceItem $line = null): array
    {
        $basis = $this->resolver->basisFromPolicy($p);
        $b = $this->resolver->resolve($line ?? $this->line(), new Invoice(['gst_rate' => 3]), $basis, $p);
        return ['total' => $b->refundTotal, 'breakdown' => $b->breakdown];
    }

    public function test_refund_all_returns_line_plus_gst(): void
    {
        $r = $this->refund($this->policy(true, true, true, 0, 0));
        $this->assertEqualsWithDelta(25900.0, $r['total'], 0.005, 'line 24700 + GST 1200');
    }

    public function test_retaining_making_charges_reduces_refund(): void
    {
        $r = $this->refund($this->policy(false, true, true, 0, 0));
        $this->assertEqualsWithDelta(23900.0, $r['total'], 0.005, 'minus 2000 making');
        $this->assertEqualsWithDelta(2000.0, $r['breakdown']['making_retained'], 0.005);
    }

    public function test_retaining_stone_charges_reduces_refund(): void
    {
        $r = $this->refund($this->policy(true, false, true, 0, 0));
        $this->assertEqualsWithDelta(24400.0, $r['total'], 0.005, 'minus 1500 stone');
        $this->assertEqualsWithDelta(1500.0, $r['breakdown']['stone_retained'], 0.005);
    }

    public function test_not_refunding_gst_drops_the_gst(): void
    {
        $r = $this->refund($this->policy(true, true, false, 0, 0));
        $this->assertEqualsWithDelta(24700.0, $r['total'], 0.005, 'GST 1200 not refunded');
        $this->assertEqualsWithDelta(0.0, $r['breakdown']['gst_refunded'], 0.005);
    }

    public function test_restocking_fee_is_applied_on_the_refundable_subtotal(): void
    {
        $r = $this->refund($this->policy(true, true, true, 0, 10));
        // 10% of 24700 = 2470 fee → 24700 - 2470 + 1200 GST = 23430
        $this->assertEqualsWithDelta(23430.0, $r['total'], 0.005);
        $this->assertEqualsWithDelta(2470.0, $r['breakdown']['restocking_fee_amount'], 0.005);
    }

    public function test_combined_deductions_compose_correctly(): void
    {
        // retain making(2000)+stone(1500), no GST, 5% restock
        $r = $this->refund($this->policy(false, false, false, 0, 5));
        // subtotal 24700-2000-1500 = 21200; restock 5% = 1060; GST 0 → 20140
        $this->assertEqualsWithDelta(20140.0, $r['total'], 0.005);
    }

    // ── hallmark itemisation (now wired) ──────────────────────────────────────

    public function test_retaining_hallmark_charges_reduces_refund(): void
    {
        $line = $this->lineWithHallmark(); // line_total 25500 incl hallmark 800

        // refund everything → full line + GST
        $on = $this->refund($this->policy(true, true, true, 0, 0, hallmark: true), $line);
        $this->assertEqualsWithDelta(26700.0, $on['total'], 0.005, '25500 + 1200 GST');

        // retain hallmark → refund drops by exactly 800
        $off = $this->refund($this->policy(true, true, true, 0, 0, hallmark: false), $line);
        $this->assertEqualsWithDelta(25900.0, $off['total'], 0.005, 'minus 800 hallmark');
        $this->assertEqualsWithDelta(800.0, $off['breakdown']['hallmark_retained'], 0.005);
    }

    public function test_retaining_hallmark_does_not_change_gst(): void
    {
        $line = $this->lineWithHallmark();
        $on  = $this->refund($this->policy(true, true, true, 0, 0, hallmark: true), $line);
        $off = $this->refund($this->policy(true, true, true, 0, 0, hallmark: false), $line);
        // GST follows the refund_gst flag, NOT the principal split (CA-safe).
        $this->assertEqualsWithDelta($on['breakdown']['gst_refunded'], $off['breakdown']['gst_refunded'], 0.005);
        $this->assertEqualsWithDelta(1200.0, $off['breakdown']['gst_refunded'], 0.005);
    }

    public function test_legacy_line_without_hallmark_is_unaffected(): void
    {
        // An old invoice line (hallmark_charges = 0 / null) behaves exactly as before.
        $on  = $this->refund($this->policy(true, true, true, 0, 0, hallmark: true));
        $off = $this->refund($this->policy(true, true, true, 0, 0, hallmark: false));
        $this->assertEqualsWithDelta($on['total'], $off['total'], 0.005, 'no hallmark on the line → nothing to retain');
    }

    public function test_combined_retain_hallmark_making_no_gst(): void
    {
        $line = $this->lineWithHallmark();
        // retain making(2000)+hallmark(800), no GST: 25500-2000-800 = 22700
        $r = $this->refund($this->policy(false, true, false, 0, 0, hallmark: false), $line);
        $this->assertEqualsWithDelta(22700.0, $r['total'], 0.005);
    }

    /**
     * The item-exists path (GoldValuationService, the common production case)
     * must deduct hallmark identically to the item-null inline fallback — else
     * the same return gives different refunds depending on whether the item row
     * still exists. (Guards the two paths against future divergence.)
     */
    public function test_item_exists_path_deducts_hallmark_identically_to_inline(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        // A real Item so $line->item resolves → resolver takes the
        // GoldValuationService (item-exists) branch instead of the inline fallback.
        $item = $this->createItem($shop->id, null, ['metal_type' => 'gold', 'purity' => 24.00]);

        $line = $this->lineWithHallmark();         // line_total 25500 incl hallmark 800
        $line->forceFill(['item_id' => $item->id]);
        $line->setRelation('item', $item);         // ensure $line->item is the real item

        $retain = $this->policy(true, true, true, 0, 0, hallmark: false);
        $refund = \App\Support\TenantContext::runFor($shop->id, function () use ($line, $retain) {
            $basis = $this->resolver->basisFromPolicy($retain);
            return $this->resolver->resolve($line, new Invoice(['gst_rate' => 3]), $basis, $retain)->refundTotal;
        });

        // Same as the inline path: 25500 − 800 hallmark + 1200 GST = 25900.
        $this->assertEqualsWithDelta(25900.0, $refund, 0.005, 'item-exists path retains hallmark too');
    }
}
