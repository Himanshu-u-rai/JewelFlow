<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin's email (recovery channel) and mobile (login id) must not be
 * editable like ordinary profile fields. Changes are staged here until verified
 * by an OTP, so the current contact stays primary until the new one is proven.
 * The OTP itself is hashed in the cache, not stored on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email_verified_at');
            $table->string('pending_mobile')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_mobile']);
        });
    }
};
