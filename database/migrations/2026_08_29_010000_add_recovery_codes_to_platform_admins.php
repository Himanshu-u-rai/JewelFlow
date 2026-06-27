<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery codes are the lockout fallback for platform-admin MFA: if the admin
 * loses email/TOTP access they can still complete the login challenge. Only the
 * hashed codes are stored; the plaintext is shown to the admin exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->json('recovery_codes')->nullable()->after('two_factor_secret');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn('recovery_codes');
        });
    }
};
