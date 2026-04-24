<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'metal_lot_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE items ALTER COLUMN metal_lot_id DROP NOT NULL');

            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedBigInteger('metal_lot_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'metal_lot_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE items ALTER COLUMN metal_lot_id SET NOT NULL');

            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedBigInteger('metal_lot_id')->nullable(false)->change();
        });
    }
};
