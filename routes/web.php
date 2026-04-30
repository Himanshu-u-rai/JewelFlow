<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GoldInventoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\BulkImportController;
use App\Http\Controllers\QuickBillController;
use App\Http\Controllers\RepairReportController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\SchemeController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\ReorderController;
use App\Http\Controllers\TagPrintController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\RetailerDashboardController;
use App\Http\Controllers\PricingSettingsController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\StockPurchaseController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\TransactionHistoryReportController;

/*
|--------------------------------------------------------------------------
| PUBLIC LANDING
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    if (auth('platform_admin')->check()) {
        return redirect()->route('admin.dashboard');
    }

    if (!auth()->check()) {
        return redirect('/login');
    }

    return redirect('/dashboard');
});

Route::get('/translations/{locale}.json', [TranslationController::class, 'show'])
    ->where('locale', '[a-zA-Z_\\-]+')
    ->name('translations.show');

Route::get('/catalog/p/{token}', [PublicCatalogController::class, 'show'])
    ->middleware('signed')
    ->name('catalog.public.show');
Route::get('/catalog/c/{token}', [PublicCatalogController::class, 'showCollection'])
    ->middleware('signed')
    ->name('catalog.public.collection.show');

/*
|--------------------------------------------------------------------------
| PUBLIC CATALOG WEBSITE (per-shop, slug-based)
|--------------------------------------------------------------------------
*/
Route::prefix('s/{slug}')->middleware('catalog.shop')->group(function () {
    Route::get('/', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'home'])->name('catalog.website.home');
    Route::get('/products', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'products'])->name('catalog.website.products');
    Route::get('/category/{category}', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'category'])->name('catalog.website.category');
    Route::get('/product/{token}', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'product'])->name('catalog.website.product');
    Route::get('/collection/{token}', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'collection'])->name('catalog.website.collection');
    Route::get('/page/{pageSlug}', [\App\Http\Controllers\PublicCatalogWebsiteController::class, 'page'])->name('catalog.website.page');
});

Route::post('/subscription/payment/webhook', [\App\Http\Controllers\SubscriptionController::class, 'webhook'])
    ->name('subscription.payment.webhook');

/*
|--------------------------------------------------------------------------
| PUBLIC SCAN ROUTES (mobile phone — no auth needed)
|--------------------------------------------------------------------------
*/
Route::get('/scan/{token}', [\App\Http\Controllers\ScanSessionController::class, 'mobile'])
    ->middleware('signed:relative')
    ->name('scan.mobile');
Route::post('/api/scan', [\App\Http\Controllers\ScanSessionController::class, 'postScan'])
    ->middleware('throttle:120,1')
    ->name('scan.post');

require __DIR__.'/admin.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATED USERS WITHOUT A SHOP
| → ONLY SHOP CREATION ALLOWED
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'tenant', 'account.active'])->group(function () {
    Route::match(['get', 'post'], '/shops/choose-type', [ShopController::class, 'chooseType'])
        ->name('shops.choose-type');

    Route::get('/subscription/plans', [\App\Http\Controllers\SubscriptionController::class, 'showPlans'])
        ->name('subscription.plans');
    Route::post('/subscription/choose', [\App\Http\Controllers\SubscriptionController::class, 'choosePlan'])
        ->name('subscription.choose');
    Route::get('/subscription/payment', [\App\Http\Controllers\SubscriptionController::class, 'payment'])
        ->name('subscription.payment');
    Route::post('/subscription/payment/initiate', [\App\Http\Controllers\SubscriptionController::class, 'initiatePayment'])
        ->name('subscription.payment.initiate');
    Route::post('/subscription/payment/callback', [\App\Http\Controllers\SubscriptionController::class, 'paymentCallback'])
        ->name('subscription.payment.callback');

    Route::get('/shops/create', [ShopController::class, 'create'])
        ->name('shops.create');
    Route::post('/shops', [ShopController::class, 'store'])
        ->name('shops.store');
});

