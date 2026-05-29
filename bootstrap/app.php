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

            // Mobile API v1 — canonical envelope, cursor pagination,
            // idempotency-protected mutations. See routes/mobile_v1.php.
            Route::middleware('api')
                ->prefix('api/mobile/v1')
                ->group(base_path('routes/mobile_v1.php'));
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
            \App\Http\Middleware\CheckMaintenanceMode::class,
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
            'rate.shop' => \App\Http\Middleware\RateLimitByShop::class,
            'mobile.envelope'  => \App\Http\Middleware\MobileEnvelope::class,
            'mobile.idempotency' => \App\Http\Middleware\EnsureIdempotency::class,
            'session.alive'    => \App\Http\Middleware\EnforceSessionAlive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        // ─── Mobile API v1 — canonical envelope on framework errors ──────────
        // Errors raised BEFORE the mobile.envelope middleware runs (e.g.
        // AuthenticationException from auth:sanctum) bypass the response
        // wrapper. Reshape them here for any path under /api/mobile/v1/* so
        // the contract is uniform regardless of where the failure originated.
        $isMobileV1 = fn (\Illuminate\Http\Request $request) =>
            $request->is('api/mobile/v1/*') && $request->expectsJson();

        $envelopeError = function (string $code, string $message, int $status, ?\Illuminate\Http\Request $request = null) {
            $requestId = $request?->headers->get('X-Request-Id')
                ?: (string) \Illuminate\Support\Str::uuid();
            return response()->json([
                'data' => null,
                'meta' => [
                    'request_id'       => $requestId,
                    'server_time'      => now()->toIso8601String(),
                    'api_version'      => '1',
                    'registry_version' => \App\Services\MetalRegistry::registryVersion(),
                ],
                'errors' => [['code' => $code, 'message' => $message]],
            ], $status)->withHeaders(['X-Request-Id' => $requestId]);
        };

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) use ($isMobileV1, $envelopeError) {
            if ($isMobileV1($request)) {
                return $envelopeError('unauthorized', 'Authentication required.', 401, $request);
            }
        });

        // ─── JSON-shaped permission denials for API requests ─────────────────
        // When the client sends `Accept: application/json` (typical for the mobile
        // app and AJAX), surface 403s with a stable JSON shape so clients can
        // parse + display them uniformly. HTML requests still hit the branded
        // errors/403.blade.php view.
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) use ($isMobileV1, $envelopeError) {
            if ($isMobileV1($request)) {
                return $envelopeError('permission_denied', $e->getMessage() ?: 'You do not have permission to perform this action.', 403, $request);
            }
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'permission_denied',
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) use ($isMobileV1, $envelopeError) {
            if ($isMobileV1($request)) {
                $status = $e->getStatusCode();
                $codeMap = [
                    401 => 'unauthorized', 403 => 'permission_denied', 404 => 'not_found',
                    409 => 'conflict', 412 => 'precondition_failed', 422 => 'unprocessable',
                    429 => 'too_many_requests', 503 => 'service_unavailable',
                ];
                $code = $codeMap[$status] ?? 'request_failed';
                return $envelopeError($code, $e->getMessage() ?: 'Request failed.', $status, $request);
            }
            if ($e->getStatusCode() === 403 && $request->expectsJson()) {
                return response()->json([
                    'error' => 'permission_denied',
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }
        });

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
