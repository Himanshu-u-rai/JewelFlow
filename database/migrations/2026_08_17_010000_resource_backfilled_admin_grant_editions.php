<?php

use App\Models\Platform\ShopSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-source the Phase-2 backfilled admin_grant editions that are actually paying
 * (or lapsed-paying) customers, NOT deliberate platform comps.
 *
 * WHY
 * ---
 * The migration 2026_08_16_010200 backfilled EVERY pre-existing shop_editions
 * row to source='admin_grant'. Combined with the (now-fixed) gate bug — where
 * admin_grant AND seed both short-circuited to "always writable" — that meant
 * any pre-Phase-2 shop could write forever, paywall bypassed, regardless of its
 * subscription. Even with the gate fixed (admin_grant alone stays always-
 * writable by design), leaving these real customers as admin_grant would keep
 * granting them free, lapse-proof access. They must be enforced like any
 * subscription customer.
 *
 * WHAT
 * ----
 * For every ACTIVE (deactivated_at IS NULL) shop_editions row with
 * source='admin_grant' whose shop has AT LEAST ONE subscription (any status)
 * whose plan grants THAT edition string: relabel source → 'seed'.
 *
 * A 'seed' edition is lapse-immune at the ROW level (never auto-deleted, so a
 * shop is never orphaned) but is NOT a free write — the gate requires a writable
 * subscription (active/trial/grace) to back it. That is exactly the correct
 * treatment for a real customer: their row survives, but their access follows
 * their payment.
 *
 * LEFT UNTOUCHED
 * --------------
 * admin_grant rows whose shop has NO subscription for that edition's product are
 * genuinely ambiguous "free access" — could be real comps (demo / pilot /
 * internal / sales-trial / support) or could be freeloaders. We do NOT touch
 * them; a human reviews the printed list below and decides.
 *
 * SAFETY
 * ------
 *   - Idempotent: only flips admin_grant → seed when a matching sub exists;
 *     re-running finds no more admin_grant rows to flip (they're seed now).
 *   - Never deactivates / deletes a row. Never changes access for a currently-
 *     writable shop (a row that WAS writable via admin_grant and has a writable
 *     sub stays writable as seed; a row that was writable via admin_grant with
 *     only a lapsed sub becomes correctly enforced — which is the whole point,
 *     and is NOT a "currently-entitled" shop being cut off).
 *   - Wrapped in a transaction.
 *
 * Edition resolution uses the SAME mapping the app uses (Plan::grantsEdition(),
 * which resolves via PlatformProduct when platform_product_id is set, with a
 * code-prefix fallback — identical to the runtime gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $relabeled = [];      // [ ['shop_id'=>.., 'edition'=>..] ]
        $untouched = [];      // admin_grant rows with NO matching sub

        DB::transaction(function () use (&$relabeled, &$untouched): void {
            // All ACTIVE admin_grant rows.
            $rows = DB::table('shop_editions')
                ->whereNull('deactivated_at')
                ->where('source', 'admin_grant')
                ->get(['id', 'shop_id', 'edition']);

            if ($rows->isEmpty()) {
                return;
            }

            // Pre-load every subscription for the affected shops (any status),
            // with plan + product, so grantsEdition() resolves exactly as the app.
            $shopIds = $rows->pluck('shop_id')->unique()->all();

            $subsByShop = ShopSubscription::query()
                ->whereIn('shop_id', $shopIds)
                ->with('plan.platformProduct')
                ->get()
                ->groupBy('shop_id');

            foreach ($rows as $row) {
                $editionsBacked = ($subsByShop[$row->shop_id] ?? collect())
                    ->map(fn (ShopSubscription $sub) => $sub->plan?->grantsEdition())
                    ->filter()
                    ->unique()
                    ->all();

                // Does the shop have ANY subscription whose plan grants THIS
                // edition string? (A real paying/lapsed customer for this product.)
                if (in_array($row->edition, $editionsBacked, true)) {
                    DB::table('shop_editions')
                        ->where('id', $row->id)
                        ->update(['source' => DB::raw("'seed'")]);

                    $relabeled[] = ['shop_id' => $row->shop_id, 'edition' => $row->edition];
                } else {
                    $untouched[] = ['shop_id' => $row->shop_id, 'edition' => $row->edition];
                }
            }
        });

        // ── Summary (audit / human review) ──────────────────────────
        $summary = sprintf(
            'resource_backfilled_admin_grant_editions: relabeled %d admin_grant→seed (real subscription customers); left %d admin_grant row(s) untouched (no backing subscription — review for genuine comp vs freeloader).',
            count($relabeled),
            count($untouched)
        );

        Log::info($summary, [
            'relabeled' => $relabeled,
            'untouched_admin_grant_no_subscription' => $untouched,
        ]);

        // Also echo for `artisan migrate` console visibility.
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $summary . PHP_EOL);
            if ($untouched !== []) {
                fwrite(STDOUT, 'Untouched admin_grant rows (no subscription — REVIEW):' . PHP_EOL);
                foreach ($untouched as $u) {
                    fwrite(STDOUT, sprintf('  - shop_id=%d edition=%s%s', $u['shop_id'], $u['edition'], PHP_EOL));
                }
            }
        }
    }

    /**
     * down() is intentionally a logged NO-OP.
     *
     * The original source for BOTH genuine comps and real customers was
     * 'admin_grant' (the blanket Phase-2 backfill), so this migration is NOT
     * losslessly reversible — we cannot tell, after the fact, which of today's
     * 'seed' rows we created vs. which were seeded by normal onboarding. Reverting
     * our relabeled rows back to 'admin_grant' would also REINTRODUCE the paywall
     * bypass for those exact customers. We therefore do nothing on rollback and
     * log a warning. The forward migration is safe and idempotent; re-running up()
     * after a deploy rollback simply re-applies the (already-correct) state.
     */
    public function down(): void
    {
        Log::warning(
            'resource_backfilled_admin_grant_editions: down() is a deliberate no-op. '
            . 'The admin_grant→seed relabel is not reversible (the original blanket backfill '
            . 'used admin_grant for both comps and real customers) and reverting would '
            . 'reintroduce the paywall-bypass for those customers. No rows changed.'
        );
    }
};
