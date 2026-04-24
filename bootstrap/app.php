<?php

use App\Services\PlatformAuditService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api/mobile')
                ->group(base_path('routes/mobile.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\TrustProxies::class,
        );

        $middleware->validateCsrfTokens(except: [
            'subscription/payment/webhook',
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->web(prepend: [
            \App\Http\Middleware\UseRouteScopedSessionCookie::class,
        ], append: [
            \App\Http\Middleware\NormalizeHumanTextInput::class,
            \App\Http\Middleware\SetShopLocale::class,
            \App\Http\Middleware\ForceDhiranSubdomain::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'nocache' => \App\Http\Middleware\NoCache::class,
            'shop.exists' => \App\Http\Middleware\EnsureShopExists::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'platform.role' => \App\Http\Middleware\EnsurePlatformRole::class,
            'tenant' => \App\Http\Middleware\EnsureTenantUser::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'edition' => \App\Http\Middleware\EnsureShopEdition::class,
            'catalog.shop' => \App\Http\Middleware\ResolveCatalogShop::class,
            'dhiran.enabled' => \App\Http\Middleware\EnsureDhiranEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        $exceptions->report(function (Throwable $exception) {
            if (!app()->bound('request')) {
                return;
            }

            $request = app('request');
            if (! $request instanceof Request) {
                return;
            }

            $path = trim($request->path(), '/');
            $isScanPath = $path === 'api/scan' || str_starts_with($path, 'scan/');

            if (! $isScanPath) {
                return;
            }

            /** @var PlatformAuditService $audit */
            $audit = app(PlatformAuditService::class);

            try {
                if ($exception instanceof ThrottleRequestsException) {
                    $audit->log(
                        actor: null,
                        action: 'scan.request_throttled',
                        targetType: 'route',
                        targetId: 0,
                        before: null,
                        after: [
                            'path' => '/' . $path,
                            'method' => $request->method(),
                            'route_name' => $request->route()?->getName(),
                        ],
                        reason: 'Scan endpoint request throttled.',
                        request: $request,
                    );
                    return;
                }

                if ($exception instanceof InvalidSignatureException) {
                    $audit->log(
                        actor: null,
                        action: 'scan.invalid_signature',
                        targetType: 'route',
                        targetId: 0,
                        before: null,
                        after: [
                            'path' => '/' . $path,
                            'method' => $request->method(),
                            'route_name' => $request->route()?->getName(),
                        ],
                        reason: 'Invalid signature for scan URL.',
                        request: $request,
                    );
                }
            } catch (Throwable $logError) {
                Log::warning('Failed to write scan security telemetry.', [
                    'error' => $logError->getMessage(),
                ]);
            }
        });
    })->create();
