<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'hallmark_charges')) {
                $table->decimal('hallmark_charges', 12, 2)->default(0)->after('stone_charges');
            }
            if (! Schema::hasColumn('items', 'rhodium_charges')) {
                $table->decimal('rhodium_charges', 12, 2)->default(0)->after('hallmark_charges');
            }
            if (! Schema::hasColumn('items', 'other_charges')) {
                $table->decimal('other_charges', 12, 2)->default(0)->after('rhodium_charges');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['hallmark_charges', 'rhodium_charges', 'other_charges']);
        });
    }
};
