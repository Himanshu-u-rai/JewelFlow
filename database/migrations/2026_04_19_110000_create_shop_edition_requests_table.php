<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 of the editions refactor.
 *
 * Backs the user-side "Add service" flow. A shop owner can self-serve
 * REMOVE editions (guarded by data checks) but ADD requests must be
 * reviewed by a platform admin — who then grants the edition through
 * the Phase 4 admin UI. This table is the handoff between the two.
 *
 * Reversible: dropping the table only loses pending/historic requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_edition_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('action', 8);      // 'add' | 'remove'
            $table->string('edition', 16);    // 'retailer' | 'manufacturer' | 'dhiran'
            $table->text('reason');
            $table->string('status', 12)->default('pending'); // pending | approved | denied | cancelled

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shop_edition_requests
            ADD CONSTRAINT shop_edition_requests_action_check
            CHECK (action IN ('add', 'remove'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE shop_edition_requests
            ADD CONSTRAINT shop_edition_requests_edition_check
            CHECK (edition IN ('retailer', 'manufacturer', 'dhiran'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE shop_edition_requests
            ADD CONSTRAINT shop_edition_requests_status_check
            CHECK (status IN ('pending', 'approved', 'denied', 'cancelled'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_edition_requests');
    }
};
