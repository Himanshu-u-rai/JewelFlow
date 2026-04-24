<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('shop_preferences', 'language')) {
            Schema::table('shop_preferences', function (Blueprint $table) {
                $table->string('language', 10)->default('en')->after('currency_symbol');
            });
        }

        DB::table('shop_preferences')
            ->where(function ($query) {
                $query->whereNull('language')->orWhere('language', '');
            })
            ->update(['language' => 'en']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('shop_preferences', 'language')) {
            Schema::table('shop_preferences', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
    }
};

