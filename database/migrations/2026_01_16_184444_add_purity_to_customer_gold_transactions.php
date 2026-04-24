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
        Schema::table('customer_gold_transactions', function (Blueprint $table) {

            if (!Schema::hasColumn('customer_gold_transactions', 'purity')) {
                $table->decimal('purity', 5, 2)->nullable();
            }

            if (!Schema::hasColumn('customer_gold_transactions', 'gross_weight')) {
                $table->decimal('gross_weight', 10, 3)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_gold_transactions', function (Blueprint $table) {
            //
        });
    }
};
