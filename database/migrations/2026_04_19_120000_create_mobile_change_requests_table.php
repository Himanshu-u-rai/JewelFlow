<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 of the editions refactor.
 *
 * Backs the user-initiated mobile-number change flow. Email-OTP only:
 *   1. POST /profile/mobile/change-request  → inserts a row + emails OTP
 *   2. POST /profile/mobile/change-verify   → validates OTP, rotates mobile
 *
 * Separate from users.email_verify_otp (which is for email verification)
 * so both flows can run independently without clobbering each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_change_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('new_mobile_number', 15);
            $table->string('otp_hash');
            $table->timestamp('expires_at');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->string('requested_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_change_requests');
    }
};
