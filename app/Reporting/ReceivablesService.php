<?php

namespace App\Reporting;

use App\Models\Invoice;
use App\Reporting\Data\DuesAgingData;
use App\Reporting\Data\EmiData;
use App\Reporting\Data\MetalLiabilityData;
use App\Reporting\Data\SchemeLiabilityData;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receivables & liability reporting (Phase 2 M3).
 *
 * #8 Customer dues aging — outstanding on finalized invoices, bucketed by age.
 * Uses the canonical sale scope (finalized) and persisted invoice totals +
 * invoice_payments only; no parallel sales definition, no controller-level
 * aggregation. This is a point-in-time snapshot, not a period report.
 */
class ReceivablesService
{
    public function duesAging(int $shopId, ?Carbon $asOf = null): DuesAgingData
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $invoices = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->finalizedSale()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(
                'invoices.id',
                'invoices.customer_id',
                DB::raw('COALESCE(invoices.finalized_at, invoices.created_at) as doc_date'),
                'invoices.total',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as customer_name"),
                'customers.mobile as customer_mobile'
            )
            ->get();

        $ids = $invoices->pluck('id')->all();

        $collectedByInvoice = empty($ids) ? collect() : DB::table('invoice_payments')
            ->where('shop_id', $shopId)
            ->whereIn('invoice_id', $ids)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount) as collected')
            ->pluck('collected', 'invoice_id');

        // Per-customer accumulator keyed by customer_id (null → walk-in bucket).
        $byCustomer = [];
        $bC = 0.0; $b3160 = 0.0; $b6190 = 0.0; $b90 = 0.0; $invCount = 0;

        foreach ($invoices as $inv) {
            $total = round((float) $inv->total, 2);
            $collected = round((float) ($collectedByInvoice[$inv->id] ?? 0), 2);
            $outstanding = round($total - $collected, 2);
            if ($outstanding <= 0.01) {
                continue; // fully paid / over-collected / reversal — not a due
            }

            $ageDays = Carbon::parse($inv->doc_date)->startOfDay()->diffInDays($asOf, false);
            $ageDays = max(0, (int) $ageDays);

            if ($ageDays <= 30)       { $bucket = 'current'; $bC += $outstanding; }
            elseif ($ageDays <= 60)   { $bucket = 'd3160';   $b3160 += $outstanding; }
            elseif ($ageDays <= 90)   { $bucket = 'd6190';   $b6190 += $outstanding; }
            else                      { $bucket = 'd90plus'; $b90 += $outstanding; }

            $key = $inv->customer_id ?? 'walkin';
            if (!isset($byCustomer[$key])) {
                $byCustomer[$key] = [
                    'customer_name' => $inv->customer_name,
                    'mobile'        => $inv->customer_mobile,
                    'invoice_count' => 0,
                    'current'       => 0.0,
                    'd3160'         => 0.0,
                    'd6190'         => 0.0,
                    'd90plus'       => 0.0,
                    'total'         => 0.0,
                ];
            }
            $byCustomer[$key]['invoice_count']++;
            $byCustomer[$key][$bucket] += $outstanding;
            $byCustomer[$key]['total'] += $outstanding;
            $invCount++;
        }

        $rows = collect($byCustomer)
            ->map(function ($c) {
                $c['current']  = round($c['current'], 2);
                $c['d3160']    = round($c['d3160'], 2);
                $c['d6190']    = round($c['d6190'], 2);
                $c['d90plus']  = round($c['d90plus'], 2);
                $c['total']    = round($c['total'], 2);
                return (object) $c;
            })
            ->sortByDesc('total')
            ->values();

        return new DuesAgingData(
            rows: $rows,
            bucketCurrent: round($bC, 2),
            bucket3160: round($b3160, 2),
            bucket6190: round($b6190, 2),
            bucket90plus: round($b90, 2),
            totalOutstanding: round($bC + $b3160 + $b6190 + $b90, 2),
            customerCount: $rows->count(),
            invoiceCount: $invCount,
            asOf: $asOf->format('Y-m-d'),
        );
    }

    /**
     * #9 Pending EMI / installment visibility — active plans, outstanding
     * (`remaining_amount`), overdue + upcoming surfacing. Completed/defaulted
     * plans carry no live receivable and are excluded.
     */
    public function emiVisibility(int $shopId, ?Carbon $asOf = null): EmiData
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $upcomingCutoff = $asOf->copy()->addDays(7)->endOfDay();

        $plans = DB::table('installment_plans as p')
            ->where('p.shop_id', $shopId)
            ->where('p.status', 'active')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->select(
                'p.id', 'p.total_payable', 'p.remaining_amount', 'p.emis_paid', 'p.total_emis',
                'p.next_due_date',
                'i.invoice_number',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), 'Walk-in') as customer_name")
            )
            ->orderBy('p.next_due_date')
            ->get();

        $totalOutstanding = 0.0; $overdueAmount = 0.0; $upcomingAmount = 0.0;
        $overdueCount = 0; $upcomingCount = 0;

        $rows = $plans->map(function ($p) use ($asOf, $upcomingCutoff, &$totalOutstanding, &$overdueAmount, &$upcomingAmount, &$overdueCount, &$upcomingCount) {
            $remaining = round((float) $p->remaining_amount, 2);
            $totalOutstanding += $remaining;

            $due = $p->next_due_date ? Carbon::parse($p->next_due_date)->startOfDay() : null;
            $overdue = $due !== null && $due->lt($asOf);
            $daysOverdue = $overdue ? $due->diffInDays($asOf) : 0;

            if ($overdue) {
                $overdueCount++;
                $overdueAmount += $remaining;
            } elseif ($due !== null && $due->lte($upcomingCutoff)) {
                $upcomingCount++;
                $upcomingAmount += $remaining;
            }

            return (object) [
                'customer_name' => $p->customer_name,
                'invoice_number' => $p->invoice_number,
                'total_payable' => round((float) $p->total_payable, 2),
                'paid'          => round((float) $p->total_payable - $remaining, 2),
                'remaining'     => $remaining,
                'emis_paid'     => (int) $p->emis_paid,
                'total_emis'    => (int) $p->total_emis,
                'next_due_date' => $p->next_due_date,
                'overdue'       => $overdue,
                'days_overdue'  => (int) $daysOverdue,
            ];
        });

        return new EmiData(
            rows: $rows,
            totalOutstanding: round($totalOutstanding, 2),
            overdueAmount: round($overdueAmount, 2),
            upcomingAmount: round($upcomingAmount, 2),
            planCount: $rows->count(),
            overdueCount: $overdueCount,
            upcomingCount: $upcomingCount,
            asOf: $asOf->format('Y-m-d'),
        );
    }

    /**
     * #10 Scheme liability exposure — current ledger balance the shop owes on
     * non-terminal (active + matured) gold-savings enrollments. The balance
     * already includes any maturity bonus that has been accrued into the ledger.
     */
    public function schemeLiability(int $shopId): SchemeLiabilityData
    {
        // Latest ledger balance per enrollment (max id wins).
        $latestBalances = DB::table('scheme_ledger_entries as e')
            ->where('e.shop_id', $shopId)
            ->whereIn('e.id', function ($q) use ($shopId) {
                $q->from('scheme_ledger_entries')
                    ->where('shop_id', $shopId)
                    ->selectRaw('MAX(id)')
                    ->groupBy('scheme_enrollment_id');
            })
            ->pluck('e.balance_after', 'e.scheme_enrollment_id');

        $enrollments = DB::table('scheme_enrollments as se')
            ->where('se.shop_id', $shopId)
            ->whereIn('se.status', ['active', 'matured'])
            ->leftJoin('schemes as s', 's.id', '=', 'se.scheme_id')
            ->leftJoin('customers as c', 'c.id', '=', 'se.customer_id')
            ->select(
                'se.id', 'se.status', 'se.total_paid', 'se.bonus_amount', 'se.is_bonus_accrued',
                'se.maturity_date', 's.name as scheme_name',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), 'Walk-in') as customer_name")
            )
            ->orderByDesc('se.total_paid')
            ->get();

        $totalLiability = 0.0; $totalContributions = 0.0; $bonusAccrued = 0.0; $maturedCount = 0;

        $rows = $enrollments->map(function ($e) use ($latestBalances, &$totalLiability, &$totalContributions, &$bonusAccrued, &$maturedCount) {
            $balance = round((float) ($latestBalances[$e->id] ?? 0), 2);
            $paid = round((float) $e->total_paid, 2);
            $bonus = $e->is_bonus_accrued ? round((float) $e->bonus_amount, 2) : 0.0;

            $totalLiability += $balance;
            $totalContributions += $paid;
            $bonusAccrued += $bonus;
            if ($e->status === 'matured') {
                $maturedCount++;
            }

            return (object) [
                'customer_name'   => $e->customer_name,
                'scheme_name'     => $e->scheme_name,
                'status'          => $e->status,
                'total_paid'      => $paid,
                'bonus_accrued'   => $bonus,
                'current_balance' => $balance,
                'maturity_date'   => $e->maturity_date,
            ];
        });

        return new SchemeLiabilityData(
            rows: $rows,
            totalLiability: round($totalLiability, 2),
            totalContributions: round($totalContributions, 2),
            bonusAccrued: round($bonusAccrued, 2),
            enrollmentCount: $rows->count(),
            maturedCount: $maturedCount,
        );
    }

    /**
     * #11 Metal / old-gold liability — fine gold the shop owes customers from
     * advance deposits. NET liability is the pooled customer_advance lot's
     * remaining; per-customer rows are GROSS deposited. Old gold accepted at POS
     * is shop stock (informational). All figures are fine grams.
     */
    public function metalLiability(int $shopId): MetalLiabilityData
    {
        $advanceLiability = round((float) DB::table('metal_lots')
            ->where('shop_id', $shopId)
            ->where('source', 'customer_advance')
            ->sum('fine_weight_remaining'), 4);

        $deposits = DB::table('customer_gold_transactions as t')
            ->where('t.shop_id', $shopId)
            ->where('t.type', 'advance')
            ->leftJoin('customers as c', 'c.id', '=', 't.customer_id')
            ->groupBy('t.customer_id', 'c.first_name', 'c.last_name')
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '')), ''), 'Walk-in') as customer_name"),
                DB::raw('SUM(t.fine_gold) as fine')
            )
            ->orderByDesc('fine')
            ->get()
            ->map(fn ($r) => (object) [
                'customer_name'  => $r->customer_name,
                'fine_deposited' => round((float) $r->fine, 4),
            ]);

        $totalDeposited = round((float) $deposits->sum('fine_deposited'), 4);

        $oldGoldAccepted = round((float) DB::table('customer_gold_transactions')
            ->where('shop_id', $shopId)
            ->where('type', 'old_metal_in')
            ->sum('fine_gold'), 4);

        $vaultOnHand = round((float) DB::table('metal_lots')
            ->where('shop_id', $shopId)
            ->sum('fine_weight_remaining'), 4);

        return new MetalLiabilityData(
            rows: $deposits,
            totalAdvanceLiability: $advanceLiability,
            totalDeposited: $totalDeposited,
            oldGoldAcceptedFine: $oldGoldAccepted,
            vaultOnHandFine: $vaultOnHand,
            customerCount: $deposits->count(),
        );
    }
}
