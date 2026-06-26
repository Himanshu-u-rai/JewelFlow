<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform_admins password broker was configured but never wired to any
 * route, so its token table was left in the legacy mobile_number shape.
 * Laravel's DatabaseTokenRepository keys reset tokens on `email`, so reshape
 * the (empty, unused) table to the standard email/token/created_at form.
 *
 * Tokens are stored hashed by the broker; this table never holds a plaintext
 * token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('platform_admin_password_reset_tokens');

        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_password_reset_tokens');

        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('mobile_number')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
