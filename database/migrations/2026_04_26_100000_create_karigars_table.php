<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karigars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');

            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 12)->nullable();

            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 20)->nullable();

            $table->decimal('default_wastage_percent', 5, 2)->nullable();
            $table->decimal('default_making_per_gram', 12, 2)->nullable();

            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_at')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karigars');
    }
};
