<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Models\StockPurchase;
use App\Services\StockPurchaseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Backdated entry into a locked period: purchases, karigar invoices (and their payments) and karigar
 * opening balances take a user-chosen date, so each must be refused when that date is on or before
 * shop_rules.financial_lock_date. The refusal is a field error on the date and nothing is written.
 */
class PeriodLockCoverageTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase, SetsPeriodLock;

    private $user;

    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // The harness owner role has no synced permissions (see JobOrderControllerSourceTest).
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$this->user, $shop] = $this->createRetailerTenant();
        $this->shopId = (int) $shop->id;
    }

    private function as(callable $fn)
    {
        return TenantContext::runFor($this->shopId, fn () => $fn($this->actingAs($this->user)));
    }

    private function purchaseCount(): int
    {
        return StockPurchase::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    private function draftPurchase(string $date, string $status = 'draft', bool $withLine = true): StockPurchase
    {
        $purchase = new StockPurchase();
        $purchase->forceFill([
            'shop_id' => $this->shopId, 'supplier_name' => 'Acme', 'purchase_number' => 'PUR-'.uniqid(),
            'purchase_date' => $date, 'status' => $status, 'total_amount' => 0,
        ])->save();
        if ($withLine) {
            TenantContext::runFor($this->shopId, fn () => $purchase->lines()->create([
                'shop_id' => $this->shopId, 'line_type' => 'ornament', 'design' => 'Ring', 'metal_type' => 'gold',
                'purity' => 22, 'gross_weight' => 5, 'net_metal_weight' => 5,
            ]));
        }

        return $purchase;
    }

    // ───────────────────────── stock purchases ─────────────────────────

    public function test_purchase_dated_inside_the_locked_period_is_refused_and_nothing_is_written(): void
    {
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => $this->insideLock()]))
            ->assertSessionHasErrors('purchase_date');

        $this->assertSame(0, $this->purchaseCount());
    }

    public function test_purchase_dated_exactly_on_the_lock_date_is_refused(): void
    {
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => $this->lockDate()]))
            ->assertSessionHasErrors('purchase_date');

        $this->assertSame(0, $this->purchaseCount());
    }

    public function test_purchase_the_day_after_the_lock_today_and_with_no_lock_are_accepted(): void
    {
        $this->setLock($this->shopId);
        foreach ([$this->dayAfterLock(), now()->toDateString()] as $date) {
            $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => $date]))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $this->purchaseCount());

        DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => $this->insideLock()]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $this->purchaseCount());
    }

    public function test_editing_a_draft_purchase_so_its_date_moves_into_the_locked_period_is_refused(): void
    {
        $purchase = $this->draftPurchase(now()->toDateString());
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->put(route('inventory.purchases.update', $purchase), ['supplier_name' => 'Acme', 'purchase_date' => $this->insideLock()]))
            ->assertSessionHasErrors('purchase_date');

        $this->assertSame(now()->toDateString(), $purchase->fresh()->purchase_date->toDateString());
    }

    public function test_editing_a_draft_purchase_that_already_sits_in_the_locked_period_is_refused(): void
    {
        $purchase = $this->draftPurchase($this->insideLock());
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->put(route('inventory.purchases.update', $purchase), ['supplier_name' => 'Changed', 'purchase_date' => $this->dayAfterLock()]))
            ->assertSessionHasErrors('purchase_date');

        $this->assertSame('Acme', $purchase->fresh()->supplier_name);
    }

    public function test_confirming_a_purchase_dated_inside_the_locked_period_is_refused(): void
    {
        $purchase = $this->draftPurchase($this->insideLock());
        $this->setLock($this->shopId);

        try {
            TenantContext::runFor($this->shopId, fn () => app(StockPurchaseService::class)->confirmPurchase($purchase, $this->user->id));
            $this->fail('A purchase in a locked period was confirmed.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Financial lock is active', $e->getMessage());
        }
        $this->assertSame('draft', $purchase->fresh()->status);
    }

    public function test_confirm_http_route_shows_the_lock_message_for_a_locked_purchase(): void
    {
        $purchase = $this->draftPurchase($this->insideLock());
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->patch(route('inventory.purchases.confirm', $purchase)))
            ->assertSessionHas('error');
        $this->assertSame('draft', $purchase->fresh()->status);
    }

    public function test_stocking_and_reversing_a_confirmed_purchase_in_the_locked_period_are_refused(): void
    {
        $purchase = $this->draftPurchase($this->insideLock(), 'confirmed');
        $this->setLock($this->shopId);
        $service = app(StockPurchaseService::class);

        foreach (['addToInventory', 'reversePurchase'] as $method) {
            try {
                TenantContext::runFor($this->shopId, fn () => $service->$method($purchase, $this->user->id));
                $this->fail("{$method} ran on a purchase in a locked period.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Financial lock is active', $e->getMessage());
            }
        }
        $this->assertSame('confirmed', $purchase->fresh()->status);
    }

    public function test_confirming_a_purchase_dated_after_the_lock_still_works(): void
    {
        $purchase = $this->draftPurchase($this->dayAfterLock());
        $this->setLock($this->shopId);

        TenantContext::runFor($this->shopId, fn () => app(StockPurchaseService::class)->confirmPurchase($purchase, $this->user->id));

        $this->assertSame('confirmed', $purchase->fresh()->status);
    }

    // ───────────────────────── karigar invoices ─────────────────────────

    private function karigar(array $extra = []): Karigar
    {
        $karigar = new Karigar();
        $karigar->forceFill(array_merge(['shop_id' => $this->shopId, 'name' => 'Ramesh', 'is_active' => true], $extra))->save();

        return $karigar;
    }

    private function karigarInvoice(Karigar $karigar, string $date, float $total = 5000): KarigarInvoice
    {
        $invoice = new KarigarInvoice();
        $invoice->forceFill([
            'shop_id' => $this->shopId, 'karigar_id' => $karigar->id, 'mode' => 'job_work',
            'karigar_invoice_number' => 'KI-'.uniqid(), 'karigar_invoice_date' => $date,
            'total_after_tax' => $total, 'amount_paid' => 0, 'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
        ])->save();

        return $invoice;
    }

    private function invoicePayload(Karigar $karigar, string $date, array $extra = []): array
    {
        return array_merge([
            'karigar_id' => $karigar->id, 'mode' => 'job_work', 'karigar_invoice_number' => 'KI-'.uniqid(),
            'karigar_invoice_date' => $date,
            'lines' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => 5, 'net_weight' => 5, 'purity' => 22]],
        ], $extra);
    }

    private function invoiceCount(): int
    {
        return KarigarInvoice::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    public function test_karigar_invoice_inside_the_locked_period_is_refused_and_nothing_is_written(): void
    {
        $karigar = $this->karigar();
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($karigar, $this->insideLock())))
            ->assertSessionHasErrors('karigar_invoice_date');

        $this->assertSame(0, $this->invoiceCount());
    }

    public function test_karigar_invoice_exactly_on_the_lock_date_is_refused(): void
    {
        $karigar = $this->karigar();
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($karigar, $this->lockDate())))
            ->assertSessionHasErrors('karigar_invoice_date');

        $this->assertSame(0, $this->invoiceCount());
    }

    public function test_karigar_invoice_after_the_lock_today_and_with_no_lock_are_accepted(): void
    {
        $karigar = $this->karigar();
        $this->setLock($this->shopId);
        foreach ([$this->dayAfterLock(), now()->toDateString()] as $date) {
            $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($karigar, $date)))
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $this->invoiceCount());

        DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($karigar, $this->insideLock())))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $this->invoiceCount());
    }

    public function test_moving_a_karigar_invoice_date_into_the_locked_period_is_refused(): void
    {
        $karigar = $this->karigar();
        $invoice = $this->karigarInvoice($karigar, now()->toDateString());
        $this->setLock($this->shopId);

        $payload = $this->invoicePayload($karigar, $this->insideLock(), ['karigar_invoice_number' => $invoice->karigar_invoice_number]);
        unset($payload['karigar_id']);
        $this->as(fn ($t) => $t->put(route('karigar-invoices.update', $invoice), $payload))
            ->assertSessionHasErrors('karigar_invoice_date');

        $this->assertSame(now()->toDateString(), $invoice->fresh()->karigar_invoice_date->toDateString());
    }

    public function test_editing_or_deleting_a_karigar_invoice_that_sits_in_the_locked_period_is_refused(): void
    {
        $karigar = $this->karigar();
        $invoice = $this->karigarInvoice($karigar, $this->insideLock());
        $this->setLock($this->shopId);

        $payload = $this->invoicePayload($karigar, $this->dayAfterLock(), ['karigar_invoice_number' => $invoice->karigar_invoice_number]);
        unset($payload['karigar_id']);
        $this->as(fn ($t) => $t->put(route('karigar-invoices.update', $invoice), $payload))
            ->assertSessionHasErrors('karigar_invoice_date');
        $this->assertSame($this->insideLock(), $invoice->fresh()->karigar_invoice_date->toDateString());

        $this->as(fn ($t) => $t->delete(route('karigar-invoices.destroy', $invoice)))->assertSessionHas('error');
        $this->assertNotNull(KarigarInvoice::withoutGlobalScopes()->find($invoice->id));
    }

    public function test_a_karigar_payment_dated_inside_the_locked_period_is_refused(): void
    {
        $karigar = $this->karigar();
        $invoice = $this->karigarInvoice($karigar, now()->toDateString());
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->post(route('karigar-invoices.pay', $invoice), [
            'payments' => [['amount' => 1000, 'mode' => 'cash', 'paid_on' => $this->insideLock()]],
        ]))->assertSessionHasErrors('payments.0.paid_on');

        $this->assertSame(0, KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $invoice->id)->count());

        $this->as(fn ($t) => $t->post(route('karigar-invoices.pay', $invoice), [
            'payments' => [['amount' => 1000, 'mode' => 'cash', 'paid_on' => $this->lockDate()]],
        ]))->assertSessionHasErrors('payments.0.paid_on');

        $this->as(fn ($t) => $t->post(route('karigar-invoices.pay', $invoice), [
            'payments' => [['amount' => 1000, 'mode' => 'cash', 'paid_on' => $this->dayAfterLock()]],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $invoice->id)->count());
    }

    // ───────────────────────── karigar opening balance ─────────────────────────

    private function karigarPayload(array $extra = []): array
    {
        return array_merge(['name' => 'Suresh'], $extra);
    }

    public function test_karigar_opening_balance_dated_inside_the_locked_period_is_refused(): void
    {
        $this->setLock($this->shopId);

        foreach ([$this->insideLock(), $this->lockDate()] as $date) {
            $this->as(fn ($t) => $t->post(route('karigars.store'), $this->karigarPayload(['opening_balance' => 5000, 'opening_balance_at' => $date])))
                ->assertSessionHasErrors('opening_balance_at');
        }
        $this->assertSame(0, Karigar::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('name', 'Suresh')->count());
    }

    public function test_karigar_opening_balance_after_the_lock_without_a_balance_or_with_no_lock_is_accepted(): void
    {
        $this->setLock($this->shopId);
        $this->as(fn ($t) => $t->post(route('karigars.store'), $this->karigarPayload(['name' => 'A1', 'opening_balance' => 5000, 'opening_balance_at' => $this->dayAfterLock()])))
            ->assertSessionHasNoErrors();
        // no money is being posted, so an old date alone is harmless
        $this->as(fn ($t) => $t->post(route('karigars.store'), $this->karigarPayload(['name' => 'A2', 'opening_balance_at' => $this->insideLock()])))
            ->assertSessionHasNoErrors();

        DB::table('shop_rules')->where('shop_id', $this->shopId)->update(['financial_lock_date' => null]);
        $this->as(fn ($t) => $t->post(route('karigars.store'), $this->karigarPayload(['name' => 'A3', 'opening_balance' => 5000, 'opening_balance_at' => $this->insideLock()])))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Karigar::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());
    }

    public function test_karigar_update_cannot_post_or_move_an_opening_balance_into_the_locked_period(): void
    {
        $karigar = $this->karigar(['opening_balance' => 1000, 'opening_balance_at' => $this->insideLock()]);
        $this->setLock($this->shopId);

        // changing the amount of a balance that sits in the locked period
        $this->as(fn ($t) => $t->put(route('karigars.update', $karigar), $this->karigarPayload(['opening_balance' => 2000, 'opening_balance_at' => $this->insideLock()])))
            ->assertSessionHasErrors('opening_balance_at');
        // moving the date of that balance out of the period
        $this->as(fn ($t) => $t->put(route('karigars.update', $karigar), $this->karigarPayload(['opening_balance' => 1000, 'opening_balance_at' => $this->dayAfterLock()])))
            ->assertSessionHasErrors('opening_balance_at');

        $fresh = Karigar::withoutGlobalScopes()->find($karigar->id);
        $this->assertEquals(1000, (float) $fresh->opening_balance);
        $this->assertSame($this->insideLock(), $fresh->opening_balance_at->toDateString());
    }

    public function test_karigar_update_that_leaves_a_locked_opening_balance_untouched_is_accepted(): void
    {
        $karigar = $this->karigar(['opening_balance' => 1000, 'opening_balance_at' => $this->insideLock()]);
        $this->setLock($this->shopId);

        $this->as(fn ($t) => $t->put(route('karigars.update', $karigar), $this->karigarPayload(['name' => 'Renamed', 'opening_balance' => 1000, 'opening_balance_at' => $this->insideLock()])))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', Karigar::withoutGlobalScopes()->find($karigar->id)->name);
    }
}
