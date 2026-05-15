<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Audits every authenticated route to confirm it has an explicit role/permission gate.
 *
 * Used as a CI safety net after Stage 2 of the Roles & Permissions overhaul:
 * any newly-added authenticated route is required to declare either:
 *   - `role:...` middleware (legacy / strict role check), OR
 *   - `can:...` middleware (permission check, preferred), OR
 *   - `permission:...` middleware (custom CheckPermission alias).
 *
 * Routes that legitimately need no gate (dashboard, logout, profile, etc.) are
 * listed in the allowlist below.
 *
 * Exits non-zero if any unguarded routes are found.
 */
class AuditAuthRoutes extends Command
{
    protected $signature = 'auth:audit-routes';
    protected $description = 'Report authenticated routes that lack an explicit role/permission gate.';

    /**
     * Route names that legitimately have no role/permission gate.
     *
     * Wildcard prefixes use `name.*` matching.
     */
    private const ALLOWLIST = [
        'dashboard',
        'logout',
        'login',
        'login.attempt',
        'subscription.status',       // any authenticated user can see their plan status
        'subscription.payment',      // payment flow — auth + subscription gates handle it
        'subscription.payment.*',
        'subscription.plans',
        'subscription.choose',
        'profile.*',                 // self-profile editing
        'announcements.dismiss',     // any user can dismiss banners
        'email.otp.*',               // OTP for self
        'shops.*',                   // shop setup pre-tenant
        'pricing.modal.*',           // pricing-required modal handled inline
        'pricing.guard.*',
        'edition.*',                 // edition request flow for shop owners
        'health',
        'health.*',
        // Laravel scaffolding for the auth user themselves
        'verification.notice',
        'verification.verify',
        'verification.send',
        'password.confirm',
        'password.update',
        // Mobile auth + bootstrap — self-service for the authenticated user.
        'mobile.bootstrap',
    ];

    /**
     * URI patterns that legitimately have no role/permission gate. Matched against
     * the raw URI when a route has no name (most mobile routes are unnamed).
     */
    private const ALLOWLIST_URIS = [
        'api/mobile/auth/logout',
        'api/mobile/auth/me',
        'dhiran/{any?}',  // redirect-only catch-all
        'confirm-password',  // Laravel-scaffolded password confirmation (no name on POST)
    ];

    public function handle(): int
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            // Only inspect authenticated web routes — not API, not unauthenticated.
            $isWeb = collect($middleware)->contains(fn ($m) => str_starts_with($m, 'web') || str_contains($m, 'web'));
            $isAuth = collect($middleware)->contains(function ($m) {
                return str_starts_with($m, 'auth') || str_contains($m, 'Authenticate');
            });

            if (! $isAuth) {
                continue;
            }

            $hasGate = collect($middleware)->contains(function ($m) {
                return str_starts_with($m, 'role:')
                    || str_starts_with($m, 'can:')
                    || str_starts_with($m, 'permission:')
                    || str_contains($m, 'RoleMiddleware')
                    || str_contains($m, 'CheckPermission')
                    || str_contains($m, 'Authorize:');
            });

            if ($hasGate) {
                continue;
            }

            $name = $route->getName();
            $uri = $route->uri();

            if ($name !== null && $this->isAllowlisted($name)) {
                continue;
            }

            if (in_array($uri, self::ALLOWLIST_URIS, true)) {
                continue;
            }

            $unguarded[] = [
                'method' => implode('|', $route->methods()),
                'uri' => $uri,
                'name' => $name ?? '(unnamed)',
            ];
        }

        if (empty($unguarded)) {
            $this->info('✓ All authenticated routes have an explicit role/permission gate.');
            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d authenticated route(s) without an explicit role/permission gate:', count($unguarded)));
        $this->table(['Method', 'URI', 'Name'], $unguarded);
        $this->newLine();
        $this->line('Each route above either needs:');
        $this->line('  • `can:permission_name` middleware (preferred, e.g. `can:settings.view`), or');
        $this->line('  • `role:owner,manager` middleware (when permission gating isn\'t a fit), or');
        $this->line('  • an entry in this command\'s ALLOWLIST (for routes that intentionally have no gate).');

        return self::FAILURE;
    }

    private function isAllowlisted(string $name): bool
    {
        foreach (self::ALLOWLIST as $pattern) {
            if ($pattern === $name) {
                return true;
            }
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -2);
                if (str_starts_with($name, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
