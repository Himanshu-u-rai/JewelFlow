<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'ended_at']);
            $table->index(['shop_id', 'ended_at']);
            $table->index(['user_id', 'ended_at']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_impersonation_sessions');
    }
};
