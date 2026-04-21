<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use LogicException;

class SubscriptionGateService
{
    public static function assertShopWritable(int $shopId): void
    {
        $shop = Shop::query()->find($shopId);
        if (!$shop) {
            throw new LogicException('Shop not found.');
        }

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

        $subscription = ShopSubscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->first();

        [$isWritable, $status, $reason] = self::resolveWriteState($shop, $subscription);
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

    private static function resolveWriteState(Shop $shop, ?ShopSubscription $subscription): array
    {
        $accessMode = (string) ($shop->access_mode ?: ($shop->is_active ? 'active' : 'suspended'));
        if ($accessMode === 'suspended') {
            return [false, 'suspended', 'Shop is suspended. Write operations are blocked.'];
        }
        if ($accessMode === 'read_only') {
            return [false, 'read_only', 'Shop is in read-only mode. Write operations are blocked.'];
        }

        if (!$subscription) {
            return [false, 'none', 'No active subscription found for shop.'];
        }

        $status = (string) $subscription->status;
        if (in_array($status, ['active', 'grace'], true)) {
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
