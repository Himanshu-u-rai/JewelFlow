<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('catalog_slug', 80)->nullable()->unique()->after('name');
        });

        // Backfill existing shops with a slug derived from their name.
        $shops = DB::table('shops')->select('id', 'name')->get();

        foreach ($shops as $shop) {
            $base = Str::slug($shop->name) ?: 'shop';
            $slug = $base;
            $suffix = 1;

            while (DB::table('shops')->where('catalog_slug', $slug)->exists()) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }

            DB::table('shops')->where('id', $shop->id)->update(['catalog_slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('catalog_slug');
        });
    }
};
