<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        // Bypass routes that should always be accessible.
        //
        // SECURITY: 'subscription.trial.start' is DELIBERATELY NOT in this list.
        // A free trial force-activates the shop, so if a suspended shop could
        // reach it, an admin suspension could be lifted for free. Keeping the
        // trial route out of the bypass means a suspended shop is blocked (and
        // logged out) by this middleware before it can start a trial. The paid
        // routes below are bypassed because restoring access via real payment is
        // intended. Do NOT add the trial route here.
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

        if (!$user->is_active) {
            return $this->deny($request, 'Your account is deactivated. Contact your shop owner.', Response::HTTP_FORBIDDEN, true);
        }

        if (!$user->shop) {
            return $next($request);
        }

        $shop = $user->shop;
        $accessMode = $shop->access_mode ?: ($shop->is_active ? 'active' : 'suspended');

        if ($accessMode === 'suspended') {
            $until = $shop->suspended_until;
            if ($until && now()->greaterThan($until)) {
                // Auto-clear expired suspension
                $shop->forceFill([
                    'access_mode' => 'active',
                    'is_active' => true,
                    'suspended_at' => null,
                    'suspension_reason' => null,
                    'suspended_until' => null,
                ])->save();
                return $next($request);
            }

            return $this->deny($request, 'This shop is suspended by platform admin.', Response::HTTP_FORBIDDEN, true);
        }

        if ($accessMode === 'read_only' && !$this->isReadOperation($request)) {
            return $this->deny($request, 'Shop is in read-only mode. Writes are blocked by platform policy.', Response::HTTP_LOCKED);
        }

        return $next($request);
    }

    private function isReadOperation(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function deny(Request $request, string $message, int $status, bool $logout = false)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], $status);
        }

        if ($logout) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors([
                'mobile_number' => $message,
            ]);
        }

        return back()->withErrors([
            'shop' => $message,
        ]);
    }
}
