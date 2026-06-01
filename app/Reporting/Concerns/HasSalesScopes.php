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
