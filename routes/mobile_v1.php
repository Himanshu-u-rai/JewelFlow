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
| Legacy routes remain in routes/mobile.php at `/api/mobile/...` for
| backward compatibility until the mobile client adopts v1 in full.
|
*/

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\V1\CustomerController;
use App\Http\Controllers\Api\Mobile\V1\ItemController;
use App\Http\Controllers\Api\Mobile\V1\RegistryController;

Route::middleware([
    'auth:sanctum',
    'tenant',
    'subscription.active',
    'account.active',
    'shop.exists',
    'rate.shop:600,1',
    'mobile.envelope',
])->group(function () {

    // ─── M1: Material registry snapshot ───────────────────────────────
    // Filled in by M1 agent. Endpoint: GET /api/mobile/v1/registry/materials
    Route::get('/registry/materials', [RegistryController::class, 'materials'])
        ->name('mobile.v1.registry.materials');

    /*
    |--------------------------------------------------------------------
    | M5 — Optimistic concurrency (ETag / If-Match)
    |--------------------------------------------------------------------
    |
    | Every resource-returning endpoint in this block sets an `ETag`
    | response header (and `X-Has-Entity-Tag: yes` so the envelope can
    | surface the tag in `meta.etag`).
    |
    | Mutation endpoints in the same block REQUIRE an `If-Match` request
    | header carrying the client's last-known ETag:
    |
    |   * Missing  → 428 Precondition Required  (precondition_required)
    |   * Mismatch → 412 Precondition Failed    (precondition_failed)
    |   * Match    → request proceeds normally
    |
    | This prevents a stale mobile copy from silently overwriting another
    | cashier's edits to the same row.
    |
    */
    Route::get('/items/{item}', [ItemController::class, 'show'])
        ->middleware('can:inventory.view')
        ->name('mobile.v1.items.show');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('can:customers.view')
        ->name('mobile.v1.customers.show');

    // ─── Mutation routes (idempotency-protected) ──────────────────────
    Route::middleware(['mobile.idempotency'])->group(function () {
        // Filled in by future phases (M8 returns, M9 karigar, etc.).

        Route::patch('/items/{item}', [ItemController::class, 'update'])
            ->middleware('can:inventory.edit')
            ->name('mobile.v1.items.update');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('can:customers.edit')
            ->name('mobile.v1.customers.update');
    });
});
