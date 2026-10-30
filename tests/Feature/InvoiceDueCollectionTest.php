<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\ShopPaymentMethod;
use App\Models\User;
use App\Services\SchemeService;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Money received later against a finalized invoice's due (open issue 2026-10-25).
 *
 * One path for the web invoice page and the app: split payments, a cash book
 * row for every money mode with its account, and the financial lock. A bill on
 * an active EMI plan is collected only through the plan: a direct payment
 * would lower the invoice due but not the plan's remaining amount, so the EMI
 * page would ask for the same money again.
 */
class InvoiceDueCollectionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_the_web_records_a_split_payment_and_books_every_money_mode(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $upi = $this->account($shop->id, ShopPaymentMethod::TYPE_UPI);

        $this->postWeb($owner, $invoice, [
            ['mode' => 'cash', 'amount' => 3000],
            ['mode' => 'upi', 'amount' => 2000, 'payment_method_id' => $upi->id, 'reference' => 'UTR-1'],
        ])->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('success');

        $this->assertEqualsWithDelta(5000, $this->paidOn($invoice), 0.01);

        $cash = $this->cashRows($invoice);
        $this->assertSame(['cash' => 3000.0, 'upi' => 2000.0], $cash->mapWithKeys(fn ($r) => [$r->payment_mode => (float) $r->amount])->all());
        $upiRow = $cash->firstWhere('payment_mode', 'upi');
        $this->assertSame($upi->id, (int) $upiRow->payment_method_id);
        $this->assertSame($upi->account_label, $upiRow->payment_method_label_snapshot);

        $audit = AuditLog::withoutTenant()->where('action', 'invoice_payment_recorded')->where('model_id', $invoice->id)->sole();
        $this->assertSame('web', $audit->data['source']);
    }

    public function test_the_web_refuses_more_than_is_due_and_a_locked_day(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 12000]])->assertSessionHasErrors('payments');

        $this->lockToday($shop->id);
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 100]])->assertSessionHasErrors('payments');

        $this->assertSame(0.0, $this->paidOn($invoice));
        $this->assertCount(0, $this->cashRows($invoice));
    }

    public function test_the_invoice_page_offers_the_form_only_where_money_is_due_and_to_staff_who_may_sell(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $form = route('invoices.payments.store', $invoice);

        $this->showAs($owner, $invoice)->assertOk()->assertSee($form, false)->assertSee('Record payment');

        $viewer = $this->staff($shop->id, ['sales.view'], '9000001001');
        $this->showAs($viewer, $invoice)->assertOk()->assertDontSee($form, false);
        $this->postWeb($viewer, $invoice, [['mode' => 'cash', 'amount' => 100]])->assertForbidden();

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 10000]])->assertSessionHasNoErrors();
        $this->showAs($owner, $invoice)->assertOk()->assertDontSee($form, false);
    }

    public function test_an_emi_bill_is_collected_only_through_its_plan_on_every_path(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $plan = $this->activePlan($invoice, 8000);
        $this->seedCredit($shop->id, (int) $invoice->customer_id, $owner->id, 1000);

        // Web invoice page: no payment or store-credit form; the way to the plan instead.
        $this->showAs($owner, $invoice)->assertOk()
            ->assertSee(InstallmentPlan::COLLECT_THROUGH_PLAN)
            ->assertSee(route('installments.show', $plan), false)
            ->assertDontSee(route('invoices.payments.store', $invoice), false)
            ->assertDontSee(route('store-credit.apply', $invoice), false);

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 1000]])
            ->assertSessionHasErrors(['payments' => InstallmentPlan::COLLECT_THROUGH_PLAN]);

        $this->actingAs($owner);
        TenantContext::set((int) $shop->id);
        $this->post(route('store-credit.apply', $invoice), ['amount' => 1000])
            ->assertSessionHasErrors(['store_credit' => InstallmentPlan::COLLECT_THROUGH_PLAN]);

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);
        $this->postJson("/api/mobile/invoices/{$invoice->id}/payments", ['mode' => 'cash', 'amount' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('errors.payments.0', InstallmentPlan::COLLECT_THROUGH_PLAN);

        $enrollment = $this->schemeEnrollment($shop->id, (int) $invoice->customer_id);
        try {
            TenantContext::runFor($shop->id, fn () => app(SchemeService::class)->applyRedemptionToInvoice($enrollment, $invoice, 1000));
            $this->fail('A scheme redemption must not pay an EMI bill directly.');
        } catch (LogicException $e) {
            $this->assertSame(InstallmentPlan::COLLECT_THROUGH_PLAN, $e->getMessage());
        }

        $this->assertSame(0.0, $this->paidOn($invoice));
        $this->assertEqualsWithDelta(8000, (float) InstallmentPlan::withoutTenant()->findOrFail($plan->id)->remaining_amount, 0.01);
    }

    public function test_the_app_books_upi_money_in_the_cash_book_with_its_account(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $upi = $this->account($shop->id, ShopPaymentMethod::TYPE_UPI);

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);
        $this->postJson("/api/mobile/invoices/{$invoice->id}/payments", [
            'mode' => 'upi', 'amount' => 2000, 'payment_method_id' => $upi->id,
        ])->assertCreated();

        $row = $this->cashRows($invoice)->sole();
        $this->assertSame('upi', $row->payment_mode);
        $this->assertEqualsWithDelta(2000, (float) $row->amount, 0.01);
        $this->assertSame($upi->id, (int) $row->payment_method_id);
    }

    public function test_the_app_invoice_says_when_the_bill_is_on_emi(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $plain = $this->dueInvoice($shop->id, 5000);
        $onEmi = $this->dueInvoice($shop->id, 10000);
        $plan = $this->activePlan($onEmi, 8000);

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);
        $this->getJson("/api/mobile/invoices/{$plain->id}")->assertOk()->assertJsonPath('emi_plan', null);

        TenantContext::set((int) $shop->id);
        $this->getJson("/api/mobile/invoices/{$onEmi->id}")->assertOk()
            ->assertJsonPath('emi_plan.id', $plan->id)
            ->assertJsonPath('emi_plan.status', 'active');
    }

    // ── web retries (Codex review 2026-10-31, finding 3) ─────────────────

    public function test_a_form_resubmitted_after_a_lost_response_records_the_payment_once(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $key = (string) Str::uuid();

        $first = $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 3000]], $key);
        $first->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('success');

        // The browser never saw that answer and sends the same form again.
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 3000]], $key)
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', session('success'));

        $this->assertEqualsWithDelta(3000, $this->paidOn($invoice), 0.01);
        $this->assertSame(1, $this->cashCount($invoice));
        $this->assertSame(1, $this->auditCount($invoice));
    }

    public function test_separate_partial_payments_from_fresh_forms_are_all_recorded(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 2000]])->assertSessionHasNoErrors();
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 2000]])->assertSessionHasNoErrors();
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 2000]])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(6000, $this->paidOn($invoice), 0.01);
        $this->assertSame(3, $this->cashCount($invoice));
    }

    public function test_the_same_form_sent_with_different_amounts_is_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $key = (string) Str::uuid();

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 3000]], $key)->assertSessionHasNoErrors();
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 1000]], $key)->assertSessionHasErrors('payments');

        $this->assertEqualsWithDelta(3000, $this->paidOn($invoice), 0.01);
        $this->assertSame(1, $this->cashCount($invoice));
    }

    public function test_a_submission_without_its_request_key_is_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 3000]], null, false)->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0.0, $this->paidOn($invoice));
    }

    public function test_a_refused_attempt_leaves_its_key_free_for_the_corrected_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $key = (string) Str::uuid();

        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 12000]], $key)->assertSessionHasErrors('payments');
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 4000]], $key)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(4000, $this->paidOn($invoice), 0.01);
    }

    public function test_a_replay_still_needs_the_permission_and_never_reaches_an_emi_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);
        $key = (string) Str::uuid();
        $this->postWeb($owner, $invoice, [['mode' => 'cash', 'amount' => 3000]], $key)->assertSessionHasNoErrors();

        $viewer = $this->staff($shop->id, ['sales.view'], '9000001002');
        $this->postWeb($viewer, $invoice, [['mode' => 'cash', 'amount' => 3000]], $key)->assertForbidden();

        $emiBill = $this->dueInvoice($shop->id, 8000);
        $this->activePlan($emiBill, 8000);
        $this->postWeb($owner, $emiBill, [['mode' => 'cash', 'amount' => 1000]], $key)
            ->assertSessionHasErrors(['payments' => InstallmentPlan::COLLECT_THROUGH_PLAN]);

        $this->assertEqualsWithDelta(3000, $this->paidOn($invoice), 0.01);
        $this->assertSame(0.0, $this->paidOn($emiBill));
    }

    public function test_the_invoice_page_gives_each_form_its_own_request_key(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->dueInvoice($shop->id, 10000);

        preg_match('/name="idempotency_key" value="([0-9a-f-]{36})"/', $this->showAs($owner, $invoice)->getContent(), $first);
        preg_match('/name="idempotency_key" value="([0-9a-f-]{36})"/', $this->showAs($owner, $invoice)->getContent(), $second);

        $this->assertNotEmpty($first[1] ?? null, 'the form carries a request key');
        $this->assertNotSame($first[1], $second[1] ?? null, 'a fresh page is a fresh collection');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function dueInvoice(int $shopId, float $total): Invoice
    {
        $customer = $this->createCustomer($shopId);

        return TenantContext::runFor($shopId, fn () => Invoice::issue([
            'shop_id' => $shopId, 'customer_id' => $customer->id, 'status' => Invoice::STATUS_FINALIZED,
            'gold_rate' => 7200, 'subtotal' => $total, 'gst' => 0, 'total' => $total, 'finalized_at' => now(),
        ]));
    }

    private function activePlan(Invoice $invoice, float $remaining): InstallmentPlan
    {
        $plan = new InstallmentPlan();
        $plan->forceFill([
            'shop_id' => $invoice->shop_id, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'total_amount' => $invoice->total, 'down_payment' => 0, 'remaining_amount' => $remaining,
            'emi_amount' => 1000, 'total_emis' => 8, 'emis_paid' => 0,
            'next_due_date' => now()->addMonth()->toDateString(), 'status' => 'active',
        ])->save();

        return $plan;
    }

    private function account(int $shopId, string $type): ShopPaymentMethod
    {
        $method = new ShopPaymentMethod();
        $method->forceFill([
            'shop_id' => $shopId, 'type' => $type, 'name' => strtoupper($type) . ' Counter',
            'upi_id' => $type === ShopPaymentMethod::TYPE_UPI ? 'shop@upi' : null,
            'bank_name' => $type === ShopPaymentMethod::TYPE_BANK ? 'Test Bank' : null,
            'account_number' => $type === ShopPaymentMethod::TYPE_BANK ? '1234567890' : null,
            'is_active' => true, 'sort_order' => 0,
        ])->save();

        return $method;
    }

    private function staff(int $shopId, array $permissions, string $mobile): User
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $permissions, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'Staff', 'shop_id' => $shopId]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

            return User::create([
                'name' => 'Staff ' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shopId, 'role_id' => $role->id,
            ]);
        });
    }

    private function seedCredit(int $shopId, int $customerId, int $ownerId, float $amount): void
    {
        $customer = Customer::withoutTenant()->findOrFail($customerId);
        TenantContext::runFor($shopId, fn () => app(StoreCreditService::class)
            ->manualAdjust($customer, $shopId, $amount, 'seed credit for test', $ownerId, $ownerId));
    }

    private function schemeEnrollment(int $shopId, int $customerId): SchemeEnrollment
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $customerId) {
            $scheme = new Scheme();
            $scheme->forceFill([
                'shop_id' => $shopId, 'name' => 'Gold Savings', 'type' => 'gold_savings',
                'start_date' => now()->subMonth()->toDateString(), 'total_installments' => 11, 'is_active' => true,
            ])->save();

            $enrollment = new SchemeEnrollment();
            $enrollment->forceFill([
                'shop_id' => $shopId, 'scheme_id' => $scheme->id, 'customer_id' => $customerId,
                'start_date' => now()->subMonth()->toDateString(), 'monthly_amount' => 1000,
                'total_paid' => 2000, 'installments_paid' => 2, 'total_installments' => 11, 'status' => 'active',
            ])->save();

            return $enrollment;
        });
    }

    private function lockToday(int $shopId): void
    {
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $shopId], [
            'default_purity' => '22K', 'default_making_type' => 'per_gram', 'default_making_value' => 0,
            'test_loss_percent' => 0, 'buyback_percent' => 100, 'rounding_precision' => 2,
            'financial_lock_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** {invoice}-bound routes need the tenant the real request would resolve (see StoreCreditRedemptionTest). */
    /** One form submission; each rendered form carries its own request key unless one is given. */
    private function postWeb(User $actor, Invoice $invoice, array $payments, ?string $key = null, bool $withKey = true)
    {
        $this->actingAs($actor);
        TenantContext::set((int) $actor->shop_id);

        $body = ['payments' => $payments];
        if ($withKey) {
            $body['idempotency_key'] = $key ?? (string) Str::uuid();
        }

        return $this->post(route('invoices.payments.store', $invoice), $body);
    }

    private function cashCount(Invoice $invoice): int
    {
        return CashTransaction::withoutTenant()->where('invoice_id', $invoice->id)->count();
    }

    private function auditCount(Invoice $invoice): int
    {
        return AuditLog::withoutTenant()->where('action', 'invoice_payment_recorded')->where('model_id', $invoice->id)->count();
    }

    private function showAs(User $actor, Invoice $invoice)
    {
        $this->actingAs($actor);
        TenantContext::set((int) $actor->shop_id);

        return $this->get(route('invoices.show', $invoice));
    }

    private function paidOn(Invoice $invoice): float
    {
        return (float) InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->sum('amount');
    }

    private function cashRows(Invoice $invoice)
    {
        return CashTransaction::withoutTenant()->where('invoice_id', $invoice->id)->orderBy('id')->get();
    }
}
