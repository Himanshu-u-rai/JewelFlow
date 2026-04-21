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
        Schema::table('repairs', function (Blueprint $table) {
            $table->decimal('gold_issued_fine', 18, 6)->nullable();
            $table->decimal('gold_returned_fine', 18, 6)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropColumn(['gold_issued_fine', 'gold_returned_fine']);
        });
    }
};
