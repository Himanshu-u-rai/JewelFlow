<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('flag_type', 60); // invoice_spike | bulk_customers | cross_tenant_pan
            $table->json('flag_data')->nullable();
            $table->boolean('reviewed')->default(false);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'reviewed']);
            $table->index(['flag_type', 'reviewed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fraud_flags');
    }
};
