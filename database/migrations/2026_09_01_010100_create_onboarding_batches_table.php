<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding Phase 1 — orchestration/lock/audit for one shop's opening migration.
 *
 * No balances live here. Opening values are posted into the existing canonical
 * ledgers (cash_transactions, metal_lots, etc.) on lock, tagged back to this
 * batch. This table only owns: the as-of date, the state machine, and the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            // Opening effective date (= start_date − 1). Every posted opening row
            // is dated here so `created_at < start_date` classifies it as opening.
            $table->date('as_of_date');
            $table->date('start_date');
            $table->string('status')->default('draft'); // draft|review|posting|locked|cancelled
            $table->jsonb('totals_snapshot')->nullable(); // reconciled totals captured at lock
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        // One active (non-terminal) batch per shop. Terminal states (locked,
        // cancelled) are excluded so a shop can re-run onboarding after cancel
        // and keep its locked history. Race-proof at the DB level.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX onboarding_batches_one_active_per_shop
            ON onboarding_batches (shop_id)
            WHERE status NOT IN ('locked', 'cancelled')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_batches');
    }
};
