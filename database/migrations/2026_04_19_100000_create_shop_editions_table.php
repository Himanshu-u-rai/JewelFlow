<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the editions refactor (see docs/editions-and-identity-refactor.md).
 *
 * Introduces shop_editions as the source of truth for which services a shop
 * has active (retailer / manufacturer / dhiran). This migration ONLY creates
 * and seeds the new table — it does not change any reads. shops.shop_type
 * and dhiran_settings.is_enabled remain authoritative until Phase 2.
 *
 * Rollback is safe: dropping shop_editions loses no data (all source fields
 * are still present on shops / dhiran_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_editions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->string('edition', 16);

            $table->timestamp('activated_at')->useCurrent();
            $table->foreignId('activated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('deactivation_reason')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'edition']);
            $table->index('shop_id');
        });

        // Postgres CHECK constraint — blocks rogue edition values at the DB layer.
        DB::statement(<<<'SQL'
            ALTER TABLE shop_editions
            ADD CONSTRAINT shop_editions_edition_check
            CHECK (edition IN ('retailer', 'manufacturer', 'dhiran'))
        SQL);

        $this->seedFromExistingShops();
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_editions');
    }

    /**
     * Seed one edition row per existing shop's shop_type, plus a 'dhiran' row
     * for every shop whose dhiran_settings.is_enabled is true.
     *
     * Idempotent: uses ON CONFLICT DO NOTHING on the UNIQUE (shop_id, edition)
     * so a re-run (via migrate:refresh / rollback+up) never duplicates rows.
     */
    private function seedFromExistingShops(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO shop_editions (shop_id, edition, activated_at, created_at, updated_at)
            SELECT
                s.id,
                s.shop_type,
                COALESCE(s.created_at, NOW()),
                NOW(),
                NOW()
            FROM shops s
            WHERE s.shop_type IN ('retailer', 'manufacturer')
            ON CONFLICT (shop_id, edition) DO NOTHING
        SQL);

        if (Schema::hasTable('dhiran_settings')) {
            DB::statement(<<<'SQL'
                INSERT INTO shop_editions (shop_id, edition, activated_at, created_at, updated_at)
                SELECT
                    ds.shop_id,
                    'dhiran',
                    COALESCE(ds.updated_at, ds.created_at, NOW()),
                    NOW(),
                    NOW()
                FROM dhiran_settings ds
                WHERE ds.is_enabled = TRUE
                ON CONFLICT (shop_id, edition) DO NOTHING
            SQL);
        }
    }
};
