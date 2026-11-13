<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit N14 (2026-11-13): opening balances are posted once per shop, and a posted batch is final.
 *
 * - At most one locked (posted) batch per shop.
 * - A locked or cancelled batch never changes status and is never deleted.
 * - Entries change only while their batch is draft, review or being posted.
 *
 * CONSTITUTION §4 Rule 2: existing rows are checked first; the migration stops and names any shop that already has
 * two posted batches, for a person to resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        $doubled = DB::table('onboarding_batches')->where('status', 'locked')
            ->groupBy('shop_id')->havingRaw('count(*) > 1')->orderBy('shop_id')->pluck('shop_id');
        if ($doubled->isNotEmpty()) {
            throw new \RuntimeException('onboarding finality: shops with more than one posted onboarding batch; resolve them first. shop ids: '
                . $doubled->implode(', '));
        }

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX onboarding_batches_one_locked_per_shop
            ON onboarding_batches (shop_id)
            WHERE status = 'locked';

            CREATE OR REPLACE FUNCTION onboarding_batches_finality_guard()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status IN ('locked', 'cancelled') THEN
                        RAISE EXCEPTION 'An onboarding batch that is % is final and cannot be deleted (id=%)', OLD.status, OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status IN ('locked', 'cancelled') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'An onboarding batch that is % is final (id=%)', OLD.status, OLD.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS onboarding_batches_finality_guard_trigger ON onboarding_batches;
            CREATE TRIGGER onboarding_batches_finality_guard_trigger
            BEFORE UPDATE OR DELETE ON onboarding_batches
            FOR EACH ROW EXECUTE FUNCTION onboarding_batches_finality_guard();

            CREATE OR REPLACE FUNCTION onboarding_entries_open_batch_guard()
            RETURNS trigger AS $$
            DECLARE
                batch_status text;
            BEGIN
                SELECT status INTO batch_status FROM onboarding_batches
                WHERE id = COALESCE(NEW.onboarding_batch_id, OLD.onboarding_batch_id);
                IF batch_status IS NOT NULL AND batch_status NOT IN ('draft', 'review', 'posting') THEN
                    RAISE EXCEPTION 'Onboarding entries of a % batch cannot change (batch id=%)', batch_status,
                        COALESCE(NEW.onboarding_batch_id, OLD.onboarding_batch_id);
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS onboarding_entries_open_batch_guard_trigger ON onboarding_entries;
            CREATE TRIGGER onboarding_entries_open_batch_guard_trigger
            BEFORE INSERT OR UPDATE OR DELETE ON onboarding_entries
            FOR EACH ROW EXECUTE FUNCTION onboarding_entries_open_batch_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS onboarding_entries_open_batch_guard_trigger ON onboarding_entries;
            DROP FUNCTION IF EXISTS onboarding_entries_open_batch_guard();
            DROP TRIGGER IF EXISTS onboarding_batches_finality_guard_trigger ON onboarding_batches;
            DROP FUNCTION IF EXISTS onboarding_batches_finality_guard();
            DROP INDEX IF EXISTS onboarding_batches_one_locked_per_shop;
        SQL);
    }
};
