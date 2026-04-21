<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_step', 30)->nullable()->after('is_active');
            $table->string('onboarding_shop_type', 20)->nullable()->after('onboarding_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_shop_type']);
        });
    }
};
