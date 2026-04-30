<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // overhead_cost = sum of all non-metal charges (making + stone + hallmark + rhodium + other).
            // cost_price remains the raw metal value only (net_metal_weight × rate_per_gram).
            // selling_price = cost_price + overhead_cost.
            // This separation lets the POS show true metal margin vs. total margin.
            $table->decimal('overhead_cost', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('overhead_cost');
        });
    }
};
