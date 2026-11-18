<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Reporting\LedgerService;
use App\Reporting\ReceivablesService;
use App\Reporting\ReportPeriod;
use App\Services\BalancesAsAt;
use App\Services\DhiranService;
use App\Services\InstallmentService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** BalancesAsAt: every area is built from a history straddling 2026-03-31 (before, on, after, 23:59 vs 00:00). */
class BalancesAsAtTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const D = '2026-03-31';

    private Shop $shop;

    private int $userId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$user, $this->shop] = $this->createRetailerTenant();
        $this->userId = $user->id;
        $this->at('2026-11-16 12:00:00');
    }

    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
    }

    private function snap(?int $shopId = null, string $date = self::D): array
    {
        return app(BalancesAsAt::class)->forShop($shopId ?? $this->shop->id, $date);
    }

    private function invoice(int $shopId, ?int $customerId, float $total, string $at, string $status = 'finalized', ?string $cancelledAt = null): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-'.uniqid(), 'customer_id' => $customerId, 'shop_id' => $shopId, 'gold_rate' => 7000,
            'subtotal' => $total, 'gst' => 0, 'total' => $total, 'status' => $status,
            'finalized_at' => $at, 'cancelled_at' => $cancelledAt, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function pay(int $shopId, int $invoiceId, float $amount, string $at): void
    {
        DB::table('invoice_payments')->insert(['shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => 'cash', 'amount' => $amount, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function cash(int $shopId, string $type, float $amt, ?string $mode, string $at, bool $opening = false, ?int $pm = null): void
    {
        DB::table('cash_transactions')->insert([
            'shop_id' => $shopId, 'user_id' => $this->userId, 'type' => $type, 'amount' => $amt, 'source_type' => 'manual',
            'payment_mode' => $mode, 'is_opening' => $opening ? DB::raw('true') : DB::raw('false'), 'payment_method_id' => $pm, 'payment_method_label_snapshot' => $pm ? 'HDFC' : null,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    // ───────── shape / empty / isolation ─────────

    public function test_empty_shop_returns_zeros_not_errors_and_retail_has_no_dhiran(): void
    {
        $s = $this->snap();
        $this->assertSame(0.0, $s['receivables']['total']);
        $this->assertSame([], $s['receivables']['customers']);
        $this->assertSame(0.0, $s['cash_and_bank']['total']);
        $this->assertSame(0.0, $s['store_credit']['total']);
        $this->assertSame(0.0, $s['customer_gold']['fine_gold_total']);
        $this->assertSame(0.0, $s['scheme_liability']['total']);
        $this->assertSame(0.0, $s['emi_outstanding']['total']);
        $this->assertSame([], $s['karigar']['karigars']);
        $this->assertSame([], $s['supplier_payables']['vendors']);
        $this->assertSame(0, $s['loyalty_points']['total_points']);
        $this->assertNull($s['dhiran']);
        $this->assertSame(0.0, $s['opening_balances']['customer_receivable_total']);
        $this->assertSame(self::D, $s['as_at']);
    }

    public function test_bad_date_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->snap(null, '31-03-2026');
    }

    // ───────── receivables ─────────

    public function test_receivables_as_at_agree_with_dues_report_and_ignore_later_events(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $s = $this->shop->id;
        $a = $this->invoice($s, $c->id, 1000, '2026-03-10 11:00:00');
        $this->pay($s, $a, 300, '2026-03-20 10:00:00');
        $this->pay($s, $a, 200, '2026-04-02 10:00:00');             // after: still owed at the date
        $this->invoice($s, $c->id, 500, '2026-03-31 23:59:00');     // last minute: counts
        $this->invoice($s, $c->id, 900, '2026-04-01 00:00:00');     // first minute after: not
        $this->invoice($s, $c->id, 400, '2026-03-12 10:00:00', 'cancelled', '2026-04-05 10:00:00'); // cancelled later: owed then
        $this->invoice($s, $c->id, 250, '2026-03-12 10:00:00', 'cancelled', '2026-03-15 10:00:00'); // cancelled before: gone

        $r = $this->snap()['receivables'];
        $this->assertSame(1600.0, $r['total']);                     // 700 + 500 + 400
        $this->assertSame($c->id, $r['customers'][0]['customer_id']);

        // Cross-check: with every row now in the past, the live dues report as of the date after all events matches
        // once later events are removed (rule parity): finalized-only subset on a date past everything.
        $this->at('2026-11-16 12:00:00');
        $later = $this->snap(null, '2026-11-16')['receivables']['total'];
        $report = app(ReceivablesService::class)->duesAging($s, Carbon::parse('2026-11-16'))->totalOutstanding;
        $this->assertSame(round($report, 2), $later);
    }

    public function test_receivables_two_shops_isolated(): void
    {
        [, $other] = $this->createRetailerTenant();
        $oc = $this->createCustomer($other->id);
        $this->invoice($other->id, $oc->id, 777, '2026-03-01 10:00:00');
        $this->assertSame(0.0, $this->snap()['receivables']['total']);
        $this->assertSame(777.0, $this->snap($other->id)['receivables']['total']);
    }

    // ───────── cash and bank ─────────

    public function test_cash_and_bank_agrees_with_cash_book_and_respects_the_cutoff(): void
    {
        $s = $this->shop->id;
        $this->cash($s, 'in', 1000, 'cash', '2026-03-10 10:00:00');
        $this->cash($s, 'out', 100, 'cash', '2026-03-31 23:59:30');
        $this->cash($s, 'in', 700, 'cash', '2026-04-01 00:00:00');   // next day
        $this->cash($s, 'in', 500, 'upi', '2026-03-31 12:00:00');
        $this->cash($s, 'in', 50, null, '2026-03-05 12:00:00');       // NULL mode = cash
        $this->cash($s, 'in', 2000, 'bank', '2026-03-01 00:00:00', true); // opening row

        $c = $this->snap()['cash_and_bank'];
        $modes = array_column($c['by_mode'], 'balance', 'mode');
        $this->assertSame(950.0, $modes['cash']);
        $this->assertSame(500.0, $modes['upi']);
        $this->assertSame(2000.0, $modes['bank']);
        $this->assertSame(3450.0, $c['total']);

        $book = app(LedgerService::class)->cashFlowByMode($s, ReportPeriod::range(self::D, self::D));
        $this->assertSame($book->totalClosing, $c['total']);
        foreach ($book->modes as $m) {
            $this->assertSame($m->closing, $modes[$m->mode]);
        }
    }

    public function test_cash_by_account_uses_payment_method(): void
    {
        $s = $this->shop->id;
        $pm = (int) DB::table('shop_payment_methods')->insertGetId([
            'shop_id' => $s, 'type' => 'bank', 'name' => 'HDFC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cash($s, 'in', 900, 'bank', '2026-03-02 10:00:00', false, $pm);
        $this->cash($s, 'in', 100, 'bank', '2026-04-02 10:00:00', false, $pm);
        $acc = $this->snap()['cash_and_bank']['by_account'];
        $this->assertSame('HDFC', $acc[0]['label']);
        $this->assertSame($pm, $acc[0]['payment_method_id']);
        $this->assertSame(900.0, $acc[0]['balance']);
    }

    // ───────── store credit ─────────

    public function test_store_credit_per_customer_as_at(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $ins = fn (float $amt, string $at) => DB::table('store_credit_movements')->insert([
            'shop_id' => $this->shop->id, 'customer_id' => $c->id, 'amount' => $amt, 'source_type' => 'manual_adjustment', 'user_id' => $this->userId,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $ins(1000, '2026-03-05 10:00:00');
        $ins(-300, '2026-03-31 23:59:00');
        $ins(-200, '2026-04-01 00:00:00');
        $sc = $this->snap()['store_credit'];
        $this->assertSame(700.0, $sc['total']);
        $this->assertSame($c->id, $sc['customers'][0]['customer_id']);
        $this->assertSame(500.0, $this->snap(null, '2026-11-16')['store_credit']['total']);
        $this->assertSame(1000.0, app(\App\Services\StoreCreditService::class)->balance($this->shop->id, $c->id) + 500.0);
    }

    // ───────── customer gold ─────────

    public function test_customer_gold_by_customer_and_purity(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $ins = fn (float $fine, float $gross, float $purity, string $at) => DB::table('customer_gold_transactions')->insert([
            'shop_id' => $this->shop->id, 'customer_id' => $c->id, 'fine_gold' => $fine, 'gross_weight' => $gross, 'purity' => $purity,
            'type' => 'adjust', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $ins(9.166, 10, 91.66, '2026-03-01 10:00:00');
        $ins(5.5, 5.5, 100, '2026-03-31 23:00:00');
        $ins(3, 3, 100, '2026-04-01 00:30:00');
        $g = $this->snap()['customer_gold'];
        $this->assertSame(14.666, $g['fine_gold_total']);
        $this->assertSame(14.666, $g['customers'][0]['fine_gold']);
        $this->assertCount(2, $g['by_purity']);
    }

    // ───────── scheme liability ─────────

    public function test_scheme_liability_uses_last_ledger_balance_on_or_before_date(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $s = $this->shop->id;
        $schemeId = (int) DB::table('schemes')->insertGetId(['shop_id' => $s, 'name' => 'S', 'type' => 'gold_savings', 'start_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $en = (int) DB::table('scheme_enrollments')->insertGetId([
            'shop_id' => $s, 'scheme_id' => $schemeId, 'customer_id' => $c->id, 'start_date' => '2026-01-01', 'monthly_amount' => 1000,
            'total_paid' => 0, 'installments_paid' => 0, 'total_installments' => 11, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $led = fn (string $dir, float $amt, float $bal, string $at) => DB::table('scheme_ledger_entries')->insert([
            'shop_id' => $s, 'scheme_enrollment_id' => $en, 'entry_type' => 'x', 'direction' => $dir, 'amount' => $amt,
            'balance_after' => $bal, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $led('credit', 1000, 1000, '2026-02-01 10:00:00');
        $led('credit', 1000, 2000, '2026-03-31 23:59:00');
        $led('debit', 2000, 0, '2026-04-10 10:00:00');   // redeemed after the date
        $sl = $this->snap()['scheme_liability'];
        $this->assertSame(2000.0, $sl['total']);
        $this->assertSame($en, $sl['enrollments'][0]['enrollment_id']);
        $this->assertSame(0.0, $this->snap(null, '2026-11-16')['scheme_liability']['total']);
        $this->assertSame(0.0, app(ReceivablesService::class)->schemeLiability($s)->totalLiability); // report agrees "now" (status active, balance 0)
    }

    // ───────── EMI ─────────

    public function test_emi_outstanding_as_at_and_default_write_off(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $s = $this->shop->id;
        $plan = fn (float $payable, string $at, string $status = 'active') => (int) DB::table('installment_plans')->insertGetId([
            'shop_id' => $s, 'customer_id' => $c->id, 'invoice_id' => $this->invoice($s, $c->id, $payable, $at), 'total_amount' => $payable, 'down_payment' => 0, 'remaining_amount' => $payable,
            'emi_amount' => $payable / 4, 'total_emis' => 4, 'emis_paid' => 0, 'status' => $status, 'principal_amount' => $payable,
            'interest_amount' => 0, 'total_payable' => $payable, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $pp = fn (int $p, float $amt, string $date) => DB::table('installment_payments')->insert([
            'shop_id' => $s, 'plan_id' => $p, 'amount' => $amt, 'payment_date' => $date, 'payment_method' => 'cash', 'created_at' => $date, 'updated_at' => $date,
        ]);
        $p1 = $plan(4000, '2026-02-01 10:00:00');
        $pp($p1, 1000, '2026-03-01');
        $pp($p1, 1000, '2026-03-31');
        $pp($p1, 1000, '2026-04-01');
        $plan(8000, '2026-04-02 10:00:00');                 // created after
        $p3 = $plan(3000, '2026-01-01 10:00:00', 'defaulted');
        DB::table('audit_logs')->insert(['shop_id' => $s, 'action' => 'installment_plan_defaulted', 'model_type' => 'installment_plan', 'model_id' => $p3, 'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00']);
        $p4 = $plan(500, '2026-01-01 10:00:00', 'defaulted'); // defaulted after the date: still owed then
        DB::table('audit_logs')->insert(['shop_id' => $s, 'action' => 'installment_plan_defaulted', 'model_type' => 'installment_plan', 'model_id' => $p4, 'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 10:00:00']);

        $e = $this->snap()['emi_outstanding'];
        $this->assertSame(2500.0, $e['total']);             // 2000 + 500
        $this->assertTrue($e['included_in_receivables']);
        // Rule parity: owedOn (all payments to date) is 1000; the as-at figure adds back the 1 April payment.
        $this->assertSame(1000.0, TenantContext::runFor($s, fn () => InstallmentService::owedOn(\App\Models\InstallmentPlan::find($p1))));
        $this->assertSame(1000.0, collect($e['plans'])->firstWhere('plan_id', $p1)['amount'] - 1000.0);
    }

    // ───────── karigar ─────────

    public function test_karigar_money_and_held_metal_as_at(): void
    {
        $s = $this->shop->id;
        $k = (int) DB::table('karigars')->insertGetId([
            'shop_id' => $s, 'name' => 'Ramesh', 'opening_balance' => 500, 'opening_balance_at' => '2026-01-01', 'is_active' => DB::raw('true'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inv = fn (float $amt, string $date) => (int) DB::table('karigar_invoices')->insertGetId([
            'shop_id' => $s, 'karigar_id' => $k, 'mode' => 'job_work', 'karigar_invoice_number' => 'K'.uniqid(), 'karigar_invoice_date' => $date,
            'total_after_tax' => $amt, 'created_at' => $date, 'updated_at' => $date,
        ]);
        $i1 = $inv(2000, '2026-03-10');
        $inv(900, '2026-04-02');
        $pay = fn (float $amt, string $date) => DB::table('karigar_payments')->insert([
            'shop_id' => $s, 'karigar_id' => $k, 'karigar_invoice_id' => $i1, 'amount' => $amt, 'mode' => 'cash', 'paid_on' => $date,
            'created_at' => $date, 'updated_at' => $date,
        ]);
        $pay(800, '2026-03-31');
        $pay(300, '2026-04-01');

        $kar = $this->snap()['karigar'];
        $row = $kar['karigars'][0];
        $this->assertSame($k, $row['karigar_id']);
        $this->assertSame(1700.0, $row['money']['payable']);   // 500 + 2000 - 800
        $this->assertSame(1700.0, $kar['total_payable']);
        $this->assertSame(1700.0 - 300.0 + 900.0 - 0.0, $this->snap(null, '2026-11-16')['karigar']['total_payable']);

        // Held metal: movements into a karigar_held lot.
        $lot = (int) DB::table('metal_lots')->insertGetId([
            'shop_id' => $s, 'karigar_id' => $k, 'source' => 'karigar_held', 'metal_type' => 'gold', 'purity' => 91.6,
            'fine_weight_total' => 0, 'fine_weight_remaining' => 0, 'cost_per_fine_gram' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mv = fn (float $f, string $at) => DB::table('metal_movements')->insert([
            'shop_id' => $s, 'from_lot_id' => null, 'to_lot_id' => $lot, 'fine_weight' => $f, 'type' => 'karigar_retained',
            'metal_type' => 'gold', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $mv(5, '2026-03-20 10:00:00');
        $mv(7, '2026-04-20 10:00:00');
        $m = $this->snap()['karigar']['karigars'][0]['metal'];
        $this->assertSame([['metal_type' => 'gold', 'fine_weight' => 5.0]], $m);
    }

    public function test_karigar_open_job_order_metal_straddling_the_date(): void
    {
        $s = $this->shop->id;
        $k = (int) DB::table('karigars')->insertGetId(['shop_id' => $s, 'name' => 'K', 'opening_balance' => 0, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        $job = fn (string $no, float $fine, string $issued, ?string $completed) => (int) DB::table('job_orders')->insertGetId([
            'shop_id' => $s, 'karigar_id' => $k, 'job_order_number' => $no, 'metal_type' => 'gold', 'purity' => 91.6,
            'issued_gross_weight' => $fine, 'issued_fine_weight' => $fine, 'status' => $completed ? 'completed' : 'issued',
            'issue_date' => $issued, 'completed_at' => $completed, 'job_type' => 'manufacture', 'created_at' => $issued, 'updated_at' => $issued,
        ]);
        $j1 = $job('J1', 10, '2026-03-01', null);
        $job('J2', 4, '2026-03-02', '2026-03-20 10:00:00');   // closed before: 0
        $job('J3', 6, '2026-03-03', '2026-04-10 10:00:00');   // closed after: still out
        $job('J4', 9, '2026-04-02', null);                    // issued after
        DB::table('job_order_receipts')->insert(['shop_id' => $s, 'job_order_id' => $j1, 'receipt_number' => 'R1', 'receipt_date' => '2026-03-15', 'total_fine_weight' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_order_receipts')->insert(['shop_id' => $s, 'job_order_id' => $j1, 'receipt_number' => 'R2', 'receipt_date' => '2026-04-15', 'total_fine_weight' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $m = $this->snap()['karigar']['karigars'][0]['metal'];
        $this->assertSame('gold', $m[0]['metal_type']);
        $this->assertSame(13.0, $m[0]['fine_weight']);       // 7 + 6
    }

    // ───────── suppliers, opening balances ─────────

    public function test_supplier_purchases_and_opening_balances(): void
    {
        $s = $this->shop->id;
        $v = (int) DB::table('vendors')->insertGetId(['shop_id' => $s, 'name' => 'Sup', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        $p = fn (float $amt, string $date, string $status) => DB::table('stock_purchases')->insert([
            'shop_id' => $s, 'vendor_id' => $v, 'purchase_number' => 'P'.uniqid(), 'purchase_date' => $date, 'status' => $status,
            'total_amount' => $amt, 'created_at' => $date, 'updated_at' => $date,
        ]);
        $p(1000, '2026-03-10', 'stocked');
        $p(500, '2026-03-31', 'confirmed');
        $p(300, '2026-03-12', 'draft');
        $p(800, '2026-04-01', 'stocked');
        $b = (int) DB::table('onboarding_batches')->insertGetId(['shop_id' => $s, 'as_of_date' => '2026-01-01', 'start_date' => '2026-01-01', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_opening_balances')->insert(['shop_id' => $s, 'vendor_id' => $v, 'onboarding_batch_id' => $b, 'direction' => 'payable', 'amount' => 250, 'as_of_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_opening_balances')->insert(['shop_id' => $s, 'vendor_id' => $v, 'onboarding_batch_id' => $b, 'direction' => 'payable', 'amount' => 999, 'as_of_date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->createCustomer($s);
        DB::table('customer_opening_balances')->insert(['shop_id' => $s, 'customer_id' => $c->id, 'onboarding_batch_id' => $b, 'direction' => 'receivable', 'amount' => 600, 'as_of_date' => '2026-03-31', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_opening_balances')->insert(['shop_id' => $s, 'customer_id' => $c->id, 'onboarding_batch_id' => $b, 'direction' => 'payable', 'amount' => 40, 'as_of_date' => '2026-03-31', 'created_at' => now(), 'updated_at' => now()]);

        $snap = $this->snap();
        $sp = $snap['supplier_payables'];
        $this->assertFalse($sp['payments_tracked']);
        $this->assertSame(1500.0, $sp['vendors'][0]['purchases_total']);
        $this->assertSame(250.0, $sp['vendors'][0]['opening_payable']);
        $ob = $snap['opening_balances'];
        $this->assertSame(250.0, $ob['supplier_payable_total']);
        $this->assertSame(600.0, $ob['customer_receivable_total']);
        $this->assertSame(40.0, $ob['customer_payable_total']);
    }

    // ───────── loyalty ─────────

    public function test_loyalty_points_and_value(): void
    {
        $c = $this->createCustomer($this->shop->id);
        $ins = fn (string $type, int $pts, string $at) => DB::table('loyalty_transactions')->insert([
            'shop_id' => $this->shop->id, 'customer_id' => $c->id, 'type' => $type, 'points' => $pts, 'balance_after' => 0,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $ins('earn', 100, '2026-03-01 10:00:00');
        $ins('redeem', 30, '2026-03-31 23:59:00');
        $ins('earn', 50, '2026-04-01 00:00:00');
        $l = $this->snap()['loyalty_points'];
        $this->assertSame(70, $l['total_points']);
        $this->assertSame(round(app(\App\Services\LoyaltyService::class)->pointsToRupees(70, $this->shop->id), 2), $l['total_value']);
        $this->assertSame(120, $this->snap(null, '2026-11-16')['loyalty_points']['total_points']);
    }

    // ───────── dhiran ─────────

    public function test_dhiran_principal_and_booked_interest_as_at(): void
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9990000000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $svc = app(DhiranService::class);

        $this->at('2026-03-01 10:00:00');
        $loan = TenantContext::runFor($shop->id, function () use ($shop, $svc) {
            $cust = Customer::create(['shop_id' => $shop->id, 'first_name' => 'C', 'last_name' => 'D', 'mobile' => '98'.fake()->unique()->numerify('########')]);

            return $svc->createLoan($shop, $cust, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
            ]], [
                'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2.0, 'interest_type' => 'flat',
                'penalty_rate_monthly' => 0, 'tenure_months' => 12, 'min_lock_months' => 0, 'min_interest_months' => 0,
                'loan_date' => '2026-03-01', 'created_by' => null,
            ]);
        });
        $this->at('2026-03-31 20:00:00');
        TenantContext::runFor($shop->id, fn () => $svc->accrueInterest($loan->fresh()));
        $this->at('2026-04-20 12:00:00');
        TenantContext::runFor($shop->id, fn () => $svc->accrueInterest($loan->fresh()));
        $this->at('2026-11-16 12:00:00');

        $d = $this->snap($shop->id)['dhiran'];
        $this->assertNotNull($d);
        $this->assertSame(100000.0, $d['total_principal']);
        $this->assertSame(2000.0, $d['total_interest']);       // 30 days at 2%/month, booked by 31 March
        $this->assertSame($loan->id, $d['loans'][0]['loan_id']);
        $now = $this->snap($shop->id, '2026-11-16')['dhiran'];
        $this->assertGreaterThan(2000.0, $now['total_interest']);
        $this->assertSame((float) $loan->fresh()->outstanding_interest, $this->snap($shop->id, '2026-04-20')['dhiran']['total_interest']);
        $this->assertNull($this->snap()['dhiran']);              // the retail shop still has none
    }
}
