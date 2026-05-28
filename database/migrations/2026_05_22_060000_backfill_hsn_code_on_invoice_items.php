<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            UPDATE invoice_items
            SET hsn_code = CASE
                WHEN i.metal_type = 'gold'   THEN sbs.hsn_gold
                WHEN i.metal_type = 'silver' THEN sbs.hsn_silver
                ELSE NULL
            END
            FROM items i,
                 invoices inv,
                 shop_billing_settings sbs
            WHERE invoice_items.item_id = i.id
              AND invoice_items.invoice_id = inv.id
              AND sbs.shop_id = inv.shop_id
              AND invoice_items.hsn_code IS NULL
              AND sbs.hsn_gold IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE invoice_items SET hsn_code = NULL");
    }
};
