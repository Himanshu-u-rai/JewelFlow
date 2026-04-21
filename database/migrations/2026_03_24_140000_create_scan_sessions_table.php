<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('token', 64)->unique();
            $table->enum('status', ['active', 'expired'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_sessions');
    }
};
