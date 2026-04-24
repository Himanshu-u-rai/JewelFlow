<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_slug_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS categories_shop_slug_unique_idx ON categories (shop_id, slug)');

            return;
        }

        try {
            Schema::table('categories', function ($table): void {
                $table->dropUnique('categories_slug_unique');
            });
        } catch (\Throwable) {
            // Global unique may already be absent on non-PostgreSQL drivers.
        }

        Schema::table('categories', function ($table): void {
            $table->unique(['shop_id', 'slug'], 'categories_shop_slug_unique_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS categories_shop_slug_unique_idx');
            DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_slug_unique UNIQUE (slug)');

            return;
        }

        try {
            Schema::table('categories', function ($table): void {
                $table->dropUnique('categories_shop_slug_unique_idx');
            });
        } catch (\Throwable) {
            // Ignore missing index on down for non-PostgreSQL drivers.
        }

        Schema::table('categories', function ($table): void {
            $table->unique('slug', 'categories_slug_unique');
        });
    }
};
