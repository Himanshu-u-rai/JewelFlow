<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'barcode') || !Schema::hasColumn('items', 'shop_id')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_barcode_unique');
            DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_shop_barcode_unique');
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unique(['shop_id', 'barcode'], 'items_shop_barcode_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_shop_barcode_unique');
        } else {
            Schema::table('items', function (Blueprint $table) {
                $table->dropUnique('items_shop_barcode_unique');
            });
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unique('barcode', 'items_barcode_unique');
        });
    }
};

