<?php

namespace Tests\Feature\FinancialYear;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobOrder;
use App\Models\JobOrderReceipt;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Models\MetalLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\InvoiceAccountingService;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Back-dating speed bump (owner-approved 2026-11-16). With the books NOT locked for the date, an entry dated in a
 * FINISHED financial year needs (a) a written reason (backdate_reason, 10+ chars), (b) the shop owner or the
 * books.backdate permission, and (c) leaves an append-only 'books.backdated' audit row. The lock always wins.
 * Today in these tests is 2026-11-16 (financial year 2026-27); 2025-26 has ended.
 */
class BackdatingSpeedBumpTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase, SetsPeriodLock;

    private const OLD = '2026-01-15';     // FY 2025-26: finished

    private const CURRENT = '2026-08-10'; // FY 2026-27: current

    private const REASON = 'Bill arrived late from vendor';

    private $user;

    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00', 'Asia/Kolkata'));
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$this->user, $shop] = $this->createRetailerTenant();
        $this->shopId = (int) $shop->id;
    }

    protected function tearDown(): void
    {
        MetalRegistry::clearShopCache();
        parent::tearDown();
    }

    private function as(callable $fn, ?User $who = null)
    {
        return TenantContext::runFor($this->shopId, fn () => $fn($this->actingAs($who ?? $this->user)));
    }

    /** A non-owner on a role named $name holding exactly $perms. */
    private function member(string $name, array $perms = []): User
    {
        return TenantContext::runFor($this->shopId, function () use ($name, $perms) {
            $role = (new Role())->forceFill(['name' => $name, 'display_name' => $name, 'shop_id' => $this->shopId]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create(['name' => $name, 'mobile_number' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'), 'realm' => 'erp', 'is_active' => true, 'shop_id' => $this->shopId, 'role_id' => $role->id]);
        });
    }

    private function audits()
    {
        return AuditLog::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('action', 'books.backdated');
    }

    private function purchaseCount(): int
    {
        return StockPurchase::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    private function storePurchase(string $date, ?string $reason = self::REASON, ?User $who = null)
    {
        $payload = ['supplier_name' => 'Acme', 'purchase_date' => $date];
        if ($reason !== null) {
            $payload['backdate_reason'] = $reason;
        }

        return $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), $payload), $who);
    }

    private function draftPurchase(string $date): StockPurchase
    {
        $p = new StockPurchase();
        $p->forceFill(['shop_id' => $this->shopId, 'supplier_name' => 'Acme', 'purchase_number' => 'PUR-'.uniqid(),
            'purchase_date' => $date, 'status' => 'draft', 'total_amount' => 0])->save();

        return $p;
    }

    // ───────────────────────── purchases (create) ─────────────────────────

    public function test_finished_year_purchase_without_a_reason_is_refused_and_nothing_is_written(): void
    {
        $this->storePurchase(self::OLD, null)->assertSessionHasErrors('backdate_reason');

        $this->assertSame(0, $this->purchaseCount());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_a_reason_shorter_than_ten_characters_or_only_spaces_is_refused(): void
    {
        foreach (['too short', '         ', '   short   ', ''] as $reason) {
            $this->storePurchase(self::OLD, $reason)->assertSessionHasErrors('backdate_reason');
        }
        $this->assertSame(0, $this->purchaseCount());

        // exactly ten characters (after trimming) is enough
        $this->storePurchase(self::OLD, '  0123456789  ')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->purchaseCount());
    }

    public function test_staff_without_the_permission_is_refused_even_with_a_reason(): void
    {
        $staff = $this->member('cashier-x', ['inventory.view', 'inventory.create']);

        $this->storePurchase(self::OLD, self::REASON, $staff)->assertSessionHasErrors('purchase_date');

        $this->assertSame(0, $this->purchaseCount());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_owner_with_a_reason_saves_and_leaves_an_audit_row(): void
    {
        $this->storePurchase(self::OLD)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->purchaseCount());
        $log = $this->audits()->sole();
        $this->assertSame($this->user->id, (int) $log->user_id);
        $this->assertSame('stock_purchase', $log->model_type);
        $this->assertSame(self::REASON, $log->data['reason']);
        $this->assertSame(self::OLD, $log->data['date']);
        $this->assertSame('2025-26', $log->data['financial_year']);
        $this->assertSame('purchase_date', $log->data['field']);
        // read by FiscalYearSnapshotService::changesSince
        $this->assertSame(self::OLD, $log->data['document_date']);
        $this->assertSame('2025-26', $log->data['fiscal_year']);
    }

    public function test_a_non_owner_holding_the_permission_may_backdate(): void
    {
        $holder = $this->member('trusted-clerk', ['inventory.view', 'inventory.create', 'books.backdate']);

        $this->storePurchase(self::OLD, self::REASON, $holder)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->purchaseCount());
        $this->assertSame($holder->id, (int) $this->audits()->sole()->user_id);
    }

    public function test_the_audit_row_is_append_only(): void
    {
        $this->storePurchase(self::OLD);
        $log = $this->audits()->sole();

        $this->expectException(\LogicException::class);
        $log->update(['description' => 'tampered']);
    }

    public function test_current_year_dates_need_no_reason_and_leave_no_audit_row(): void
    {
        $this->storePurchase(self::CURRENT, null)->assertSessionHasNoErrors();
        $this->storePurchase('2026-04-01', null)->assertSessionHasNoErrors();   // first day of the current year
        $this->storePurchase('2026-11-16', null)->assertSessionHasNoErrors();   // today
        $this->storePurchase('2027-02-01', null)->assertSessionHasNoErrors();   // future, same year

        $this->assertSame(4, $this->purchaseCount());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_the_last_day_of_the_finished_year_is_the_boundary(): void
    {
        $this->storePurchase('2026-03-31', null)->assertSessionHasErrors('backdate_reason');
        $this->storePurchase('2026-03-31')->assertSessionHasNoErrors();
        $this->assertSame('2025-26', $this->audits()->sole()->data['financial_year']);
    }

    public function test_year_evaluation_follows_indian_time_just_after_midnight_on_1_april(): void
    {
        // 31 March 2027 18:45 UTC is 1 April 2027 00:15 IST: the financial year 2026-27 has just ended.
        $this->travelTo(Carbon::parse('2027-03-31 18:45:00', 'UTC'));

        $this->storePurchase('2027-03-20', null)->assertSessionHasErrors('backdate_reason');
        $this->storePurchase('2027-04-01', null)->assertSessionHasNoErrors();
    }

    public function test_a_locked_date_is_still_refused_by_the_lock_and_no_reason_bypasses_it(): void
    {
        $this->setLock($this->shopId, '2026-02-01');

        $this->storePurchase(self::OLD)->assertSessionHasErrors('purchase_date');

        $this->assertSame(0, $this->purchaseCount());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_a_date_after_the_lock_inside_the_finished_year_still_needs_a_reason(): void
    {
        $this->setLock($this->shopId, '2026-01-31');

        $this->storePurchase('2026-02-10', null)->assertSessionHasErrors('backdate_reason');
        $this->storePurchase('2026-02-10')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->purchaseCount());
    }

    public function test_the_reason_is_not_stored_on_the_purchase_and_cannot_be_forged_by_a_garbage_value(): void
    {
        // an array / oversized reason is not a reason
        $this->as(fn ($t) => $t->post(route('inventory.purchases.store'), ['supplier_name' => 'Acme', 'purchase_date' => self::OLD, 'backdate_reason' => ['x']]))
            ->assertSessionHasErrors('backdate_reason');
        $this->assertSame(0, $this->purchaseCount());
    }

    // ───────────────────────── purchases (edit) ─────────────────────────

    public function test_editing_a_previous_year_draft_purchase_needs_the_reason_and_the_permission(): void
    {
        $purchase = $this->draftPurchase(self::OLD);
        $staff = $this->member('cashier-y', ['inventory.view', 'inventory.edit']);
        $put = fn (?string $reason, ?User $who = null) => $this->as(fn ($t) => $t->put(route('inventory.purchases.update', $purchase), array_filter([
            'supplier_name' => 'Changed', 'purchase_date' => self::OLD, 'backdate_reason' => $reason,
        ], fn ($v) => $v !== null)), $who);

        $put(null)->assertSessionHasErrors('backdate_reason');
        $put(self::REASON, $staff)->assertSessionHasErrors('purchase_date');
        $this->assertSame('Acme', $purchase->fresh()->supplier_name);

        $put(self::REASON)->assertSessionHasNoErrors();
        $this->assertSame('Changed', $purchase->fresh()->supplier_name);
        // old date and new date are the same day: one audit row, naming the purchase
        $log = $this->audits()->sole();
        $this->assertSame($purchase->id, (int) $log->model_id);
    }

    public function test_moving_a_current_year_purchase_into_the_finished_year_needs_a_reason(): void
    {
        $purchase = $this->draftPurchase(self::CURRENT);

        $this->as(fn ($t) => $t->put(route('inventory.purchases.update', $purchase), ['supplier_name' => 'Acme', 'purchase_date' => self::OLD]))
            ->assertSessionHasErrors('backdate_reason');
        $this->assertSame(self::CURRENT, $purchase->fresh()->purchase_date->toDateString());
    }

    public function test_moving_a_previous_year_purchase_to_the_current_year_still_needs_a_reason(): void
    {
        $purchase = $this->draftPurchase(self::OLD);

        $this->as(fn ($t) => $t->put(route('inventory.purchases.update', $purchase), ['supplier_name' => 'Acme', 'purchase_date' => self::CURRENT]))
            ->assertSessionHasErrors('backdate_reason');
        $this->assertSame(self::OLD, $purchase->fresh()->purchase_date->toDateString());
    }

    // ───────────────────────── karigar invoices, payments, opening balance ─────────────────────────

    private function karigar(array $extra = []): Karigar
    {
        $k = new Karigar();
        $k->forceFill(array_merge(['shop_id' => $this->shopId, 'name' => 'Ramesh', 'is_active' => true], $extra))->save();

        return $k;
    }

    private function invoicePayload(Karigar $k, string $date, ?string $reason): array
    {
        return array_filter([
            'karigar_id' => $k->id, 'mode' => 'job_work', 'karigar_invoice_number' => 'KI-'.uniqid(),
            'karigar_invoice_date' => $date, 'backdate_reason' => $reason,
            'lines' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => 5, 'net_weight' => 5, 'purity' => 22]],
        ], fn ($v) => $v !== null);
    }

    private function invoiceCount(): int
    {
        return KarigarInvoice::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    public function test_karigar_invoice_in_a_finished_year_needs_reason_and_permission(): void
    {
        $k = $this->karigar();
        $staff = $this->member('karigar-clerk', ['karigar_invoice.manage']);

        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($k, self::OLD, null)))
            ->assertSessionHasErrors('backdate_reason');
        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($k, self::OLD, self::REASON)), $staff)
            ->assertSessionHasErrors('karigar_invoice_date');
        $this->assertSame(0, $this->invoiceCount());

        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($k, self::OLD, self::REASON)))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->invoiceCount());
        $this->assertSame('karigar_invoice_date', $this->audits()->sole()->data['field']);
    }

    public function test_karigar_invoice_in_the_current_year_needs_no_reason(): void
    {
        $this->as(fn ($t) => $t->post(route('karigar-invoices.store'), $this->invoicePayload($this->karigar(), self::CURRENT, null)))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->invoiceCount());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_editing_a_previous_year_karigar_invoice_needs_a_reason(): void
    {
        $k = $this->karigar();
        $inv = new KarigarInvoice();
        $inv->forceFill(['shop_id' => $this->shopId, 'karigar_id' => $k->id, 'mode' => 'job_work', 'karigar_invoice_number' => 'KI-OLD',
            'karigar_invoice_date' => self::OLD, 'total_after_tax' => 5000, 'amount_paid' => 0, 'payment_status' => KarigarInvoice::PAYMENT_UNPAID])->save();

        $payload = $this->invoicePayload($k, self::CURRENT, null);
        unset($payload['karigar_id']);
        $payload['karigar_invoice_number'] = 'KI-OLD';
        $this->as(fn ($t) => $t->put(route('karigar-invoices.update', $inv), $payload))->assertSessionHasErrors('backdate_reason');
        $this->assertSame(self::OLD, $inv->fresh()->karigar_invoice_date->toDateString());
    }

    public function test_a_karigar_payment_dated_in_a_finished_year_needs_a_reason_and_permission(): void
    {
        $k = $this->karigar();
        $inv = new KarigarInvoice();
        $inv->forceFill(['shop_id' => $this->shopId, 'karigar_id' => $k->id, 'mode' => 'job_work', 'karigar_invoice_number' => 'KI-P',
            'karigar_invoice_date' => self::CURRENT, 'total_after_tax' => 5000, 'amount_paid' => 0, 'payment_status' => KarigarInvoice::PAYMENT_UNPAID])->save();
        $staff = $this->member('karigar-clerk2', ['karigar_invoice.manage']);
        $pay = fn (?string $reason, ?User $who = null) => $this->as(fn ($t) => $t->post(route('karigar-invoices.pay', $inv), array_filter([
            'payments' => [['amount' => 1000, 'mode' => 'cash', 'paid_on' => self::OLD]], 'backdate_reason' => $reason,
        ], fn ($v) => $v !== null)), $who);

        $pay(null)->assertSessionHasErrors('backdate_reason');
        $pay(self::REASON, $staff)->assertSessionHasErrors('payments.0.paid_on');
        $this->assertSame(0, KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $inv->id)->count());

        $pay(self::REASON)->assertSessionHasNoErrors();
        $this->assertSame(1, KarigarPayment::withoutGlobalScopes()->where('karigar_invoice_id', $inv->id)->count());
        $this->assertSame('payments.0.paid_on', $this->audits()->sole()->data['field']);
    }

    public function test_karigar_opening_balance_dated_in_a_finished_year_needs_a_reason(): void
    {
        $store = fn (array $extra) => $this->as(fn ($t) => $t->post(route('karigars.store'), array_merge(['name' => 'Suresh'], $extra)));

        $store(['opening_balance' => 5000, 'opening_balance_at' => self::OLD])->assertSessionHasErrors('backdate_reason');
        $this->assertSame(0, Karigar::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());

        // no money posted: an old date alone needs no reason
        $store(['opening_balance_at' => self::OLD])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->audits()->count());

        $store(['name' => 'Suresh2', 'opening_balance' => 5000, 'opening_balance_at' => self::OLD, 'backdate_reason' => self::REASON])->assertSessionHasNoErrors();
        $this->assertSame('opening_balance_at', $this->audits()->sole()->data['field']);
    }

    public function test_renaming_a_karigar_whose_opening_balance_sits_in_a_finished_year_needs_no_reason(): void
    {
        $k = $this->karigar(['opening_balance' => 1000, 'opening_balance_at' => self::OLD]);

        $this->as(fn ($t) => $t->put(route('karigars.update', $k), ['name' => 'Renamed', 'opening_balance' => 1000, 'opening_balance_at' => self::OLD]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', Karigar::withoutGlobalScopes()->find($k->id)->name);

        // but changing the amount is a financial write into the old year
        $this->as(fn ($t) => $t->put(route('karigars.update', $k), ['name' => 'Renamed', 'opening_balance' => 2000, 'opening_balance_at' => self::OLD]))
            ->assertSessionHasErrors('backdate_reason');
    }

    // ───────────────────────── job orders (web + mobile) ─────────────────────────

    private function karigarId(): int
    {
        return (int) DB::table('karigars')->insertGetId([
            'shop_id' => $this->shopId, 'name' => 'JO Karigar', 'default_wastage_percent' => 2,
            'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function jobCount(): int
    {
        return JobOrder::withoutGlobalScopes()->where('shop_id', $this->shopId)->count();
    }

    public function test_web_job_order_issue_in_a_finished_year_needs_reason_and_permission(): void
    {
        $kid = $this->karigarId();
        $staff = $this->member('jo-clerk', ['job_order.manage']);
        $payload = fn (?string $reason) => array_filter([
            'karigar_id' => $kid, 'metal_type' => 'gold', 'purity' => 22, 'allowed_wastage_percent' => 5,
            'issue_date' => self::OLD, 'metal_source' => 'none', 'job_type' => 'repair', 'backdate_reason' => $reason,
        ], fn ($v) => $v !== null);

        $this->as(fn ($t) => $t->post(route('job-orders.store'), $payload(null)))->assertSessionHasErrors('backdate_reason');
        $this->as(fn ($t) => $t->post(route('job-orders.store'), $payload(self::REASON)), $staff)->assertSessionHasErrors('issue_date');
        $this->assertSame(0, $this->jobCount());

        $this->as(fn ($t) => $t->post(route('job-orders.store'), $payload(self::REASON)))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->jobCount());
        $this->assertSame('issue_date', $this->audits()->sole()->data['field']);
    }

    public function test_web_job_order_receipt_in_a_finished_year_needs_a_reason(): void
    {
        $kid = $this->karigarId();
        $jobId = (int) DB::table('job_orders')->insertGetId([
            'shop_id' => $this->shopId, 'karigar_id' => $kid, 'job_order_number' => 'JO-'.uniqid(), 'challan_number' => 'CH-'.uniqid(),
            'metal_type' => 'gold', 'purity' => 22, 'issued_gross_weight' => 50, 'issued_fine_weight' => 45.83,
            'expected_return_fine_weight' => 44.93, 'allowed_wastage_percent' => 2, 'status' => 'issued',
            'issue_date' => self::CURRENT, 'created_at' => now(), 'updated_at' => now(), 'created_by_user_id' => $this->user->id,
        ]);
        $payload = fn (?string $reason) => array_filter([
            'receipt_date' => self::OLD, 'backdate_reason' => $reason,
            'items' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => 5, 'net_weight' => 5, 'purity' => 22]],
        ], fn ($v) => $v !== null);

        $this->as(fn ($t) => $t->post(route('job-orders.receive.store', $jobId), $payload(null)))->assertSessionHasErrors('backdate_reason');
        $this->assertSame(0, JobOrderReceipt::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());

        $this->as(fn ($t) => $t->post(route('job-orders.receive.store', $jobId), $payload(self::REASON)))->assertSessionHasNoErrors();
        $this->assertSame(1, JobOrderReceipt::withoutGlobalScopes()->where('shop_id', $this->shopId)->count());
        $this->assertSame('receipt_date', $this->audits()->sole()->data['field']);
    }

    private function vaultLot(): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 22.00,
            'fine_weight_total' => 100, 'fine_weight_remaining' => 100, 'cost_per_fine_gram' => 5000])->save();

        return $lot;
    }

    private function mobileIssue(string $date, MetalLot $lot, ?string $reason)
    {
        $payload = ['karigar_id' => $this->karigarId(), 'metal_type' => 'gold', 'purity' => 22, 'issue_date' => $date,
            'issuances' => [['metal_lot_id' => $lot->id, 'gross_weight' => 10, 'fine_weight' => 9.166]]];
        if ($reason !== null) {
            $payload['backdate_reason'] = $reason;
        }

        return $this->postJson('/api/mobile/v1/job-orders', $payload, ['X-Idempotency-Key' => 'bd-'.uniqid()]);
    }

    public function test_mobile_job_order_issue_in_a_finished_year_is_a_422_without_a_reason(): void
    {
        $lot = $this->vaultLot();
        Role::withoutTenant()->findOrFail($this->user->role_id)->givePermission('job_order.manage');
        Sanctum::actingAs($this->user);
        TenantContext::set($this->shopId);

        $r = $this->mobileIssue(self::OLD, $lot, null)->assertStatus(422);
        $this->assertStringContainsString('Give a reason', json_encode($r->json()));
        $this->assertSame(0, $this->jobCount());
        $this->assertEqualsWithDelta(100.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);

        $this->mobileIssue(self::OLD, $lot, self::REASON)->assertStatus(201);
        $this->assertSame(1, $this->jobCount());
        $this->assertSame(1, $this->audits()->count());

        // current year: no reason
        $this->mobileIssue(self::CURRENT, $lot, null)->assertStatus(201);
    }

    public function test_mobile_job_order_issue_without_permission_is_a_422_and_the_lock_still_wins(): void
    {
        $lot = $this->vaultLot();
        $staff = $this->member('jo-mobile-clerk', ['job_order.manage']);
        Sanctum::actingAs($staff);
        TenantContext::set($this->shopId);

        $this->mobileIssue(self::OLD, $lot, self::REASON)->assertStatus(422);
        $this->assertSame(0, $this->jobCount());

        Sanctum::actingAs($this->user);
        Role::withoutTenant()->findOrFail($this->user->role_id)->givePermission('job_order.manage');
        $this->setLock($this->shopId, '2026-02-01');
        $r = $this->mobileIssue(self::OLD, $lot, self::REASON)->assertStatus(422);
        $this->assertStringContainsString('Financial lock is active', json_encode($r->json()));
        $this->assertSame(0, $this->audits()->count());
    }

    // ───────────────────────── cancelling a previous-year invoice ─────────────────────────

    private function finalizedInvoiceIn(string $when): Invoice
    {
        $this->travelTo(Carbon::parse($when, 'Asia/Kolkata'));
        $this->actingAs($this->user);
        TenantContext::set($this->shopId);
        $customer = $this->createCustomer($this->shopId);
        $item = $this->createItem($this->shopId);
        $invoice = InvoiceAccountingService::createDraft(['customer_id' => $customer->id, 'shop_id' => $this->shopId,
            'gold_rate' => 6000, 'gst_rate' => 3, 'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0]);
        InvoiceItem::record(['invoice_id' => $invoice->id, 'item_id' => $item->id, 'weight' => 10, 'rate' => 6000,
            'making_charges' => 500, 'stone_amount' => 200, 'line_total' => 60700]);
        $invoice = InvoiceAccountingService::finalizeDraft($invoice, 3.0);
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00', 'Asia/Kolkata'));

        return $invoice;
    }

    public function test_cancelling_a_previous_year_invoice_needs_the_permission_and_is_audited(): void
    {
        $invoice = $this->finalizedInvoiceIn('2026-01-10 11:00:00');
        $this->assertSame('2025-26', $invoice->fiscal_year);

        $staff = $this->member('void-clerk', ['sales.void']);
        $this->actingAs($staff);
        try {
            InvoiceAccountingService::cancelByReversal($invoice, self::REASON);
            $this->fail('A previous-year invoice was cancelled without the permission.');
        } catch (\Illuminate\Validation\ValidationException|\LogicException $e) {
            $this->assertSame(\App\Support\NotAvailable::MESSAGE, $e->getMessage());
        }
        $this->assertNull($invoice->fresh()->reversed_by_invoice_id);
        $this->assertSame(0, $this->audits()->count());

        $this->actingAs($this->user);
        InvoiceAccountingService::cancelByReversal($invoice, self::REASON);
        $this->assertNotNull($invoice->fresh()->reversed_by_invoice_id);
        $log = $this->audits()->sole();
        $this->assertSame('invoice', $log->model_type);
        $this->assertSame($invoice->id, (int) $log->model_id);
        $this->assertSame('2025-26', $log->data['financial_year']);
        $this->assertSame(self::REASON, $log->data['reason']);
    }

    public function test_cancelling_a_current_year_invoice_needs_no_extra_permission(): void
    {
        $invoice = $this->finalizedInvoiceIn('2026-09-10 11:00:00');
        $this->actingAs($this->member('void-clerk2', ['sales.void']));

        InvoiceAccountingService::cancelByReversal($invoice, 'Short');

        $this->assertNotNull($invoice->fresh()->reversed_by_invoice_id);
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_cancelling_a_previous_year_invoice_through_a_locked_year_is_still_refused_by_the_lock(): void
    {
        $invoice = $this->finalizedInvoiceIn('2026-01-10 11:00:00');
        $this->setLock($this->shopId, '2026-03-31');
        $this->actingAs($this->user);

        try {
            InvoiceAccountingService::cancelByReversal($invoice, self::REASON);
            $this->fail('A locked invoice was cancelled.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('lock date', $e->getMessage());
        }
        $this->assertSame(0, $this->audits()->count());
    }

    // ───────────────────────── permission catalogue ─────────────────────────

    public function test_the_permission_exists_and_owner_and_manager_roles_hold_it_but_staff_do_not_by_default(): void
    {
        $this->assertTrue(Permission::where('name', 'books.backdate')->exists());
        $this->assertTrue(TenantContext::runFor($this->shopId, fn () => $this->user->fresh()->hasPermission('books.backdate')));

        (require database_path('migrations/2026_11_16_400000_add_books_backdate_permission.php'))->up();
        $managerRole = (new Role())->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $this->shopId]);
        $managerRole->save();
        (require database_path('migrations/2026_11_16_400000_add_books_backdate_permission.php'))->up();
        TenantContext::runFor($this->shopId, function () use ($managerRole) {
            $this->assertTrue(Role::find($managerRole->id)->hasPermission('books.backdate'));
            $this->assertFalse($this->member('plain-staff', ['sales.view'])->fresh()->hasPermission('books.backdate'));
        });
    }
}
