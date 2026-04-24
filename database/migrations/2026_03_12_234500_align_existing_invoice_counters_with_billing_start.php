<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shop_counters') || !Schema::hasTable('shop_billing_settings')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            $this->alignCountersGeneric();

            return;
        }

        $nowExpr = 'now()';

        // Ensure invoice counter row exists for every shop with billing settings.
        DB::statement("
            INSERT INTO shop_counters (shop_id, counter_key, current_value, created_at, updated_at)
            SELECT
                s.shop_id,
                'invoice',
                GREATEST(
                    COALESCE(i.max_seq, 0),
                    GREATEST(COALESCE(s.invoice_start_number, 1001) - 1, 0)
                ),
                {$nowExpr},
                {$nowExpr}
            FROM shop_billing_settings s
            LEFT JOIN (
                SELECT shop_id, MAX(invoice_sequence) AS max_seq
                FROM invoices
                GROUP BY shop_id
            ) i ON i.shop_id = s.shop_id
            WHERE NOT EXISTS (
                SELECT 1
                FROM shop_counters c
                WHERE c.shop_id = s.shop_id
                  AND c.counter_key = 'invoice'
            )
        ");

        // Move existing counters forward when billing start is higher.
        DB::statement("
            UPDATE shop_counters c
            SET current_value = GREATEST(
                    c.current_value,
                    COALESCE(i.max_seq, 0),
                    GREATEST(COALESCE(s.invoice_start_number, 1001) - 1, 0)
                ),
                updated_at = {$nowExpr}
            FROM shop_billing_settings s
            LEFT JOIN (
                SELECT shop_id, MAX(invoice_sequence) AS max_seq
                FROM invoices
                GROUP BY shop_id
            ) i ON i.shop_id = s.shop_id
            WHERE c.shop_id = s.shop_id
              AND c.counter_key = 'invoice'
        ");
    }

    public function down(): void
    {
        // Forward-only data alignment migration; no safe rollback needed.
    }

    private function alignCountersGeneric(): void
    {
        $settings = DB::table('shop_billing_settings')->get(['shop_id', 'invoice_start_number']);

        foreach ($settings as $setting) {
            $maxInvoiceSequence = (int) DB::table('invoices')
                ->where('shop_id', $setting->shop_id)
                ->max('invoice_sequence');

            $currentCounter = DB::table('shop_counters')
                ->where('shop_id', $setting->shop_id)
                ->where('counter_key', 'invoice')
                ->value('current_value');

            $targetValue = max(
                (int) ($currentCounter ?? 0),
                $maxInvoiceSequence,
                max(((int) ($setting->invoice_start_number ?? 1001)) - 1, 0)
            );

            DB::table('shop_counters')->updateOrInsert(
                [
                    'shop_id' => $setting->shop_id,
                    'counter_key' => 'invoice',
                ],
                [
                    'current_value' => $targetValue,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
