<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_purchases DROP CONSTRAINT IF EXISTS stock_purchases_status_check");
        DB::statement("ALTER TABLE stock_purchases ALTER COLUMN status TYPE VARCHAR(20)");
        DB::statement("ALTER TABLE stock_purchases ADD CONSTRAINT stock_purchases_status_check CHECK (status IN ('draft','confirmed','stocked'))");

        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->timestamp('stocked_at')->nullable()->after('confirmed_at');
            $table->foreignId('stocked_by_user_id')->nullable()->after('stocked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropForeign(['stocked_by_user_id']);
            $table->dropColumn(['stocked_at', 'stocked_by_user_id']);
        });
        DB::statement("ALTER TABLE stock_purchases DROP CONSTRAINT IF EXISTS stock_purchases_status_check");
        DB::statement("ALTER TABLE stock_purchases ADD CONSTRAINT stock_purchases_status_check CHECK (status IN ('draft','confirmed'))");
    }
};
