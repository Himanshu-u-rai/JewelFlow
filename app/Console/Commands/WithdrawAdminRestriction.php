<?php

namespace App\Console\Commands;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw an administrative restriction that was applied by mistake, WITHOUT
 * granting any access the subscription has not paid for.
 *
 * WHY THIS EXISTS AND NOTHING ELSE WOULD DO
 *
 * Every existing admin action refuses this on purpose:
 *
 *   • ShopManagementController::updateStatus('active') clears the attribution,
 *     but it also sets access_mode=active — unpaid ERP — and is (correctly)
 *     blocked outright by the entitlesAccessToday() guard for a lapsed shop.
 *   • updateStatus('read_only'|'suspended') STAMPS suspended_by. It can impose
 *     a hold, never retract one.
 *   • BillingManagementController leaves every access column byte-identical
 *     when the shop is administratively held, by explicit design.
 *
 * So the platform can impose a hold and can fully restore a paid-up shop, but
 * has no way to say "this hold should never have existed; the shop was only
 * ever a subscription lapse". That is this command, and only that.
 *
 * WHAT IT CHANGES: shops.suspended_by → NULL, plus the updated_at bookkeeping
 * column. Nothing else. The MODE is not touched, so the shop stays exactly as
 * restricted as it is now; only the ATTRIBUTION is withdrawn. Because
 * suspended_by is the proof-positive discriminator, that single write is what
 * moves the shop from the administrative axis back onto the entitlement axis:
 *
 *   ShopSubscription::blocksNewPaidTerm()  true → false   owner may buy again
 *   EnsureSubscriptionIsActive             denyAdministrative() → recover()
 *   Shop::accessClassification()           admin_suspended → subscription_lapse
 *
 * Access itself does NOT widen: the shop keeps access_mode='suspended', the
 * reconciler re-derives the same 'suspended' from the lapsed subscription row,
 * and normal enforcement continues to apply.
 *
 * WHAT THE AUDIT HISTORY CAN AND CANNOT PROVE
 *
 * The history is shown so a human can confirm the restriction on the row is the
 * specific one they are withdrawing. It supports ATTRIBUTION; it can never
 * prove that no write happened. ShopManagementController::updateStatus() saves
 * the shop and THEN writes its audit entry, with no enclosing transaction — a
 * failure between the two leaves a state change with no record of it. So the
 * absence of an entry is not evidence of the absence of a write.
 *
 * What is checkable is RECONCILIATION: the latest access-relevant entry must
 * describe the row as it stands right now. If it does not, an unlogged write
 * happened and the command refuses rather than guessing.
 *
 * Default is a dry-run: it prints the current state, the audit history, the
 * proposed result, and writes NOTHING.
 *
 * ponytail: a console command, not a new admin screen. A permanent
 * hold-withdrawal button is a standing power the platform has deliberately not
 * granted, and this is a correction of a specific mistaken action. Promote it
 * to the UI only if withdrawing holds becomes routine.
 */
class WithdrawAdminRestriction extends Command
{
    protected $signature = 'shop:withdraw-admin-restriction
        {shop : shop id}
        {--authorized-by= : platform_admin id authorising this correction; recorded as the audit actor}
        {--reason= : why the restriction is being withdrawn (recorded in the platform audit log)}
        {--expect-suspended-by= : the administrator id whose restriction is being withdrawn}
        {--expect-updated-at= : shops.updated_at as printed by the dry run}
        {--expect-audit-event= : id of the latest access-relevant audit event, as printed by the dry run}
        {--commit : actually write the change (default is a dry-run)}';

    protected $description = 'Withdraw a mistakenly applied administrative restriction, leaving the shop as restricted as it is now';

    /**
     * Timestamp fingerprint format, matching the column: shops.updated_at is
     * `timestamp(0)` — SECOND precision, verified against the schema, not assumed.
     *
     * So this fingerprint CANNOT detect a second write landing inside the same
     * second as the one it recorded, and asking for microseconds would only
     * print a decorative ".000000" that implies a discrimination the column does
     * not have. That limit is why it is not the only assertion required:
     * --expect-suspended-by pins the holding administrator and
     * --expect-audit-event pins the reviewed event, and none of the three is
     * optional.
     */
    private const TS = 'Y-m-d H:i:s';

