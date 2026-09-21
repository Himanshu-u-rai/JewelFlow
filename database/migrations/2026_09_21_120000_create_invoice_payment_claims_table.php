<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3-07b — durable idempotency claims for mobile invoice payments.
 *
 * WHY A NEW TABLE RATHER THAN `idempotency_keys`
 * ----------------------------------------------
 * `idempotency_keys` was inspected before this was written and does not fit,
 * for two independent reasons:
 *
 *   1. IDENTITY. It is UNIQUE on (shop_id, user_id, key). The legacy payment
 *      cache key is `invoice_payment_idempotency:{invoice}:{key}` — invoice and
 *      key, with NO user in it. Adopting that table would WIDEN the identity:
 *      two users in one shop retrying one key against one invoice would claim
 *      two separate rows and charge twice. That is a regression introduced by
 *      the repair, which is worse than the defect.
 *
 *      Collapsing `user_id` to NULL does not recover the narrower identity
 *      either. `user_id` is nullable there, and PostgreSQL treats NULLs as
 *      DISTINCT in a UNIQUE index, so every row would be unique and the
 *      constraint would stop constraining anything at all.
 *
 *   2. TRANSACTION BOUNDARIES. `EnsureIdempotency` records its key AFTER
 *      `$next($request)` returns, in a statement outside the controller's
 *      transaction. That is the same uncoordinated shape as the cache write
 *      this change exists to replace.
 *
 * So the identity here is exactly (invoice_id, key) — the legacy key's own
 * identity, preserved rather than reinterpreted.
 *
 * NOT AN ACCOUNTING LEDGER. This table records that a request was served and
 * what was returned. It holds no money column, participates in no balance, and
 * is not read by any accounting path. CONSTITUTION Article I does not reach it
 * and no protected trigger is added, altered or disabled here.
 *
 * ADDITIVE AND BASELINE-SAFE. A new table with no FK pointing INTO it. Code at
 * the deployed baseline neither reads nor writes it, so this migration can be
 * applied ahead of the application change with no behavioural effect, which is
 * the ordering the release runbook requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_payment_claims')) {
            return;
        }

        Schema::create('invoice_payment_claims', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('shop_id');

            // Audit only — deliberately NOT part of the uniqueness. Recording
            // who served the original request is useful; letting a second user
            // re-charge the same key is not.
            $table->unsignedBigInteger('user_id')->nullable();

            // Same 80-char budget as `idempotency_keys.key`, so a client that
            // satisfies the mobile v1 middleware also satisfies this route.
            $table->string('key', 80);

            // sha256 of METHOD|path|raw body. Absent for legacy cache entries,
            // which is why those cannot be conflict-checked — see the
            // controller and P-11.
            $table->string('request_hash', 64);

            $table->smallInteger('response_status');
            $table->jsonb('response_body')->nullable();

            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // THE MUTUAL EXCLUSION. Two workers racing the same (invoice, key)
            // collide here; the loser's whole transaction rolls back, taking
            // its payment INSERT with it, and it then replays the winner's
            // committed claim.
            $table->unique(['invoice_id', 'key'], 'invoice_payment_claims_invoice_key_unique');

            // Pruning path, mirroring PruneIdempotencyKeys.
            $table->index('created_at', 'invoice_payment_claims_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_claims');
    }
};
