<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_render_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->json('snapshot');
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['shop_id', 'invoice_id']);
        });

        Schema::create('invoice_item_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->cascadeOnDelete();
            $table->json('snapshot');
            $table->timestamps();

            $table->unique('invoice_item_id');
            $table->index(['shop_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_item_snapshots');
        Schema::dropIfExists('invoice_render_snapshots');
    }
};

