<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner 2026-10-31: a quick bill no longer prints its pricing mode ("Mode :
 * Gst Exclusive") on the bill page or the printout; the GST rows already say
 * it. The VOID / ORIGINAL / EDITED badge that sat on that line stays.
 */
class QuickBillPricingModeHiddenTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_bill_page_and_printouts_leave_out_the_pricing_mode_but_keep_the_badge(): void
    {
        [$owner] = $this->createRetailerTenant();
        $payload = fn (string $name) => [
            'bill_date' => now()->toDateString(), 'pricing_mode' => 'gst_exclusive', 'gst_rate' => 3,
            'save_action' => 'issue', 'customer_name' => $name,
            'items' => [['description' => 'Gold chain', 'metal_type' => 'gold', 'net_weight' => 10, 'rate' => 7200, 'line_total' => 1000]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1030]],
        ];
        $as = fn (callable $call) => TenantContext::runFor((int) $owner->shop_id, fn () => $call($this->actingAs($owner)));

        $as(fn ($t) => $t->post(route('quick-bills.store'), $payload('Walk-in'))->assertSessionHasNoErrors());
        $id = (int) DB::table('quick_bills')->where('shop_id', $owner->shop_id)->value('id');
        $as(fn ($t) => $t->put(route('quick-bills.update', $id), $payload('Walk-in (corrected)'))->assertSessionHasNoErrors());

        $show = $as(fn ($t) => $t->get(route('quick-bills.show', $id))->assertOk()->getContent());
        $live = $as(fn ($t) => $t->get(route('quick-bills.print', $id))->assertOk()->getContent());
        $original = $as(fn ($t) => $t->get(route('quick-bills.print-original', $id))->assertOk()->getContent());

        foreach (['bill page' => $show, 'print' => $live, 'original print' => $original] as $where => $html) {
            $this->assertStringNotContainsString('Gst Exclusive', $html, "{$where} still shows the pricing mode");
            $this->assertStringNotContainsString('Pricing mode', $html, "{$where} still labels the pricing mode");
            $this->assertStringNotContainsString('<div class="k">Mode</div>', $html, "{$where} still has the Mode line");
        }
        $this->assertStringContainsString('badge-edited', $live, 'the edited badge moved, not vanished');
        $this->assertStringContainsString('badge-original', $original, 'the original badge moved, not vanished');
    }
}
