<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('slug', 100);
            $table->longText('content')->nullable();
            $table->string('type', 20)->default('custom'); // about, terms, contact, custom
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_pages');
    }
};
