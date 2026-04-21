<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_website_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('accent_color', 20)->default('#8f6a2d');
            $table->string('tagline', 150)->nullable();
            $table->string('hero_image_path')->nullable();
            $table->boolean('show_prices')->default(true);
            $table->boolean('show_weights')->default(true);
            $table->boolean('show_huid')->default(false);
            $table->text('meta_description')->nullable();
            $table->string('social_whatsapp', 20)->nullable();
            $table->string('social_instagram')->nullable();
            $table->string('social_facebook')->nullable();
            $table->json('featured_categories')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_website_settings');
    }
};
