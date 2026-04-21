<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('item_id')->constrained('items');

            $table->decimal('weight', 18, 6);
            $table->decimal('rate', 12, 2);
            $table->decimal('making_charges', 18, 2);
            $table->decimal('stone_amount', 18, 2);
            $table->decimal('line_total', 18, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
