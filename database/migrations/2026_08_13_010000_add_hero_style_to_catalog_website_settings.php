<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a shop choose how the public homepage hero is filled:
     *   image  — the uploaded banner (hero_image_path)
     *   color  — a solid colour (hero_bg_color)
     *   plain  — the default light background
     *
     * Default 'image' preserves existing behaviour: a shop that already
     * uploaded a banner keeps showing it; a shop with no banner falls back to
     * the plain hero at render time.
     */
    public function up(): void
    {
        Schema::table('catalog_website_settings', function (Blueprint $table) {
            $table->string('hero_style', 10)->default('image')->after('hero_image_path');
            $table->string('hero_bg_color', 20)->nullable()->after('hero_style');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_website_settings', function (Blueprint $table) {
            $table->dropColumn(['hero_style', 'hero_bg_color']);
        });
    }
};
