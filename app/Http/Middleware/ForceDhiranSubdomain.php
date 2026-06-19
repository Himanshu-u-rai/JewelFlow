<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a request arrives on the dhiran.* subdomain, force the user into
 * the Dhiran-only UI. Non-Dhiran routes redirect to the Dhiran dashboard.
 * Auth + logout routes are exempt so users can still sign in / sign out.
 */
class ForceDhiranSubdomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! str_starts_with($request->getHost(), 'dhiran.')) {
            return $next($request);
        }

        $name = $request->route()?->getName() ?? '';

        $exempt = in_array($name, [
                'login', 'login.store', 'logout', 'register',
                'password.request', 'password.email', 'password.reset', 'password.store',
                'verification.notice', 'verification.verify', 'verification.send',
                // Dhiran onboarding reuses the shared, product-agnostic payment flow.
                // These routes MUST be reachable on the dhiran.* host or the Dhiran
                // "Continue to payment" step dead-loops back to the dashboard.
                'subscription.payment', 'subscription.payment.initiate',
                'subscription.payment.callback', 'subscription.payment.webhook',
                'subscription.choose', 'subscription.trial.start',
            ], true)
            || str_starts_with($name, 'dhiran.')
            || str_starts_with($request->path(), 'dhiran/')
            || $request->path() === 'dhiran'
            || $request->path() === 'livewire'
            || str_starts_with($request->path(), 'livewire/')
            || str_starts_with($request->path(), '_debugbar');

        if ($exempt) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // An ERP account that lands on the Dhiran host must NOT be forced into the
        // Dhiran app — send it back to its own realm. Only genuine Dhiran accounts
        // are funnelled to the Dhiran dashboard.
        if (! $user->isDhiran()) {
            return redirect('/dashboard');
        }

        return redirect()->route('dhiran.dashboard');
    }
}
