<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('installment_plans')
            ->select('shop_id', 'invoice_id', DB::raw('COUNT(*) as c'))
            ->groupBy('shop_id', 'invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot add unique EMI plan constraint: duplicate invoice plans already exist.');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS installment_plans_shop_invoice_unique ON installment_plans (shop_id, invoice_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS installment_plans_shop_invoice_unique');
    }
};
