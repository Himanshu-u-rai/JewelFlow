<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3-17. ExportReadyNotification is declared on the `database` channel, and the
 * frozen export plan requires the in-app notification carrying the download
 * link; no migration ever created the table that channel writes to, so every
 * queued export failed after its file was written.
 *
 * Laravel's standard notifications schema. Skipped where a table of that name
 * already exists: whether any server has one is not observed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    // ponytail: not dropped. up() may have skipped a table that already
    // existed, and dropping would delete notifications this never created.
    public function down(): void
    {
    }
};
