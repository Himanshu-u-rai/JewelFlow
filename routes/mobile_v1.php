<?php

/*
|--------------------------------------------------------------------------
| Mobile API v1 routes
|--------------------------------------------------------------------------
|
| Mounted at `/api/mobile/v1/...` (see bootstrap/app.php).
|
| All routes in this file emit responses through the `mobile.envelope`
| middleware which wraps them in the canonical `{data, meta, errors}`
| shape defined by the Mobile Contract Stabilization plan.
|
| Mutation routes (POST/PATCH/PUT/DELETE) additionally pass through
| `mobile.idempotency` so retries are safe.
|
| EXCEPTION: PUT /uploads/{token} is intentionally excluded from both
| `mobile.envelope` and `mobile.idempotency` — the upload token is
| already the idempotency key, and the raw-bytes body must not be
| double-buffered by a middleware layer.
|
| Legacy routes remain in routes/mobile.php at `/api/mobile/...` for
| backward compatibility until the mobile client adopts v1 in full.
|
*/

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\V1\CustomerController;
use App\Http\Controllers\Api\Mobile\V1\InstallmentController;
use App\Http\Controllers\Api\Mobile\V1\ItemController;
use App\Http\Controllers\Api\Mobile\V1\JobOrderController;
use App\Http\Controllers\Api\Mobile\V1\KarigarController;
use App\Http\Controllers\Api\Mobile\V1\ReferencePriceController;
use App\Http\Controllers\Api\Mobile\V1\RegistryController;
use App\Http\Controllers\Api\Mobile\V1\ReturnController;
use App\Http\Controllers\Api\Mobile\V1\CashBookController;
use App\Http\Controllers\Api\Mobile\V1\DevicePushTokenController;
use App\Http\Controllers\Api\Mobile\V1\NotificationController;
use App\Http\Controllers\Api\Mobile\V1\SessionController;
use App\Http\Controllers\Api\Mobile\V1\UploadController;

// ─── Auth layer shared by ALL v1 routes ───────────────────────────────────
// session.alive checks that the Sanctum token is still bound to a live
// MobileDeviceSession. Revoked / replaced / expired sessions get a stable
// session_revoked / session_replaced / session_ended 401 response.
// Legacy tokens (pre-M7, mobile_device_session_id IS NULL) pass through.
$authMiddleware = [
    'auth:sanctum',
    'session.alive',
    'tenant',
    'subscription.active',
    'account.active',
    'shop.exists',
    'rate.shop:600,1',
];

// ─── PUT /uploads/{token} — raw bytes, NO envelope, NO idempotency ─────────
// This route receives the raw binary body from the client. Adding envelope
// or idempotency middleware would buffer the entire body twice, causing
// memory issues on large files. The upload_token serves as its own
// idempotency surface.
Route::middleware($authMiddleware)
    ->group(function () {
        Route::put('/uploads/{token}', [UploadController::class, 'upload'])
            ->middleware('can:inventory.create')
            ->name('mobile.v1.uploads.upload');
    });

