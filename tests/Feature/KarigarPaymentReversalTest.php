<?php

namespace Tests\Feature;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Services\KarigarInvoiceService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M5 (audit A2): a karigar payment (or a settled karigar invoice)
 * was permanent — recordPayment had no inverse, violating the CONSTITUTION §3
 * compensating-entry doctrine. reversePayment() now appends a negative
 * compensating KarigarPayment (the ledger is immutable, so we never edit/delete),
 * recomputes amount_paid/payment_status, and — when fully reversed — returns the
 * invoice to 'unpaid' so it can be corrected again.
 */
class KarigarPaymentReversalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makePaidInvoice(int $shopId, float $total = 5000.0): array
    {
        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shopId, 'name' => 'Ramesh'])->save();

        $invoice = new KarigarInvoice();
        $invoice->forceFill([
            'shop_id'                => $shopId,
            'karigar_id'             => $karigar->id,
            'mode'                   => 'job_work',
            'karigar_invoice_number' => 'KI-' . uniqid(),
            'karigar_invoice_date'   => now()->toDateString(),
            'total_after_tax'        => $total,
            'amount_paid'            => $total,
            'payment_status'         => KarigarInvoice::PAYMENT_PAID,
        ])->save();

        $payment = KarigarPayment::record([
            'shop_id'            => $shopId,
            'karigar_id'         => $karigar->id,
            'karigar_invoice_id' => $invoice->id,
            'amount'             => $total,
            'mode'               => 'cash',
            'paid_on'            => now()->toDateString(),
        ]);

        return [$invoice, $payment];
    }

    public function test_route_and_gate_exist(): void
    {
        $this->assertTrue(Route::has('karigar-invoices.payments.reverse'));
        $route = Route::getRoutes()->getByName('karigar-invoices.payments.reverse');
        $this->assertContains('can:karigar_invoice.manage', $route->gatherMiddleware());
    }

    public function test_reversal_offsets_payment_and_reopens_invoice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$invoice, $payment] = $this->makePaidInvoice($shop->id, 5000.0);

        TenantContext::runFor($shop->id, fn () =>
            app(KarigarInvoiceService::class)->reversePayment($invoice, $payment, 'Wrong amount', $owner->id)
        );

        // Compensating negative entry exists; original is untouched (immutable).
        $reversal = KarigarPayment::withoutGlobalScopes()
            ->where('karigar_invoice_id', $invoice->id)
            ->where('reference', 'REVERSAL:' . $payment->id)
            ->first();
        $this->assertNotNull($reversal);
        $this->assertEquals(-5000.0, (float) $reversal->amount);
        $this->assertEquals(5000.0, (float) KarigarPayment::withoutGlobalScopes()->find($payment->id)->amount);

        // Net paid is zero → invoice reopened to unpaid (correctable again).
        $fresh = KarigarInvoice::withoutGlobalScopes()->find($invoice->id);
        $this->assertEquals(0.0, (float) $fresh->amount_paid);
        $this->assertSame(KarigarInvoice::PAYMENT_UNPAID, $fresh->payment_status);

        $this->assertDatabaseHas('audit_logs', [
            'shop_id'    => $shop->id,
            'action'     => 'karigar_payment_reversed',
            'model_type' => 'karigar_payment',
            'model_id'   => $payment->id,
        ]);
    }

    public function test_cannot_reverse_twice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$invoice, $payment] = $this->makePaidInvoice($shop->id);

        TenantContext::runFor($shop->id, fn () =>
            app(KarigarInvoiceService::class)->reversePayment($invoice, $payment, null, $owner->id)
        );

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(KarigarInvoiceService::class)->reversePayment($invoice, $payment, null, $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a second reversal of the same payment must be rejected');
    }

    public function test_cannot_reverse_a_reversal_entry(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [$invoice, $payment] = $this->makePaidInvoice($shop->id);

        $reversal = TenantContext::runFor($shop->id, fn () =>
            app(KarigarInvoiceService::class)->reversePayment($invoice, $payment, null, $owner->id)
        );

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(KarigarInvoiceService::class)->reversePayment($invoice, $reversal, null, $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a reversal entry cannot itself be reversed');
    }
}
