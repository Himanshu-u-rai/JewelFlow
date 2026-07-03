<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow store_credit_movements.source_type='opening_advance' so an existing
 * shop's customer advances can be seeded as opening store credit at onboarding
 * lock. The wallet balance = SUM(amount), so a positive opening_advance row
 * behaves exactly like any other credit — consumable in a later sale.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'store_credit_movements_source_check';

    private const SOURCES = [
        'credit_note_issued',
        'sale_applied',
        'manual_adjustment',
        'expiry',
        'reversal',
        'opening_advance',
    ];

    public function up(): void
    {
        $this->setSources(self::SOURCES);
    }

    public function down(): void
    {
        // Refuse to narrow the CHECK while opening_advance rows exist: the ALTER
        // would fail against them anyway (leaving a half-applied rollback), and
        // silently deleting/rewriting posted store-credit rows would corrupt
        // customers' wallet balances. Reverse the owning onboarding batches first.
        if (DB::table('store_credit_movements')->where('source_type', 'opening_advance')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back: store_credit_movements rows with source_type=opening_advance '
                . 'exist. Removing it from the CHECK would orphan posted opening advances. '
                . 'Reverse the onboarding batches that created them, then retry.'
            );
        }

        $this->setSources(array_values(array_diff(self::SOURCES, ['opening_advance'])));
    }

    private function setSources(array $sources): void
    {
        $list = implode(', ', array_map(fn ($s) => "'".$s."'", $sources));
        DB::statement('ALTER TABLE store_credit_movements DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE store_credit_movements ADD CONSTRAINT '.self::CONSTRAINT
            .' CHECK (source_type IN ('.$list.'))'
        );
    }
};
