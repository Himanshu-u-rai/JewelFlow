<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow store_credit_movements.source_type='old_gold_excess' so that when a
 * customer's old-gold/old-silver value exceeds the invoice total at POS, the
 * excess is stored on their wallet instead of blocking the sale or leaking
 * out as a cash payout. Balance = SUM(amount), so a positive old_gold_excess
 * row behaves exactly like any other credit — consumable in a later sale.
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
        'old_gold_excess',
    ];

    public function up(): void
    {
        $this->setSources(self::SOURCES);
    }

    public function down(): void
    {
        // Refuse to narrow the CHECK while old_gold_excess rows exist: the ALTER
        // would fail against them anyway, and deleting/rewriting posted
        // store-credit rows would corrupt customers' wallet balances.
        if (DB::table('store_credit_movements')->where('source_type', 'old_gold_excess')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back: store_credit_movements rows with source_type=old_gold_excess '
                . 'exist. Removing it from the CHECK would orphan posted excess credits.'
            );
        }

        $this->setSources(array_values(array_diff(self::SOURCES, ['old_gold_excess'])));
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
