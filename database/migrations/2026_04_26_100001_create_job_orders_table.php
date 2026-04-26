<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('karigar_id');

            $table->string('job_order_number', 30);
            $table->string('challan_number', 30)->nullable();

            $table->string('metal_type', 10);
            $table->decimal('purity', 5, 2);

            $table->decimal('issued_gross_weight', 18, 6)->default(0);
            $table->decimal('issued_fine_weight', 18, 6)->default(0);
            $table->decimal('expected_return_fine_weight', 18, 6)->default(0);
            $table->decimal('allowed_wastage_percent', 5, 2)->default(0);

            $table->string('status', 20)->default('issued');

            $table->date('issue_date');
            $table->date('expected_return_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->decimal('returned_gross_weight', 18, 6)->default(0);
            $table->decimal('returned_fine_weight', 18, 6)->default(0);
            $table->decimal('leftover_returned_fine_weight', 18, 6)->default(0);
            $table->decimal('actual_wastage_fine', 18, 6)->default(0);

            $table->json('discrepancy_flags')->nullable();
            $table->boolean('discrepancy_acknowledged')->default(false);

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('karigar_id')->references('id')->on('karigars')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'karigar_id']);
            $table->unique(['shop_id', 'job_order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
