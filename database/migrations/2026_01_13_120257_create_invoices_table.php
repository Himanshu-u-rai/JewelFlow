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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->string('invoice_number')->unique();
            $table->foreignId('customer_id')->constrained('customers');

            $table->decimal('gold_rate', 12, 2); // locked rate at sale time
            $table->decimal('subtotal', 18, 2);
            $table->decimal('gst', 18, 2);
            $table->decimal('total', 18, 2);

            $table->string('status'); // draft, paid, cancelled

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
