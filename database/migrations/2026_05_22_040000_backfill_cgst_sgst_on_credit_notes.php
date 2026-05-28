<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            UPDATE credit_notes
            SET
                cgst_amount = ROUND(gst / 2.0, 2),
                sgst_amount = ROUND(gst - ROUND(gst / 2.0, 2), 2),
                igst_amount = 0
            WHERE cgst_amount IS NULL
        ");

        // Backfill exchange_order_id for existing exchange CNs
        DB::statement("
            UPDATE credit_notes cn
            SET exchange_order_id = eo.id
            FROM return_orders ro
            JOIN exchange_orders eo ON eo.return_order_id = ro.id
            WHERE cn.return_order_id = ro.id
              AND cn.exchange_order_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE credit_notes
            SET cgst_amount = NULL, sgst_amount = NULL, igst_amount = NULL, exchange_order_id = NULL
        ");
    }
};
