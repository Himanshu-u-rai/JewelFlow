<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\SetShopLocale::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    protected $middlewareAliases = [  // Changed from $routeMiddleware
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'role' => \App\Http\Middleware\RoleMiddleware::class,
        
        // Your custom middleware
        'shop.exists' => \App\Http\Middleware\EnsureShopExists::class,
        'nocache' => \App\Http\Middleware\NoCache::class,
        'admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
        'platform.role' => \App\Http\Middleware\EnsurePlatformRole::class,
        'tenant' => \App\Http\Middleware\EnsureTenantUser::class,
        'subscription.active' => \App\Http\Middleware\EnsureSubscriptionIsActive::class,
        'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        'edition' => \App\Http\Middleware\EnsureShopEdition::class,
        'dhiran.enabled' => \App\Http\Middleware\EnsureDhiranEnabled::class,
        'permission' => \App\Http\Middleware\CheckPermission::class,
    ];
}
