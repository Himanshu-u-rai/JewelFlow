<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 10/10 — REVISED for constitutional fidelity.
 *
 * ORIGINAL INTENT: Install a new metal_movements_append_only_trigger
 *   alongside the Constitutional Lockdown trigger set.
 *
 * WHY THAT INTENT WAS WRONG: An append-only trigger on metal_movements
 *   has been in place since 2026_02_18_000001_financial_accounting_hardening.php
 *   under the name `metal_movements_immutable_trigger`. Installing a
 *   second trigger doing the same job is redundant. Same for
 *   `cash_transactions` and `customer_gold_transactions` — both
 *   carry pre-existing immutability triggers from the Feb 2026
 *   financial hardening work.
 *
 * REVISED BEHAVIOR (this migration now):
 *   - DOES NOT create any new trigger.
 *   - Asserts that the pre-existing `metal_movements_immutable_trigger`
 *     is installed. If it is missing, the migration aborts: the
 *     foundational protection is not in place and Phase 0 cannot proceed.
 *   - The CONSTITUTION.md Article IX.A trigger registry should be
 *     amended to list `metal_movements_immutable_trigger`,
 *     `cash_transactions_immutable_trigger`, and
 *     `customer_gold_transactions_immutable_trigger` (the three
 *     pre-existing immutability triggers from Feb 2026).
 *
 * This migration's purpose is now a constitutional sanity check, not
 * a trigger installation. Renamed file kept under the Phase 0
 * timestamp bracket so the registry order remains coherent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metal_movements')) {
            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Assert the pre-existing immutable trigger is installed.
        // This trigger was created by 2026_02_18_000001_financial_accounting_hardening.php.
        $existing = DB::selectOne(
            "SELECT 1 FROM information_schema.triggers
             WHERE trigger_schema = 'public'
               AND event_object_table = 'metal_movements'
               AND trigger_name = 'metal_movements_immutable_trigger'
             LIMIT 1"
        );

        if (! $existing) {
            throw new RuntimeException(
                'metal_movements_immutable_trigger is missing. The append-only '
                . 'protection that has been in place since Feb 2026 is gone. '
                . 'Phase 0 cannot proceed — investigate before continuing. '
                . 'Constitutional reference: prevent_ledger_mutation() and '
                . '2026_02_18_000001_financial_accounting_hardening.php.'
            );
        }

        // Also assert the function exists.
        $functionExists = DB::selectOne(
            "SELECT 1 FROM pg_proc WHERE proname = 'prevent_ledger_mutation'"
        );

        if (! $functionExists) {
            throw new RuntimeException(
                'prevent_ledger_mutation() function is missing. The shared '
                . 'append-only enforcement function is gone. Investigate.'
            );
        }
    }

    public function down(): void
    {
        // No-op. This migration is now a constitutional assertion; it
        // installs no resources, so there is nothing to drop.
    }
};
