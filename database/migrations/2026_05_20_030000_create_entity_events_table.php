<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('entity_type', 50);
            $table->bigInteger('entity_id');
            $table->string('event_type', 80);
            $table->tinyInteger('level')->default(0)->comment('0=operational, 1=contextual, 2=detail');
            $table->text('summary');
            $table->jsonb('detail')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();

            // Primary lookup: all events for an entity, newest first.
            $table->index(
                ['shop_id', 'entity_type', 'entity_id', 'occurred_at'],
                'entity_events_entity_occurred_idx'
            );

            // Level-filtered lookup (e.g. fetch only operational events for a feed).
            $table->index(
                ['shop_id', 'entity_type', 'entity_id', 'level', 'occurred_at'],
                'entity_events_entity_level_occurred_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_events');
    }
};
