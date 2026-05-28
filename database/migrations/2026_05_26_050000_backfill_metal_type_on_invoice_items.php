<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 5/9 — Silent Wrongness Elimination
 *
 * Backfills `invoice_items.metal_type` from the joined `items.metal_type`.
 *
 * Constitutional rule: this migration uses raw SQL to UPDATE finalized
 * invoice_items rows. It runs BEFORE migration 060000 installs the
 * extended finalized-guard trigger that locks metal_type. The order is
 * intentional — the trigger is the constitutional protection going
 * forward, but pre-trigger backfill is the legitimate one-shot exception
 * (boundary doctrine: Lane 3 - Migration backfill window).
 *
 * No silent coercion: rows where items.metal_type IS NULL are left
 * NULL on the invoice_item. The Phase 1 NOT-NULL transition will require
 * those to be human-classified first.
 *
 * Pre-validation: counts how many invoice_items can be resolved.
 *
 * Idempotent: re-running sets the same value, no double-write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_items') || ! Schema::hasColumn('invoice_items', 'metal_type')) {
            throw new RuntimeException(
                'invoice_items.metal_type column missing. Run migration 2026_05_26_040000 first.'
            );
        }

        // Pre-validation: how many invoice_items can be resolved via items?
        $resolvable = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) AS total_invoice_items,
                COUNT(*) FILTER (WHERE ii.metal_type IS NULL) AS to_backfill,
                COUNT(*) FILTER (
                    WHERE ii.metal_type IS NULL
                      AND i.metal_type IS NOT NULL
                ) AS resolvable
            FROM invoice_items ii
            LEFT JOIN items i ON i.id = ii.item_id
        SQL);

        error_log(sprintf(
            '[Phase0/050000] invoice_items.metal_type: total=%d, to_backfill=%d, resolvable=%d',
            (int) $resolvable->total_invoice_items,
            (int) $resolvable->to_backfill,
            (int) $resolvable->resolvable
        ));

        // Backfill from items.metal_type.
        // Note: this updates invoice_items rows whose parent invoice may be
        // status='finalized'. This is permitted because:
        //   (a) the invoice_items_finalized_guard trigger is not yet
        //       enforcing metal_type immutability — migration 060000 extends it.
        //   (b) the current trigger's allow-list does not list metal_type as
        //       an allowed-mutation column, so we use raw SQL via DB::statement
        //       which bypasses Eloquent observers but still hits the DB trigger.
        //
        // The existing trigger checks columns IN the allow-list; since
        // metal_type was added today and is NOT in the trigger's blocked-changes
        // list (because the column didn't exist when the trigger was written),
        // the trigger ignores the change. We're safe.
        //
        // To be defensive: temporarily set session_replication_role and immediately
        // restore. This is the ONE legitimate backfill-window pattern documented
        // in CONSTITUTION.md §3 Lane 3.
        //
        // Actually — re-checking: the trigger compares NEW.X IS DISTINCT FROM OLD.X
        // for each named column. metal_type is not in that list. So the trigger
        // simply doesn't see this UPDATE as a blocked-column change. Safe.

        DB::statement(<<<'SQL'
            UPDATE invoice_items
            SET metal_type = i.metal_type
            FROM items i
            WHERE invoice_items.item_id = i.id
              AND invoice_items.metal_type IS NULL
              AND i.metal_type IS NOT NULL
        SQL);

        // Post-validation: report remaining NULLs.
        $remaining = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) AS still_null,
                COUNT(DISTINCT invoice_id) AS invoices_affected
            FROM invoice_items
            WHERE metal_type IS NULL
        SQL);

        if ((int) $remaining->still_null > 0) {
            error_log(sprintf(
                '[Phase0/050000] invoice_items still NULL after backfill: %d row(s) across %d invoice(s). These will require human classification before Phase 1 NOT NULL.',
                (int) $remaining->still_null,
                (int) $remaining->invoices_affected
            ));
        }
    }

    public function down(): void
    {
        // No-op. The forward migration is idempotent; reversing would require
        // a marker column we did not add. The column drop in 040000.down()
        // removes the data anyway.
    }
};
