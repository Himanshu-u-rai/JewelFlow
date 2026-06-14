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

    private function policy(bool $making, bool $stone, bool $gst, float $wear, float $restock): ShopPreferences
    {
        $p = new ShopPreferences();
        $p->refund_making_charges = $making;
        $p->refund_stone_charges  = $stone;
        $p->refund_hallmark_charges = true;
        $p->refund_gst = $gst;
        $p->wear_loss_pct = $wear;
        $p->restocking_fee_pct = $restock;
        return $p;
    }

    private function refund(ShopPreferences $p): array
    {
        $basis = $this->resolver->basisFromPolicy($p);
        $b = $this->resolver->resolve($this->line(), new Invoice(['gst_rate' => 3]), $basis, $p);
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

    /**
     * Documents the known limitation: toggling refund_hallmark_charges OFF does
     * NOT change the refund, because hallmark is not itemised on invoice_items.
     * Two refunds with the flag on vs off must be identical right now.
     */
    public function test_hallmark_flag_is_currently_inert_on_invoice_returns(): void
    {
        $on  = $this->policy(true, true, true, 0, 0);
        $off = $this->policy(true, true, true, 0, 0);
        $off->refund_hallmark_charges = false;

        $this->assertEqualsWithDelta(
            $this->refund($on)['total'],
            $this->refund($off)['total'],
            0.005,
            'hallmark flag has no effect yet — if this fails, hallmark deduction was wired; update the settings hint + this test',
        );
    }
}
