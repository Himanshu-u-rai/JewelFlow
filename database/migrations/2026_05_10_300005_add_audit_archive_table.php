<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirror of platform_audit_logs — holds records older than the retention window
        Schema::create('platform_audit_logs_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('archived_at')->useCurrent();

            $table->index('actor_admin_id');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs_archive');
    }
};
