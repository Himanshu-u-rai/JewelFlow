<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items') || Schema::hasColumn('items', 'images')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->json('images')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('items') || ! Schema::hasColumn('items', 'images')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('images');
        });
    }
};
