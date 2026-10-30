<?php

namespace App\Http\Middleware;

use App\Support\NotAvailable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authorize;

/**
 * The `can:` route middleware with the one plain refusal the web and the app
 * show (NotAvailable), instead of Laravel's "This action is unauthorized."
 * It never names the permission. Only the route's own check is re-worded:
 * refusals from inside the request pass through untouched.
 */
class AuthorizeWithReason extends Authorize
{
    public function handle($request, Closure $next, $ability, ...$models)
    {
        try {
            $this->gate->authorize($ability, $this->getGateArguments($request, $models));
        } catch (AuthorizationException $e) {
            throw new AuthorizationException(NotAvailable::MESSAGE, $e->getCode(), $e);
        }

        return $next($request);
    }
}
