<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitByShop
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, int $maxAttempts = 600, int $decayMinutes = 1): Response
    {
        // Get shop_id from the authenticated user (web guard)
        $shopId = auth()->user()?->shop_id ?? 'unauthenticated';
        $key = 'api_shop:' . $shopId . '|' . $request->ip();

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);
            return response()->json([
                'message' => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $this->limiter->attempts($key)));

        return $response;
    }
}
