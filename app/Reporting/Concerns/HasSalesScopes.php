<?php

namespace App\Reporting\Concerns;

use App\Reporting\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The canonical "this is a sale" definition, applied to the Invoice model.
 *
 * This is the single source of truth for two questions the audit found were
 * answered inconsistently across the codebase (audit §4.3/4.4):
 *
 *   1. What counts as a sale?      → status = finalized (never draft, never cancelled)
 *   2. What date does it belong to? → accounting date = COALESCE(finalized_at, created_at)
 *
 * Every report that talks about "sales" MUST go through these scopes so that
 * the dashboard, the GST report, the closing report, and the P&L can never
 * disagree about the same period again.
 */
trait HasSalesScopes
{
    /** Finalized invoices only — the canonical "sale" status filter. */
    public function scopeFinalizedSale(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_FINALIZED);
    }

    /**
     * Every bill that was ever ISSUED as a sale: finalized, plus bills cancelled after they were issued (a
     * reversal or a full return sets cancelled_at). A discarded DRAFT is also 'cancelled' but never has
     * cancelled_at, so it never counts.
     *
     * Use this for SALES FIGURES (revenue, GST, registers): a bill is a sale in the period it was issued, and
     * its later cancellation / return is a separate negative entry in its own period (a reversal bill that is
     * already negative, or a credit note). Do NOT use it for what is still owed — a cancelled bill owes
     * nothing, so receivables keep using finalizedSale().
     */
    public function scopeIssuedSale(Builder $query): Builder
    {
        $status = $query->qualifyColumn('status');
        $cancelledAt = $query->qualifyColumn('cancelled_at');

        return $query->where(function (Builder $q) use ($status, $cancelledAt) {
            $q->where($status, self::STATUS_FINALIZED)
                ->orWhere(fn (Builder $c) => $c->where($status, self::STATUS_CANCELLED)->whereNotNull($cancelledAt));
        });
    }

    /** The same "issued bill" rule as raw SQL, for query-builder joins that cannot use the scope. */
    public static function issuedSaleSql(string $table = 'invoices'): string
    {
        return "({$table}.status = '" . self::STATUS_FINALIZED . "' OR ({$table}.status = '" . self::STATUS_CANCELLED
            . "' AND {$table}.cancelled_at IS NOT NULL))";
    }

    /** Issued bills dated (accounting date) inside the period. */
    public function scopeIssuedSalesIn(Builder $query, ReportPeriod $period): Builder
    {
        return $query->issuedSale()->accountingBetween($period);
    }

    /**
     * Filter by accounting date (finalized_at, falling back to created_at for
     * legacy rows that predate finalized_at). NEVER raw created_at alone.
     */
    public function scopeAccountingBetween(Builder $query, ReportPeriod $period): Builder
    {
        $column = "COALESCE({$query->qualifyColumn('finalized_at')}, {$query->qualifyColumn('created_at')})";

        return $query->whereRaw(
            "{$column} BETWEEN ? AND ?",
            [$period->start(), $period->end()]
        );
    }

    /** Canonical sales selection: finalized + within the accounting period. */
    public function scopeSalesIn(Builder $query, ReportPeriod $period): Builder
    {
        return $query->finalizedSale()->accountingBetween($period);
    }
}