// ─── All other v1 routes — canonical envelope ──────────────────────────────
Route::middleware(array_merge($authMiddleware, ['mobile.envelope']))
    ->group(function () {

        // ─── M7: Session governance ────────────────────────────────────
        // Read-only session endpoints (no idempotency required).
        Route::get('/sessions', [SessionController::class, 'index'])
            ->name('mobile.v1.sessions.index');
        Route::get('/sessions/me', [SessionController::class, 'me'])
            ->name('mobile.v1.sessions.me');

        // ─── Push notification token (per device session) ─────────────
        // Registering your own device's token needs no extra permission —
        // the token binds to the caller's current session only.
        Route::post('/device/push-token', [DevicePushTokenController::class, 'store'])
            ->name('mobile.v1.device.push_token.store');
        Route::delete('/device/push-token', [DevicePushTokenController::class, 'destroy'])
            ->name('mobile.v1.device.push_token.destroy');

        // ─── Owner sale-notification feed (owners only) ───────────────
        Route::middleware('role:owner')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index'])
                ->name('mobile.v1.notifications.index');
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
                ->name('mobile.v1.notifications.unread_count');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
                ->name('mobile.v1.notifications.read_all');
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
                ->whereNumber('id')
                ->name('mobile.v1.notifications.read');
        });

        // ─── M1: Material registry snapshot ───────────────────────────
        Route::get('/registry/materials', [RegistryController::class, 'materials'])
            ->name('mobile.v1.registry.materials');

        // ─── M5: Optimistic concurrency (ETag / If-Match) ─────────────
        Route::get('/items/{item}', [ItemController::class, 'show'])
            ->middleware('can:inventory.view')
            ->name('mobile.v1.items.show');

        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('can:customers.view')
            ->name('mobile.v1.customers.show');

        // ─── M6: Upload intent + status ───────────────────────────────
        Route::post('/uploads/intent', [UploadController::class, 'intent'])
            ->middleware(['can:inventory.create', 'mobile.idempotency'])
            ->name('mobile.v1.uploads.intent');

        Route::get('/uploads/{token}', [UploadController::class, 'show'])
            ->middleware('can:inventory.view')
            ->name('mobile.v1.uploads.show');

        // ─── M9: Karigar read endpoints ────────────────────────────────
        Route::get('/karigars', [KarigarController::class, 'index'])
            ->middleware('can:karigar.view')
            ->name('mobile.v1.karigars.index');

        Route::get('/karigars/{karigar}', [KarigarController::class, 'show'])
            ->middleware('can:karigar.view')
            ->name('mobile.v1.karigars.show');

        Route::get('/job-orders', [JobOrderController::class, 'index'])
            ->middleware('can:job_order.view')
            ->name('mobile.v1.job_orders.index');

        Route::get('/job-orders/{jobOrder}', [JobOrderController::class, 'show'])
            ->middleware('can:job_order.view')
            ->name('mobile.v1.job_orders.show');

        // ─── M8: Returns read endpoints ────────────────────────────────
        // returns.approve covers viewing too for owners/managers; staff
        // with returns.approve can read. For general staff read access,
        // we gate on sales.view which all sales staff have.
        Route::get('/returns', [ReturnController::class, 'index'])
            ->middleware('can:sales.view')
            ->name('mobile.v1.returns.index');

        Route::get('/returns/{returnOrder}', [ReturnController::class, 'show'])
            ->middleware('can:sales.view')
            ->name('mobile.v1.returns.show');

        // ─── M10: Reference price read endpoints ───────────────────────
        Route::get('/reference-prices', [ReferencePriceController::class, 'index'])
            ->middleware('can:settings.view')
            ->name('mobile.v1.reference_prices.index');

        // ─── Cash Book (Phase 4) — read endpoints ──────────────────────
        Route::get('/cashbook', [CashBookController::class, 'index'])
            ->middleware('can:cash.view')
            ->name('mobile.v1.cashbook.index');
        Route::get('/cashbook/drawer-check', [CashBookController::class, 'drawerContext'])
            ->middleware('can:cash.view')
            ->name('mobile.v1.cashbook.drawer_context');

        // ─── Mutation routes (idempotency-protected) ───────────────────
        Route::middleware(['mobile.idempotency'])->group(function () {

            // Cash Book (Phase 4) — manual entry + drawer check
            Route::post('/cashbook', [CashBookController::class, 'store'])
                ->middleware('can:cash.create')
                ->name('mobile.v1.cashbook.store');
            Route::post('/cashbook/drawer-check', [CashBookController::class, 'storeDrawerCheck'])
                ->middleware('can:cash.create')
                ->name('mobile.v1.cashbook.drawer_check');

            // M7 — session mutations
            Route::post('/sessions/lock', [SessionController::class, 'lock'])
                ->name('mobile.v1.sessions.lock');
            Route::post('/sessions/unlock', [SessionController::class, 'unlock'])
                ->name('mobile.v1.sessions.unlock');
            Route::delete('/sessions', [SessionController::class, 'destroyForUser'])
                ->name('mobile.v1.sessions.destroy_for_user');
            Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])
                ->name('mobile.v1.sessions.destroy');

            // M5 — item + customer PATCH
            Route::patch('/items/{item}', [ItemController::class, 'update'])
                ->middleware('can:inventory.edit')
                ->name('mobile.v1.items.update');

            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
                ->middleware('can:customers.edit')
                ->name('mobile.v1.customers.update');

            // M8 — return create + approve
            Route::post('/returns', [ReturnController::class, 'store'])
                ->middleware('can:sales.create')
                ->name('mobile.v1.returns.store');

            Route::post('/returns/{returnOrder}/approve', [ReturnController::class, 'approve'])
                ->middleware('can:returns.approve')
                ->name('mobile.v1.returns.approve');

            // M9 — karigar job-order issue + receipt
            Route::post('/job-orders', [JobOrderController::class, 'store'])
                ->middleware('can:job_order.manage')
                ->name('mobile.v1.job_orders.store');

            Route::post('/job-orders/{jobOrder}/receipt', [JobOrderController::class, 'receipt'])
                ->middleware('can:job_order.manage')
                ->name('mobile.v1.job_orders.receipt');

            // ─── EMI / Installments ────────────────────────────────────────
            // Finalize a POS-EMI draft into a plan, record a monthly EMI payment,
            // and discard an abandoned draft. Reuse the web InstallmentService.
            Route::post('/installments/finalize', [InstallmentController::class, 'finalizeDraft'])
                ->middleware('can:sales.create')
                ->name('mobile.v1.installments.finalize');

            Route::post('/installments/discard-draft', [InstallmentController::class, 'discardDraft'])
                ->middleware('can:sales.create')
                ->name('mobile.v1.installments.discard_draft');

            Route::post('/installments/{plan}/pay', [InstallmentController::class, 'pay'])
                ->whereNumber('plan')
                ->middleware('can:sales.create')
                ->name('mobile.v1.installments.pay');

        });

        // EMI read endpoints (no idempotency needed) — gated to sales.view.
        Route::get('/installments', [InstallmentController::class, 'index'])
            ->middleware('can:sales.view')
            ->name('mobile.v1.installments.index');

        Route::get('/installments/{plan}', [InstallmentController::class, 'show'])
            ->whereNumber('plan')
            ->middleware('can:sales.view')
            ->name('mobile.v1.installments.show');
    });
