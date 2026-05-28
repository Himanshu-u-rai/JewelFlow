<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A. Add require_restock_inspection to shop_preferences.
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->boolean('require_restock_inspection')
                ->default(false)
                ->after('return_settlement_mode');
        });

        // B. Extend items_status_check to allow 'pending_restock'.
        DB::unprepared(<<<'SQL'
ALTER TABLE items DROP CONSTRAINT IF EXISTS items_status_check;
ALTER TABLE items ADD CONSTRAINT items_status_check
    CHECK (status IN ('in_stock','sold','returned','melted','transferred','reversed','pending_listing','pending_restock'));
SQL);
    }

    public function down(): void
    {
        // A. Drop require_restock_inspection from shop_preferences.
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('require_restock_inspection');
        });

        // B. Restore items_status_check without 'pending_restock'.
        DB::unprepared(<<<'SQL'
ALTER TABLE items DROP CONSTRAINT IF EXISTS items_status_check;
ALTER TABLE items ADD CONSTRAINT items_status_check
    CHECK (status IN ('in_stock','sold','returned','melted','transferred','reversed','pending_listing'));
SQL);
    }
};
