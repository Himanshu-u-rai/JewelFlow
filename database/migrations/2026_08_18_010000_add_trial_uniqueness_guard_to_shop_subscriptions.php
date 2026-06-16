<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level guard against duplicate free trials (defence in depth for the
 * application's one-trial-per-family check).
 *
 * A trial subscription is identified by price_paid = 0 AND razorpay_payment_id
 * IS NULL. The paid path is already protected by the UNIQUE razorpay_payment_id
 * constraint (+ a UniqueConstraintViolationException catch), but trials set that
 * column NULL and Postgres allows unlimited NULLs — so two concurrent
 * /subscription/trial/start requests could both pass the SELECT-then-INSERT
 * eligibility check and both create a trial row.
 *
 * This partial unique index stops two trial rows for the SAME (user, plan) from
 * ever coexisting, so the race resolves at the database with a unique-violation
 * (which startTrial() now catches). The cross-plan-same-family case
 * (retailer vs manufacturer, both in the 'erp' family) is still enforced at the
 * application layer by hasUsedTrialForFamily(); this index is the last-line
 * backstop for the exact-duplicate race.
 *
 * Keyed on user_id (not shop_id) because a trial is created with shop_id = NULL
 * in the pre-shop onboarding flow — user_id is always present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS shop_subscriptions_trial_unique
            ON shop_subscriptions (user_id, plan_id)
            WHERE price_paid = 0 AND razorpay_payment_id IS NULL AND user_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS shop_subscriptions_trial_unique');
    }
};
