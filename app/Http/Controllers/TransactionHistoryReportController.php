<?php

namespace App\Http\Controllers;

use App\Models\ShopPaymentMethod;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionHistoryReportController extends Controller
{
    public function index(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        [$dateFrom, $dateTo, $resolvedPeriod] = $this->resolveDateRange($request);

        $flow = $request->input('flow', 'all');
        if (!in_array($flow, ['all', 'in', 'out'], true)) {
            $flow = 'all';
        }

        $search = mb_substr(trim((string) $request->input('q', '')), 0, 100);

        $union = $this->buildUnionQuery($shopId);

        $dateScoped = DB::query()
            ->fromSub($union, 'tx')
            ->when($dateFrom, fn (Builder $q) => $q->whereDate('txn_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $q) => $q->whereDate('txn_at', '<=', $dateTo));

        $availableTypes = (clone $dateScoped)
            ->select('txn_type')
            ->distinct()
            ->orderBy('txn_type')
            ->pluck('txn_type')
            ->filter()
            ->values();

        $availableModes = (clone $dateScoped)
            ->select('payment_mode')
            ->whereNotNull('payment_mode')
            ->where('payment_mode', '!=', '')
            ->distinct()
            ->orderBy('payment_mode')
            ->pluck('payment_mode')
            ->filter()
            ->values();

        $paymentMethods = ShopPaymentMethod::query()
            ->where('shop_id', $shopId)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        // Whitelist filters against known values to prevent arbitrary string probing
        $txnTypeRaw = trim((string) $request->input('txn_type', 'all'));
        $txnType = $availableTypes->contains($txnTypeRaw) ? $txnTypeRaw : 'all';

        $paymentModeRaw = trim((string) $request->input('payment_mode', 'all'));
        $paymentMode = $availableModes->contains($paymentModeRaw) ? $paymentModeRaw : 'all';

        $paymentMethodIdRaw = $request->filled('payment_method_id') ? (int) $request->input('payment_method_id') : null;
        $paymentMethodId = ($paymentMethodIdRaw && $paymentMethods->contains('id', $paymentMethodIdRaw))
            ? $paymentMethodIdRaw
            : null;

        $filtered = (clone $dateScoped)
            ->when($flow !== 'all', fn (Builder $q) => $q->where('flow', $flow))
            ->when($txnType !== '' && $txnType !== 'all', fn (Builder $q) => $q->where('txn_type', $txnType))
            ->when($paymentMode !== '' && $paymentMode !== 'all', fn (Builder $q) => $q->where('payment_mode', $paymentMode))
            ->when($paymentMethodId, fn (Builder $q) => $q->where('payment_method_id', $paymentMethodId))
            ->when($search !== '', function (Builder $q) use ($search) {
                $needle = '%' . strtolower($search) . '%';
                $q->where(function (Builder $inner) use ($needle) {
                    $inner->whereRaw("LOWER(COALESCE(reference_no, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(party_name, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(notes, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(txn_type, '')) LIKE ?", [$needle]);
                });
            });

        $totals = (clone $filtered)
            ->selectRaw("
                COUNT(*) as txn_count,
                COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) as total_out,
                COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE -amount END), 0) as net_total
            ")
            ->first();

        $byType = (clone $filtered)
            ->selectRaw("
                txn_type,
                COUNT(*) as txn_count,
                COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) as total_out
            ")
            ->groupBy('txn_type')
            ->orderByDesc('txn_count')
            ->limit(12)
            ->get();

        $byMode = (clone $filtered)
            ->selectRaw("
                payment_mode,
                COUNT(*) as txn_count,
                COALESCE(SUM(CASE WHEN flow = 'in' THEN amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN flow = 'out' THEN amount ELSE 0 END), 0) as total_out
            ")
            ->whereNotNull('payment_mode')
            ->where('payment_mode', '!=', '')
            ->groupBy('payment_mode')
            ->orderByDesc('txn_count')
            ->limit(12)
            ->get();

        $transactions = (clone $filtered)
            ->orderByDesc('txn_at')
            ->orderByDesc('source_key')
            ->paginate(30)
            ->withQueryString();

        return view('reports.transactions', [
            'transactions' => $transactions,
            'totals' => $totals,
            'byType' => $byType,
            'byMode' => $byMode,
            'paymentMethods' => $paymentMethods,
            'availableTypes' => $availableTypes,
            'availableModes' => $availableModes,
            'resolvedPeriod' => $resolvedPeriod,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'filters' => [
                'period' => $resolvedPeriod,
                'date_from' => $request->input('date_from', $dateFrom),
                'date_to' => $request->input('date_to', $dateTo),
                'period_month' => $request->input('period_month', ''),
                'period_year' => $request->input('period_year', now()->year),
                'period_quarter' => $request->input('period_quarter', '1'),
                'flow' => $flow,
                'txn_type' => $txnType,
                'payment_mode' => $paymentMode,
                'payment_method_id' => $paymentMethodId,
                'q' => $search,
            ],
        ]);
    }

    private function buildUnionQuery(int $shopId): Builder
    {
        $cashTransactions = DB::table('cash_transactions as ct')
            ->leftJoin('invoices as inv', 'inv.id', '=', 'ct.invoice_id')
            ->leftJoin('customers as cust', 'cust.id', '=', 'inv.customer_id')
            ->where('ct.shop_id', $shopId)
            ->selectRaw("
                ct.created_at as txn_at,
                CASE
                    WHEN LOWER(COALESCE(ct.type, '')) IN ('out', 'buyback', 'purchase', 'expense', 'disbursement') THEN 'out'
                    ELSE 'in'
                END as flow,
                ct.amount as amount,
                COALESCE(NULLIF(LOWER(ct.payment_mode), ''), 'cash') as payment_mode,
                NULL::bigint as payment_method_id,
                NULL::text as payment_method_name,
                COALESCE(NULLIF(ct.source_type, ''), 'cash_transaction') as txn_type,
                COALESCE(inv.invoice_number, ('CASH-' || ct.id::text)) as reference_no,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cust.first_name, ''), ' ', COALESCE(cust.last_name, ''))), ''), '-') as party_name,
                COALESCE(ct.description, '-') as notes,
                ('cash:' || ct.id::text) as source_key
            ");

        $quickBillPayments = DB::table('quick_bill_payments as qbp')
            ->join('quick_bills as qb', 'qb.id', '=', 'qbp.quick_bill_id')
            ->leftJoin('customers as cust', 'cust.id', '=', 'qb.customer_id')
            ->leftJoin('shop_payment_methods as spm', 'spm.id', '=', 'qbp.payment_method_id')
            ->where('qbp.shop_id', $shopId)
            ->where('qb.status', '!=', 'void')
            ->selectRaw("
                COALESCE(qbp.paid_at, qbp.created_at) as txn_at,
                'in' as flow,
                qbp.amount as amount,
                COALESCE(NULLIF(LOWER(qbp.payment_mode), ''), 'cash') as payment_mode,
                qbp.payment_method_id as payment_method_id,
                spm.name as payment_method_name,
                'quick_bill_payment' as txn_type,
                qb.bill_number as reference_no,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cust.first_name, ''), ' ', COALESCE(cust.last_name, ''))), ''), qb.customer_name, '-') as party_name,
                COALESCE(qbp.notes, qbp.reference_no, 'Quick bill payment') as notes,
                ('quickbill:' || qbp.id::text) as source_key
            ");

        $karigarPayments = DB::table('karigar_payments as kp')
            ->join('karigars as k', 'k.id', '=', 'kp.karigar_id')
            ->leftJoin('karigar_invoices as ki', 'ki.id', '=', 'kp.karigar_invoice_id')
            ->leftJoin('job_orders as jo', 'jo.id', '=', 'kp.job_order_id')
            ->leftJoin('shop_payment_methods as spm', 'spm.id', '=', 'kp.payment_method_id')
            ->where('kp.shop_id', $shopId)
            ->selectRaw("
                COALESCE(kp.paid_on::timestamp, kp.created_at) as txn_at,
                'out' as flow,
                kp.amount as amount,
                COALESCE(NULLIF(LOWER(kp.mode), ''), 'cash') as payment_mode,
                kp.payment_method_id as payment_method_id,
                spm.name as payment_method_name,
                CASE
                    WHEN kp.karigar_invoice_id IS NULL THEN 'karigar_advance'
                    ELSE 'karigar_payment'
                END as txn_type,
                COALESCE(ki.karigar_invoice_number, jo.job_order_number, ('KPAY-' || kp.id::text)) as reference_no,
                COALESCE(k.name, '-') as party_name,
                COALESCE(kp.notes, kp.reference, 'Karigar payment') as notes,
                ('karigar:' || kp.id::text) as source_key
            ");

        $stockPurchases = DB::table('stock_purchases as sp')
            ->leftJoin('vendors as v', 'v.id', '=', 'sp.vendor_id')
            ->where('sp.shop_id', $shopId)
            ->whereIn('sp.status', ['confirmed', 'stocked'])
            ->where('sp.total_amount', '>', 0)
            ->selectRaw("
                COALESCE(sp.stocked_at, sp.confirmed_at, sp.purchase_date::timestamp) as txn_at,
                'out' as flow,
                sp.total_amount as amount,
                NULL::text as payment_mode,
                NULL::bigint as payment_method_id,
                NULL::text as payment_method_name,
                'stock_purchase' as txn_type,
                COALESCE(NULLIF(sp.purchase_number, ''), NULLIF(sp.invoice_number, ''), ('PUR-' || sp.id::text)) as reference_no,
                COALESCE(v.name, sp.supplier_name, '-') as party_name,
                COALESCE(sp.notes, 'Stock purchase entry') as notes,
                ('stock:' || sp.id::text) as source_key
            ");

        $invoicePaymentsWithoutCashbook = DB::table('invoice_payments as ip')
            ->join('invoices as inv', 'inv.id', '=', 'ip.invoice_id')
            ->leftJoin('customers as cust', 'cust.id', '=', 'inv.customer_id')
            ->leftJoin('shop_payment_methods as spm', 'spm.id', '=', 'ip.payment_method_id')
            ->where('ip.shop_id', $shopId)
            ->whereIn('ip.mode', ['old_gold', 'old_silver', 'emi', 'scheme'])
            ->selectRaw("
                ip.created_at as txn_at,
                'in' as flow,
                ip.amount as amount,
                COALESCE(NULLIF(LOWER(ip.mode), ''), 'other') as payment_mode,
                ip.payment_method_id as payment_method_id,
                spm.name as payment_method_name,
                ('invoice_' || LOWER(ip.mode)) as txn_type,
                inv.invoice_number as reference_no,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cust.first_name, ''), ' ', COALESCE(cust.last_name, ''))), ''), '-') as party_name,
                COALESCE(ip.note, 'Invoice payment') as notes,
                ('invpay:' || ip.id::text) as source_key
            ");

        return $cashTransactions
            ->unionAll($quickBillPayments)
            ->unionAll($karigarPayments)
            ->unionAll($stockPurchases)
            ->unionAll($invoicePaymentsWithoutCashbook);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveDateRange(Request $request): array
    {
        $today = Carbon::today();
        $period = (string) $request->input('period', 'last_30_days');
        $from = null;
        $to = null;
        $resolved = $period;

        switch ($period) {
            case 'today':
                $from = $today->copy();
                $to = $today->copy();
                break;
            case 'last_7_days':
                $from = $today->copy()->subDays(6);
                $to = $today->copy();
                break;
            case 'this_month':
                $from = $today->copy()->startOfMonth();
                $to = $today->copy()->endOfMonth();
                break;
            case 'this_quarter':
                $from = $today->copy()->startOfQuarter();
                $to = $today->copy()->endOfQuarter();
                break;
            case 'this_year':
                $from = $today->copy()->startOfYear();
                $to = $today->copy()->endOfYear();
                break;
            case 'month':
                $month = (string) $request->input('period_month', '');
                if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
                    $monthDate = Carbon::createFromFormat('Y-m', $month);
                    $from = $monthDate->copy()->startOfMonth();
                    $to = $monthDate->copy()->endOfMonth();
                }
                break;
            case 'quarter':
                $year = max(2000, min(2100, (int) $request->input('period_year', $today->year)));
                $quarter = (int) $request->input('period_quarter', 1);
                $quarter = max(1, min(4, $quarter));
                $from = Carbon::create($year, (($quarter - 1) * 3) + 1, 1)->startOfDay();
                $to = $from->copy()->addMonths(2)->endOfMonth();
                break;
            case 'year':
                $year = max(2000, min(2100, (int) $request->input('period_year', $today->year)));
                $from = Carbon::create($year, 1, 1)->startOfDay();
                $to = Carbon::create($year, 12, 31)->endOfDay();
                break;
            case 'custom':
                $customFrom = $this->safeDateInput($request->input('date_from'));
                $customTo = $this->safeDateInput($request->input('date_to'));
                if ($customFrom && $customTo) {
                    $from = $customFrom;
                    $to = $customTo;
                }
                break;
            case 'last_30_days':
            default:
                $resolved = 'last_30_days';
                $from = $today->copy()->subDays(29);
                $to = $today->copy();
                break;
        }

        if (!$from || !$to) {
            $resolved = 'last_30_days';
            $from = $today->copy()->subDays(29);
            $to = $today->copy();
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            $from->toDateString(),
            $to->toDateString(),
            $resolved,
        ];
    }

    private function safeDateInput(mixed $value): ?Carbon
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
