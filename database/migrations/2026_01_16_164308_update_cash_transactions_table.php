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
        Schema::table('cash_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_transactions', 'shop_id')) {
                $table->foreignId('shop_id')->constrained('shops');
            }

            if (!Schema::hasColumn('cash_transactions', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users');
            }

            if (!Schema::hasColumn('cash_transactions', 'type')) {
                $table->enum('type', ['in', 'out']);
            }

            if (!Schema::hasColumn('cash_transactions', 'amount')) {
                $table->decimal('amount', 18, 2);
            }

            if (!Schema::hasColumn('cash_transactions', 'source_type')) {
                $table->string('source_type');
            }

            if (!Schema::hasColumn('cash_transactions', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable();
            }

            if (!Schema::hasColumn('cash_transactions', 'description')) {
                $table->text('description')->nullable();
            }

            $table->index(['shop_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
