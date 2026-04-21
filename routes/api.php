<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PosController;

Route::middleware(['auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])->group(function () {
    Route::middleware('throttle:api-pos-read')->group(function () {
        Route::get('/items', [PosController::class, 'items']);
        Route::get('/customers', [PosController::class, 'customers']);
    });

    Route::post('/sale', [PosController::class, 'sale'])
        ->middleware('throttle:api-pos-sale');
});
