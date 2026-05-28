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

    // ─── Mutation routes (idempotency-protected) ──────────────────────
    Route::middleware(['mobile.idempotency'])->group(function () {
        // Filled in by future phases (M8 returns, M9 karigar, etc.).
    });
});
