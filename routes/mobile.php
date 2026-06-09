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
use App\Http\Controllers\Api\Mobile\PricingController;
use App\Http\Controllers\Api\Mobile\VendorController;

// --- Public (no auth) ---
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// --- Authenticated ---
Route::middleware(['auth:sanctum', 'tenant', 'subscription.active', 'account.active', 'shop.exists', 'rate.shop:600,1'])
    ->group(function () {

        // Auth — self-management for the authenticated user; no role/permission gate.
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Bootstrap — tenant/shop capabilities, config, feature flags.
        // Available to every authenticated mobile user (the mobile app boot-loads it).
        Route::get('/bootstrap', [BootstrapController::class, 'show'])
            ->name('mobile.bootstrap');

        // Dashboard — minimal read-only summary. Use reports.view since this surfaces KPIs.
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:reports.view']);

        // Items
        Route::get('/items/search', [ItemController::class, 'search'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view']);
        Route::get('/items/barcode/{barcode}', [ItemController::class, 'findByBarcode'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view']);
        Route::get('/items/retailer-pricing-meta', [ItemController::class, 'retailerPricingMeta'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view']);
        Route::post('/items', [ItemController::class, 'store'])
            ->middleware(['throttle:api-pos-sale', 'can:inventory.create']);
        Route::get('/items/{item}', [ItemController::class, 'show'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view'])
            ->whereNumber('item');
        Route::put('/items/{item}', [ItemController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:inventory.edit'])
            ->whereNumber('item');
        Route::patch('/items/{item}', [ItemController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:inventory.edit'])
            ->whereNumber('item');

        // Lookup data for item registration — read-only, inventory.view to scope.
        Route::get('/categories', [ItemController::class, 'categories'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view']);
        Route::get('/vendors/lookup', [ItemController::class, 'vendors'])
            ->middleware(['throttle:api-pos-read', 'can:vendors.view']);
        Route::get('/karigars/lookup', [ItemController::class, 'karigars'])
            ->middleware(['throttle:api-pos-read', 'can:karigar.view']);

        // Suppliers (vendors) — full CRUD + ledger.
        Route::get('/vendors', [VendorController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:vendors.view'])
            ->name('mobile.vendors.index');
        Route::post('/vendors', [VendorController::class, 'store'])
            ->middleware(['throttle:api-pos-sale', 'can:vendors.manage'])
            ->name('mobile.vendors.store');
        Route::get('/vendors/{vendor}', [VendorController::class, 'show'])
            ->middleware(['throttle:api-pos-read', 'can:vendors.view'])
            ->whereNumber('vendor')
            ->name('mobile.vendors.show');
        Route::put('/vendors/{vendor}', [VendorController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:vendors.manage'])
            ->whereNumber('vendor')
            ->name('mobile.vendors.update');
        Route::patch('/vendors/{vendor}', [VendorController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:vendors.manage'])
            ->whereNumber('vendor');
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])
            ->middleware(['throttle:api-pos-sale', 'can:vendors.manage'])
            ->whereNumber('vendor')
            ->name('mobile.vendors.destroy');
        Route::get('/vendors/{vendor}/ledger', [VendorController::class, 'ledger'])
            ->middleware(['throttle:api-pos-read', 'can:vendors.ledger'])
            ->whereNumber('vendor')
            ->name('mobile.vendors.ledger');

        // Customers — view/create/edit gated by respective permissions.
        // /customers (index) is registered before /customers/search so the more
        // specific path is never shadowed.
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:customers.view']);
        Route::get('/customers/search', [CustomerController::class, 'search'])
            ->middleware(['throttle:api-pos-read', 'can:customers.view']);
        Route::get('/customers/{customer}/context', [CustomerController::class, 'context'])
            ->middleware(['throttle:api-pos-read', 'can:customers.view']);
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware(['throttle:api-pos-sale', 'can:customers.create']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:customers.edit']);
        Route::post('/customers/{customer}/verify-compliance', [CustomerController::class, 'verifyCompliance'])
            ->middleware(['throttle:api-pos-sale', 'can:customers.edit']);
        Route::post('/customers/{customer}/kyc-documents', [CustomerController::class, 'uploadKycDocument'])
            ->middleware(['throttle:api-pos-sale', 'can:customers.edit']);
        Route::get('/customers/{customer}/kyc-documents/{kycDocument}', [CustomerController::class, 'showKycDocument'])
            ->middleware(['throttle:api-pos-read', 'can:customers.view']);
        Route::delete('/customers/{customer}/kyc-documents/{kycDocument}', [CustomerController::class, 'deleteKycDocument'])
            ->middleware(['throttle:api-pos-sale', 'can:customers.edit']);

        // Stock
        Route::get('/stock', [StockController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:inventory.view']);
        Route::post('/stock/batch-status', [StockController::class, 'batchStatus'])
            ->middleware(['throttle:api-pos-sale', 'can:inventory.edit']);

        // Repairs
        Route::get('/repairs', [RepairController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:repairs.view']);
        Route::get('/repairs/{repair}', [RepairController::class, 'show'])
            ->middleware(['throttle:api-pos-read', 'can:repairs.view'])
            ->whereNumber('repair');
        Route::post('/repairs', [RepairController::class, 'store'])
            ->middleware(['throttle:api-pos-sale', 'can:repairs.create']);
        Route::post('/repairs/{repair}/status', [RepairController::class, 'updateStatus'])
            ->middleware(['throttle:api-pos-sale', 'can:repairs.edit']);
        Route::post('/repairs/{repair}/deliver', [RepairController::class, 'deliver'])
            ->middleware(['throttle:api-pos-sale', 'can:repairs.edit']);

        // Invoices — view/show/print = sales.view; add-payment = sales.create.
        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::get('/invoices/{invoice}/template', [InvoiceController::class, 'template'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'storePayment'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.create'])
            ->whereNumber('invoice');

        // Quick Bills.
        Route::get('/quick-bills', [QuickBillController::class, 'index'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::get('/quick-bills/{quickBill}', [QuickBillController::class, 'show'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::get('/quick-bills/{quickBill}/template', [QuickBillController::class, 'template'])
            ->middleware(['throttle:api-pos-read', 'can:sales.view']);
        Route::post('/quick-bills', [QuickBillController::class, 'store'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.create']);
        Route::put('/quick-bills/{quickBill}', [QuickBillController::class, 'update'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.create']);
        Route::post('/quick-bills/{quickBill}/void', [QuickBillController::class, 'void'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.void']);

        // Catalog Sharing — view = catalog.manage (catalog is owner/manager only by default).
        Route::get('/catalog/items', [CatalogController::class, 'items'])
            ->middleware(['throttle:api-pos-read', 'can:catalog.manage']);
        Route::get('/catalog/categories', [CatalogController::class, 'categories'])
            ->middleware(['throttle:api-pos-read', 'can:catalog.manage']);
        Route::get('/catalog/template', [CatalogController::class, 'template'])
            ->middleware(['throttle:api-pos-read', 'can:catalog.manage']);
        Route::put('/catalog/template', [CatalogController::class, 'updateTemplate'])
            ->middleware(['throttle:api-pos-sale', 'can:catalog.manage']);
        Route::post('/catalog/collections', [CatalogController::class, 'storeCollection'])
            ->middleware(['throttle:api-pos-sale', 'can:catalog.manage']);

        // Mobile POS.
        Route::get('/pos/bootstrap', [PosController::class, 'bootstrap'])
            ->middleware(['throttle:api-pos-read', 'can:sales.pos']);
        Route::post('/pos/preview', [PosController::class, 'preview'])
            ->middleware(['throttle:api-pos-read', 'can:sales.pos']);
        Route::post('/pos/sell', [PosController::class, 'sell'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.create']);
        Route::post('/pos/quote', [PosController::class, 'quote'])
            ->middleware(['throttle:api-pos-read', 'can:sales.pos']);
        Route::post('/pos/quote/persist', [PosController::class, 'persistQuote'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.pos']);

        // POS Scan Connection — sales.pos to participate in a scan session.
        Route::post('/scan/connect', [ScanController::class, 'connect'])
            ->middleware(['throttle:api-pos-read', 'can:sales.pos']);
        Route::post('/scan/send', [ScanController::class, 'send'])
            ->middleware(['throttle:api-pos-sale', 'can:sales.pos']);

        // Daily Pricing — pricing.update permission.
        Route::get('/pricing/today', [PricingController::class, 'today'])
            ->middleware(['throttle:api-pos-read', 'can:pricing.update']);
        Route::post('/pricing/today', [PricingController::class, 'saveToday'])
            ->middleware(['throttle:api-pos-sale', 'can:pricing.update']);
    });
