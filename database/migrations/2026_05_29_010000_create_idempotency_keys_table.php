<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys for /api/mobile/v1/ mutation routes.
 *
 * Backs the `mobile.idempotency` middleware (App\Http\Middleware\EnsureIdempotency).
 * NOT an accounting ledger — this is a short-lived cache table, pruned by the
 * `mobile:prune-idempotency-keys` console command.
 *
 * @see app/Http/Middleware/EnsureIdempotency.php
 * @see app/Console/Commands/PruneIdempotencyKeys.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_keys')) {
            return;
        }

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('key', 80);
            $table->string('request_hash', 64);
            $table->smallInteger('response_status');
            $table->jsonb('response_body')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Lookup path: (shop_id, user_id, key). Unique so two concurrent writes
            // for the same key race on the constraint — the loser is correctly
            // rejected as a conflict by the middleware.
            $table->unique(['shop_id', 'user_id', 'key'], 'idempotency_keys_scope_key_unique');

            // Pruning path: PruneIdempotencyKeys scans by created_at.
            $table->index('created_at', 'idempotency_keys_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
