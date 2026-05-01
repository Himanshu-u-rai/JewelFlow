<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\BootstrapController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\ItemController;
use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Http\Controllers\Api\Mobile\StockController;
use App\Http\Controllers\Api\Mobile\RepairController;
use App\Http\Controllers\Api\Mobile\InvoiceController;
use App\Http\Controllers\Api\Mobile\ScanController;
use App\Http\Controllers\Api\Mobile\PosController;
use App\Http\Controllers\Api\Mobile\QuickBillController;
use App\Http\Controllers\Api\Mobile\CatalogController;
use App\Http\Controllers\Api\Mobile\VendorController;

// --- Public (no auth) ---
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// --- Authenticated ---
Route::middleware(['auth:sanctum', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
    ->group(function () {

        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Bootstrap — tenant/shop capabilities, config, feature flags
        Route::get('/bootstrap', [BootstrapController::class, 'show'])
            ->name('mobile.bootstrap');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('throttle:api-pos-read');

        // Items
        Route::get('/items/search', [ItemController::class, 'search'])
            ->middleware('throttle:api-pos-read');
        Route::get('/items/barcode/{barcode}', [ItemController::class, 'findByBarcode'])
            ->middleware('throttle:api-pos-read');
        Route::get('/items/retailer-pricing-meta', [ItemController::class, 'retailerPricingMeta'])
            ->middleware('throttle:api-pos-read');
        Route::post('/items', [ItemController::class, 'store'])
            ->middleware('throttle:api-pos-sale');
        Route::get('/items/{item}', [ItemController::class, 'show'])
            ->middleware('throttle:api-pos-read')
            ->whereNumber('item');
        Route::put('/items/{item}', [ItemController::class, 'update'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('item');
        Route::patch('/items/{item}', [ItemController::class, 'update'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('item');

        // Lookup data for item registration
        Route::get('/categories', [ItemController::class, 'categories'])
            ->middleware('throttle:api-pos-read');
        // Lightweight vendor lookup for item-create — kept alongside the full
        // suppliers CRUD below. Full list/search lives at GET /vendors (index).
        Route::get('/vendors/lookup', [ItemController::class, 'vendors'])
            ->middleware('throttle:api-pos-read');

        // Suppliers (vendors) — full CRUD + ledger.
        Route::get('/vendors', [VendorController::class, 'index'])
            ->middleware('throttle:api-pos-read')
            ->name('mobile.vendors.index');
        Route::post('/vendors', [VendorController::class, 'store'])
            ->middleware('throttle:api-pos-sale')
            ->name('mobile.vendors.store');
        Route::get('/vendors/{vendor}', [VendorController::class, 'show'])
            ->middleware('throttle:api-pos-read')
            ->whereNumber('vendor')
            ->name('mobile.vendors.show');
        Route::put('/vendors/{vendor}', [VendorController::class, 'update'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('vendor')
            ->name('mobile.vendors.update');
        Route::patch('/vendors/{vendor}', [VendorController::class, 'update'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('vendor');
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('vendor')
            ->name('mobile.vendors.destroy');
        Route::get('/vendors/{vendor}/ledger', [VendorController::class, 'ledger'])
            ->middleware('throttle:api-pos-read')
            ->whereNumber('vendor')
            ->name('mobile.vendors.ledger');

        // Customers
        Route::get('/customers/search', [CustomerController::class, 'search'])
            ->middleware('throttle:api-pos-read');
        Route::get('/customers/{customer}/context', [CustomerController::class, 'context'])
            ->middleware('throttle:api-pos-read');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('throttle:api-pos-sale');

        // Stock
        Route::get('/stock', [StockController::class, 'index'])
            ->middleware('throttle:api-pos-read');
        Route::post('/stock/batch-status', [StockController::class, 'batchStatus'])
            ->middleware('throttle:api-pos-sale');

        // Repairs
        Route::get('/repairs', [RepairController::class, 'index'])
            ->middleware('throttle:api-pos-read');
        Route::get('/repairs/{repair}', [RepairController::class, 'show'])
            ->middleware('throttle:api-pos-read')
            ->whereNumber('repair');
        Route::post('/repairs', [RepairController::class, 'store'])
            ->middleware('throttle:api-pos-sale');
        Route::post('/repairs/{repair}/status', [RepairController::class, 'updateStatus'])
            ->middleware('throttle:api-pos-sale');
        Route::post('/repairs/{repair}/deliver', [RepairController::class, 'deliver'])
            ->middleware('throttle:api-pos-sale');

        // Invoices
        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('throttle:api-pos-read');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware('throttle:api-pos-read');
        Route::get('/invoices/{invoice}/template', [InvoiceController::class, 'template'])
            ->middleware('throttle:api-pos-read');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'storePayment'])
            ->middleware('throttle:api-pos-sale')
            ->whereNumber('invoice');

        // Quick Bills
        Route::get('/quick-bills', [QuickBillController::class, 'index'])
            ->middleware('throttle:api-pos-read');
        Route::get('/quick-bills/{quickBill}', [QuickBillController::class, 'show'])
            ->middleware('throttle:api-pos-read');
        Route::get('/quick-bills/{quickBill}/template', [QuickBillController::class, 'template'])
            ->middleware('throttle:api-pos-read');
        Route::post('/quick-bills', [QuickBillController::class, 'store'])
            ->middleware('throttle:api-pos-sale');
        Route::put('/quick-bills/{quickBill}', [QuickBillController::class, 'update'])
            ->middleware('throttle:api-pos-sale');
        Route::post('/quick-bills/{quickBill}/void', [QuickBillController::class, 'void'])
            ->middleware('throttle:api-pos-sale');

        // Catalog Sharing
        Route::get('/catalog/items', [CatalogController::class, 'items'])
            ->middleware('throttle:api-pos-read');
        Route::get('/catalog/categories', [CatalogController::class, 'categories'])
            ->middleware('throttle:api-pos-read');
        Route::get('/catalog/template', [CatalogController::class, 'template'])
            ->middleware('throttle:api-pos-read');
        Route::put('/catalog/template', [CatalogController::class, 'updateTemplate'])
            ->middleware('throttle:api-pos-sale');
        Route::post('/catalog/collections', [CatalogController::class, 'storeCollection'])
            ->middleware('throttle:api-pos-sale');

        // Mobile POS
        Route::get('/pos/bootstrap', [PosController::class, 'bootstrap'])
            ->middleware('throttle:api-pos-read');
        Route::post('/pos/preview', [PosController::class, 'preview'])
            ->middleware('throttle:api-pos-read');
        Route::post('/pos/sell', [PosController::class, 'sell'])
            ->middleware('throttle:api-pos-sale');

        // POS Scan Connection
        Route::post('/scan/connect', [ScanController::class, 'connect'])
            ->middleware('throttle:api-pos-read');
        Route::post('/scan/send', [ScanController::class, 'send'])
            ->middleware('throttle:api-pos-sale');
    });
