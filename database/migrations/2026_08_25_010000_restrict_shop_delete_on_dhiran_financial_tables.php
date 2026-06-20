<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dhiran financial-safety hardening (Phase 5, Part A).
 *
 * Dhiran financial tables had shop_id → shops ON DELETE CASCADE, so a hard
 * `Shop::delete()` would silently wipe all pawn/loan financial history
 * (loans, items, payments, ledger, cash entries). There is no app path that
 * hard-deletes a shop today, but pawn records are regulated financial history
 * and must not be destroyable by a single cascade.
 *
 * This migration flips shop_id to ON DELETE RESTRICT on the five financial
 * tables. After it, attempting to hard-delete a shop that still has Dhiran
 * data raises a FK violation at the DB layer — the last line of defence behind
 * the application-level guard (Shop::deleting hook).
 *
 * NOT changed:
 *  - loan → children (dhiran_loan_id) stays CASCADE: there is no app path to
 *    delete a single loan, and shop_id RESTRICT already blocks the shop-delete
 *    that would reach them. Belt: every financial table independently RESTRICTs
 *    on shop_id, so any one of them blocks the delete.
 *  - actor FKs (created_by / released_by / forfeited_by → users) stay SET NULL:
 *    deleting a user must keep financial history (just anonymises the actor).
 *  - dhiran_settings.shop_id stays CASCADE: settings are config, not financial
 *    history, and should die with the shop.
 *
 * Additive + reversible. No data moved or deleted.
 */
return new class extends Migration
{
    /** table => FK constraint name (Laravel convention {table}_shop_id_foreign). */
    private array $fks = [
        'dhiran_loans'         => 'dhiran_loans_shop_id_foreign',
        'dhiran_loan_items'    => 'dhiran_loan_items_shop_id_foreign',
        'dhiran_payments'      => 'dhiran_payments_shop_id_foreign',
        'dhiran_ledger_entries' => 'dhiran_ledger_entries_shop_id_foreign',
        'dhiran_cash_entries'  => 'dhiran_cash_entries_shop_id_foreign',
    ];

    public function up(): void
    {
        foreach ($this->fks as $table => $fk) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fk}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$fk} "
                . "FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT"
            );
        }
    }

    public function down(): void
    {
        // Restore the original CASCADE behaviour.
        foreach ($this->fks as $table => $fk) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fk}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$fk} "
                . "FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE"
            );
        }
    }
};
