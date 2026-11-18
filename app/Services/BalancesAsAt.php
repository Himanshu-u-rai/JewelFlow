<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * READ-ONLY. States a shop's money and metal balances at 23:59:59 IST (Asia/Kolkata) on a past date, from the
 * append-only ledgers and document dates the reports use. Never writes. Money = floats rounded to 2 decimals,
 * weights = floats rounded to 3 decimals. Timestamp columns (created_at) are compared < start of the next IST day;
 * date columns (invoice/EMI/karigar/opening dates) are compared <= $date.
 *
 * forShop(int $shopId, string 'Y-m-d'): array
 *   shop_id, as_at, basis
 *   receivables      {total, customers:[{customer_id|null, name, amount}]}  finalized invoices (accounting date
 *                    COALESCE(finalized_at, created_at)) minus invoice_payments, only positive dues; rule of
 *                    ReceivablesService::duesAging. An invoice cancelled AFTER the date still counts as it stood.
 *                    Credit notes are not netted (the dues report does not). Excludes opening_balances.
 *   cash_and_bank    {total, by_mode:[{mode, balance}], by_account:[{payment_method_id, label, balance}]}
 *                    cash_transactions (in - out), rule of LedgerService::cashFlowByMode, opening rows included.
 *   store_credit     {total, customers:[{customer_id, name, amount}]}   store_credit_movements sum
 *   customer_gold    {fine_gold_total, customers:[{customer_id, name, fine_gold, gross_weight}],
 *                     by_purity:[{purity, fine_gold, gross_weight}]}     customer_gold_transactions sum (gold only)
 *   scheme_liability {total, enrollments:[{enrollment_id, customer_id, balance}]}  last scheme_ledger_entries
 *                    balance_after on or before the date (rule of ReceivablesService::schemeLiability)
 *   emi_outstanding  {total, included_in_receivables:true, plans:[{plan_id, customer_id, invoice_id, amount}]}
 *                    total_payable - installment_payments (payment_date <= date) as InstallmentService::owedOn;
 *                    plans defaulted on/before the date are written off (audit_logs) and excluded.
 *                    OVERLAPS receivables (the plan's invoice is also a due): do not add the two.
 *   karigar          {karigars:[{karigar_id, name, metal:[{metal_type, fine_weight}], money:{opening, invoiced,
 *                     paid, payable}}], total_payable}
 *                    metal = open job orders (issued - receipts to date; a job completed on/before the date is 0,
 *                    leftover/wastage only realised at completion) + karigar_held lots via metal_movements.
 *                    money = karigars.opening_balance (if opening_balance_at <= date) + karigar_invoices
 *                    (karigar_invoice_date) - karigar_payments (paid_on; reversals are stored negative), the
 *                    Karigar::outstanding_balance rule.
 *   supplier_payables {payments_tracked:false, vendors:[{vendor_id, name, purchases_total, opening_payable,
 *                     opening_receivable}], purchases_total}  the app records NO payments to suppliers, so
 *                     "owed" cannot be stated; purchases = confirmed/stocked stock_purchases (purchase_date).
 *   loyalty_points   {total_points, total_value, customers:[{customer_id, name, points}]}  earn - redeem, value
 *                    at the shop's current point value (LoyaltyService::pointsToRupees)
 *   dhiran           null for a shop without Dhiran, else {total_principal, total_interest, total_penalty,
 *                    interest_collected_to_date, loans:[{loan_id, loan_number, customer_id, status, principal,
 *                    interest, penalty}]}  last dhiran_ledger_entries balances on or before the date: interest
 *                    is what was BOOKED by then (accrual is lazy), not recomputed to the date.
 *   opening_balances {customers:[{customer_id, name, receivable, payable}], suppliers:[{vendor_id, name,
 *                    receivable, payable}], customer_receivable_total, customer_payable_total,
 *                    supplier_receivable_total, supplier_payable_total}  as_of_date <= date
 */
final class BalancesAsAt
{
    private string $date;

    private string $endExclusive;

    private int $shopId;

    public function forShop(int $shopId, string $date): array
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Kolkata');
        if (! $d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Date must be Y-m-d, got '{$date}'.");
        }
        $this->shopId = $shopId;
        $this->date = $date;
        $this->endExclusive = $d->addDay()->format('Y-m-d H:i:s');

        return [
            'shop_id' => $shopId,
            'as_at' => $date,
            'basis' => 'end of day Asia/Kolkata',
            'receivables' => $this->receivables(),
            'cash_and_bank' => $this->cashAndBank(),
            'store_credit' => $this->storeCredit(),
            'customer_gold' => $this->customerGold(),
            'scheme_liability' => $this->schemeLiability(),
            'emi_outstanding' => $this->emi(),
            'karigar' => $this->karigar(),
            'supplier_payables' => $this->supplierPayables(),
            'loyalty_points' => $this->loyalty(),
            'dhiran' => $this->dhiran(),
            'opening_balances' => $this->openingBalances(),
        ];
    }

    private static function m($v): float
    {
        return round((float) $v, 2);
    }

    private static function w($v): float
    {
        return round((float) $v, 3);
    }

    /** @return array<int,string> customer id => display name */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return DB::table('customers')->where('shop_id', $this->shopId)->whereIn('id', $ids)
            ->selectRaw("id, COALESCE(NULLIF(TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')), ''), 'Walk-in') as n")
            ->pluck('n', 'id')->all();
    }

    private function receivables(): array
    {
        $invoices = DB::table('invoices')->where('shop_id', $this->shopId)
            ->whereRaw('COALESCE(finalized_at, created_at) < ?', [$this->endExclusive])
            ->where(fn ($q) => $q->where('status', 'finalized')
                ->orWhere(fn ($c) => $c->where('status', 'cancelled')->whereNotNull('finalized_at')->where('cancelled_at', '>=', $this->endExclusive)))
            ->get(['id', 'customer_id', 'total']);

        $paid = $invoices->isEmpty() ? collect() : DB::table('invoice_payments')
            ->where('shop_id', $this->shopId)->whereIn('invoice_id', $invoices->pluck('id')->all())
            ->where('created_at', '<', $this->endExclusive)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) as s')->pluck('s', 'invoice_id');

        $by = [];
        foreach ($invoices as $inv) {
            $out = round((float) $inv->total - round((float) ($paid[$inv->id] ?? 0), 2), 2);
            if ($out <= 0.01) {
                continue;
            }
            $key = $inv->customer_id ?? 0;
            $by[$key] = ($by[$key] ?? 0) + $out;
        }
        $names = $this->names(array_keys($by));
        $rows = [];
        foreach ($by as $cid => $amt) {
            $rows[] = ['customer_id' => $cid ?: null, 'name' => $cid ? ($names[$cid] ?? 'Unknown') : 'Walk-in', 'amount' => self::m($amt)];
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return ['total' => self::m(array_sum(array_column($rows, 'amount'))), 'customers' => $rows];
    }

    private function cashAndBank(): array
    {
        $base = fn () => DB::table('cash_transactions')->where('shop_id', $this->shopId)->where('created_at', '<', $this->endExclusive);
        $net = "COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0)";
        $modeExpr = "COALESCE(NULLIF(TRIM(payment_mode), ''), 'cash')";

        $modes = $base()->selectRaw("$modeExpr as mode, $net as b")->groupBy(DB::raw($modeExpr))->orderBy('mode')->get()
            ->map(fn ($r) => ['mode' => $r->mode, 'balance' => self::m($r->b)])->all();

        $accts = $base()->whereNotNull('payment_method_id')
            ->selectRaw("payment_method_id, MAX(payment_method_label_snapshot) as label, $net as b")
            ->groupBy('payment_method_id')->orderBy('payment_method_id')->get()
            ->map(fn ($r) => ['payment_method_id' => (int) $r->payment_method_id, 'label' => $r->label, 'balance' => self::m($r->b)])->all();

        return ['total' => self::m(array_sum(array_column($modes, 'balance'))), 'by_mode' => $modes, 'by_account' => $accts];
    }

    private function storeCredit(): array
    {
        $rows = DB::table('store_credit_movements')->where('shop_id', $this->shopId)->where('created_at', '<', $this->endExclusive)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount) as s')->orderBy('customer_id')->get()
            ->filter(fn ($r) => abs((float) $r->s) >= 0.005)->values();
        $names = $this->names($rows->pluck('customer_id')->all());
        $out = $rows->map(fn ($r) => ['customer_id' => (int) $r->customer_id, 'name' => $names[$r->customer_id] ?? 'Unknown', 'amount' => self::m($r->s)])->all();

        return ['total' => self::m(array_sum(array_column($out, 'amount'))), 'customers' => $out];
    }

    private function customerGold(): array
    {
        $q = fn () => DB::table('customer_gold_transactions')->where('shop_id', $this->shopId)->where('created_at', '<', $this->endExclusive);
        $rows = $q()->groupBy('customer_id')->selectRaw('customer_id, SUM(fine_gold) as f, SUM(gross_weight) as g')->orderBy('customer_id')->get()
            ->filter(fn ($r) => abs((float) $r->f) >= 0.0005)->values();
        $names = $this->names($rows->pluck('customer_id')->all());
        $cust = $rows->map(fn ($r) => ['customer_id' => (int) $r->customer_id, 'name' => $names[$r->customer_id] ?? 'Unknown', 'fine_gold' => self::w($r->f), 'gross_weight' => self::w($r->g)])->all();
        $purity = $q()->groupBy('purity')->selectRaw('purity, SUM(fine_gold) as f, SUM(gross_weight) as g')->orderBy('purity')->get()
            ->filter(fn ($r) => abs((float) $r->f) >= 0.0005)
            ->map(fn ($r) => ['purity' => (float) $r->purity, 'fine_gold' => self::w($r->f), 'gross_weight' => self::w($r->g)])->values()->all();

        return ['fine_gold_total' => self::w(array_sum(array_column($cust, 'fine_gold'))), 'customers' => $cust, 'by_purity' => $purity];
    }

    private function schemeLiability(): array
    {
        $rows = DB::table('scheme_ledger_entries as e')->join('scheme_enrollments as se', 'se.id', '=', 'e.scheme_enrollment_id')
            ->where('e.shop_id', $this->shopId)
            ->whereIn('e.id', fn ($q) => $q->from('scheme_ledger_entries')->where('shop_id', $this->shopId)
                ->where('created_at', '<', $this->endExclusive)->selectRaw('MAX(id)')->groupBy('scheme_enrollment_id'))
            ->where('e.balance_after', '>', 0)->orderBy('e.scheme_enrollment_id')
            ->get(['e.scheme_enrollment_id', 'se.customer_id', 'e.balance_after'])
            ->map(fn ($r) => ['enrollment_id' => (int) $r->scheme_enrollment_id, 'customer_id' => (int) $r->customer_id, 'balance' => self::m($r->balance_after)])->all();

        return ['total' => self::m(array_sum(array_column($rows, 'balance'))), 'enrollments' => $rows];
    }

    private function emi(): array
    {
        $plans = DB::table('installment_plans')->where('shop_id', $this->shopId)->where('created_at', '<', $this->endExclusive)->orderBy('id')->get();
        $paid = $plans->isEmpty() ? collect() : DB::table('installment_payments')->where('shop_id', $this->shopId)
            ->whereIn('plan_id', $plans->pluck('id')->all())->whereRaw('payment_date <= ?', [$this->date])
            ->groupBy('plan_id')->selectRaw('plan_id, SUM(amount) as s')->pluck('s', 'plan_id');
        $defaulted = DB::table('audit_logs')->where('shop_id', $this->shopId)->where('action', 'installment_plan_defaulted')
            ->where('created_at', '<', $this->endExclusive)->pluck('model_id')->map(fn ($v) => (int) $v)->all();

        $out = [];
        foreach ($plans as $p) {
            if (in_array((int) $p->id, $defaulted, true)) {
                continue;
            }
            $owed = round(max(0, (float) $p->total_payable - (float) ($paid[$p->id] ?? 0)), 2);
            if ($owed > 0) {
                $out[] = ['plan_id' => (int) $p->id, 'customer_id' => (int) $p->customer_id, 'invoice_id' => $p->invoice_id ? (int) $p->invoice_id : null, 'amount' => $owed];
            }
        }

        return ['total' => self::m(array_sum(array_column($out, 'amount'))), 'included_in_receivables' => true, 'plans' => $out];
    }

    private function karigar(): array
    {
        $s = $this->shopId;
        $ks = DB::table('karigars')->where('shop_id', $s)->orderBy('id')->get(['id', 'name', 'opening_balance', 'opening_balance_at'])->keyBy('id');

        // Open jobs: issued - receipts to date; closed on/before date => 0.
        $jobs = DB::table('job_orders as jo')->where('jo.shop_id', $s)->whereRaw('jo.issue_date <= ?', [$this->date])
            ->where(fn ($q) => $q->whereNull('jo.completed_at')->orWhere('jo.completed_at', '>=', $this->endExclusive))
            ->whereNotNull('jo.karigar_id')
            ->selectRaw("jo.karigar_id, COALESCE(jo.metal_type, 'unknown') as metal_type, jo.issued_fine_weight - COALESCE((SELECT SUM(r.total_fine_weight) FROM job_order_receipts r WHERE r.job_order_id = jo.id AND r.receipt_date <= ?), 0) as out", [$this->date])
            ->get();
        $metal = [];
        foreach ($jobs as $j) {
            $metal[$j->karigar_id][$j->metal_type] = ($metal[$j->karigar_id][$j->metal_type] ?? 0) + max(0, (float) $j->out);
        }
        // Held (retained) metal: movements into/out of karigar_held lots.
        $held = DB::select("
            SELECT l.karigar_id, COALESCE(l.metal_type, 'unknown') AS metal_type,
                   COALESCE(SUM(CASE WHEN m.to_lot_id = l.id THEN m.fine_weight ELSE 0 END) - SUM(CASE WHEN m.from_lot_id = l.id THEN m.fine_weight ELSE 0 END), 0) AS fine
            FROM metal_lots l JOIN metal_movements m ON (m.to_lot_id = l.id OR m.from_lot_id = l.id)
            WHERE l.shop_id = ? AND l.source = 'karigar_held' AND m.shop_id = ? AND m.created_at < ?
            GROUP BY l.karigar_id, l.metal_type", [$s, $s, $this->endExclusive]);
        foreach ($held as $h) {
            $metal[$h->karigar_id][$h->metal_type] = ($metal[$h->karigar_id][$h->metal_type] ?? 0) + (float) $h->fine;
        }

        $inv = DB::table('karigar_invoices')->where('shop_id', $s)->whereRaw('karigar_invoice_date <= ?', [$this->date])
            ->groupBy('karigar_id')->selectRaw('karigar_id, SUM(total_after_tax) as t')->pluck('t', 'karigar_id');
        // Same sign rule as Karigar::payable (opening + invoiced - paid): every payment row counts by its amount.
        $pay = DB::table('karigar_payments')->where('shop_id', $s)->whereRaw('paid_on <= ?', [$this->date])
            ->groupBy('karigar_id')->selectRaw('karigar_id, SUM(amount) as t')->pluck('t', 'karigar_id');

        $rows = [];
        $total = 0.0;
        foreach ($ks as $id => $k) {
            $opening = ($k->opening_balance_at === null || $k->opening_balance_at <= $this->date) ? (float) $k->opening_balance : 0.0;
            $invoiced = (float) ($inv[$id] ?? 0);
            $paid = (float) ($pay[$id] ?? 0);
            $payable = round($opening + $invoiced - $paid, 2);
            $m = [];
            foreach ($metal[$id] ?? [] as $type => $f) {
                if (abs($f) >= 0.0005) {
                    $m[] = ['metal_type' => $type, 'fine_weight' => self::w($f)];
                }
            }
            if ($m === [] && abs($payable) < 0.005 && $invoiced == 0.0 && $paid == 0.0) {
                continue;
            }
            $total += $payable;
            $rows[] = ['karigar_id' => (int) $id, 'name' => $k->name, 'metal' => $m,
                'money' => ['opening' => self::m($opening), 'invoiced' => self::m($invoiced), 'paid' => self::m($paid), 'payable' => $payable]];
        }

        return ['karigars' => $rows, 'total_payable' => self::m($total)];
    }

    private function supplierPayables(): array
    {
        $s = $this->shopId;
        $buy = DB::table('stock_purchases')->where('shop_id', $s)->whereIn('status', ['confirmed', 'stocked'])
            ->whereRaw('purchase_date <= ?', [$this->date])->whereNotNull('vendor_id')
            ->groupBy('vendor_id')->selectRaw('vendor_id, SUM(total_amount) as t')->pluck('t', 'vendor_id');
        $open = $this->supplierOpening();
        $ids = array_unique(array_merge($buy->keys()->all(), array_keys($open)));
        sort($ids);
        $names = $ids ? DB::table('vendors')->where('shop_id', $s)->whereIn('id', $ids)->pluck('name', 'id') : collect();
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['vendor_id' => (int) $id, 'name' => $names[$id] ?? 'Unknown', 'purchases_total' => self::m($buy[$id] ?? 0),
                'opening_payable' => self::m($open[$id]['payable'] ?? 0), 'opening_receivable' => self::m($open[$id]['receivable'] ?? 0)];
        }

        return ['payments_tracked' => false, 'vendors' => $rows, 'purchases_total' => self::m(array_sum(array_column($rows, 'purchases_total')))];
    }

    private function supplierOpening(): array
    {
        $o = [];
        foreach (DB::table('supplier_opening_balances')->where('shop_id', $this->shopId)->whereRaw('as_of_date <= ?', [$this->date])->get() as $r) {
            $o[$r->vendor_id][$r->direction] = ($o[$r->vendor_id][$r->direction] ?? 0) + (float) $r->amount;
        }

        return $o;
    }

    private function loyalty(): array
    {
        $rows = DB::table('loyalty_transactions')->where('shop_id', $this->shopId)->where('created_at', '<', $this->endExclusive)
            ->groupBy('customer_id')->selectRaw("customer_id, SUM(CASE WHEN type = 'earn' THEN points ELSE -points END) as p")->orderBy('customer_id')->get()
            ->filter(fn ($r) => (int) $r->p !== 0)->values();
        $names = $this->names($rows->pluck('customer_id')->all());
        $out = $rows->map(fn ($r) => ['customer_id' => (int) $r->customer_id, 'name' => $names[$r->customer_id] ?? 'Unknown', 'points' => (int) $r->p])->all();
        $total = array_sum(array_column($out, 'points'));

        return ['total_points' => $total, 'total_value' => self::m(app(LoyaltyService::class)->pointsToRupees($total, $this->shopId)), 'customers' => $out];
    }

    private function dhiran(): ?array
    {
        $s = $this->shopId;
        $has = DB::table('shop_editions')->where('shop_id', $s)->where('edition', 'dhiran')->whereNull('deactivated_at')->exists()
            || DB::table('dhiran_loans')->where('shop_id', $s)->exists();
        if (! $has) {
            return null;
        }
        $rows = DB::table('dhiran_ledger_entries as e')->join('dhiran_loans as l', 'l.id', '=', 'e.dhiran_loan_id')
            ->where('e.shop_id', $s)
            ->whereIn('e.id', fn ($q) => $q->from('dhiran_ledger_entries')->where('shop_id', $s)->where('created_at', '<', $this->endExclusive)
                ->selectRaw('MAX(id)')->groupBy('dhiran_loan_id'))
            ->orderBy('l.id')
            ->get(['l.id', 'l.loan_number', 'l.customer_id', 'l.status', 'e.balance_after', 'e.interest_balance_after', 'e.penalty_balance_after', 'e.entry_type'])
            ->map(fn ($r) => ['loan_id' => (int) $r->id, 'loan_number' => $r->loan_number, 'customer_id' => (int) $r->customer_id,
                'status' => $r->status, 'principal' => self::m($r->balance_after), 'interest' => self::m($r->interest_balance_after), 'penalty' => self::m($r->penalty_balance_after)])
            ->filter(fn ($r) => $r['principal'] > 0 || $r['interest'] > 0 || $r['penalty'] > 0)->values()->all();
        $collected = DB::table('dhiran_payments')->where('shop_id', $s)->whereRaw('payment_date <= ?', [$this->date])->sum('interest_component');

        return [
            'total_principal' => self::m(array_sum(array_column($rows, 'principal'))),
            'total_interest' => self::m(array_sum(array_column($rows, 'interest'))),
            'total_penalty' => self::m(array_sum(array_column($rows, 'penalty'))),
            'interest_collected_to_date' => self::m($collected),
            'loans' => $rows,
        ];
    }

    private function openingBalances(): array
    {
        $s = $this->shopId;
        $c = [];
        foreach (DB::table('customer_opening_balances')->where('shop_id', $s)->whereRaw('as_of_date <= ?', [$this->date])->get() as $r) {
            $c[$r->customer_id][$r->direction] = ($c[$r->customer_id][$r->direction] ?? 0) + (float) $r->amount;
        }
        $cn = $this->names(array_keys($c));
        $cust = [];
        foreach ($c as $id => $d) {
            $cust[] = ['customer_id' => (int) $id, 'name' => $cn[$id] ?? 'Unknown', 'receivable' => self::m($d['receivable'] ?? 0), 'payable' => self::m($d['payable'] ?? 0)];
        }
        $o = $this->supplierOpening();
        $vn = $o ? DB::table('vendors')->where('shop_id', $s)->whereIn('id', array_keys($o))->pluck('name', 'id') : collect();
        $sup = [];
        foreach ($o as $id => $d) {
            $sup[] = ['vendor_id' => (int) $id, 'name' => $vn[$id] ?? 'Unknown', 'receivable' => self::m($d['receivable'] ?? 0), 'payable' => self::m($d['payable'] ?? 0)];
        }

        return [
            'customers' => $cust, 'suppliers' => $sup,
            'customer_receivable_total' => self::m(array_sum(array_column($cust, 'receivable'))),
            'customer_payable_total' => self::m(array_sum(array_column($cust, 'payable'))),
            'supplier_receivable_total' => self::m(array_sum(array_column($sup, 'receivable'))),
            'supplier_payable_total' => self::m(array_sum(array_column($sup, 'payable'))),
        ];
    }
}
