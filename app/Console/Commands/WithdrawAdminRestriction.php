<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw an administrative restriction that was applied by mistake, WITHOUT
 * granting any access the subscription does not pay for.
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
 * WHAT IT CHANGES: shops.suspended_by → NULL. One column. The MODE is not
 * touched, so the shop stays exactly as restricted as it is now; only the
 * ATTRIBUTION is withdrawn. Because suspended_by is the proof-positive
 * discriminator, that single write is what moves the shop from the
 * administrative axis back onto the entitlement axis:
 *
 *   ShopSubscription::blocksNewPaidTerm()  true → false   owner may buy again
 *   EnsureSubscriptionIsActive             denyAdministrative() → recover()
 *   Shop::accessClassification()           admin_suspended → subscription_lapse
 *
 * Access itself does NOT widen: the shop keeps access_mode='suspended', the
 * reconciler re-derives the same 'suspended' from the lapsed subscription row,
 * and normal enforcement continues to apply.
 *
 * Default is a dry-run: it prints before/after and writes NOTHING.
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
        {--reason= : why the restriction is being withdrawn (recorded in the platform audit log)}
        {--expect-updated-at= : the shops.updated_at value seen during the dry run; refuses if it has moved}
        {--commit : actually write the change (default is a dry-run)}';

    protected $description = 'Withdraw a mistakenly applied administrative restriction, leaving the shop as restricted as it is now';

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

        if ($fault = $this->refusalReason($shop)) {
            $this->error($fault);

            return self::FAILURE;
        }

        $before = $this->accessColumns($shop);
        $this->renderState($shop, $before);

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run with:');
            $this->line(sprintf(
                '  php artisan shop:withdraw-admin-restriction %d --reason="…" --expect-updated-at="%s" --commit',
                $shop->id,
                (string) $shop->updated_at
            ));

            return self::SUCCESS;
        }

        if (blank($this->option('reason'))) {
            $this->error('--reason is required when committing. It is the audit record of why the hold was withdrawn.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($shop, $before) {
                // Re-read under the row lock and re-check EVERY guard. The dry run
                // and the commit are two separate operator actions minutes apart;
                // anything decided on the unlocked snapshot is decided on stale data.
                $locked = Shop::whereKey($shop->id)->lockForUpdate()->first();

                if ($fault = $this->refusalReason($locked)) {
                    throw new \RuntimeException($fault);
                }

                $lockedBefore = $this->accessColumns($locked);

                $locked->forceFill(['suspended_by' => null])->save();

                $this->audit->log(
                    null,
                    'shop.admin_restriction_withdrawn',
                    Shop::class,
                    $locked->id,
                    $lockedBefore,
                    $this->accessColumns($locked->fresh()),
                    (string) $this->option('reason'),
                );

                $this->info('Written. New state:');
                $this->renderState($locked->fresh(), $before);
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Every precondition, in one place, so the dry run and the locked commit
     * apply the IDENTICAL test. Returns the refusal message, or null to proceed.
     * Fails closed: anything it cannot positively prove is a refusal.
     */
    private function refusalReason(?Shop $shop): ?string
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

        // Compare-and-swap. The operator's authority to withdraw comes from
        // having inspected THIS row; if anything has written to the shop since —
        // including a new, legitimate restriction — that authority is stale.
        $expected = $this->option('expect-updated-at');
        if (filled($expected) && (string) $shop->updated_at !== (string) $expected) {
            return sprintf(
                'Refusing: shop #%d has been modified since it was inspected (expected updated_at "%s", found "%s"). Re-run the dry run and confirm the current restriction is still the one being withdrawn.',
                $shop->id,
                $expected,
                (string) $shop->updated_at
            );
        }

        // Simulate the post-state on a SEPARATE instance so nothing is mutated
        // here. If the shop would not land on a clean subscription lapse, the
        // reason text does not corroborate one, and withdrawing the attribution
        // would leave it unattributed-and-unexplained rather than recovered.
        // Refuse instead of rewriting the reason — inventing a corroborating
        // string is exactly how an administrative hold gets laundered into a
        // lapse.
        $simulated = Shop::find($shop->id);
        $simulated->suspended_by = null;
        if ($simulated->accessClassification() !== 'subscription_lapse') {
            return sprintf(
                'Refusing: with the attribution withdrawn shop #%d would classify as "%s", not "subscription_lapse". Its suspension_reason (%s) does not corroborate a lapse.',
                $shop->id,
                $simulated->accessClassification(),
                json_encode($shop->suspension_reason)
            );
        }

        return null;
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

    private function renderState(Shop $shop, array $before): void
    {
        $this->table(
            ['column', 'before', 'after'],
            [
                ['access_mode', $before['access_mode'] ?? '—', $shop->access_mode ?? '—'],
                ['is_active', var_export((bool) ($before['is_active'] ?? false), true), var_export((bool) $shop->is_active, true)],
                ['suspended_by', $before['suspended_by'] ?? 'NULL', $shop->suspended_by ?? 'NULL'],
                ['suspension_reason', $before['suspension_reason'] ?? 'NULL', $shop->suspension_reason ?? 'NULL'],
                ['suspended_at', (string) ($before['suspended_at'] ?? 'NULL'), (string) ($shop->suspended_at ?? 'NULL')],
                ['suspended_until', (string) ($before['suspended_until'] ?? 'NULL'), (string) ($shop->suspended_until ?? 'NULL')],
                ['— classification', '—', $shop->accessClassification()],
                ['— owner may buy a plan', '—', var_export(! ShopSubscription::blocksNewPaidTerm(
                    ShopSubscription::where('shop_id', $shop->id)->latest('id')->first(),
                    $shop
                ), true)],
            ]
        );
    }
}
