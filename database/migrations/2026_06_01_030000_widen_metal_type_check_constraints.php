<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Migration 3/N — Widen CHECK constraints for Tier 2 metals.
 *
 * Phase 0 installed CHECK constraints permitting only ('gold','silver').
 * Phase 1 widens them to ('gold','silver','platinum','copper'). NULL
 * remains permitted for legacy rows.
 *
 * Constitutional rule: the DB CHECK acts as a hard backstop for the
 * system-wide tier list. Per-shop opt-in is enforced separately by
 * MetalRegistry::assertEnabledForShop() at the service layer — the DB
 * constraint allows Tier 2 metals even if the specific shop hasn't
 * opted in, because the per-shop policy lives in shop_enabled_metals,
 * not in the schema. Defense in depth: shop-level check at the
 * application, system-level check at the DB.
 *
 * Pre-validation: no rows can violate the WIDER constraint, by
 * construction (the old constraint was strictly tighter).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'items'        => 'items_metal_type_check',
            'metal_lots'   => 'metal_lots_metal_type_check',
            'products'     => 'products_metal_type_check',
        ];

        foreach ($tables as $table => $constraint) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Drop old (gold/silver) constraint, install wider (gold/silver/platinum/copper).
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$constraint}
                CHECK (metal_type IS NULL OR metal_type IN ('gold', 'silver', 'platinum', 'copper'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore Phase 0 (tighter) constraints. Pre-validate that no
        // existing rows hold Tier 2 metals — if any do, the down() must
        // refuse to proceed, because tightening would corrupt those rows.
        $tables = [
            'items',
            'metal_lots',
            'products',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $violators = DB::table($table)
                ->whereIn('metal_type', ['platinum', 'copper'])
                ->count();
            if ($violators > 0) {
                throw new RuntimeException(
                    "Cannot revert {$table} CHECK to Phase 0 (gold/silver only) — "
                    . "{$violators} row(s) hold Tier 2 metal_type. Manual reclassification required."
                );
            }
        }

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $constraint = $table . '_metal_type_check';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$constraint}
                CHECK (metal_type IS NULL OR metal_type IN ('gold', 'silver'))
            SQL);
        }
    }
};
