<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_bill_items', function (Blueprint $table) {
            if (!Schema::hasColumn('quick_bill_items', 'hallmark_charge')) {
                $table->decimal('hallmark_charge', 12, 2)->default(0)->after('stone_charge');
            }
            if (!Schema::hasColumn('quick_bill_items', 'rhodium_charge')) {
                $table->decimal('rhodium_charge', 12, 2)->default(0)->after('hallmark_charge');
            }
            if (!Schema::hasColumn('quick_bill_items', 'other_charge')) {
                $table->decimal('other_charge', 12, 2)->default(0)->after('rhodium_charge');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quick_bill_items', function (Blueprint $table) {
            $drops = [];
            foreach (['hallmark_charge', 'rhodium_charge', 'other_charge'] as $column) {
                if (Schema::hasColumn('quick_bill_items', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
