<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use LogicException;

class SubscriptionGateService
{
    /**
     * Statuses where a paid product subscription still grants WRITE access.
     * read_only / expired / suspended / cancelled do NOT.
     */
    private const WRITABLE_SUBSCRIPTION_STATUSES = ['active', 'trial', 'grace'];

    public static function assertShopWritable(int $shopId): void
    {
        $shop = Shop::query()->find($shopId);
        if (!$shop) {
            throw new LogicException('Shop not found.');
        }

        // Hard access-mode blocks are preserved exactly: a suspended or
        // read-only shop can never write, regardless of editions.
        $accessMode = (string) ($shop->access_mode ?: ($shop->is_active ? 'active' : 'suspended'));
        if ($accessMode === 'suspended') {
            $reason = 'Shop is suspended. Write operations are blocked.';
            self::auditBlockedWrite($shopId, 'suspended', $reason);
            throw new LogicException($reason);
        }
        if ($accessMode === 'read_only') {
            $reason = 'Shop is in read-only mode. Write operations are blocked.';
            self::auditBlockedWrite($shopId, 'read_only', $reason);
            throw new LogicException($reason);
        }

        if (!self::shouldEnforceNow()) {
            return;
        }

        [$isWritable, $status, $reason] = self::resolveWriteState($shop);
        if ($isWritable) {
            return;
        }

        self::auditBlockedWrite($shopId, $status, $reason);
        throw new LogicException($reason);
    }

    private static function shouldEnforceNow(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            return (bool) config('platform.enforce_subscriptions', false);
        }

        return (bool) config('platform.enforce_subscriptions', false);
    }

    /**
     * Multi-product writability.
     *
     * A shop is writable when it holds AT LEAST ONE entitled edition. An edition
     * is entitled when EITHER:
     *   - it was granted by an admin (source='admin_grant') or is the shop's seed
     *     edition (source='seed') — neither lapses with a subscription, OR
     *   - it is subscription-backed and at least one subscription for that
     *     product is in a writable state (active / trial / grace).
     *
     * This removes the old one-shop-one-subscription assumption: a Dhiran-only
     * shop is writable on its Dhiran edition without any retail subscription,
     * and a retail+dhiran shop is writable while EITHER product is entitled.
     *
     * Fails closed: no active editions, or every active edition's only backing
     * is a lapsed subscription → blocked.
     */
    private static function resolveWriteState(Shop $shop): array
    {
        $accessMode = (string) ($shop->access_mode ?: ($shop->is_active ? 'active' : 'suspended'));
        if ($accessMode === 'suspended') {
            return [false, 'suspended', 'Shop is suspended. Write operations are blocked.'];
        }
        if ($accessMode === 'read_only') {
            return [false, 'read_only', 'Shop is in read-only mode. Write operations are blocked.'];
        }

        $activeEditions = ShopEditionAssignment::query()
            ->where('shop_id', $shop->id)
            ->whereNull('deactivated_at')
            ->get();

        // Backward-compat bridge: a shop with NO edition rows (legacy shops, or
        // tenants seeded straight into shop_subscriptions without a shop_type)
        // is judged purely on its latest subscription, exactly as the old gate
        // did. This keeps every pre-editions write path behaving identically.
        if ($activeEditions->isEmpty()) {
            return self::resolveLegacySubscriptionState($shop);
        }

        // Writable subscriptions for this shop, indexed by the edition they grant.
        $writableSubs = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->whereIn('status', self::WRITABLE_SUBSCRIPTION_STATUSES)
            ->with('plan.platformProduct')
            ->get();

        $writableEditions = [];
        foreach ($writableSubs as $sub) {
            $granted = $sub->plan?->grantsEdition();
            if ($granted) {
                $writableEditions[$granted] = true;
            }
        }

        foreach ($activeEditions as $edition) {
            // Admin grants and seed editions are always writable — access the
            // shop holds independently of any payment.
            if (in_array($edition->source, [
                ShopEditionAssignment::SOURCE_ADMIN_GRANT,
                ShopEditionAssignment::SOURCE_SEED,
            ], true)) {
                return [true, 'admin_grant', ''];
            }

            // Subscription-backed edition: writable if any writable subscription
            // grants this edition.
            if (isset($writableEditions[$edition->edition])) {
                return [true, 'active', ''];
            }
        }

        // Every active edition's only backing is a lapsed subscription.
        return [false, 'expired', 'Subscription expired and grace period ended. Write operations are blocked.'];
    }

    /**
     * Legacy single-subscription writability — used only as a fallback for
     * shops that have no shop_editions rows at all. Identical to the pre-
     * editions gate so existing behavior is byte-for-byte preserved.
     */
    private static function resolveLegacySubscriptionState(Shop $shop): array
    {
        $subscription = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->first();

        if (!$subscription) {
            return [false, 'none', 'No active subscription found for shop.'];
        }

        $status = (string) $subscription->status;
        if (in_array($status, ['active', 'trial', 'grace'], true)) {
            return [true, $status, ''];
        }

        if ($status === 'expired') {
            if ($subscription->grace_ends_at && now()->toDateString() <= $subscription->grace_ends_at->toDateString()) {
                return [false, 'read_only', 'Subscription expired and grace/read-only period is active. Writes are blocked.'];
            }

            return [false, 'expired', 'Subscription expired and grace period ended. Write operations are blocked.'];
        }

        if ($status === 'read_only') {
            return [false, 'read_only', 'Subscription is in read-only mode. Write operations are blocked.'];
        }

        if (in_array($status, ['suspended', 'cancelled'], true)) {
            return [false, $status, "Subscription is {$status}. Write operations are blocked."];
        }

        return [false, $status, 'Subscription status is invalid for write operations.'];
    }

    private static function auditBlockedWrite(int $shopId, string $status, string $reason): void
    {
        try {
            app(PlatformAuditService::class)->log(
                null,
                'subscription.write_blocked',
                Shop::class,
                $shopId,
                null,
                [
                    'shop_id' => $shopId,
                    'actor_id' => auth()->id(),
                    'attempted_action' => self::detectAttemptedAction(),
                    'subscription_status' => $status,
                    'blocked_at' => now()->toDateTimeString(),
                ],
                $reason,
                request()
            );
        } catch (\Throwable) {
            // Subscription gate must fail closed even if audit write fails.
        }
    }

    private static function detectAttemptedAction(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        foreach ($frames as $frame) {
            $class = $frame['class'] ?? '';
            if ($class === '' || str_contains($class, self::class)) {
                continue;
            }

            if (str_contains($class, 'Controller') || str_contains($class, 'Service')) {
                return $class . '@' . ($frame['function'] ?? 'unknown');
            }
        }

        return 'unknown';
    }
}