/*
|--------------------------------------------------------------------------
| AUTHENTICATED + MUST HAVE A SHOP
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])->group(function () {

    Route::post('/scan-session/create', [\App\Http\Controllers\ScanSessionController::class, 'create'])
        ->middleware('role:owner,manager,staff')
        ->name('scan.session.create');
    Route::get('/scan-session/{token}/poll', [\App\Http\Controllers\ScanSessionController::class, 'poll'])
        ->middleware('role:owner,manager,staff')
        ->name('scan.session.poll');
    Route::post('/scan-session/{token}/expire', [\App\Http\Controllers\ScanSessionController::class, 'expire'])
        ->middleware('role:owner,manager,staff')
        ->name('scan.session.expire');

    // ======= EMAIL OTP VERIFICATION =======
    Route::post('/email/otp/send',   [\App\Http\Controllers\EmailVerificationOtpController::class, 'sendOtp'])
        ->middleware('throttle:5,1')
        ->name('email.otp.send');
    Route::post('/email/otp/verify', [\App\Http\Controllers\EmailVerificationOtpController::class, 'verifyOtp'])
        ->middleware('throttle:10,1')
        ->name('email.otp.verify');
    Route::post('/email/otp/resend', [\App\Http\Controllers\EmailVerificationOtpController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('email.otp.resend');


    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/subscription', [\App\Http\Controllers\SubscriptionController::class, 'status'])->name('subscription.status');

    // ======= EXPORT =======
    Route::get('/export', [ExportController::class, 'index'])->name('export.index');
    Route::post('/export/customers', [ExportController::class, 'exportCustomers'])->name('export.customers');
    Route::post('/export/products', [ExportController::class, 'exportProducts'])->name('export.products');
    Route::post('/export/invoices', [ExportController::class, 'exportInvoices'])->name('export.invoices');
    Route::post('/export/gold-ledger', [ExportController::class, 'exportGoldLedger'])->name('export.gold-ledger');
    Route::post('/export/cash-transactions', [ExportController::class, 'exportCashTransactions'])->name('export.cash-transactions');
    Route::post('/export/all', [ExportController::class, 'exportAll'])
        ->middleware('edition:manufacturer')
        ->name('export.all');

    // ======= BULK IMPORTS =======
    Route::get('/imports', [BulkImportController::class, 'index'])
        ->middleware('role:owner,manager,staff')
        ->name('imports.index');
    Route::get('/imports/{import}', [BulkImportController::class, 'show'])
        ->middleware('role:owner,manager,staff')
        ->name('imports.show');
    Route::post('/imports/catalog/preview', [BulkImportController::class, 'previewCatalog'])
        ->middleware('role:owner,manager', 'edition:manufacturer')
        ->name('imports.catalog.preview');
    Route::post('/imports/manufacture/preview', [BulkImportController::class, 'previewManufacture'])
        ->middleware('role:owner,manager', 'edition:manufacturer')
        ->name('imports.manufacture.preview');
    Route::post('/imports/stock/preview', [BulkImportController::class, 'previewStock'])
        ->middleware('role:owner,manager', 'edition:retailer')
        ->name('imports.stock.preview');
    Route::post('/imports/{import}/execute', [BulkImportController::class, 'execute'])
        ->middleware('role:owner,manager')
        ->name('imports.execute');
    Route::get('/imports/template/{type}', [BulkImportController::class, 'downloadTemplate'])
        ->middleware('role:owner,manager,staff')
        ->name('imports.template');
    Route::get('/imports/{import}/errors', [BulkImportController::class, 'downloadErrors'])
        ->middleware('role:owner,manager,staff')
        ->name('imports.errors');
    Route::post('/imports/{import}/cancel', [BulkImportController::class, 'cancel'])
        ->middleware('role:owner,manager')
        ->name('imports.cancel');

    // ======= PRODUCT MASTER =======
    Route::resource('categories', \App\Http\Controllers\CategoryController::class);
    Route::resource('sub-categories', \App\Http\Controllers\SubCategoryController::class);

    Route::get('/api/sub-categories', function (\Illuminate\Http\Request $request) {
        $shopId = auth()->user()->shop_id;
        $categoryId = $request->input('category_id');

        $subs = \App\Models\SubCategory::where('shop_id', $shopId)
            ->where('category_id', $categoryId)
            ->get(['id', 'name']);

        return response()->json($subs);
    })->name('api.sub-categories');

    Route::resource('products', \App\Http\Controllers\ProductController::class);

    // ======= LIVE SEARCH SUGGESTIONS =======
    Route::get('/search/suggestions', \App\Http\Controllers\SearchSuggestionsController::class)->name('search.suggestions');

    // ======= GOLD INVENTORY (Manufacturer only) =======
    Route::get('/inventory/gold', [GoldInventoryController::class, 'index'])->middleware('edition:manufacturer')->name('inventory.gold.index');
    Route::get('/inventory/gold/create', [GoldInventoryController::class, 'create'])->middleware('edition:manufacturer')->name('inventory.gold.create');
    Route::post('/inventory/gold', [GoldInventoryController::class, 'store'])->middleware('edition:manufacturer')->name('inventory.gold.store');
    Route::get('/inventory/gold/{lot}', [GoldInventoryController::class, 'show'])->middleware('edition:manufacturer')->name('inventory.gold.show');
    Route::get('/inventory/gold/{lot}/edit', [GoldInventoryController::class, 'edit'])->middleware('edition:manufacturer')->name('inventory.gold.edit');
    Route::put('/inventory/gold/{lot}', [GoldInventoryController::class, 'update'])->middleware('edition:manufacturer')->name('inventory.gold.update');

    // ======= ITEMS / STOCK =======
    Route::get('/inventory/items', [ItemController::class, 'index'])->name('inventory.items.index');
    Route::get('/inventory/items/create', [ItemController::class, 'create'])->name('inventory.items.create');
    Route::post('/inventory/items', [ItemController::class, 'store'])->name('inventory.items.store');
    Route::post('/inventory/items/quick-add-purity', [ItemController::class, 'quickAddPurity'])->name('inventory.items.quick-add-purity');
    Route::post('/inventory/items/quick-add-category', [ItemController::class, 'quickAddCategory'])->name('inventory.items.quick-add-category');
    Route::post('/inventory/items/quick-add-sub-category', [ItemController::class, 'quickAddSubCategory'])->name('inventory.items.quick-add-sub-category');
    Route::post('/inventory/items/quick-add-karigar', [ItemController::class, 'quickAddKarigar'])->name('inventory.items.quick-add-karigar');
    Route::get('/inventory/items/{item}', [ItemController::class, 'show'])->name('inventory.items.show');
    Route::get('/inventory/items/{item}/edit', [ItemController::class, 'edit'])->name('inventory.items.edit');
    Route::put('/inventory/items/{item}', [ItemController::class, 'update'])->name('inventory.items.update');
    Route::delete('/inventory/items/{item}', [ItemController::class, 'destroy'])->name('inventory.items.destroy');

    // ======= CUSTOMERS =======
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::post('/customers/quick-store', [CustomerController::class, 'quickStore'])->name('customers.quick-store');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    Route::get('/customers/{customer}/gold', [\App\Http\Controllers\CustomerGoldController::class, 'create'])
        ->middleware('edition:manufacturer')
        ->name('customers.gold.create');

    Route::post('/customers/{customer}/gold', [\App\Http\Controllers\CustomerGoldController::class, 'store'])
        ->middleware('edition:manufacturer')
        ->name('customers.gold.store');

    // ======= PROFILE =======
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ======= POS =======
    Route::get('/pos', [PosController::class, 'index'])->middleware('role:owner,manager,staff')->name('pos.index');
    Route::get('/pos/customer/{customer}', [PosController::class, 'showCustomerPos'])->middleware('role:owner,manager,staff')->name('pos.customer');
    Route::get('/pos/customers/search', [PosController::class, 'searchCustomers'])->middleware('role:owner,manager,staff')->name('pos.customers.search');
    Route::post('/pos/sell', [PosController::class, 'sell'])->middleware('role:owner,manager,staff')->name('pos.sell');
    Route::post('/pos/exchange', [PosController::class, 'exchange'])->middleware('role:owner,manager,staff', 'edition:manufacturer');
    Route::post('/api/price-preview', [PosController::class, 'preview'])->middleware('role:owner,manager,staff')->name('pos.preview');
    Route::get('/api/item-by-barcode/{barcode}', [PosController::class, 'findByBarcode'])->middleware('role:owner,manager,staff')->name('pos.barcode');

    // ======= REPAIRS =======
    Route::get('/repairs', [\App\Http\Controllers\RepairController::class, 'index'])->name('repairs.index');
    Route::post('/repairs', [\App\Http\Controllers\RepairController::class, 'store'])->name('repairs.store');
    Route::get('/repairs/{repair}/edit', [\App\Http\Controllers\RepairController::class, 'edit'])->name('repairs.edit');
    Route::put('/repairs/{repair}', [\App\Http\Controllers\RepairController::class, 'update'])->name('repairs.update');
    Route::post('/repairs/{repair}/deliver', [\App\Http\Controllers\RepairController::class, 'deliver'])->name('repairs.deliver');
    Route::delete('/repairs/{repair}', [\App\Http\Controllers\RepairController::class, 'destroy'])->name('repairs.destroy');

    // ======= JOB WORK (Retailer) =======
    Route::middleware('edition:retailer')->group(function () {
        Route::get('/vault', [\App\Http\Controllers\BullionVaultController::class, 'index'])->name('vault.index');
        Route::get('/vault/ledger', [\App\Http\Controllers\BullionVaultController::class, 'ledger'])->name('vault.ledger');
        Route::get('/vault/lots/create', [\App\Http\Controllers\BullionVaultController::class, 'createLot'])->name('vault.lots.create');
        Route::post('/vault/lots', [\App\Http\Controllers\BullionVaultController::class, 'storeLot'])->name('vault.lots.store');
        Route::get('/vault/lots/{metalLot}', [\App\Http\Controllers\BullionVaultController::class, 'showLot'])->name('vault.lots.show');

        Route::get('/karigars', [\App\Http\Controllers\KarigarController::class, 'index'])->name('karigars.index');
        Route::get('/karigars/create', [\App\Http\Controllers\KarigarController::class, 'create'])->name('karigars.create');
        Route::post('/karigars', [\App\Http\Controllers\KarigarController::class, 'store'])->name('karigars.store');
        Route::get('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'show'])->name('karigars.show');
        Route::get('/karigars/{karigar}/edit', [\App\Http\Controllers\KarigarController::class, 'edit'])->name('karigars.edit');
        Route::put('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'update'])->name('karigars.update');
        Route::delete('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'destroy'])->name('karigars.destroy');
        Route::patch('/karigars/{karigar}/toggle', [\App\Http\Controllers\KarigarController::class, 'toggle'])->name('karigars.toggle');

        Route::get('/job-orders', [\App\Http\Controllers\JobOrderController::class, 'index'])->name('job-orders.index');
        Route::get('/job-orders/create', [\App\Http\Controllers\JobOrderController::class, 'create'])->name('job-orders.create');
        Route::post('/job-orders', [\App\Http\Controllers\JobOrderController::class, 'store'])->name('job-orders.store');
        Route::get('/job-orders/{jobOrder}', [\App\Http\Controllers\JobOrderController::class, 'show'])->name('job-orders.show');
        Route::post('/job-orders/{jobOrder}/cancel', [\App\Http\Controllers\JobOrderController::class, 'cancel'])->name('job-orders.cancel');
        Route::get('/job-orders/{jobOrder}/challan', [\App\Http\Controllers\JobOrderController::class, 'challan'])->name('job-orders.challan');
        Route::get('/job-orders/{jobOrder}/return-doc', [\App\Http\Controllers\JobOrderController::class, 'returnDoc'])->name('job-orders.return-doc');
        Route::get('/job-orders/{jobOrder}/receive', [\App\Http\Controllers\JobOrderController::class, 'receiveForm'])->name('job-orders.receive.form');
        Route::post('/job-orders/{jobOrder}/receive', [\App\Http\Controllers\JobOrderController::class, 'storeReceipt'])->name('job-orders.receive.store');
        Route::post('/job-orders/{jobOrder}/leftover-return', [\App\Http\Controllers\JobOrderController::class, 'leftoverReturn'])->name('job-orders.leftover');
        Route::post('/job-orders/{jobOrder}/acknowledge', [\App\Http\Controllers\JobOrderController::class, 'acknowledge'])->name('job-orders.acknowledge');

        Route::get('/karigar-invoices', [\App\Http\Controllers\KarigarInvoiceController::class, 'index'])->name('karigar-invoices.index');
        Route::get('/karigar-invoices/create', [\App\Http\Controllers\KarigarInvoiceController::class, 'create'])->name('karigar-invoices.create');
        Route::post('/karigar-invoices', [\App\Http\Controllers\KarigarInvoiceController::class, 'store'])->name('karigar-invoices.store');
        Route::get('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'show'])->name('karigar-invoices.show');
        Route::get('/karigar-invoices/{karigarInvoice}/edit', [\App\Http\Controllers\KarigarInvoiceController::class, 'edit'])->name('karigar-invoices.edit');
        Route::put('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'update'])->name('karigar-invoices.update');
        Route::delete('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'destroy'])->name('karigar-invoices.destroy');
        Route::get('/karigar-invoices/{karigarInvoice}/print', [\App\Http\Controllers\KarigarInvoiceController::class, 'print'])->name('karigar-invoices.print');
        Route::post('/karigar-invoices/{karigarInvoice}/payments', [\App\Http\Controllers\KarigarInvoiceController::class, 'recordPayment'])->name('karigar-invoices.pay');
    });

    // ======= REPORTS =======
    Route::get('/report/gold', [ReportController::class, 'gold'])->middleware('role:owner')->name('report.gold');
    Route::get('/report/metal-exchange', [\App\Http\Controllers\MetalExchangeReportController::class, 'index'])->middleware('edition:retailer')->name('report.metal-exchange');
    Route::get('/report/daily', [\App\Http\Controllers\DailyReportController::class, 'index'])->middleware('role:owner')->name('report.daily');
    Route::get('/report/cash', [\App\Http\Controllers\CashReportController::class, 'index'])->middleware('role:owner')->name('report.cash');
    Route::get('/report/pnl', [\App\Http\Controllers\PnlController::class, 'index'])->middleware('role:owner')->name('report.pnl');
    Route::get('/report/gst', [\App\Http\Controllers\GstController::class, 'index'])->middleware('role:owner')->name('report.gst');
    Route::get('/report/closing', [\App\Http\Controllers\ClosingController::class, 'index'])->middleware('role:owner')->name('report.closing');
    Route::get('/report/transactions', [TransactionHistoryReportController::class, 'index'])->middleware('role:owner')->name('report.transactions');
    Route::get('/report/repairs', [RepairReportController::class, 'index'])->middleware('role:owner')->name('report.repairs');
    Route::get('/report/audit', function () {
        return redirect()->route('settings.edit', ['tab' => 'audit']);
    })->middleware('role:owner');

    // ======= SETTINGS =======
    Route::get('/profile/mobile/change', [\App\Http\Controllers\MobileChangeController::class, 'showForm'])->name('profile.mobile.change');
    Route::post('/profile/mobile/change-request', [\App\Http\Controllers\MobileChangeController::class, 'requestChange'])->name('profile.mobile.request');
    Route::post('/profile/mobile/change-verify', [\App\Http\Controllers\MobileChangeController::class, 'verifyChange'])->name('profile.mobile.verify');

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('role:owner')->name('settings.edit');
    Route::get('/settings/services', [\App\Http\Controllers\ShopServicesController::class, 'index'])->middleware('role:owner')->name('settings.services');
    Route::post('/settings/services/request-add', [\App\Http\Controllers\ShopServicesController::class, 'requestAdd'])->middleware('role:owner')->name('settings.services.request-add');
    Route::post('/settings/services/remove', [\App\Http\Controllers\ShopServicesController::class, 'remove'])->middleware('role:owner')->name('settings.services.remove');
    Route::post('/settings/services/requests/{editionRequest}/cancel', [\App\Http\Controllers\ShopServicesController::class, 'cancelRequest'])->middleware('role:owner')->name('settings.services.request.cancel');
    Route::patch('/settings/shop', [SettingsController::class, 'updateShop'])->middleware('role:owner')->name('settings.update.shop');
    Route::patch('/settings/rules', [SettingsController::class, 'updateRules'])->middleware('role:owner')->name('settings.update.rules');
    Route::patch('/settings/billing', [SettingsController::class, 'updateBilling'])->middleware('role:owner')->name('settings.update.billing');
    Route::patch('/settings/preferences', [SettingsController::class, 'updatePreferences'])->middleware('role:owner')->name('settings.update.preferences');
    Route::post('/settings/whatsapp-template', [SettingsController::class, 'saveWhatsappTemplate'])->middleware('role:owner')->name('settings.whatsapp.template');
    Route::patch('/settings/roles/{role}', [SettingsController::class, 'updateRolePermissions'])->middleware('role:owner')->name('settings.update.role');
    Route::patch('/settings/pricing/timezone', [PricingSettingsController::class, 'updateTimezone'])->middleware('role:owner')->name('settings.pricing.update-timezone');
    Route::post('/settings/pricing/daily-rates', [PricingSettingsController::class, 'saveTodayRates'])->middleware('role:owner')->name('settings.pricing.save-rates');
    Route::post('/settings/pricing/purity-profiles', [PricingSettingsController::class, 'storeProfile'])->middleware('role:owner')->name('settings.pricing.profiles.store');
    Route::patch('/settings/pricing/purity-profiles/{profile}', [PricingSettingsController::class, 'updateProfile'])->middleware('role:owner')->name('settings.pricing.profiles.update');
    Route::post('/settings/pricing/purity-profiles/{profile}/override', [PricingSettingsController::class, 'storeOverride'])->middleware('role:owner')->name('settings.pricing.overrides.store');
    Route::patch('/settings/pricing/legacy-items/{item}', [PricingSettingsController::class, 'resolveLegacyItem'])->middleware('role:owner')->name('settings.pricing.legacy.resolve');

    // Payment Methods settings
    Route::get('/settings/payment-methods', [PaymentMethodController::class, 'index'])->middleware('role:owner')->name('settings.payment-methods.index');
    Route::post('/settings/payment-methods', [PaymentMethodController::class, 'store'])->middleware('role:owner')->name('settings.payment-methods.store');
    Route::put('/settings/payment-methods/{method}', [PaymentMethodController::class, 'update'])->middleware('role:owner')->name('settings.payment-methods.update');
    Route::delete('/settings/payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->middleware('role:owner')->name('settings.payment-methods.destroy');
    Route::patch('/settings/payment-methods/{method}/toggle', [PaymentMethodController::class, 'toggle'])->middleware('role:owner')->name('settings.payment-methods.toggle');

    // Catalog Website settings
    Route::patch('/settings/catalog-website', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'updateSettings'])->middleware('role:owner')->name('settings.update.catalog-website');
    Route::post('/settings/catalog-pages', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'storePage'])->middleware('role:owner')->name('settings.catalog-pages.store');
    Route::put('/settings/catalog-pages/{page}', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'updatePage'])->middleware('role:owner')->name('settings.catalog-pages.update');
    Route::delete('/settings/catalog-pages/{page}', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'destroyPage'])->middleware('role:owner')->name('settings.catalog-pages.destroy');

    // ======= LEDGER & INVOICE =======
    Route::get('/ledger', [\App\Http\Controllers\LedgerController::class, 'index'])->name('ledger.index');
    Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}/edit', [\App\Http\Controllers\InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::put('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'update'])->name('invoices.update');
    Route::get('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoice/{invoice}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])->name('invoices.print');

    // ======= QUICK BILL GENERATOR =======
    Route::get('/quick-bills', [QuickBillController::class, 'index'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.index');
    Route::get('/quick-bills/create', [QuickBillController::class, 'create'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.create');
    Route::post('/quick-bills', [QuickBillController::class, 'store'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.store');
    Route::get('/quick-bills/{quickBill}', [QuickBillController::class, 'show'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.show');
    Route::get('/quick-bills/{quickBill}/edit', [QuickBillController::class, 'edit'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.edit');
    Route::put('/quick-bills/{quickBill}', [QuickBillController::class, 'update'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.update');
    Route::post('/quick-bills/{quickBill}/void', [QuickBillController::class, 'void'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.void');
    Route::get('/quick-bills/{quickBill}/print', [QuickBillController::class, 'print'])
        ->middleware('role:owner,manager,staff')
        ->name('quick-bills.print');

    // ======= CASH BOOK =======
    Route::get('/cashbook', [\App\Http\Controllers\CashBookController::class, 'index'])->name('cashbook.index');
    Route::get('/cashbook/create', [\App\Http\Controllers\CashBookController::class, 'create'])->name('cashbook.create');
    Route::post('/cashbook', [\App\Http\Controllers\CashBookController::class, 'store'])->name('cashbook.store');

    // ======= STAFF MANAGEMENT =======
    Route::get('/staff', [\App\Http\Controllers\StaffController::class, 'index'])->middleware('role:owner')->name('staff.index');
    Route::get('/staff/create', [\App\Http\Controllers\StaffController::class, 'create'])->middleware('role:owner')->name('staff.create');
    Route::post('/staff', [\App\Http\Controllers\StaffController::class, 'store'])->middleware('role:owner')->name('staff.store');
    Route::get('/staff/{staff}/edit', [\App\Http\Controllers\StaffController::class, 'edit'])->middleware('role:owner')->name('staff.edit');
    Route::put('/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'update'])->middleware('role:owner')->name('staff.update');
    Route::delete('/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'destroy'])->middleware('role:owner')->name('staff.destroy');

    // ======= RETAILER FEATURES (edition:retailer) =======

    // --- Stock Purchases / Inward ---
    Route::get('/inventory/purchases', [StockPurchaseController::class, 'index'])->middleware('edition:retailer')->name('inventory.purchases.index');
    Route::get('/inventory/purchases/create', [StockPurchaseController::class, 'create'])->middleware('edition:retailer')->name('inventory.purchases.create');
    Route::post('/inventory/purchases', [StockPurchaseController::class, 'store'])->middleware('edition:retailer')->name('inventory.purchases.store');
    Route::get('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'show'])->middleware('edition:retailer')->name('inventory.purchases.show');
    Route::get('/inventory/purchases/{purchase}/edit', [StockPurchaseController::class, 'edit'])->middleware('edition:retailer')->name('inventory.purchases.edit');
    Route::put('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'update'])->middleware('edition:retailer')->name('inventory.purchases.update');
    Route::patch('/inventory/purchases/{purchase}/confirm', [StockPurchaseController::class, 'confirm'])->middleware('edition:retailer')->name('inventory.purchases.confirm');
    Route::patch('/inventory/purchases/{purchase}/stock', [StockPurchaseController::class, 'addToInventory'])->middleware('edition:retailer')->name('inventory.purchases.stock');
    Route::get('/inventory/purchases/{purchase}/vault/{line}', [StockPurchaseController::class, 'vaultLineForm'])->middleware('edition:retailer')->name('inventory.purchases.vault-line.form');
    Route::post('/inventory/purchases/{purchase}/vault/{line}', [StockPurchaseController::class, 'vaultLine'])->middleware('edition:retailer')->name('inventory.purchases.vault-line');
    Route::delete('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'destroy'])->middleware('edition:retailer')->name('inventory.purchases.destroy');

    // --- Vendors / Suppliers ---
    Route::get('/vendors', [VendorController::class, 'index'])->middleware('edition:retailer')->name('vendors.index');
    Route::get('/vendors/create', [VendorController::class, 'create'])->middleware('edition:retailer')->name('vendors.create');
    Route::post('/vendors', [VendorController::class, 'store'])->middleware('edition:retailer')->name('vendors.store');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])->middleware('edition:retailer')->name('vendors.show');
    Route::get('/vendors/{vendor}/edit', [VendorController::class, 'edit'])->middleware('edition:retailer')->name('vendors.edit');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])->middleware('edition:retailer')->name('vendors.update');
    Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('edition:retailer')->name('vendors.destroy');

    // --- Schemes & Offers ---
    Route::get('/schemes', [SchemeController::class, 'index'])->middleware('edition:retailer')->name('schemes.index');
    Route::get('/schemes/create', [SchemeController::class, 'create'])->middleware('edition:retailer')->name('schemes.create');
    Route::post('/schemes', [SchemeController::class, 'store'])->middleware('edition:retailer')->name('schemes.store');
    Route::get('/schemes/{scheme}', [SchemeController::class, 'show'])->middleware('edition:retailer')->name('schemes.show');
    Route::get('/schemes/{scheme}/edit', [SchemeController::class, 'edit'])->middleware('edition:retailer')->name('schemes.edit');
    Route::put('/schemes/{scheme}', [SchemeController::class, 'update'])->middleware('edition:retailer')->name('schemes.update');
    Route::delete('/schemes/{scheme}', [SchemeController::class, 'destroy'])->middleware('edition:retailer')->name('schemes.destroy');
    Route::get('/schemes/{scheme}/enroll', [SchemeController::class, 'enrollForm'])->middleware('edition:retailer')->name('schemes.enroll.form');
    Route::post('/schemes/{scheme}/enroll', [SchemeController::class, 'enroll'])->middleware('edition:retailer')->name('schemes.enroll');
    Route::get('/scheme-enrollments/{enrollment}', [SchemeController::class, 'enrollmentShow'])->middleware('edition:retailer')->name('schemes.enrollment.show');
    Route::post('/scheme-enrollments/{enrollment}/pay', [SchemeController::class, 'recordPayment'])->middleware('edition:retailer')->name('schemes.enrollment.pay');
    Route::post('/scheme-enrollments/{enrollment}/redeem', [SchemeController::class, 'redeemToInvoice'])->middleware('edition:retailer')->name('schemes.enrollment.redeem');

    // --- Loyalty Points (now inline in Customers page) ---
    Route::get('/loyalty', fn () => redirect()->route('customers.index', ['view' => 'loyalty']))->middleware('edition:retailer')->name('loyalty.index');
    Route::get('/loyalty/{customer}/history', fn (\App\Models\Customer $customer) => redirect()->route('customers.show', $customer))->middleware('edition:retailer')->name('loyalty.history');
    Route::get('/loyalty/{customer}/adjust', [LoyaltyController::class, 'adjustForm'])->middleware('edition:retailer')->name('loyalty.adjust.form');
    Route::post('/loyalty/{customer}/adjust', [LoyaltyController::class, 'adjust'])->middleware('edition:retailer')->name('loyalty.adjust');

    // --- EMI / Installments ---
    Route::get('/installments', [InstallmentController::class, 'index'])->middleware('edition:retailer')->name('installments.index');
    Route::get('/installments/create', [InstallmentController::class, 'create'])->middleware('edition:retailer')->name('installments.create');
    Route::post('/installments', [InstallmentController::class, 'store'])->middleware('edition:retailer')->name('installments.store');
    Route::get('/installments/{plan}', [InstallmentController::class, 'show'])->middleware('edition:retailer')->name('installments.show');
    Route::post('/installments/{plan}/pay', [InstallmentController::class, 'recordPayment'])->middleware('edition:retailer')->name('installments.pay');
    Route::get('/installments/{plan}/payments/{payment}/receipt', [InstallmentController::class, 'receipt'])->middleware('edition:retailer')->name('installments.receipt');

    // --- Reorder Alerts ---
    Route::get('/reorder', [ReorderController::class, 'index'])->middleware('edition:retailer')->name('reorder.index');
    Route::get('/reorder/create', [ReorderController::class, 'create'])->middleware('edition:retailer')->name('reorder.create');
    Route::post('/reorder', [ReorderController::class, 'store'])->middleware('edition:retailer')->name('reorder.store');
    Route::get('/reorder/{rule}/edit', [ReorderController::class, 'edit'])->middleware('edition:retailer')->name('reorder.edit');
    Route::put('/reorder/{rule}', [ReorderController::class, 'update'])->middleware('edition:retailer')->name('reorder.update');
    Route::delete('/reorder/{rule}', [ReorderController::class, 'destroy'])->middleware('edition:retailer')->name('reorder.destroy');

    // --- Tag / Label Printing ---
    Route::get('/tags', [TagPrintController::class, 'index'])->middleware('edition:retailer')->name('tags.index');
    Route::post('/tags/print', [TagPrintController::class, 'print'])->middleware('edition:retailer')->name('tags.print');

    // --- Retailer Reports & Analytics ---
    Route::get('/report/stock-aging', [RetailerDashboardController::class, 'stockAging'])->middleware('edition:retailer')->name('report.stock-aging');
    Route::get('/report/sellers', [RetailerDashboardController::class, 'sellers'])->middleware('edition:retailer')->name('report.sellers');
    Route::get('/report/occasions', [RetailerDashboardController::class, 'occasions'])->middleware('edition:retailer')->name('report.occasions');
    Route::get('/catalog', [CatalogController::class, 'index'])->middleware('edition:retailer')->name('catalog.index');
    Route::post('/catalog/collections', [CatalogController::class, 'storeCollection'])->middleware('edition:retailer')->name('catalog.collections.store');
    // Legacy aliases — keep old names resolving so any bookmarked links or nav items still work
    Route::get('/report/whatsapp', [CatalogController::class, 'index'])->middleware('edition:retailer')->name('report.whatsapp');
    Route::post('/report/whatsapp/collection-link', [CatalogController::class, 'storeCollection'])->middleware('edition:retailer')->name('report.whatsapp.collection-link');

    // ======= DHIRAN (Gold Loan / Pledge Module) =======
    // On the main domain, redirect all /dhiran/* to the dedicated subdomain
    Route::get('/dhiran/{any?}', function () {
        if (! str_starts_with(request()->getHost(), 'dhiran.')) {
            return redirect('https://dhiran.jewelflows.com/' . request()->path(), 301);
        }
        abort(404);
    })->where('any', '.*');

    Route::prefix('dhiran')->name('dhiran.')->group(function () {
        // Dashboard & Activation — no dhiran.enabled check (dashboard shows activation page)
        Route::get('/', [\App\Http\Controllers\DhiranController::class, 'dashboard'])->middleware('can:dhiran.view')->name('dashboard');
        Route::post('/activate', [\App\Http\Controllers\DhiranController::class, 'activate'])->middleware('can:dhiran.settings')->name('activate');

        // All remaining routes require dhiran to be enabled
        Route::middleware(['dhiran.enabled'])->group(function () {
            // Loan CRUD — create permission
            Route::get('/create', [\App\Http\Controllers\DhiranController::class, 'create'])->middleware('can:dhiran.create')->name('create');
            Route::post('/', [\App\Http\Controllers\DhiranController::class, 'store'])->middleware('can:dhiran.create')->name('store');

            // Loan listing & detail — view permission
            Route::get('/loans', [\App\Http\Controllers\DhiranController::class, 'loans'])->middleware('can:dhiran.view')->name('loans');
            Route::get('/loans/{loan}', [\App\Http\Controllers\DhiranController::class, 'show'])->middleware('can:dhiran.view')->name('show');

            // Payments — pay permission
            Route::post('/loans/{loan}/pay-interest', [\App\Http\Controllers\DhiranController::class, 'payInterest'])->middleware('can:dhiran.pay')->name('pay-interest');
            Route::post('/loans/{loan}/repay', [\App\Http\Controllers\DhiranController::class, 'repay'])->middleware('can:dhiran.pay')->name('repay');
            Route::post('/loans/{loan}/pre-close', [\App\Http\Controllers\DhiranController::class, 'preClose'])->middleware('can:dhiran.pay')->name('pre-close');
            Route::post('/loans/{loan}/close', [\App\Http\Controllers\DhiranController::class, 'close'])->middleware('can:dhiran.pay')->name('close');

            // Release item — release permission
            Route::post('/loans/{loan}/release-item', [\App\Http\Controllers\DhiranController::class, 'releaseItem'])->middleware('can:dhiran.release')->name('release-item');

            // Renew — renew permission
            Route::post('/loans/{loan}/renew', [\App\Http\Controllers\DhiranController::class, 'renew'])->middleware('can:dhiran.renew')->name('renew');

            // Forfeiture — forfeit permission
            Route::post('/loans/{loan}/send-notice', [\App\Http\Controllers\DhiranController::class, 'sendNotice'])->middleware('can:dhiran.forfeit')->name('send-notice');
            Route::post('/loans/{loan}/forfeit', [\App\Http\Controllers\DhiranController::class, 'forfeit'])->middleware('can:dhiran.forfeit')->name('forfeit');

            // Printable documents — view permission
            Route::get('/loans/{loan}/receipt', [\App\Http\Controllers\DhiranController::class, 'receipt'])->middleware('can:dhiran.view')->name('receipt');
            Route::get('/loans/{loan}/closure-certificate', [\App\Http\Controllers\DhiranController::class, 'closureCertificate'])->middleware('can:dhiran.view')->name('closure-certificate');
            Route::get('/loans/{loan}/forfeiture-notice', [\App\Http\Controllers\DhiranController::class, 'forfeitureNotice'])->middleware('can:dhiran.view')->name('forfeiture-notice');
            Route::get('/loans/{loan}/payments/{payment}/receipt', [\App\Http\Controllers\DhiranController::class, 'paymentReceipt'])->middleware('can:dhiran.view')->name('payment-receipt');

            // Customer loan history — view permission
            Route::get('/customers/{customer}', [\App\Http\Controllers\DhiranController::class, 'customerLoans'])->middleware('can:dhiran.view')->name('customer-loans');

            // Settings — settings permission
            Route::get('/settings', [\App\Http\Controllers\DhiranController::class, 'settings'])->middleware('can:dhiran.settings')->name('settings');
            Route::patch('/settings', [\App\Http\Controllers\DhiranController::class, 'updateSettings'])->middleware('can:dhiran.settings')->name('settings.update');

            // Reports — reports permission
            Route::get('/reports', [\App\Http\Controllers\DhiranController::class, 'reports'])->middleware('can:dhiran.reports')->name('reports.index');
            Route::get('/reports/active', [\App\Http\Controllers\DhiranController::class, 'reportActive'])->middleware('can:dhiran.reports')->name('reports.active');
            Route::get('/reports/overdue', [\App\Http\Controllers\DhiranController::class, 'reportOverdue'])->middleware('can:dhiran.reports')->name('reports.overdue');
            Route::get('/reports/interest', [\App\Http\Controllers\DhiranController::class, 'reportInterest'])->middleware('can:dhiran.reports')->name('reports.interest');
            Route::get('/reports/forfeiture', [\App\Http\Controllers\DhiranController::class, 'reportForfeiture'])->middleware('can:dhiran.reports')->name('reports.forfeiture');
            Route::get('/reports/cashbook', [\App\Http\Controllers\DhiranController::class, 'reportCashbook'])->middleware('can:dhiran.reports')->name('reports.cashbook');
            Route::get('/reports/profitability', [\App\Http\Controllers\DhiranController::class, 'reportProfitability'])->middleware('can:dhiran.reports')->name('reports.profitability');
        });
    });
});

/*
|--------------------------------------------------------------------------
| AUTH ROUTES (Breeze / Fortify / Jetstream)
|--------------------------------------------------------------------------
*/
require __DIR__.'/auth.php';
