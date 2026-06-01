<?php

namespace App\Reporting;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Reporting\Data\DayBookData;
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
