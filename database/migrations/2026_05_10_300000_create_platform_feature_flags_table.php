<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->boolean('enabled')->default(false);
            $table->string('scope', 20)->default('global'); // global | shop
            $table->unsignedBigInteger('scope_id')->nullable(); // shop_id when scope=shop
            $table->text('description')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['key', 'scope', 'scope_id']);
            $table->index(['key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_feature_flags');
    }
};
