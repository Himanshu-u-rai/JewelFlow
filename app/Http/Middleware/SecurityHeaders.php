<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        if (app()->environment('local')) {
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 https://checkout.razorpay.com; style-src 'self' 'unsafe-inline' http://localhost:5173 https://fonts.googleapis.com https://fonts.bunny.net; font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net; img-src 'self' data: blob:; connect-src 'self' ws://localhost:5173 http://localhost:5173 https://api.razorpay.com; frame-src https://api.razorpay.com https://checkout.razorpay.com; object-src 'none'; base-uri 'self';";
        } else {
            $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://checkout.razorpay.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net; font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net; img-src 'self' data: blob:; connect-src 'self' https://api.razorpay.com; frame-src https://api.razorpay.com https://checkout.razorpay.com; object-src 'none'; base-uri 'self';";
        }
        $response->headers->set('Content-Security-Policy', $csp);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
