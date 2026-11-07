<?php

namespace App\Support;

use App\Models\User;

/**
 * Can the signed-in user open the page behind a named route?
 *
 * Reads the route's own `can:` and `role:` middleware, so a link is shown exactly when its destination would let the
 * user in — no permission names copied into views. Used as `@canopen('route.name') … @endcanopen`.
 *
 * ponytail: `can:ability,model` gates need the bound model and are not evaluated here (treated as allowed; the server
 * still enforces them). Edition (`edition:`) gates are left to the callers, which already branch on the shop's editions.
 */
final class RouteAccess
{
    public static function allows(string $routeName, mixed $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $route = app('router')->getRoutes()->getByName($routeName);
        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                continue;
            }
            [$alias, $args] = explode(':', $middleware, 2);
            $params = explode(',', $args);

            if ($alias === 'can' && count($params) === 1 && $user->cannot($params[0])) {
                return false;
            }
            if ($alias === 'role' && ! in_array($user->role?->name, $params, true)) {
                return false;
            }
        }

        return true;
    }

    /** Same question for a URL (lists that hold URLs, like the dashboard command palette). */
    public static function allowsUrl(string $url, mixed $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($url, 'GET'));
        } catch (\Throwable) {
            return false;
        }
        if ($route->getName() === null || ! self::allows($route->getName(), $user)) {
            return $route->getName() === null;
        }

        // Settings is one route with per-tab rules.
        if ($route->getName() === 'settings.edit') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (isset($query['tab'])) {
                return \App\Http\Controllers\SettingsController::canSeeTab($user, (string) $query['tab']);
            }
        }

        return true;
    }
}
