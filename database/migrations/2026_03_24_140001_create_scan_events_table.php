<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scan_session_id');
            $table->string('barcode');
            $table->boolean('processed')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scan_session_id', 'processed']);

            $table->foreign('scan_session_id')
                  ->references('id')
                  ->on('scan_sessions')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_events');
    }
};
