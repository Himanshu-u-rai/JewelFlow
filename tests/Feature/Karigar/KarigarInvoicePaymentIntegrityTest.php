<?php

namespace Tests\Feature\Karigar;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Models\Shop;
use App\Models\User;
use App\Services\KarigarInvoiceService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N15 (2026-11-13): paying, editing, deleting and applying advances to a karigar invoice worked on the copy
 * loaded with the page, without the invoice's row lock (only reversal locked it). Two payments at once each summed
 * only what they could see (amount_paid and status understated the money paid); an edit could rewrite the lines of
 * an invoice paid a moment earlier; one advance could be applied to two invoices; nothing stopped paying more than
 * was due; and a multi-split payment recorded the splits one by one, so a failing split left the earlier ones.
 */
class KarigarInvoicePaymentIntegrityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{User, Shop, Karigar, KarigarInvoice} an unpaid ₹5,000 invoice */
    private function invoice(float $total = 5000): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'Ramesh', 'is_active' => true])->save();
        $invoice = new KarigarInvoice();
        $invoice->forceFill([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'mode' => 'job_work',
            'karigar_invoice_number' => 'KI-' . uniqid(), 'karigar_invoice_date' => now()->toDateString(),
            'total_after_tax' => $total, 'amount_paid' => 0, 'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
        ])->save();
        $this->actingAs($user);
        TenantContext::set($shop->id);

        return [$user, $shop, $karigar, $invoice];
    }

    private function service(): KarigarInvoiceService
    {
        return app(KarigarInvoiceService::class);
    }

    private function split(float $amount): array
    {
        return ['amount' => $amount, 'mode' => 'cash', 'paid_on' => now()->toDateString()];
    }

    public function test_a_payment_above_what_is_due_is_refused(): void
    {
        [$user, , , $invoice] = $this->invoice();
        $this->service()->recordPayments($invoice, [$this->split(3000)], $user->id);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('more than the ₹2,000.00 due');
        $this->service()->recordPayments($invoice->fresh(), [$this->split(2500)], $user->id);
    }

    public function test_splits_are_recorded_together_or_not_at_all(): void
    {
        [$user, , , $invoice] = $this->invoice();
        try {
            $this->service()->recordPayments($invoice, [$this->split(3000), $this->split(3000)], $user->id);
            $this->fail('Splits above the due amount were accepted.');
        } catch (\LogicException) {
        }

        $this->assertSame(0, KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $invoice->id)->count());
        $this->assertSame(KarigarInvoice::PAYMENT_UNPAID, $invoice->fresh()->payment_status);
    }

    public function test_paid_state_always_matches_the_payments(): void
    {
        [$user, , , $invoice] = $this->invoice();
        $stale = $invoice->fresh();
        $this->service()->recordPayments($invoice, [$this->split(2000)], $user->id);
        // A second cashier's page still shows the invoice unpaid.
        $this->service()->recordPayments($stale, [$this->split(3000)], $user->id);

        $fresh = $invoice->fresh();
        $this->assertEqualsWithDelta(5000, (float) $fresh->amount_paid, 0.001);
        $this->assertSame(KarigarInvoice::PAYMENT_PAID, $fresh->payment_status);
    }

    public function test_an_invoice_paid_after_the_page_loaded_cannot_be_edited_or_deleted(): void
    {
        [$user, $shop, , $invoice] = $this->invoice();
        $stale = $invoice->fresh();
        $this->service()->recordPayments($invoice, [$this->split(1000)], $user->id);

        try {
            $this->service()->update($stale, [], [['description' => 'Rewritten', 'quantity' => 1, 'rate' => 1]], null);
            $this->fail('A paid invoice was edited.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('recorded payments', $e->getMessage());
        }

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->app->bind(KarigarInvoice::class, fn () => $stale);
        $this->delete(route('karigar-invoices.destroy', $invoice))->assertSessionHas('error');
        $this->assertNotNull(KarigarInvoice::withoutGlobalScopes()->find($invoice->id));
    }

    // ── Advances (owner 2026-11-13, option 1): applied by compensating entries, never by editing a payment ──
    // karigar_payments is append-only (CONSTITUTION trigger #11), so linking an advance by updating its row always
    // failed and rolled the whole invoice back. An advance is now used by two appended rows: minus on the advance
    // (ADVANCE-USED:id) and plus on the invoice (FROM-ADVANCE:id). No cash moves; the karigar's total is unchanged.

    private function advance(Shop $shop, Karigar $karigar, float $amount): KarigarPayment
    {
        return KarigarPayment::record([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'amount' => $amount, 'mode' => 'cash',
            'paid_on' => now()->subDays(5)->toDateString(),
        ]);
    }

    private function billWith(User $user, Shop $shop, Karigar $karigar, string $no, float $metal, array $advanceIds): KarigarInvoice
    {
        return $this->service()->create([
            'karigar_id' => $karigar->id, 'mode' => 'job_work', 'karigar_invoice_number' => $no,
            'karigar_invoice_date' => now()->toDateString(), 'advance_payment_ids' => $advanceIds,
        ], [['description' => 'Work', 'metal_amount' => $metal]], null, $shop->id, $user->id);
    }

    private function karigarTotalPaid(Karigar $karigar): float
    {
        return round((float) KarigarPayment::withoutGlobalScopes()->where('karigar_id', $karigar->id)->sum('amount'), 2);
    }

    public function test_an_advance_pays_an_invoice_and_is_used_once(): void
    {
        [$user, $shop, $karigar] = $this->invoice();
        $advance = $this->advance($shop, $karigar, 1500);

        $first = $this->billWith($user, $shop, $karigar, 'KI-A', 4000, [$advance->id]);
        $second = $this->billWith($user, $shop, $karigar, 'KI-B', 4000, [$advance->id]);

        $this->assertEqualsWithDelta(1500, (float) $first->fresh()->amount_paid, 0.001);
        $this->assertSame(KarigarInvoice::PAYMENT_PARTIAL, $first->fresh()->payment_status);
        $this->assertEqualsWithDelta(0, (float) $second->fresh()->amount_paid, 0.001, 'the advance is spent');
        $this->assertEqualsWithDelta(1500, $this->karigarTotalPaid($karigar), 0.001, 'no money moved: still the one advance');
        $this->assertSame([], $this->service()->availableAdvances($shop->id, $karigar->id)->all());
        $this->assertSame(1500.0, (float) $advance->fresh()->amount, 'the advance row itself is never edited');
    }

    public function test_an_advance_larger_than_the_bill_keeps_its_remainder(): void
    {
        [$user, $shop, $karigar] = $this->invoice();
        $advance = $this->advance($shop, $karigar, 1500);

        $bill = $this->billWith($user, $shop, $karigar, 'KI-A', 1000, [$advance->id]);

        $this->assertSame(KarigarInvoice::PAYMENT_PAID, $bill->fresh()->payment_status);
        $this->assertEqualsWithDelta(1000, (float) $bill->fresh()->amount_paid, 0.001);
        $left = $this->service()->availableAdvances($shop->id, $karigar->id);
        $this->assertSame([$advance->id], $left->pluck('id')->all());
        $this->assertEqualsWithDelta(500, $left->first()['available'], 0.001);
    }

    public function test_reversing_a_payment_made_from_an_advance_makes_the_advance_usable_again(): void
    {
        [$user, $shop, $karigar] = $this->invoice();
        $advance = $this->advance($shop, $karigar, 1500);
        $bill = $this->billWith($user, $shop, $karigar, 'KI-A', 4000, [$advance->id]);
        $fromAdvance = KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $bill->id)->sole();

        $this->service()->reversePayment($bill, $fromAdvance, 'Wrong invoice', $user->id);

        $this->assertEqualsWithDelta(0, (float) $bill->fresh()->amount_paid, 0.001);
        $this->assertEqualsWithDelta(1500, $this->service()->availableAdvances($shop->id, $karigar->id)->first()['available'], 0.001);
        $this->assertEqualsWithDelta(1500, $this->karigarTotalPaid($karigar), 0.001, 'still only the one advance paid out');
    }

    /**
     * Security review of d734d188: the bookkeeping was keyed off `reference`, which users type. A real payment
     * entered with reference "FROM-ADVANCE:<id>" and then reversed minted advance credit from nothing. Bookkeeping
     * now lives in system-only columns, and user references cannot use the reserved prefixes.
     */
    public function test_a_typed_reference_cannot_mint_advance_credit(): void
    {
        [$user, $shop, $karigar, $invoice] = $this->invoice();
        $advance = $this->advance($shop, $karigar, 1000);
        $this->billWith($user, $shop, $karigar, 'KI-A', 5000, [$advance->id]); // the advance is now spent

        try {
            $this->service()->recordPayments($invoice, [$this->split(500) + ['reference' => 'FROM-ADVANCE:' . $advance->id]], $user->id);
            $this->fail('A reserved bookkeeping prefix was accepted as a user reference.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        // Even a row that carries the text (an old or imported one) is not bookkeeping: reversing it gives nothing back.
        $typed = KarigarPayment::record([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'karigar_invoice_id' => $invoice->id, 'amount' => 500,
            'mode' => 'cash', 'paid_on' => now()->toDateString(), 'reference' => 'FROM-ADVANCE:' . $advance->id,
        ]);
        $this->service()->reversePayment($invoice->fresh(), $typed, 'test', $user->id);

        $this->assertSame([], $this->service()->availableAdvances($shop->id, $karigar->id)->all(), 'no advance credit appeared');
        $this->assertEqualsWithDelta(1000, $this->karigarTotalPaid($karigar), 0.001, 'the advance, and the 500 paid then reversed');
    }

    public function test_a_typed_reversal_marker_cannot_block_a_reversal(): void
    {
        [$user, $shop, $karigar, $invoice] = $this->invoice();
        [$paid] = $this->service()->recordPayments($invoice, [$this->split(1000)], $user->id);
        KarigarPayment::record([
            'shop_id' => $shop->id, 'karigar_id' => $karigar->id, 'karigar_invoice_id' => $invoice->id, 'amount' => 1,
            'mode' => 'cash', 'paid_on' => now()->toDateString(), 'reference' => 'REVERSAL:' . $paid->id,
        ]);

        $this->service()->reversePayment($invoice->fresh(), $paid, 'genuine', $user->id);
        $this->assertEqualsWithDelta(1, (float) $invoice->fresh()->amount_paid, 0.001);
    }

    public function test_the_form_lists_only_advances_with_money_left(): void
    {
        [$user, $shop, $karigar] = $this->invoice();
        $used = $this->advance($shop, $karigar, 800);
        $open = $this->advance($shop, $karigar, 300);
        $this->billWith($user, $shop, $karigar, 'KI-A', 5000, [$used->id]);

        $listed = $this->service()->availableAdvances($shop->id, $karigar->id);
        $this->assertSame([$open->id], $listed->pluck('id')->all(), 'no spent advance and none of the bookkeeping rows');
    }
}
