<?php

namespace App\Http\Middleware;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionIsActive
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // Bypass routes that should always be accessible during onboarding
        $bypassRouteNames = [
            'subscription.plans', 'subscription.choose', 'subscription.payment',
            'subscription.payment.initiate', 'subscription.payment.callback',
            'subscription.status',
            'shops.choose-type', 'shops.create', 'shops.store',
            'login', 'register', 'logout',
            'catalog.public.show', 'catalog.public.collection.show',
            'translations.show',
        ];
        if ($request->routeIs($bypassRouteNames)) {
            return $next($request);
        }

        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        if (!$user->shop_id || !$user->shop) {
            return $next($request);
        }

        $shop = $user->shop;
        if ($shop->access_mode === 'suspended') {
            // A subscription lapse is RECOVERABLE — route the owner to plans
            // (never log them out). Only a genuine admin suspension gets the
            // Contact-Support dead end.
            if ($shop->suspensionIsSubscriptionManaged()) {
                return $this->recover($request);
            }

            return $this->deny($request, 'Your shop has been suspended. Please contact support.');
        }
        if ($shop->access_mode === 'read_only') {
            if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['message' => 'Shop is in read-only mode. Write operations are not allowed.'], Response::HTTP_FORBIDDEN);
                }
                if ($request->routeIs('settings.pricing.save-rates')) {
                    return redirect()->route('dashboard')->with(
                        'error',
                        'Today’s rates were not saved because this shop is in read-only mode. Extend or reactivate the subscription first.'
                    );
                }

                return back()->withErrors(['message' => 'Shop is in read-only mode. Write operations are not allowed.']);
            }
        }

        if (!config('platform.enforce_subscriptions', false)) {
            $this->restoreIfSubscriptionManagedSuspension($shop, $request);
            return $next($request);
        }

        $subscription = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->first();

        // If no subscription exists at all, this is a new shop. Redirect to plan selection.
        if (!$subscription) {
            if ($request->routeIs('subscription.plans') || $request->routeIs('subscription.choose')) {
                return $next($request);
            }
            return redirect()->route('subscription.plans');
        }

        [$mode, $shouldBlock, $reason] = $this->resolveSubscriptionAccess($subscription);

        if ($mode !== $shop->access_mode) {
            $before = $shop->only(['access_mode', 'is_active', 'suspended_at', 'suspension_reason']);
            $updates = $this->modeUpdates($mode, $reason);
            $shop->forceFill($updates)->save();

            $this->audit->log(
                null,
                'billing.enforcement.access_mode_changed',
                Shop::class,
                $shop->id,
                $before,
                $shop->fresh()->only(['access_mode', 'is_active', 'suspended_at', 'suspension_reason']),
                $reason,
                $request
            );
        }

        if ($shouldBlock) {
            // Subscription lapse (as opposed to an admin block) is recoverable:
            // the resolver only ever emits "Subscription …" reasons here, and
            // modeUpdates() has just written the same reason to the shop, so the
            // classifier agrees. Route to recovery instead of the logout deny().
            if ($shop->suspensionIsSubscriptionManaged()) {
                return $this->recover($request);
            }

            return $this->deny($request, $reason ?: 'Subscription status does not allow access.');
        }

        return $next($request);
    }

    /**
     * Recovery response for a subscription lapse. The owner can fix it, so send
     * them to the plan picker WITHOUT logging out (subscription.* routes are
     * bypass-listed above, so there is no redirect loop). Staff cannot purchase,
     * so they get a clear "owner must renew" message. API clients keep the 403
     * forbidden contract but carry a stable SUBSCRIPTION_REQUIRED code so the
     * mobile app can branch to a renew flow.
     */
    private function recover(Request $request)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            // Keep the 403 block contract (a lapsed tenant is still forbidden),
            // but attach a stable code so the mobile app can branch to a renew
            // flow instead of treating it as a generic suspension.
            return response()->json([
                'code' => 'SUBSCRIPTION_REQUIRED',
                'message' => 'Your subscription has ended. Renew a plan to restore access.',
            ], Response::HTTP_FORBIDDEN);
        }

        $user = Auth::user();
        if ($user && $user->isShopOwner()) {
            return redirect()->route('subscription.plans')->with(
                'error',
                'Your subscription has ended. Choose a plan to restore access to your shop.'
            );
        }

        // Staff: no purchase, no plan leakage. Log out with an owner-must-renew
        // message (re-login lets them switch shops).
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/login')->withErrors([
            'mobile_number' => 'Your shop owner must renew the subscription to restore access.',
        ]);
    }

    private function resolveSubscriptionAccess(?ShopSubscription $subscription): array
    {
        if (!$subscription) {
            return ['suspended', true, 'No active subscription found for shop.'];
        }

        $status = $subscription->status;
        if (in_array($status, ['active', 'trial', 'grace'], true)) {
            return ['active', false, null];
        }

        if ($status === 'read_only') {
            return ['read_only', false, 'Subscription placed in read-only mode.'];
        }

        if ($status === 'expired') {
            if ($subscription->grace_ends_at && now()->toDateString() <= $subscription->grace_ends_at->toDateString()) {
                return ['read_only', false, 'Subscription expired; grace period read-only access is active.'];
            }
            return ['suspended', true, 'Subscription expired and grace period ended.'];
        }

        if (in_array($status, ['cancelled', 'suspended'], true)) {
            return ['suspended', true, 'Subscription is ' . $status . '.'];
        }

        return ['suspended', true, 'Subscription status is invalid for tenant access.'];
    }

    private function modeUpdates(string $mode, ?string $reason): array
    {
        if ($mode === 'active') {
            return [
                'access_mode' => 'active',
                'is_active' => true,
                'deactivated_at' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ];
        }

        return [
            'access_mode' => $mode,
            'is_active' => false,
            'deactivated_at' => now(),
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ];
    }

    private function deny(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/login')->withErrors(['mobile_number' => $message]);
    }

    private function restoreIfSubscriptionManagedSuspension(?Shop $shop, Request $request): void
    {
        if (!$shop) {
            return;
        }

        if (!$shop->suspensionIsSubscriptionManaged()) {
            return;
        }

        if (($shop->access_mode ?? 'active') === 'active' && $shop->is_active) {
            return;
        }

        $before = $shop->only(['access_mode', 'is_active', 'suspended_at', 'suspension_reason']);
        $shop->forceFill([
            'access_mode' => 'active',
            'is_active' => true,
            'deactivated_at' => null,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_until' => null,
        ])->save();

        $this->audit->log(
            null,
            'billing.enforcement.disabled_auto_restore',
            Shop::class,
            $shop->id,
            $before,
            $shop->fresh()->only(['access_mode', 'is_active', 'suspended_at', 'suspension_reason']),
            'Subscription enforcement disabled',
            $request
        );
    }
}
