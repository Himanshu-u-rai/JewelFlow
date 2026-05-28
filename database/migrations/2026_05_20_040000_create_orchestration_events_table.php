<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orchestration_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->string('entity_type', 50);
            $table->bigInteger('entity_id');
            $table->string('prompt_type', 80);
            $table->string('suggested_action', 80);
            $table->text('suggestion_reason');
            $table->string('user_decision', 80);
            $table->boolean('was_overridden')->default(false);
            $table->jsonb('context_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Primary lookup: all orchestration events for a given entity.
            $table->index(
                ['shop_id', 'entity_type', 'entity_id'],
                'orchestration_events_entity_idx'
            );

            // Override audit: filter by override flag and time for weekly reports.
            $table->index(
                ['shop_id', 'was_overridden', 'created_at'],
                'orchestration_events_override_audit_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_events');
    }
};
