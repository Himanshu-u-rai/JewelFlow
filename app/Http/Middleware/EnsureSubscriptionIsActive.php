<?php

namespace App\Http\Middleware;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use App\Support\SubscriptionRecovery;
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
            if ($shop->suspensionIsSubscriptionManaged()) {
                // Enforcement OFF: a subscription lapse must NEVER lock ERP access.
                // Auto-heal the (legacy/pre-existing) subscription-managed suspension
                // and let the request through as normal ERP. Admin suspensions fall
                // through to the deny below and are NEVER healed here.
                if (!config('platform.enforce_subscriptions', false)) {
                    $this->restoreIfSubscriptionManagedSuspension($shop, $request);
                    return $next($request);
                }

                // Enforcement ON: a subscription lapse is RECOVERABLE — route the
                // owner to the plan picker (never log them out).
                return SubscriptionRecovery::recover($request);
            }

            // Administrative suspension: Contact-Support dead end, regardless of the
            // enforcement flag. Payment can never lift it.
            return SubscriptionRecovery::denyAdministrative($request);
        }
        // `read_only` is reserved EXCLUSIVELY for a JewelFlows administrator hold,
        // and `suspended_by` is the proof-positive discriminator (every
        // administrative writer stamps it, every administrative restore nulls it).
        //
        // A shop wearing read_only WITHOUT that stamp is a LEGACY row minted by the
        // old expiry fork — a lapse dressed up as an administrative decision. It
        // must not get the read-only treatment (browsable ERP, writes bounced with
        // a validation error and no way out). Fall through instead: the reconciler
        // below rewrites it onto the correct axis (suspended) and routes the owner
        // to the plan picker, for reads AND writes alike. With enforcement OFF the
        // fall-through lands on restoreIfSubscriptionManagedSuspension() instead,
        // which heals the row back to active — a lapse never locks ERP access when
        // the platform is not enforcing.
        if ($shop->access_mode === 'read_only' && $shop->suspensionIsAdministrative()) {
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

        // An administrator restriction is NEVER reconciled away. Without this guard
        // a shop under a compliance hold with a perfectly healthy subscription
        // resolves to `active`, and the very first page view force-fills
        // access_mode / suspended_at / suspension_reason back to active — silently
        // lifting a JewelFlows admin's hold with no admin ever acting, and (because
        // the same row backs the write gate above) handing the shop full write
        // access. Subscription reconciliation owns the entitlement axis only; the
        // administrative axis is the platform admin's alone.
        if ($mode !== $shop->access_mode && ! $shop->suspensionIsAdministrative()) {
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
            // modeUpdates() has just written the same reason to the shop (with
            // suspended_by untouched), so the classifier agrees. Route to recovery
            // instead of the logout deny().
            if ($shop->suspensionIsSubscriptionManaged()) {
                return SubscriptionRecovery::recover($request);
            }

            return $this->deny($request, $reason ?: 'Subscription status does not allow access.');
        }

        return $next($request);
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

        // A `read_only` subscription row is a LEGACY artefact of the old expiry
        // fork, never a live entitlement. It means the term lapsed, so it resolves
        // exactly like `expired`: suspend and route to recovery. Reconciling it
        // this way is what lets a stranded shop reach the plan picker again.
        // The reason must keep its "Subscription" prefix — that string is what
        // Shop::suspensionIsSubscriptionManaged() reads to decide the block below
        // is recoverable (owner → plan picker) rather than a logout dead end.
        if ($status === 'read_only') {
            return ['suspended', true, 'Subscription lapsed; legacy read-only state reconciled.'];
        }

        // Grace is a first-class status written by the scheduler and is handled
        // above as full access. An `expired` row therefore has no grace left to
        // grant, and must never be softened into read-only — that state belongs to
        // administrators only, and it is precisely what stranded lapsed shops in a
        // browsable ERP with no renewal path.
        if ($status === 'expired') {
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
