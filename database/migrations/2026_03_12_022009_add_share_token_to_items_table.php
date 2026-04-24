<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'share_token')) {
                $table->string('share_token', 64)->nullable()->after('barcode');
                $table->unique('share_token', 'items_share_token_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'share_token')) {
                $table->dropUnique('items_share_token_unique');
                $table->dropColumn('share_token');
            }
        });
    }
};
