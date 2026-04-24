<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'shop_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN shop_id DROP NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('shop_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'shop_id')) {
            return;
        }

        $orphans = DB::table('users')->whereNull('shop_id')->count();
        if ($orphans > 0) {
            throw new RuntimeException("Cannot enforce users.shop_id NOT NULL: {$orphans} users have NULL shop_id.");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN shop_id SET NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
        });
    }
};
