<?php

namespace App\Reporting;

use App\Models\CashTransaction;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\MetalMovement;
use App\Reporting\Data\CashDayData;
use App\Reporting\Data\CashFlowData;
use App\Reporting\Data\DayBookData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ledger reporting (Phase 2 M2): day-book / journal.
 *
 * A chronological accounting event stream over a period — sales, credit notes,
 * cash movements and invoice cancellations (reversal visibility) — each row
 * carrying its source reference for full traceability (nxr4). Reads persisted
 * documents only; no recomputation.
 */
class LedgerService
{
    public function dayBook(int $shopId, ReportPeriod $period): DayBookData
    {
        [$start, $end] = $period->bounds();
        $events = collect();

        // Sales (finalized invoices, by accounting date).
        $sales = Invoice::withoutTenant()
            ->where('invoices.shop_id', $shopId)
            ->salesIn($period)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select(
                DB::raw('COALESCE(invoices.finalized_at, invoices.created_at) as occurred_at'),
                'invoices.invoice_number as reference',
                'invoices.total as amount',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as party")
            )
            ->get();
        $salesTotal = 0.0;
        foreach ($sales as $s) {
            $salesTotal += (float) $s->amount;
            $events->push($this->event($s->occurred_at, 'Sale', $s->reference, $s->party, (float) $s->amount, 'credit', 'invoice'));
        }

        // Credit notes (returns), by issued_at.
        $cns = CreditNote::withoutTenant()
            ->where('credit_notes.shop_id', $shopId)
            ->whereBetween('credit_notes.issued_at', [$start, $end])
            ->leftJoin('customers', 'customers.id', '=', 'credit_notes.customer_id')
            ->select(
                'credit_notes.issued_at as occurred_at',
                'credit_notes.credit_note_number as reference',
                'credit_notes.total as amount',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(customers.first_name, '') || ' ' || COALESCE(customers.last_name, '')), ''), 'Walk-in') as party")
            )
            ->get();
        $refundTotal = 0.0;
        foreach ($cns as $cn) {
            $refundTotal += (float) $cn->amount;
            $events->push($this->event($cn->occurred_at, 'Credit Note', $cn->reference, $cn->party, (float) $cn->amount, 'debit', 'credit_note'));
        }

        // Invoice cancellations in period (reversal visibility).
        $cancels = Invoice::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_CANCELLED)
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$start, $end])
            ->select('cancelled_at as occurred_at', 'invoice_number as reference', 'total as amount')
            ->get();
        foreach ($cancels as $c) {
            $events->push($this->event($c->occurred_at, 'Invoice Cancelled', $c->reference, '', (float) $c->amount, 'debit', 'invoice_cancel'));
        }

        // Cash transactions (the cash ledger).
        $cash = DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->whereBetween('created_at', [$start, $end])
            ->select('created_at as occurred_at', 'type', 'amount', 'payment_mode', 'source_type', 'description', 'invoice_id')
            ->get();
        $cashIn = 0.0;
        $cashOut = 0.0;
        foreach ($cash as $t) {
            $isIn = $t->type === 'in';
            $isIn ? $cashIn += (float) $t->amount : $cashOut += (float) $t->amount;
            $ref = $t->source_type ?: ($t->description ?: 'cash');
            $events->push($this->event(
                $t->occurred_at,
                $isIn ? 'Cash In' : 'Cash Out',
                $ref,
                $t->payment_mode ?? '',
                (float) $t->amount,
                $isIn ? 'credit' : 'debit',
                'cash'
            ));
        }

        $events = $events->sortBy('occurred_at')->values();

        return new DayBookData(
            events: $events,
            salesTotal: round($salesTotal, 2),
            refundTotal: round($refundTotal, 2),
            cashIn: round($cashIn, 2),
            cashOut: round($cashOut, 2),
            eventCount: $events->count(),
        );
    }

    /**
     * One day's cash ledger (M5 extraction of CashReportController). Same two
     * grouped queries, no behaviour change.
     */
    public function cashDay(int $shopId, string $date): CashDayData
    {
        $rows = CashTransaction::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->get();

        $modeBreakdown = CashTransaction::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->whereNotNull('payment_mode')
            ->select('payment_mode', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_mode')
            ->pluck('total', 'payment_mode');

        return new CashDayData(rows: $rows, modeBreakdown: $modeBreakdown);
    }

    /**
     * One day's metal movements grouped by type (M5 extraction of
     * DailyReportController). Same query, no behaviour change.
     */
    public function metalMovementDay(int $shopId, string $date): Collection
    {
        return MetalMovement::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->select('type', DB::raw('SUM(fine_weight) as total'))
            ->groupBy('type')
            ->get();
    }

    /**
     * Cash flow over a period (Phase 3): opening + in − out = closing, with the
     * per-entry ledger carrying a running balance. Canonical aggregation over
     * cash_transactions — the report wraps this without re-deriving balances.
     */
    public function cashFlow(int $shopId, ReportPeriod $period): CashFlowData
    {
        [$start, $end] = $period->bounds();

        // Opening = net cash (in − out) before the period start.
        $opening = round((float) DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->where('created_at', '<', $start)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0) as net")
            ->value('net'), 2);

        $txns = DB::table('cash_transactions as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.shop_id', $shopId)
            ->whereBetween('c.created_at', [$start, $end])
            ->orderBy('c.created_at')->orderBy('c.id')
            ->select(
                'c.created_at as occurred_at', 'c.type', 'c.amount', 'c.payment_mode',
                'c.source_type', 'c.description',
                DB::raw("COALESCE(u.name, 'System') as operator")
            )
            ->get();

        $cashIn = 0.0;
        $cashOut = 0.0;
        $running = $opening;
        $rows = collect();
        foreach ($txns as $t) {
            $amount = (float) $t->amount;
            if ($t->type === 'in') {
                $cashIn += $amount;
                $running += $amount;
            } else {
                $cashOut += $amount;
                $running -= $amount;
            }
            $rows->push((object) [
                'occurred_at' => $t->occurred_at,
                'type' => $t->type === 'in' ? 'Cash In' : 'Cash Out',
                'source' => $t->source_type ?: ($t->description ?: 'cash'),
                'payment_mode' => $t->payment_mode,
                'description' => $t->description,
                'operator' => $t->operator,
                'amount' => round($amount, 2),
                'running_balance' => round($running, 2),
            ]);
        }

        $cashIn = round($cashIn, 2);
        $cashOut = round($cashOut, 2);

        return new CashFlowData(
            opening: $opening,
            cashIn: $cashIn,
            cashOut: $cashOut,
            closing: round($opening + $cashIn - $cashOut, 2),
            rows: $rows,
            count: $rows->count(),
        );
    }

    /**
     * Per-payment-mode cash flow over a period. Same opening + in − out =
     * closing identity as cashFlow(), but split by money mode so the cash book
     * can show the physical drawer (cash) separately from UPI / bank / card /
     * wallet / other. Computed from immutable cash_transactions; never stored.
     *
     * Historical NULL payment_mode is treated as 'cash' defensively via
     * COALESCE — the stored NULL stays truthful. Old gold/silver never appears
     * (those tenders never create a cash_transactions row).
     */
    public function cashFlowByMode(int $shopId, ReportPeriod $period): \App\Reporting\Data\PerModeCashFlowData
    {
        [$start, $end] = $period->bounds();
        $modeExpr = "COALESCE(NULLIF(TRIM(payment_mode), ''), 'cash')";

        // Opening per mode = net (in − out) before the period start.
        $opening = DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->where('created_at', '<', $start)
            ->selectRaw("$modeExpr as mode, COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE -amount END), 0) as net")
            ->groupBy(DB::raw($modeExpr))
            ->pluck('net', 'mode');

        // In / out per mode within the period.
        $within = DB::table('cash_transactions')
            ->where('shop_id', $shopId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("$modeExpr as mode")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in'  THEN amount ELSE 0 END), 0) as money_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END), 0) as money_out")
            ->groupBy(DB::raw($modeExpr))
            ->get()
            ->keyBy('mode');

        $allModes = collect($opening->keys())
            ->merge($within->keys())
            ->unique()
            ->sort()
            ->values();

        $modes = collect();
        $totalOpening = $totalIn = $totalOut = $totalClosing = 0.0;

        foreach ($allModes as $mode) {
            $open = round((float) ($opening[$mode] ?? 0), 2);
            $in   = round((float) ($within[$mode]->money_in ?? 0), 2);
            $out  = round((float) ($within[$mode]->money_out ?? 0), 2);
            $close = round($open + $in - $out, 2);

            $modes->push((object) [
                'mode'     => $mode,
                'opening'  => $open,
                'moneyIn'  => $in,
                'moneyOut' => $out,
                'closing'  => $close,
            ]);

            $totalOpening += $open;
            $totalIn      += $in;
            $totalOut     += $out;
            $totalClosing += $close;
        }

        return new \App\Reporting\Data\PerModeCashFlowData(
            modes: $modes,
            totalOpening: round($totalOpening, 2),
            totalIn: round($totalIn, 2),
            totalOut: round($totalOut, 2),
            totalClosing: round($totalClosing, 2),
        );
    }

    private function event($occurredAt, string $type, ?string $reference, ?string $party, float $amount, string $direction, string $source): object
    {
        return (object) [
            'occurred_at' => $occurredAt,
            'event_type'  => $type,
            'reference'   => $reference ?? '',
            'party'       => $party ?? '',
            'amount'      => round($amount, 2),
            'direction'   => $direction, // credit = money/value in, debit = out/reversal
            'source'      => $source,
        ];
    }
}