    public function __construct(private PlatformAuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $shop = Shop::find((int) $this->argument('shop'));
        if (! $shop) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        $history = $this->accessHistory($shop->id);

        if ($fault = $this->refusalReason($shop, $history, $commit)) {
            $this->error($fault);

            return self::FAILURE;
        }

        $this->renderCurrent($shop);
        $this->renderHistory($shop, $history);
        $this->renderProposed($shop);

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Confirm the highlighted event is the action you are withdrawing, then re-run with:');
            $this->line(sprintf(
                '  php artisan shop:withdraw-admin-restriction %d \\'
                . PHP_EOL . '    --authorized-by=<platform_admin id> --reason="…" \\'
                . PHP_EOL . '    --expect-suspended-by=%s --expect-updated-at="%s" --expect-audit-event=%s --commit',
                $shop->id,
                $shop->suspended_by,
                $shop->updated_at?->format(self::TS),
                $history->last()?->id
            ));

            return self::SUCCESS;
        }

        $admin = PlatformAdmin::find((int) $this->option('authorized-by'));

        try {
            DB::transaction(function () use ($shop, $admin) {
                // Re-read under the row lock and re-check EVERY guard against the
                // locked row. The dry run and the commit are two separate operator
                // actions minutes apart; anything decided on the unlocked snapshot
                // is decided on stale data.
                $locked = Shop::whereKey($shop->id)->lockForUpdate()->first();

                if ($fault = $this->refusalReason($locked, $this->accessHistory($shop->id), true)) {
                    throw new \RuntimeException($fault);
                }

                // Deliberately NOT $locked->save(). Shop::booted() registers a
                // `saving` hook that lowercases and trims owner_email / shop_email,
                // so an Eloquent save on a row holding an unnormalised address
                // would silently rewrite customer data alongside the correction and
                // break the "one column" promise. A query-builder update fires no
                // model events and touches only the named columns.
                //
                // The suspended_by predicate makes this a compare-and-swap at the
                // database level as well as in the guards above.
                $affected = Shop::query()
                    ->whereKey($locked->id)
                    ->where('suspended_by', $locked->suspended_by)
                    ->update(['suspended_by' => null]);

                if ($affected !== 1) {
                    throw new \RuntimeException("Refusing: the compare-and-swap matched {$affected} rows, not 1. Nothing written.");
                }

                // The correction and its record commit together or not at all.
                // PlatformAuditService::log() returns void and RETURNS SILENTLY
                // when it cannot resolve an actor, so a successful call is not
                // evidence that anything was written — the row is verified below.
                $highWater = (int) PlatformAuditLog::max('id');

                $this->audit->log(
                    $admin,
                    'shop.admin_restriction_withdrawn',
                    Shop::class,
                    $locked->id,
                    $this->accessColumns($locked),
                    $this->accessColumns($locked->fresh()),
                    (string) $this->option('reason'),
                );

                $written = PlatformAuditLog::query()
                    ->where('id', '>', $highWater)
                    ->where('action', 'shop.admin_restriction_withdrawn')
                    ->where('target_type', Shop::class)
                    ->where('target_id', $locked->id)
                    ->exists();

                if (! $written) {
                    throw new \RuntimeException('Refusing: the audit record was not written. Rolling the correction back — an unrecorded correction is not acceptable.');
                }
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Success is printed only here, AFTER the transaction has committed.
        $this->newLine();
        $this->info('Committed. New state:');
        $this->renderCurrent($shop->fresh());

        return self::SUCCESS;
    }

    /**
     * Every precondition, in one place, so the dry run and the locked commit
     * apply the IDENTICAL test. Returns the refusal message, or null to proceed.
     * Fails closed: anything it cannot positively prove is a refusal.
     */
    private function refusalReason(?Shop $shop, Collection $history, bool $commit): ?string
    {
        if (! $shop) {
            return 'Shop disappeared.';
        }

        if (! $shop->suspensionIsAdministrative()) {
            return "Shop #{$shop->id} is not under an administrative restriction — there is no attribution to withdraw.";
        }

        // Enforcement OFF turns this correction into a giveaway: the withdrawn
        // attribution makes the row subscription-managed, and then
        // EnsureSubscriptionIsActive::restoreIfSubscriptionManagedSuspension()
        // heals it to access_mode='active' on the shop's very next page view —
        // full unpaid ERP access, which is the one thing this must not do.
        if (! config('platform.enforce_subscriptions', false)) {
            return 'Refusing: platform.enforce_subscriptions is OFF, so withdrawing the attribution would auto-restore this shop to full access on its next request.';
        }

        // A shop whose term genuinely covers today is a different decision: the
        // hold is the only thing restricting it, so withdrawing it RESTORES
        // access. That is an access ruling, and belongs in the admin screen with
        // an operator looking at it, not in a data-correction command.
        if (ShopSubscription::entitlesAccessToday($shop)) {
            return "Refusing: shop #{$shop->id} holds a subscription term covering today, so withdrawing the hold would restore access. Use the admin shop screen for that decision.";
        }

        if ($fault = $this->reconciliationFault($shop, $history)) {
            return $fault;
        }

        // Simulate the post-state on a SEPARATE, uncached instance so nothing is
        // mutated here. If the shop would not land on a clean subscription lapse,
        // the reason text does not corroborate one, and withdrawing the
        // attribution would leave it unattributed-and-unexplained rather than
        // recovered. Refuse instead of rewriting the reason — inventing a
        // corroborating string is exactly how an administrative hold gets
        // laundered into a lapse.
        $simulated = $this->simulate($shop);
        if ($simulated->accessClassification() !== 'subscription_lapse') {
            return sprintf(
                'Refusing: with the attribution withdrawn shop #%d would classify as "%s", not "subscription_lapse". Its suspension_reason (%s) does not corroborate a lapse.',
                $shop->id,
                $simulated->accessClassification(),
                json_encode($shop->suspension_reason)
            );
        }

        if (! $commit) {
            return null;
        }

        // ── From here down: assertions the operator must supply explicitly. ──
        // None of these may default. A missing value is a refusal, not a skip:
        // an unasserted safeguard is indistinguishable from an absent one.

        if (blank($this->option('reason'))) {
            return '--reason is required when committing. It is the audit record of why the hold was withdrawn.';
        }

        $admin = $this->authorizingAdminFault();
        if (is_string($admin)) {
            return $admin;
        }

        $expectedActor = $this->option('expect-suspended-by');
        if (blank($expectedActor)) {
            return '--expect-suspended-by is required when committing. Name the administrator whose restriction you are withdrawing.';
        }
        if ((int) $expectedActor !== (int) $shop->suspended_by) {
            return sprintf(
                'Refusing: shop #%d is held by administrator #%s, not the #%s you named. A different administrator has restricted this shop since it was inspected.',
                $shop->id,
                $shop->suspended_by,
                $expectedActor
            );
        }

        $expectedUpdatedAt = $this->option('expect-updated-at');
        if (blank($expectedUpdatedAt)) {
            return '--expect-updated-at is required when committing. Pass the value the dry run printed.';
        }
        if ($shop->updated_at?->format(self::TS) !== (string) $expectedUpdatedAt) {
            return sprintf(
                'Refusing: shop #%d has been modified since it was inspected (expected updated_at "%s", found "%s").',
                $shop->id,
                $expectedUpdatedAt,
                $shop->updated_at?->format(self::TS)
            );
        }

        // Binds the correction to the exact reviewed event. A later hold — even
        // one by the SAME administrator carrying the SAME reason text, which is
        // byte-identical in the shop's own columns — appends a new audit row, so
        // the latest id moves and this refuses. It is the only safeguard here
        // that can see that case at all.
        $expectedEvent = $this->option('expect-audit-event');
        if (blank($expectedEvent)) {
            return '--expect-audit-event is required when committing. Pass the latest access-relevant event id the dry run printed.';
        }
        if ((int) $expectedEvent !== (int) $history->last()?->id) {
            return sprintf(
                'Refusing: the latest access-relevant audit event for shop #%d is now #%s, not the #%s you reviewed. A further administrative change has been recorded — re-inspect before withdrawing anything.',
                $shop->id,
                $history->last()?->id ?? 'none',
                $expectedEvent
            );
        }

        return null;
    }

    /**
     * The authorising administrator must be named, exist, and be active. The
     * default PlatformAuditService actor fallback is NOT acceptable here: it
     * silently attributes the correction to the lowest-id super_admin — someone
     * who did not authorise it — and writes nothing at all when no super_admin
     * exists. A correction nobody is named for is not auditable.
     *
     * Returns a refusal string, or null when the admin is valid.
     */
    private function authorizingAdminFault(): ?string
    {
        $id = $this->option('authorized-by');
        if (blank($id)) {
            return '--authorized-by is required when committing. Name the platform administrator authorising this correction.';
        }

        $admin = PlatformAdmin::find((int) $id);
        if (! $admin) {
            return "Refusing: no platform administrator #{$id} exists to authorise this correction.";
        }
        if (! $admin->is_active) {
            return "Refusing: platform administrator #{$id} is not active.";
        }
        if ($admin->role !== 'super_admin') {
            return "Refusing: platform administrator #{$id} is a '{$admin->role}', not a super_admin.";
        }

        return null;
    }

    /**
     * The current row must be described by the latest access-relevant audit
     * entry. This does NOT prove no write occurred — updateStatus() saves the
     * shop before logging, unTRANSACTIONED, so a write can exist with no entry —
     * but a MISMATCH is positive evidence that one did, and that is refusable.
     */
    private function reconciliationFault(Shop $shop, Collection $history): ?string
    {
        $latest = $history->last();

        if (! $latest) {
            return sprintf(
                'Refusing: shop #%d is administratively held but has no access-relevant audit history, so the restriction cannot be attributed to any recorded action.',
                $shop->id
            );
        }

        $after = $this->snapshot($latest->after);

        foreach (['access_mode', 'suspended_by'] as $column) {
            if (! array_key_exists($column, $after)) {
                return sprintf(
                    'Refusing: the latest access-relevant audit event (#%d, %s) does not record %s, so the current state cannot be reconciled against it.',
                    $latest->id,
                    $latest->action,
                    $column
                );
            }
        }

        if ((string) $after['access_mode'] !== (string) $shop->access_mode
            || (int) $after['suspended_by'] !== (int) $shop->suspended_by) {
            return sprintf(
                'Refusing: shop #%d does not match its latest audit event (#%d, %s). Recorded after-state was access_mode=%s / suspended_by=%s; the row now reads access_mode=%s / suspended_by=%s. A write happened that was never logged — investigate before correcting anything.',
                $shop->id,
                $latest->id,
                $latest->action,
                json_encode($after['access_mode']),
                json_encode($after['suspended_by']),
                json_encode($shop->access_mode),
                json_encode($shop->suspended_by)
            );
        }

        return null;
    }

    /**
     * Access-relevant platform audit entries for this shop, scoped by BOTH
     * target_type and target_id — target_id alone collides with every other
     * audited model that happens to share an id.
     */
    private function accessHistory(int $shopId): Collection
    {
        return PlatformAuditLog::query()
            ->where('target_type', Shop::class)
            ->where('target_id', $shopId)
            ->orderBy('id')
            ->get()
            ->filter(fn (PlatformAuditLog $e) => $this->snapshot($e->before) !== [] || $this->snapshot($e->after) !== [])
            ->values();
    }

    /**
     * Normalises an audit side to the access columns. Entries are written in two
     * shapes: flat (ShopManagementController, the middlewares) and nested under
     * 'shop' (BillingManagementController). Returns [] for an entry that says
     * nothing about access at all.
     */
    private function snapshot($side): array
    {
        if (! is_array($side)) {
            return [];
        }

        $data = is_array($side['shop'] ?? null) ? $side['shop'] : $side;

        return array_intersect_key(
            $data,
            array_flip(['access_mode', 'suspended_by', 'suspension_reason', 'is_active'])
        );
    }

    /**
     * A separate, uncached instance carrying the proposed change IN MEMORY only.
     * Never saved. accessClassification() memoises per instance, so the live
     * $shop cannot be reused here — it would return its already-computed
     * pre-change answer and the dry run would show the current state twice.
     */
    private function simulate(Shop $shop): Shop
    {
        $simulated = Shop::find($shop->id);
        $simulated->suspended_by = null;

        return $simulated;
    }

    private function accessColumns(Shop $shop): array
    {
        return $shop->only([
            'is_active',
            'access_mode',
            'suspended_at',
            'suspended_by',
            'suspension_reason',
            'suspended_until',
        ]);
    }

    private function renderCurrent(Shop $shop): void
    {
        $this->newLine();
        $this->line("<comment>Shop #{$shop->id} — stored access columns</comment>");
        $this->table(['column', 'value'], [
            ['access_mode', $shop->access_mode ?? 'NULL'],
            ['is_active', var_export((bool) $shop->is_active, true)],
            ['suspended_by', $shop->suspended_by ?? 'NULL'],
            ['suspension_reason', $shop->suspension_reason ?? 'NULL'],
            ['suspended_at', (string) ($shop->suspended_at ?? 'NULL')],
            ['suspended_until', (string) ($shop->suspended_until ?? 'NULL')],
            ['updated_at (fingerprint)', $shop->updated_at?->format(self::TS) ?? 'NULL'],
        ]);
    }

    private function renderHistory(Shop $shop, Collection $history): void
    {
        $this->newLine();
        $this->line('<comment>Access-relevant platform audit history</comment> (target_type=' . Shop::class . ", target_id={$shop->id})");

        $imposing = $this->imposingEvent($shop, $history);

        $rows = $history->map(function (PlatformAuditLog $e) use ($imposing, $history) {
            $before = $this->snapshot($e->before);
            $after = $this->snapshot($e->after);

            $marker = match (true) {
                $imposing && $e->id === $imposing->id => '→ WITHDRAWING',
                $imposing && $e->id > $imposing->id   => '   later change',
                $e->id === $history->last()?->id      => '   latest',
                default                               => '',
            };

            return [
                $e->id,
                $e->action,
                $e->actor_admin_id ?? 'system',
                $e->created_at?->format('Y-m-d H:i:s') ?? '—',
                sprintf(
                    '%s → %s',
                    json_encode($before['access_mode'] ?? null) . '/' . json_encode($before['suspended_by'] ?? null),
                    json_encode($after['access_mode'] ?? null) . '/' . json_encode($after['suspended_by'] ?? null)
                ),
                (string) ($after['suspension_reason'] ?? $e->reason ?? ''),
                $marker,
            ];
        })->all();

        $this->table(
            ['event', 'action', 'actor', 'at', 'access_mode/suspended_by', 'reason', ''],
            $rows
        );

        if ($imposing && $imposing->id !== $history->last()?->id) {
            $this->warn(sprintf(
                'There are %d later access-relevant event(s) after #%d. Confirm none of them is a legitimate restriction before withdrawing.',
                $history->where('id', '>', $imposing->id)->count(),
                $imposing->id
            ));
        }

        $this->line('<comment>Note:</comment> updateStatus() saves the shop before writing its audit entry and does not wrap the pair in a transaction. This history supports attribution; a missing entry does NOT guarantee no write occurred.');
    }

    /**
     * The latest entry that SET suspended_by to its current value from something
     * different — the action being withdrawn. Scanning backwards deliberately
     * tolerates a long prior history; only the most recent imposition matters.
     */
    private function imposingEvent(Shop $shop, Collection $history): ?PlatformAuditLog
    {
        return $history->reverse()->first(function (PlatformAuditLog $e) use ($shop) {
            $before = $this->snapshot($e->before);
            $after = $this->snapshot($e->after);

            return array_key_exists('suspended_by', $after)
                && (int) $after['suspended_by'] === (int) $shop->suspended_by
                && (int) ($before['suspended_by'] ?? 0) !== (int) $shop->suspended_by;
        });
    }

    private function renderProposed(Shop $shop): void
    {
        $simulated = $this->simulate($shop);
        $subscription = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();

        $this->newLine();
        $this->line('<comment>Proposed result</comment> (simulated in memory — nothing written)');
        $this->table(['', 'current', 'proposed'], [
            ['suspended_by', $shop->suspended_by ?? 'NULL', $simulated->suspended_by ?? 'NULL'],
            ['access_mode', $shop->access_mode ?? 'NULL', $simulated->access_mode ?? 'NULL'],
            ['classification', $shop->accessClassification(), $simulated->accessClassification()],
            [
                'owner may buy a plan',
                var_export(! ShopSubscription::blocksNewPaidTerm($subscription, $shop), true),
                var_export(! ShopSubscription::blocksNewPaidTerm($subscription, $simulated), true),
            ],
            [
                'entitled to ERP today',
                var_export(ShopSubscription::entitlesAccessToday($shop), true),
                var_export(ShopSubscription::entitlesAccessToday($simulated), true),
            ],
        ]);
        $this->line('Columns written: suspended_by (set to NULL), plus the updated_at bookkeeping stamp the query builder refreshes. No other shop field, and no subscription row, is touched.');
    }
}
