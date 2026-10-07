<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_promotion_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 32);
            $table->string('realm', 16);
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target', 16);
            $table->string('choice', 24)->nullable();
            $table->timestamps();
            $table->unique(['environment', 'realm', 'shop_id', 'user_id', 'target'], 'product_promo_preference_identity');
        });
        Schema::create('product_promotion_exposures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preference_id')->constrained('product_promotion_preferences')->cascadeOnDelete();
            $table->string('campaign', 64);
            $table->timestamp('created_at');
            $table->unique(['preference_id', 'campaign'], 'product_promo_exposure_once');
        });
        Schema::create('product_recognition_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('environment', 32);
            $table->string('source_realm', 16);
            $table->foreignId('source_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('source_proof', 64);
            $table->string('code_hash', 64)->unique();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('target_proof', 64)->nullable();
            $table->timestamp('target_approved_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recognitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('environment', 32);
            foreach (['erp', 'dhiran'] as $realm) {
                $table->foreignId($realm.'_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId($realm.'_shop_id')->constrained('shops')->cascadeOnDelete();
                $table->string($realm.'_proof', 64);
                $table->timestamp($realm.'_approved_at');
            }
            $table->uuid('request_id');
            $table->string('purpose', 32)->default('promotion_suppression');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'erp_user_id', 'erp_shop_id', 'dhiran_user_id', 'dhiran_shop_id'], 'product_recognition_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recognitions');
        Schema::dropIfExists('product_recognition_requests');
        Schema::dropIfExists('product_promotion_exposures');
        Schema::dropIfExists('product_promotion_preferences');
    }
};
