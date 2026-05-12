<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_backup_log', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('success'); // success | failed
            $table->string('type', 30)->default('scheduled'); // scheduled | manual
            $table->bigInteger('size_bytes')->nullable();
            $table->string('filename', 500)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('triggered_by_admin_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_backup_log');
    }
};
