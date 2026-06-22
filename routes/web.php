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

/*
|--------------------------------------------------------------------------
| PUBLIC LANDING
|--------------------------------------------------------------------------
*/
// Health checks — no auth, consumed by load balancers and uptime monitors
Route::get('/health', [\App\Http\Controllers\HealthController::class, 'health']);
Route::get('/ping',   [\App\Http\Controllers\HealthController::class, 'ping']);

Route::get('/', function () {
    if (auth('platform_admin')->check()) {
        return redirect()->route('admin.dashboard');
    }

    // Dhiran subdomain (dhiran.jewelflows.com/) is a separate product front door.
    // The realm is derived from the host, so the root must never leak the ERP
    // landing or the ERP dashboard to a Dhiran visitor (and vice-versa).
    if (\App\Support\Realm::current() === \App\Support\Realm::DHIRAN) {
        $user = auth()->user();

        if (! $user) {
            // Guest → Dhiran-branded login (its own entry page).
            return redirect()->route('login');
        }

        // ERP account on the Dhiran host → bounce to its own realm; it never
        // gets the Dhiran app experience.
        if (! $user->isDhiran()) {
            return redirect('/dashboard');
        }

        // Dhiran account → the Dhiran dashboard. That controller itself renders
        // the activation/onboarding placeholder when Dhiran isn't set up yet.
        return redirect()->route('dhiran.dashboard');
    }

    if (auth()->check()) {
        return redirect('/dashboard');
    }

    return view('landing');
})->name('home');

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
    Route::post('/subscription/trial/start', [\App\Http\Controllers\SubscriptionController::class, 'startTrial'])
        ->name('subscription.trial.start');
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
    Route::get('/shops/pincode/{pincode}', [ShopController::class, 'lookupPincode'])
        ->whereNumber('pincode')
        ->name('shops.pincode-lookup');
});

/*
|--------------------------------------------------------------------------
| DHIRAN ONBOARDING (Phase 3)
| Authenticated Dhiran-realm user, NO shop yet.
| → Dhiran-only plan selection + Dhiran business creation.
|--------------------------------------------------------------------------
| Deliberately NOT inside the shop.exists / subscription.active groups: the
| Dhiran customer reaches these before having a shop or a subscription. realm:dhiran
| keeps the flow Dhiran-only (an ERP account is bounced out). The shared payment
| screens (subscription.payment / .initiate / .callback, above) are reused as-is.
*/
Route::middleware(['auth', 'tenant', 'account.active', 'realm:dhiran'])
    ->prefix('dhiran')
    ->name('dhiran.')
    ->group(function () {
        Route::get('/plans', [\App\Http\Controllers\DhiranOnboardingController::class, 'plans'])
            ->name('plans');
        Route::post('/subscribe', [\App\Http\Controllers\DhiranOnboardingController::class, 'subscribe'])
            ->name('subscribe');
        Route::get('/onboarding', [\App\Http\Controllers\DhiranOnboardingController::class, 'onboarding'])
            ->name('onboarding');
        Route::post('/onboarding', [\App\Http\Controllers\DhiranOnboardingController::class, 'store'])
            ->name('onboarding.store');
    });

/*
|--------------------------------------------------------------------------
| AUTHENTICATED + MUST HAVE A SHOP
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])->group(function () {

    Route::post('/announcements/{announcement}/dismiss', [\App\Http\Controllers\AnnouncementDismissController::class, 'dismiss'])->name('announcements.dismiss');

    // Mobile-scan session for POS — anyone with POS access can use it.
    Route::post('/scan-session/create', [\App\Http\Controllers\ScanSessionController::class, 'create'])
        ->middleware('can:sales.pos')
        ->name('scan.session.create');
    Route::get('/scan-session/{token}/poll', [\App\Http\Controllers\ScanSessionController::class, 'poll'])
        ->middleware('can:sales.pos')
        ->name('scan.session.poll');
    Route::post('/scan-session/{token}/expire', [\App\Http\Controllers\ScanSessionController::class, 'expire'])
        ->middleware('can:sales.pos')
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
        ->middleware('realm:erp')
        ->name('dashboard');

    Route::get('/subscription', [\App\Http\Controllers\SubscriptionController::class, 'status'])->name('subscription.status');

    // ======= BILLING INVOICES (platform-billing portal — strictly shop owner) =======
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])
        ->middleware('role:owner')
        ->name('billing.invoices.index');
    Route::get('/billing/{invoice}', [\App\Http\Controllers\BillingController::class, 'show'])
        ->middleware('role:owner')
        ->name('billing.invoices.show');

    // ======= DATA EXPORTS HUB (reports.export permission) =======
    // The per-dataset CSV exports (customers/products/invoices/gold-ledger/
    // cash-transactions) were retired — those data sets now export through the
    // reporting framework at /reports/{key}/export (CSV/Excel/PDF + filters +
    // sensitive-column gating). The hub page links to those panels.
    Route::get('/export', [ExportController::class, 'index'])->middleware('can:reports.export')->name('export.index');
    Route::post('/export/all', [ExportController::class, 'exportAllWorkbook'])
        ->middleware('can:reports.export')
        ->name('export.all');

    // ======= BULK IMPORTS (gated on the purpose-built imports.manage; held by owner+manager) =======
    Route::get('/imports', [BulkImportController::class, 'index'])
        ->middleware('can:imports.manage')
        ->name('imports.index');
    Route::get('/imports/{import}', [BulkImportController::class, 'show'])
        ->middleware('can:imports.manage')
        ->name('imports.show');
    Route::post('/imports/catalog/preview', [BulkImportController::class, 'previewCatalog'])
        ->middleware('can:imports.manage', 'edition:manufacturer')
        ->name('imports.catalog.preview');
    Route::post('/imports/manufacture/preview', [BulkImportController::class, 'previewManufacture'])
        ->middleware('can:imports.manage', 'edition:manufacturer')
        ->name('imports.manufacture.preview');
    Route::post('/imports/stock/preview', [BulkImportController::class, 'previewStock'])
        ->middleware('can:imports.manage', 'edition:retailer')
        ->name('imports.stock.preview');
    Route::post('/imports/{import}/execute', [BulkImportController::class, 'execute'])
        ->middleware('can:imports.manage')
        ->name('imports.execute');
    Route::get('/imports/template/{type}', [BulkImportController::class, 'downloadTemplate'])
        ->middleware('can:imports.manage')
        ->name('imports.template');
    Route::get('/imports/{import}/errors', [BulkImportController::class, 'downloadErrors'])
        ->middleware('can:imports.manage')
        ->name('imports.errors');
    Route::post('/imports/{import}/cancel', [BulkImportController::class, 'cancel'])
        ->middleware('can:imports.manage')
        ->name('imports.cancel');

    // ======= PRODUCT MASTER (inventory.view to read; catalog.manage to mutate) =======
    Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'index'])->middleware('can:inventory.view')->name('categories.index');
    Route::get('/categories/create', [\App\Http\Controllers\CategoryController::class, 'create'])->middleware('can:catalog.manage')->name('categories.create');
    Route::post('/categories', [\App\Http\Controllers\CategoryController::class, 'store'])->middleware('can:catalog.manage')->name('categories.store');
    Route::get('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'show'])->middleware('can:inventory.view')->name('categories.show');
    Route::get('/categories/{category}/edit', [\App\Http\Controllers\CategoryController::class, 'edit'])->middleware('can:catalog.manage')->name('categories.edit');
    Route::put('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'update'])->middleware('can:catalog.manage')->name('categories.update');
    Route::patch('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'update'])->middleware('can:catalog.manage');
    Route::delete('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->middleware('can:catalog.manage')->name('categories.destroy');

    Route::get('/sub-categories', [\App\Http\Controllers\SubCategoryController::class, 'index'])->middleware('can:inventory.view')->name('sub-categories.index');
    Route::get('/sub-categories/create', [\App\Http\Controllers\SubCategoryController::class, 'create'])->middleware('can:catalog.manage')->name('sub-categories.create');
    Route::post('/sub-categories', [\App\Http\Controllers\SubCategoryController::class, 'store'])->middleware('can:catalog.manage')->name('sub-categories.store');
    Route::get('/sub-categories/{sub_category}', [\App\Http\Controllers\SubCategoryController::class, 'show'])->middleware('can:inventory.view')->name('sub-categories.show');
    Route::get('/sub-categories/{sub_category}/edit', [\App\Http\Controllers\SubCategoryController::class, 'edit'])->middleware('can:catalog.manage')->name('sub-categories.edit');
    Route::put('/sub-categories/{sub_category}', [\App\Http\Controllers\SubCategoryController::class, 'update'])->middleware('can:catalog.manage')->name('sub-categories.update');
    Route::patch('/sub-categories/{sub_category}', [\App\Http\Controllers\SubCategoryController::class, 'update'])->middleware('can:catalog.manage');
    Route::delete('/sub-categories/{sub_category}', [\App\Http\Controllers\SubCategoryController::class, 'destroy'])->middleware('can:catalog.manage')->name('sub-categories.destroy');

    Route::get('/api/sub-categories', function (\Illuminate\Http\Request $request) {
        $shopId = auth()->user()->shop_id;
        $categoryId = $request->input('category_id');

        $subs = \App\Models\SubCategory::where('shop_id', $shopId)
            ->where('category_id', $categoryId)
            ->get(['id', 'name']);

        return response()->json($subs);
    })->middleware('can:inventory.view')->name('api.sub-categories');

    Route::get('/products', [\App\Http\Controllers\ProductController::class, 'index'])->middleware('can:inventory.view')->name('products.index');
    Route::get('/products/create', [\App\Http\Controllers\ProductController::class, 'create'])->middleware('can:catalog.manage')->name('products.create');
    Route::post('/products', [\App\Http\Controllers\ProductController::class, 'store'])->middleware('can:catalog.manage')->name('products.store');
    Route::get('/products/{product}', [\App\Http\Controllers\ProductController::class, 'show'])->middleware('can:inventory.view')->name('products.show');
    Route::get('/products/{product}/edit', [\App\Http\Controllers\ProductController::class, 'edit'])->middleware('can:catalog.manage')->name('products.edit');
    Route::put('/products/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->middleware('can:catalog.manage')->name('products.update');
    Route::patch('/products/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->middleware('can:catalog.manage');
    Route::delete('/products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy'])->middleware('can:catalog.manage')->name('products.destroy');

    // ======= LIVE SEARCH SUGGESTIONS =======
    Route::get('/search/suggestions', \App\Http\Controllers\SearchSuggestionsController::class)->middleware('can:inventory.view')->name('search.suggestions');

    // ======= GOLD INVENTORY (Manufacturer only) =======
    Route::get('/inventory/gold', [GoldInventoryController::class, 'index'])->middleware(['edition:manufacturer', 'can:inventory.view'])->name('inventory.gold.index');
    Route::get('/inventory/gold/create', [GoldInventoryController::class, 'create'])->middleware(['edition:manufacturer', 'can:inventory.create'])->name('inventory.gold.create');
    Route::post('/inventory/gold', [GoldInventoryController::class, 'store'])->middleware(['edition:manufacturer', 'can:inventory.create'])->name('inventory.gold.store');
    Route::get('/inventory/gold/{lot}', [GoldInventoryController::class, 'show'])->middleware(['edition:manufacturer', 'can:inventory.view'])->name('inventory.gold.show');
    Route::get('/inventory/gold/{lot}/edit', [GoldInventoryController::class, 'edit'])->middleware(['edition:manufacturer', 'can:inventory.edit'])->name('inventory.gold.edit');
    Route::put('/inventory/gold/{lot}', [GoldInventoryController::class, 'update'])->middleware(['edition:manufacturer', 'can:inventory.edit'])->name('inventory.gold.update');

    // ======= ITEMS / STOCK =======
    // Staff: view + show only. Create/edit/delete = owner + manager.
    Route::get('/inventory/items', [ItemController::class, 'index'])->middleware('can:inventory.view')->name('inventory.items.index');
    Route::get('/inventory/items/pricing-alerts', [ItemController::class, 'pricingAlerts'])->middleware(['can:inventory.view', 'edition:retailer'])->name('inventory.items.pricing-alerts');
    Route::get('/inventory/items/create', [ItemController::class, 'create'])->middleware('can:inventory.create')->name('inventory.items.create');
    Route::post('/inventory/items', [ItemController::class, 'store'])->middleware('can:inventory.create')->name('inventory.items.store');
    Route::post('/inventory/items/quick-add-purity', [ItemController::class, 'quickAddPurity'])->middleware('can:inventory.create')->name('inventory.items.quick-add-purity');
    Route::post('/inventory/items/quick-add-category', [ItemController::class, 'quickAddCategory'])->middleware('can:catalog.manage')->name('inventory.items.quick-add-category');
    Route::post('/inventory/items/quick-add-sub-category', [ItemController::class, 'quickAddSubCategory'])->middleware('can:catalog.manage')->name('inventory.items.quick-add-sub-category');
    Route::post('/inventory/items/quick-add-karigar', [ItemController::class, 'quickAddKarigar'])->middleware('can:karigar.manage')->name('inventory.items.quick-add-karigar');
    Route::get('/inventory/items/{item}', [ItemController::class, 'show'])->middleware('can:inventory.view')->name('inventory.items.show');
    Route::get('/inventory/items/{item}/edit', [ItemController::class, 'edit'])->middleware('can:inventory.edit')->name('inventory.items.edit');
    Route::put('/inventory/items/{item}', [ItemController::class, 'update'])->middleware('can:inventory.edit')->name('inventory.items.update');
    Route::delete('/inventory/items/{item}', [ItemController::class, 'destroy'])->middleware('can:inventory.delete')->name('inventory.items.destroy');

    // ======= CUSTOMERS =======
    // Staff: view + create (needed for POS). Edit = owner + manager. Delete = owner only.
    // The ERP customer screens are retailer/manufacturer-only. edition:retailer,
    // manufacturer keeps a Dhiran-ONLY shop out of the ERP customer UI (Dhiran has
    // its own shop-scoped borrower creation). Data is already shop-scoped; this
    // prevents ERP-screen exposure inside Dhiran.
    Route::get('/customers', [CustomerController::class, 'index'])->middleware(['edition:retailer,manufacturer', 'can:customers.view'])->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->middleware(['edition:retailer,manufacturer', 'can:customers.create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware(['edition:retailer,manufacturer', 'can:customers.create'])->name('customers.store');
    Route::post('/customers/quick-store', [CustomerController::class, 'quickStore'])->middleware(['edition:retailer,manufacturer', 'can:customers.create'])->name('customers.quick-store');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware(['edition:retailer,manufacturer', 'can:customers.view'])->name('customers.show');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware(['edition:retailer,manufacturer', 'can:customers.edit'])->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware(['edition:retailer,manufacturer', 'can:customers.edit'])->name('customers.update');
    Route::post('/customers/{customer}/verify-compliance', [CustomerController::class, 'verifyCompliance'])->middleware(['edition:retailer,manufacturer', 'can:customers.edit'])->name('customers.verify-compliance');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware(['edition:retailer,manufacturer', 'can:customers.delete'])->name('customers.destroy');

    // KYC docs follow customer access: create-class can attach, delete-class can remove.
    Route::post('/kyc-documents', [\App\Http\Controllers\KycDocumentController::class, 'store'])->middleware('can:customers.create')->name('kyc-documents.store');
    Route::get('/kyc-documents/{kycDocument}/file', [\App\Http\Controllers\KycDocumentController::class, 'show'])->middleware('can:customers.view')->name('kyc-documents.show');
    Route::delete('/kyc-documents/{kycDocument}', [\App\Http\Controllers\KycDocumentController::class, 'destroy'])->middleware('can:customers.edit')->name('kyc-documents.destroy');

    Route::get('/customers/{customer}/gold', [\App\Http\Controllers\CustomerGoldController::class, 'create'])
        ->middleware(['edition:manufacturer', 'can:customers.edit'])
        ->name('customers.gold.create');

    Route::post('/customers/{customer}/gold', [\App\Http\Controllers\CustomerGoldController::class, 'store'])
        ->middleware(['edition:manufacturer', 'can:customers.edit'])
        ->name('customers.gold.store');

    // ======= STORE CREDIT (surface re-connection — PRODUCT_SURFACE_INTEGRITY_AUDIT.md §3.C) =======
    // Maps to the already-committed StoreCreditController. Manual adjustment is
    // owner-only — enforced at BOTH the route (role:owner) and in the controller
    // (ensureOwner()) for defense in depth on a financial-balance write. Redemption
    // (applyToInvoice) is gated by sales.create like recording any payment.
    Route::get('/customers/{customer}/store-credit/adjust', [\App\Http\Controllers\StoreCreditController::class, 'adjustCreate'])->middleware('role:owner')->name('store-credit.adjust.create');
    Route::post('/customers/{customer}/store-credit/adjust', [\App\Http\Controllers\StoreCreditController::class, 'adjustStore'])->middleware('role:owner')->name('store-credit.adjust.store');
    Route::post('/invoices/{invoice}/store-credit/apply', [\App\Http\Controllers\StoreCreditController::class, 'applyToInvoice'])->middleware('can:sales.create')->name('store-credit.apply');

    // ======= PROFILE — owner only (settings.edit = Owner by default) =======
    Route::get('/profile', [ProfileController::class, 'edit'])->middleware('can:settings.edit')->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('can:settings.edit')->name('profile.update');
    Route::post('/profile/deactivate', [ProfileController::class, 'deactivate'])->middleware('can:settings.edit')->name('profile.deactivate');

    // ======= POS =======
    Route::get('/pos', [PosController::class, 'index'])->middleware('can:sales.pos')->name('pos.index');
    Route::get('/pos/customer/{customer}', [PosController::class, 'showCustomerPos'])->middleware('can:sales.pos')->name('pos.customer');
    Route::get('/pos/customers/search', [PosController::class, 'searchCustomers'])->middleware('can:sales.pos')->name('pos.customers.search');
    Route::post('/pos/sell', [PosController::class, 'sell'])->middleware('can:sales.create')->name('pos.sell');
    Route::post('/pos/quote', [PosController::class, 'quote'])
        ->middleware('can:sales.pos')
        ->name('pos.quote');
    Route::post('/pos/quote/persist', [PosController::class, 'persistQuote'])
        ->middleware('can:sales.pos')
        ->name('pos.quote.persist');
    Route::post('/pos/compliance/save', [PosController::class, 'saveCompliance'])->middleware('can:sales.create')->name('pos.compliance.save');
    Route::post('/pos/exchange', [PosController::class, 'exchange'])->middleware('can:sales.create', 'edition:manufacturer');
    Route::post('/api/price-preview', [PosController::class, 'preview'])->middleware('can:sales.pos')->name('pos.preview');
    Route::get('/api/item-by-barcode/{barcode}', [PosController::class, 'findByBarcode'])->middleware('can:sales.pos')->name('pos.barcode');

    // ======= RETURNS & EXCHANGES (web surface re-connection — RETURNS_SYSTEM_DIAGNOSTIC.md) =======
    // Maps 1:1 to the already-committed Returns\ReturnsController & Returns\ExchangeController.
    // No controller/semantics changes — pure route re-registration. Static segments
    // (control-center) are declared BEFORE the {returnOrder} wildcard.
    Route::prefix('returns')->group(function () {
        Route::get('/', [\App\Http\Controllers\Returns\ReturnsController::class, 'index'])->middleware('can:returns.view')->name('returns.index');
        Route::get('/control-center', [\App\Http\Controllers\Returns\ReturnsController::class, 'controlCenter'])->middleware('can:returns.approve')->name('returns.control-center');
        Route::post('/batch-restock', [\App\Http\Controllers\Returns\ReturnsController::class, 'batchRestock'])->middleware('can:returns.approve')->name('returns.batch-restock');

        // Disposition / recovery on returned items.
        Route::post('/items/{item}/redispose', [\App\Http\Controllers\Returns\ReturnsController::class, 'redisposeItem'])->middleware('can:returns.approve')->name('returns.items.redispose');
        Route::post('/items/{item}/fix-orphan-status', [\App\Http\Controllers\Returns\ReturnsController::class, 'fixOrphanStatus'])->middleware('can:returns.approve')->name('returns.items.fix-orphan-status');
        Route::get('/items/{disposition}/recover', [\App\Http\Controllers\Returns\ReturnsController::class, 'showRecover'])->middleware('can:returns.approve')->name('returns.items.recover');
        Route::post('/items/{disposition}/recover', [\App\Http\Controllers\Returns\ReturnsController::class, 'storeRecover'])->middleware('can:returns.approve')->name('returns.items.recover.store');

        // Single return order.
        Route::get('/{returnOrder}', [\App\Http\Controllers\Returns\ReturnsController::class, 'show'])->middleware('can:returns.view')->name('returns.show');
        Route::get('/{returnOrder}/approve-review', [\App\Http\Controllers\Returns\ReturnsController::class, 'showApprove'])->middleware('can:returns.approve')->name('returns.approve-review');
        Route::post('/{returnOrder}/approve', [\App\Http\Controllers\Returns\ReturnsController::class, 'approve'])->middleware('can:returns.approve')->name('returns.approve');
        Route::post('/{returnOrder}/reject', [\App\Http\Controllers\Returns\ReturnsController::class, 'reject'])->middleware('can:returns.approve')->name('returns.reject');
        Route::post('/{returnOrder}/dispositions/{disposition}/recover-inline', [\App\Http\Controllers\Returns\ReturnsController::class, 'inlineRecover'])->middleware('can:returns.approve')->name('returns.disposition.recover-inline');
    });

    // Start-a-return + unified exchange entry points are invoice-scoped.
    Route::get('/invoices/{invoice}/returns/create', [\App\Http\Controllers\Returns\ReturnsController::class, 'create'])->middleware('can:returns.create')->name('returns.create');
    Route::post('/invoices/{invoice}/returns', [\App\Http\Controllers\Returns\ReturnsController::class, 'store'])->middleware('can:returns.create')->name('returns.store');
    Route::get('/invoices/{invoice}/exchange', [\App\Http\Controllers\Returns\ExchangeController::class, 'createUnified'])->middleware('can:returns.create')->name('exchanges.unified.create');
    Route::post('/invoices/{invoice}/exchange', [\App\Http\Controllers\Returns\ExchangeController::class, 'storeUnified'])->middleware('can:returns.create')->name('exchanges.unified.store');

    Route::prefix('exchanges')->group(function () {
        Route::get('/', [\App\Http\Controllers\Returns\ExchangeController::class, 'index'])->middleware('can:returns.view')->name('exchanges.index');
        Route::get('/create/{returnOrder}', [\App\Http\Controllers\Returns\ExchangeController::class, 'create'])->middleware('can:returns.create')->name('exchanges.create');
        Route::post('/{returnOrder}', [\App\Http\Controllers\Returns\ExchangeController::class, 'store'])->middleware('can:returns.create')->name('exchanges.store');
        Route::get('/{exchange}', [\App\Http\Controllers\Returns\ExchangeController::class, 'show'])->middleware('can:returns.view')->name('exchanges.show');
        Route::get('/{exchange}/receipt', [\App\Http\Controllers\Returns\ExchangeController::class, 'receipt'])->middleware('can:returns.view')->name('exchanges.receipt');
    });

    // ======= REPAIRS =======
    // Staff: view + create + edit + status update + deliver (repairs.create/edit permission).
    // Delete = owner + manager only.
    Route::get('/repairs', [\App\Http\Controllers\RepairController::class, 'index'])->middleware('can:repairs.view')->name('repairs.index');
    Route::post('/repairs', [\App\Http\Controllers\RepairController::class, 'store'])->middleware('can:repairs.create')->name('repairs.store');
    Route::get('/repairs/{repair}', [\App\Http\Controllers\RepairController::class, 'show'])->middleware('can:repairs.view')->name('repairs.show');
    Route::get('/repairs/{repair}/edit', [\App\Http\Controllers\RepairController::class, 'edit'])->middleware('can:repairs.edit')->name('repairs.edit');
    Route::put('/repairs/{repair}', [\App\Http\Controllers\RepairController::class, 'update'])->middleware('can:repairs.edit')->name('repairs.update');
    Route::patch('/repairs/{repair}/status', [\App\Http\Controllers\RepairController::class, 'updateStatus'])->middleware('can:repairs.edit')->name('repairs.status');
    Route::post('/repairs/{repair}/deliver', [\App\Http\Controllers\RepairController::class, 'deliver'])->middleware('can:repairs.edit')->name('repairs.deliver');
    Route::delete('/repairs/{repair}', [\App\Http\Controllers\RepairController::class, 'destroy'])->middleware('can:repairs.delete')->name('repairs.destroy');

    // ======= JOB WORK (Retailer) =======
    Route::middleware('edition:retailer')->group(function () {
        // Bullion Vault — view to read; manage to write/dispatch.
        Route::get('/vault', [\App\Http\Controllers\BullionVaultController::class, 'index'])->middleware('can:vault.view')->name('vault.index');
        Route::get('/vault/ledger', [\App\Http\Controllers\BullionVaultController::class, 'ledger'])->middleware('can:vault.view')->name('vault.ledger');
        Route::get('/vault/lots/create', [\App\Http\Controllers\BullionVaultController::class, 'createLot'])->middleware('can:vault.manage')->name('vault.lots.create');
        Route::post('/vault/lots', [\App\Http\Controllers\BullionVaultController::class, 'storeLot'])->middleware('can:vault.manage')->name('vault.lots.store');
        Route::get('/vault/lots/{metalLot}', [\App\Http\Controllers\BullionVaultController::class, 'showLot'])->middleware('can:vault.view')->name('vault.lots.show');
        Route::post('/vault/lots/{metalLot}/adjust', [\App\Http\Controllers\BullionVaultController::class, 'adjustLot'])->middleware('can:vault.manage')->name('vault.lots.adjust');

        // Karigars — view to read; manage to mutate.
        Route::get('/karigars', [\App\Http\Controllers\KarigarController::class, 'index'])->middleware('can:karigar.view')->name('karigars.index');
        Route::get('/karigars/create', [\App\Http\Controllers\KarigarController::class, 'create'])->middleware('can:karigar.manage')->name('karigars.create');
        Route::post('/karigars', [\App\Http\Controllers\KarigarController::class, 'store'])->middleware('can:karigar.manage')->name('karigars.store');
        Route::get('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'show'])->middleware('can:karigar.view')->name('karigars.show');
        Route::get('/karigars/{karigar}/edit', [\App\Http\Controllers\KarigarController::class, 'edit'])->middleware('can:karigar.manage')->name('karigars.edit');
        Route::put('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'update'])->middleware('can:karigar.manage')->name('karigars.update');
        Route::delete('/karigars/{karigar}', [\App\Http\Controllers\KarigarController::class, 'destroy'])->middleware('can:karigar.manage')->name('karigars.destroy');
        Route::patch('/karigars/{karigar}/toggle', [\App\Http\Controllers\KarigarController::class, 'toggle'])->middleware('can:karigar.manage')->name('karigars.toggle');

        // Job Orders — view to read; manage to mutate.
        Route::get('/job-orders', [\App\Http\Controllers\JobOrderController::class, 'index'])->middleware('can:job_order.view')->name('job-orders.index');
        Route::get('/job-orders/create', [\App\Http\Controllers\JobOrderController::class, 'create'])->middleware('can:job_order.manage')->name('job-orders.create');
        Route::post('/job-orders', [\App\Http\Controllers\JobOrderController::class, 'store'])->middleware('can:job_order.manage')->name('job-orders.store');
        Route::get('/job-orders/{jobOrder}', [\App\Http\Controllers\JobOrderController::class, 'show'])->middleware('can:job_order.view')->name('job-orders.show');
        Route::post('/job-orders/{jobOrder}/cancel', [\App\Http\Controllers\JobOrderController::class, 'cancel'])->middleware('can:job_order.manage')->name('job-orders.cancel');
        Route::get('/job-orders/{jobOrder}/challan', [\App\Http\Controllers\JobOrderController::class, 'challan'])->middleware('can:job_order.view')->name('job-orders.challan');
        Route::get('/job-orders/{jobOrder}/return-doc', [\App\Http\Controllers\JobOrderController::class, 'returnDoc'])->middleware('can:job_order.view')->name('job-orders.return-doc');
        Route::get('/job-orders/{jobOrder}/receive', [\App\Http\Controllers\JobOrderController::class, 'receiveForm'])->middleware('can:job_order.manage')->name('job-orders.receive.form');
        Route::post('/job-orders/{jobOrder}/receive', [\App\Http\Controllers\JobOrderController::class, 'storeReceipt'])->middleware('can:job_order.manage')->name('job-orders.receive.store');
        Route::post('/job-orders/{jobOrder}/leftover-return', [\App\Http\Controllers\JobOrderController::class, 'leftoverReturn'])->middleware('can:job_order.manage')->name('job-orders.leftover');
        Route::post('/job-orders/{jobOrder}/acknowledge', [\App\Http\Controllers\JobOrderController::class, 'acknowledge'])->middleware('can:job_order.manage')->name('job-orders.acknowledge');
        Route::post('/job-orders/{jobOrder}/reassign', [\App\Http\Controllers\JobOrderController::class, 'reassign'])->middleware('can:job_order.manage')->name('job-orders.reassign');
        Route::post('/karigar-balance/transfer', [\App\Http\Controllers\JobOrderController::class, 'transferBalance'])->middleware('can:job_order.manage')->name('karigar-balance.transfer');

        // Karigar Invoices — view to read; manage to mutate.
        Route::get('/karigar-invoices', [\App\Http\Controllers\KarigarInvoiceController::class, 'index'])->middleware('can:karigar_invoice.view')->name('karigar-invoices.index');
        Route::get('/karigar-invoices/create', [\App\Http\Controllers\KarigarInvoiceController::class, 'create'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.create');
        Route::post('/karigar-invoices', [\App\Http\Controllers\KarigarInvoiceController::class, 'store'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.store');
        Route::get('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'show'])->middleware('can:karigar_invoice.view')->name('karigar-invoices.show');
        Route::get('/karigar-invoices/{karigarInvoice}/edit', [\App\Http\Controllers\KarigarInvoiceController::class, 'edit'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.edit');
        Route::put('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'update'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.update');
        Route::delete('/karigar-invoices/{karigarInvoice}', [\App\Http\Controllers\KarigarInvoiceController::class, 'destroy'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.destroy');
        Route::get('/karigar-invoices/{karigarInvoice}/print', [\App\Http\Controllers\KarigarInvoiceController::class, 'print'])->middleware('can:karigar_invoice.view')->name('karigar-invoices.print');
        Route::post('/karigar-invoices/{karigarInvoice}/payments', [\App\Http\Controllers\KarigarInvoiceController::class, 'recordPayment'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.pay');
        Route::post('/karigar-invoices/{karigarInvoice}/payments/{payment}/reverse', [\App\Http\Controllers\KarigarInvoiceController::class, 'reversePayment'])->middleware('can:karigar_invoice.manage')->name('karigar-invoices.payments.reverse');
    });

    // ======= REPORTS (permission-gated: reports.view for most; reports.daily_closing for closing) =======
    Route::get('/reports', [ReportController::class, 'hub'])->middleware('can:reports.view')->name('report.hub');
    // Gold Balances — served by the reporting spine (Phase 4). Same URL/name/permission.
    Route::get('/report/gold', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'gold-balances')->middleware('can:reports.view')->name('report.gold');
    Route::get('/report/metal-exchange', [\App\Http\Controllers\MetalExchangeReportController::class, 'index'])->middleware(['can:reports.view', 'edition:retailer'])->name('report.metal-exchange');
    Route::post('/old-metal-lots/{metalLot}/dispatch', [\App\Http\Controllers\OldMetalWeeklyLotController::class, 'dispatch'])->middleware(['can:vault.manage', 'edition:retailer'])->name('old-metal-lots.dispatch');
    // Daily Sales Summary — served by the reporting spine (Phase 3). Same URL/name/permission;
    // honours the legacy ?date=YYYY-MM-DD bookmark. Sales/GST + the day's metal movement.
    Route::get('/report/daily', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'daily-summary')->middleware('can:reports.view')->name('report.daily');
    // Cash Flow — served by the reporting spine (Phase 3). Same URL/name/permission.
    Route::get('/report/cash', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'cash-flow')->middleware('can:reports.view')->name('report.cash');
    // Profit & Loss — served by the reporting spine (Phase 4). Same URL/name/permission;
    // honours the legacy ?date=YYYY-MM-DD single-day bookmark, else the current month.
    Route::get('/report/pnl', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'pnl')->middleware('can:reports.view')->name('report.pnl');
    Route::get('/report/gst', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'gst')->middleware('can:reports.view')->name('report.gst');
    // CA / compliance tax pack (Phase 2 M1) — all read-only, reports.view gated.
    Route::get('/report/gstr1', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'gstr1')->middleware('can:reports.view')->name('report.gstr1');
    // report.gstr1.csv retired (Phase 3 Cleanup #1) — use the spine export (POST /reports/gstr1/export).
    Route::get('/report/gstr3b', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'gstr3b')->middleware('can:reports.view')->name('report.gstr3b');
    Route::get('/report/cn-register', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'cn-register')->middleware('can:reports.view')->name('report.cn-register');
    // report.cn-register.csv retired (Phase 3 Cleanup #1) — use the spine export.
    // CA Ledger & Reconciliation pack (Phase 2 M2) — read-only, reports.view gated.
    // Payment Reconciliation — served by the reporting spine (Phase 3). Same URL/name/permission.
    Route::get('/report/payment-reconciliation', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'payment-reconciliation')->middleware('can:reports.view')->name('report.payment-reconciliation');
    // report.payment-reconciliation.csv retired (Phase 3) — use the spine export (POST /reports/payment-reconciliation/export).
    Route::get('/report/day-book', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'day-book')->middleware('can:reports.view')->name('report.day-book');
    // report.day-book.csv retired (Phase 3 Cleanup #1) — use the spine export.
    // Inventory Valuation — served by the reporting spine (Phase 3). Same URL/name/permission.
    Route::get('/report/inventory-valuation', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'inventory-valuation')->middleware('can:reports.view')->name('report.inventory-valuation');
    Route::get('/report/inventory-valuation/export', [\App\Http\Controllers\Reporting\ReconciliationReportController::class, 'inventoryValuationCsv'])->middleware('can:reports.view')->name('report.inventory-valuation.csv');
    Route::get('/report/dead-stock', [\App\Http\Controllers\Reporting\ReconciliationReportController::class, 'deadStock'])->middleware('can:reports.view')->name('report.dead-stock');
    Route::get('/report/dead-stock/export', [\App\Http\Controllers\Reporting\ReconciliationReportController::class, 'deadStockCsv'])->middleware('can:reports.view')->name('report.dead-stock.csv');
    Route::get('/report/karigar-settlement', [\App\Http\Controllers\Reporting\KarigarReportController::class, 'settlement'])->middleware('can:reports.view')->name('report.karigar-settlement');
    Route::get('/report/karigar-settlement/export', [\App\Http\Controllers\Reporting\KarigarReportController::class, 'settlementCsv'])->middleware('can:reports.view')->name('report.karigar-settlement.csv');
    Route::get('/report/shrinkage', [\App\Http\Controllers\Reporting\KarigarReportController::class, 'shrinkage'])->middleware('can:reports.view')->name('report.shrinkage');
    Route::get('/report/shrinkage/export', [\App\Http\Controllers\Reporting\KarigarReportController::class, 'shrinkageCsv'])->middleware('can:reports.view')->name('report.shrinkage.csv');
    Route::get('/report/purchase-efficiency', [\App\Http\Controllers\Reporting\ReconciliationReportController::class, 'purchaseEfficiency'])->middleware('can:reports.view')->name('report.purchase-efficiency');
    Route::get('/report/purchase-efficiency/export', [\App\Http\Controllers\Reporting\ReconciliationReportController::class, 'purchaseEfficiencyCsv'])->middleware('can:reports.view')->name('report.purchase-efficiency.csv');
    Route::get('/report/operator-performance', [\App\Http\Controllers\Reporting\AuditReportController::class, 'operatorPerformance'])->middleware('can:reports.view')->name('report.operator-performance');
    Route::get('/report/operator-performance/export', [\App\Http\Controllers\Reporting\AuditReportController::class, 'operatorPerformanceCsv'])->middleware('can:reports.view')->name('report.operator-performance.csv');
    Route::get('/report/suspicious-activity', [\App\Http\Controllers\Reporting\AuditReportController::class, 'suspiciousActivity'])->middleware('can:reports.view')->name('report.suspicious-activity');
    Route::get('/report/suspicious-activity/export', [\App\Http\Controllers\Reporting\AuditReportController::class, 'suspiciousActivityCsv'])->middleware('can:reports.view')->name('report.suspicious-activity.csv');
    Route::get('/report/dues-aging', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'duesAging'])->middleware('can:reports.view')->name('report.dues-aging');
    Route::get('/report/dues-aging/export', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'duesAgingCsv'])->middleware('can:reports.view')->name('report.dues-aging.csv');
    Route::get('/report/emi', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'emi'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.emi');
    Route::get('/report/emi/export', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'emiCsv'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.emi.csv');
    Route::get('/report/scheme-liability', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'schemeLiability'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.scheme-liability');
    Route::get('/report/scheme-liability/export', [\App\Http\Controllers\Reporting\ReceivablesReportController::class, 'schemeLiabilityCsv'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.scheme-liability.csv');
    // Metal Liability — served by the reporting spine (Phase 3). Same URL/name/permission.
    Route::get('/report/metal-liability', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'metal-liability')->middleware('can:reports.view')->name('report.metal-liability');
    // report.metal-liability.csv retired (Phase 3) — use the spine export (POST /reports/metal-liability/export).
    // Daily Closing — served by the reporting spine (Phase 3). Same URL/name; keeps reports.daily_closing.
    Route::get('/report/closing', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'daily-closing')->middleware('can:reports.daily_closing')->name('report.closing');
    Route::get('/report/repairs', [RepairReportController::class, 'index'])->middleware('can:reports.view')->name('report.repairs');
    Route::get('/report/reference-prices', [\App\Http\Controllers\ReferencePriceHistoryController::class, 'index'])->middleware('can:reports.view')->name('report.reference-prices');
    Route::get('/report/audit', function () {
        return redirect()->route('settings.edit', ['tab' => 'audit']);
    })->middleware('can:settings.view');
    Route::get('/settings/audit/export', [SettingsController::class, 'exportAudit'])
        ->middleware('can:settings.view')->name('settings.audit.export');

    // ======= REPORTING-EXPORT SPINE (Phase 0; per-report gates enforced in ExportRequest) =======
    // Phase 1 pilot — Sales / Invoice Register screen.
    Route::get('/report/sales-register', [\App\Http\Controllers\Reporting\SalesRegisterController::class, 'index'])
        ->middleware('can:reports.view')->name('report.sales-register');
    // Generic spine report screen (any registered report; rigidity enforced in the controller).
    Route::get('/reporting/{report}/screen', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])
        ->middleware('can:reports.view')->name('reporting.report.screen');
    Route::get('/reports/{report}/export', [\App\Http\Controllers\Reporting\ExportController::class, 'panel'])->name('reporting.export.panel');
    Route::post('/reports/{report}/export', [\App\Http\Controllers\Reporting\ExportController::class, 'export'])->name('reporting.export');
    Route::get('/reporting/exports/{export}/download', [\App\Http\Controllers\Reporting\ExportDownloadController::class, 'download'])
        ->middleware('signed')
        ->name('reporting.exports.download');

    // Shop-wide export presets (frozen §8; GAP 1). reports.export gates all of
    // these; the controller restricts create/edit/delete to owner/manager. A
    // preset only pre-fills the export panel — it never bypasses the export gate.
    Route::middleware('can:reports.export')->group(function () {
        Route::get('/reports/{report}/export-presets', [\App\Http\Controllers\Reporting\PresetController::class, 'index'])->name('reporting.presets.index');
        Route::post('/reports/{report}/export-presets', [\App\Http\Controllers\Reporting\PresetController::class, 'store'])->name('reporting.presets.store');
        Route::patch('/reports/{report}/export-presets/{preset}', [\App\Http\Controllers\Reporting\PresetController::class, 'update'])->name('reporting.presets.update');
        Route::delete('/reports/{report}/export-presets/{preset}', [\App\Http\Controllers\Reporting\PresetController::class, 'destroy'])->name('reporting.presets.destroy');
    });

    // ======= SETTINGS =======
    Route::get('/profile/mobile/change', [\App\Http\Controllers\MobileChangeController::class, 'showForm'])->middleware('can:settings.edit')->name('profile.mobile.change');
    Route::post('/profile/mobile/change-request', [\App\Http\Controllers\MobileChangeController::class, 'requestChange'])->middleware('can:settings.edit')->name('profile.mobile.request');
    Route::post('/profile/mobile/change-verify', [\App\Http\Controllers\MobileChangeController::class, 'verifyChange'])->middleware('can:settings.edit')->name('profile.mobile.verify');

    // Settings — read access: anyone with `settings.view` permission (default: Owner + Manager).
    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('can:settings.view')->name('settings.edit');
    Route::get('/settings/services', [\App\Http\Controllers\ShopServicesController::class, 'index'])->middleware('can:settings.view')->name('settings.services');
    Route::get('/settings/payment-methods', [PaymentMethodController::class, 'index'])->middleware('can:settings.view')->name('settings.payment-methods.index');

    // Settings — write access: requires `settings.edit` permission (default: Owner only).
    Route::post('/settings/services/request-add', [\App\Http\Controllers\ShopServicesController::class, 'requestAdd'])->middleware('can:settings.edit')->name('settings.services.request-add');
    // Paid self-serve add (Phase 5b checkout): initiate Razorpay order for a
    // product, then verify and create a NEW product subscription on success.
    Route::post('/settings/services/initiate-add', [\App\Http\Controllers\ShopServicesController::class, 'initiateAdd'])->middleware('can:settings.edit')->name('settings.services.initiate-add');
    Route::post('/settings/services/add-callback', [\App\Http\Controllers\ShopServicesController::class, 'addCallback'])->middleware('can:settings.edit')->name('settings.services.add-callback');
    Route::post('/settings/services/remove', [\App\Http\Controllers\ShopServicesController::class, 'remove'])->middleware('can:settings.edit')->name('settings.services.remove');
    Route::post('/settings/services/requests/{editionRequest}/cancel', [\App\Http\Controllers\ShopServicesController::class, 'cancelRequest'])->middleware('can:settings.edit')->name('settings.services.request.cancel');
    Route::patch('/settings/shop', [SettingsController::class, 'updateShop'])->middleware('can:settings.edit')->name('settings.update.shop');
    Route::patch('/settings/billing', [SettingsController::class, 'updateBilling'])->middleware('can:settings.edit')->name('settings.update.billing');
    Route::patch('/settings/preferences', [SettingsController::class, 'updatePreferences'])->middleware('can:settings.edit')->name('settings.update.preferences');
    Route::patch('/settings/return-policy', [SettingsController::class, 'updateReturnPolicy'])->middleware('can:settings.edit')->name('settings.update.return-policy');
    Route::patch('/settings/materials', [SettingsController::class, 'updateMaterials'])->middleware('can:settings.edit')->name('settings.update.materials');
    Route::patch('/settings/gst', [SettingsController::class, 'updateGst'])->middleware('can:settings.edit')->name('settings.update.gst');
    // Per-metal GST categories (the per-metal GST rate setting).
    Route::post('/settings/gst-categories', [\App\Http\Controllers\GstCategoryController::class, 'store'])->middleware('can:settings.edit')->name('settings.gst-categories.store');
    Route::patch('/settings/gst-categories/{gstCategory}', [\App\Http\Controllers\GstCategoryController::class, 'update'])->middleware('can:settings.edit')->name('settings.gst-categories.update');
    Route::delete('/settings/gst-categories/{gstCategory}', [\App\Http\Controllers\GstCategoryController::class, 'destroy'])->middleware('can:settings.edit')->name('settings.gst-categories.destroy');
    Route::patch('/settings/materials/reference', [SettingsController::class, 'updateMaterialReference'])->middleware('can:settings.edit')->name('settings.update.material-reference');

    // Connected mobile devices — disconnect one device, or all devices for one staff member.
    // The controller does its own tenant scoping + owner/manager guards; `staff.manage` is
    // the defense-in-depth gate matching sibling destructive routes.
    Route::delete('/settings/devices/{deviceSession}', [\App\Http\Controllers\MobileDeviceSessionController::class, 'destroy'])->middleware('can:staff.manage')->name('settings.devices.destroy');
    Route::delete('/settings/devices/user/{user}/all', [\App\Http\Controllers\MobileDeviceSessionController::class, 'destroyAllForUser'])->middleware('can:staff.manage')->name('settings.devices.destroy-all');
    Route::post('/settings/whatsapp-template', [SettingsController::class, 'saveWhatsappTemplate'])->middleware('can:settings.edit')->name('settings.whatsapp.template');
    // The role-permission editor itself stays `role:owner` — only the shop owner
    // controls who has which permissions. Permission-gating this would let a
    // Manager with settings.edit reshape the entire permission matrix.
    Route::patch('/settings/roles/{role}', [SettingsController::class, 'updateRolePermissions'])->middleware('role:owner')->name('settings.update.role');
    Route::patch('/settings/pricing/timezone', [PricingSettingsController::class, 'updateTimezone'])->middleware('can:pricing.update')->name('settings.pricing.update-timezone');
    Route::post('/settings/pricing/daily-rates', [PricingSettingsController::class, 'saveTodayRates'])->middleware('can:pricing.update')->name('settings.pricing.save-rates');
    Route::post('/settings/pricing/purity-profiles', [PricingSettingsController::class, 'storeProfile'])->middleware('can:pricing.update')->name('settings.pricing.profiles.store');
    Route::patch('/settings/pricing/purity-profiles/{profile}', [PricingSettingsController::class, 'updateProfile'])->middleware('can:pricing.update')->name('settings.pricing.profiles.update');
    Route::post('/settings/pricing/purity-profiles/{profile}/override', [PricingSettingsController::class, 'storeOverride'])->middleware('can:pricing.update')->name('settings.pricing.overrides.store');
    Route::patch('/settings/pricing/legacy-items/{item}', [PricingSettingsController::class, 'resolveLegacyItem'])->middleware('can:pricing.update')->name('settings.pricing.legacy.resolve');

    // Payment Methods write routes — `settings.edit` gates configuration changes.
    Route::post('/settings/payment-methods', [PaymentMethodController::class, 'store'])->middleware('can:settings.edit')->name('settings.payment-methods.store');
    Route::put('/settings/payment-methods/{method}', [PaymentMethodController::class, 'update'])->middleware('can:settings.edit')->name('settings.payment-methods.update');
    Route::delete('/settings/payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->middleware('can:settings.edit')->name('settings.payment-methods.destroy');
    Route::patch('/settings/payment-methods/{method}/toggle', [PaymentMethodController::class, 'toggle'])->middleware('can:settings.edit')->name('settings.payment-methods.toggle');

    // Catalog Website settings — settings.edit gates configuration; catalog.manage gates
    // the catalog content itself (collections/pages). Following the existing dichotomy.
    Route::patch('/settings/catalog-website', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'updateSettings'])->middleware('can:settings.edit')->name('settings.update.catalog-website');
    Route::post('/settings/catalog-pages', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'storePage'])->middleware('can:catalog.manage')->name('settings.catalog-pages.store');
    Route::put('/settings/catalog-pages/{page}', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'updatePage'])->middleware('can:catalog.manage')->name('settings.catalog-pages.update');
    Route::delete('/settings/catalog-pages/{page}', [\App\Http\Controllers\CatalogWebsiteSettingsController::class, 'destroyPage'])->middleware('can:catalog.manage')->name('settings.catalog-pages.destroy');

    // ======= LEDGER & INVOICE (sales.* permissions) =======
    // Metal Movement Ledger — served by the reporting spine (Phase 3). Same URL/name/permission.
    Route::get('/ledger', [\App\Http\Controllers\Reporting\ReportScreenController::class, 'show'])->defaults('report', 'metal-ledger')->middleware('can:reports.view')->name('ledger.index');
    Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->middleware('can:sales.view')->name('invoices.index');
    Route::get('/invoices/{invoice}/edit', [\App\Http\Controllers\InvoiceController::class, 'edit'])->middleware('can:sales.create')->name('invoices.edit');
    // Route gate is the lower 'sales.create' so cashiers can FINALIZE drafts;
    // the cancel/void branch is separately gated 'sales.void' inside update().
    Route::put('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'update'])->middleware('can:sales.create')->name('invoices.update');
    Route::get('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'show'])->middleware('can:sales.view')->name('invoices.show');
    Route::get('/invoice/{invoice}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])->middleware('can:sales.view')->name('invoices.print');

    // ======= QUICK BILL GENERATOR (sales.* permissions) =======
    Route::get('/quick-bills', [QuickBillController::class, 'index'])
        ->middleware('can:sales.view')
        ->name('quick-bills.index');
    Route::get('/quick-bills/create', [QuickBillController::class, 'create'])
        ->middleware('can:sales.create')
        ->name('quick-bills.create');
    Route::post('/quick-bills', [QuickBillController::class, 'store'])
        ->middleware('can:sales.create')
        ->name('quick-bills.store');
    Route::get('/quick-bills/{quickBill}', [QuickBillController::class, 'show'])
        ->middleware('can:sales.view')
        ->name('quick-bills.show');
    Route::get('/quick-bills/{quickBill}/edit', [QuickBillController::class, 'edit'])
        ->middleware('can:sales.create')
        ->name('quick-bills.edit');
    Route::put('/quick-bills/{quickBill}', [QuickBillController::class, 'update'])
        ->middleware('can:sales.create')
        ->name('quick-bills.update');
    Route::post('/quick-bills/{quickBill}/void', [QuickBillController::class, 'void'])
        ->middleware('can:sales.void')
        ->name('quick-bills.void');
    Route::get('/quick-bills/{quickBill}/print', [QuickBillController::class, 'print'])
        ->middleware('can:sales.view')
        ->name('quick-bills.print');
    Route::get('/quick-bills/{quickBill}/print/original', [QuickBillController::class, 'printOriginal'])
        ->middleware('can:sales.view')
        ->name('quick-bills.print-original');

    // ======= CASH BOOK (cash.view / cash.create permissions) =======
    Route::get('/cashbook', [\App\Http\Controllers\CashBookController::class, 'index'])->middleware('can:cash.view')->name('cashbook.index');
    Route::get('/cashbook/create', [\App\Http\Controllers\CashBookController::class, 'create'])->middleware('can:cash.create')->name('cashbook.create');
    Route::post('/cashbook', [\App\Http\Controllers\CashBookController::class, 'store'])->middleware('can:cash.create')->name('cashbook.store');
    Route::post('/cashbook/drawer-check', [\App\Http\Controllers\CashBookController::class, 'storeDrawerCheck'])->middleware('can:cash.create')->name('cashbook.drawer-check');

    // ======= STAFF MANAGEMENT (staff.view to read; staff.manage to mutate) =======
    Route::get('/staff', [\App\Http\Controllers\StaffController::class, 'index'])->middleware('can:staff.view')->name('staff.index');
    Route::get('/staff/create', [\App\Http\Controllers\StaffController::class, 'create'])->middleware('can:staff.manage')->name('staff.create');
    Route::post('/staff', [\App\Http\Controllers\StaffController::class, 'store'])->middleware('can:staff.manage')->name('staff.store');
    Route::get('/staff/{staff}/edit', [\App\Http\Controllers\StaffController::class, 'edit'])->middleware('can:staff.manage')->name('staff.edit');
    Route::put('/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'update'])->middleware('can:staff.manage')->name('staff.update');
    Route::delete('/staff/{staff}', [\App\Http\Controllers\StaffController::class, 'destroy'])->middleware('can:staff.manage')->name('staff.destroy');
    Route::patch('/staff/{staff}/reactivate', [\App\Http\Controllers\StaffController::class, 'reactivate'])->middleware('can:staff.manage')->name('staff.reactivate');

    // ======= RETAILER FEATURES (edition:retailer) =======

    // --- Stock Purchases / Inward ---
    Route::get('/inventory/purchases', [StockPurchaseController::class, 'index'])->middleware(['edition:retailer', 'can:inventory.view'])->name('inventory.purchases.index');
    Route::get('/inventory/purchases/create', [StockPurchaseController::class, 'create'])->middleware(['edition:retailer', 'can:inventory.create'])->name('inventory.purchases.create');
    Route::post('/inventory/purchases', [StockPurchaseController::class, 'store'])->middleware(['edition:retailer', 'can:inventory.create'])->name('inventory.purchases.store');
    Route::get('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'show'])->middleware(['edition:retailer', 'can:inventory.view'])->name('inventory.purchases.show');
    Route::get('/inventory/purchases/{purchase}/edit', [StockPurchaseController::class, 'edit'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('inventory.purchases.edit');
    Route::put('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'update'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('inventory.purchases.update');
    Route::patch('/inventory/purchases/{purchase}/confirm', [StockPurchaseController::class, 'confirm'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('inventory.purchases.confirm');
    Route::patch('/inventory/purchases/{purchase}/stock', [StockPurchaseController::class, 'addToInventory'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('inventory.purchases.stock');
    Route::patch('/inventory/purchases/{purchase}/reverse', [StockPurchaseController::class, 'reverse'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('inventory.purchases.reverse');
    Route::get('/inventory/purchases/{purchase}/vault/{line}', [StockPurchaseController::class, 'vaultLineForm'])->middleware(['edition:retailer', 'can:vault.manage'])->name('inventory.purchases.vault-line.form');
    Route::post('/inventory/purchases/{purchase}/vault/{line}', [StockPurchaseController::class, 'vaultLine'])->middleware(['edition:retailer', 'can:vault.manage'])->name('inventory.purchases.vault-line');
    Route::delete('/inventory/purchases/{purchase}', [StockPurchaseController::class, 'destroy'])->middleware(['edition:retailer', 'can:inventory.delete'])->name('inventory.purchases.destroy');

    // --- Vendors / Suppliers ---
    Route::get('/vendors', [VendorController::class, 'index'])->middleware(['edition:retailer', 'can:vendors.view'])->name('vendors.index');
    Route::get('/vendors/create', [VendorController::class, 'create'])->middleware(['edition:retailer', 'can:vendors.manage'])->name('vendors.create');
    Route::post('/vendors', [VendorController::class, 'store'])->middleware(['edition:retailer', 'can:vendors.manage'])->name('vendors.store');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])->middleware(['edition:retailer', 'can:vendors.view'])->name('vendors.show');
    Route::get('/vendors/{vendor}/edit', [VendorController::class, 'edit'])->middleware(['edition:retailer', 'can:vendors.manage'])->name('vendors.edit');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])->middleware(['edition:retailer', 'can:vendors.manage'])->name('vendors.update');
    Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])->middleware(['edition:retailer', 'can:vendors.manage'])->name('vendors.destroy');

    // --- Schemes & Offers (reuse catalog.manage — no dedicated schemes permission in seed) ---
    Route::get('/schemes', [SchemeController::class, 'index'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.index');
    Route::get('/schemes/create', [SchemeController::class, 'create'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.create');
    Route::post('/schemes', [SchemeController::class, 'store'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.store');
    Route::get('/schemes/{scheme}', [SchemeController::class, 'show'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.show');
    Route::get('/schemes/{scheme}/edit', [SchemeController::class, 'edit'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.edit');
    Route::put('/schemes/{scheme}', [SchemeController::class, 'update'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.update');
    Route::delete('/schemes/{scheme}', [SchemeController::class, 'destroy'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('schemes.destroy');
    // Enrollment + payment recording — customer-facing actions, sales-level access.
    Route::get('/schemes/{scheme}/enroll', [SchemeController::class, 'enrollForm'])->middleware(['edition:retailer', 'can:sales.create'])->name('schemes.enroll.form');
    Route::post('/schemes/{scheme}/enroll', [SchemeController::class, 'enroll'])->middleware(['edition:retailer', 'can:sales.create'])->name('schemes.enroll');
    Route::get('/scheme-enrollments/{enrollment}', [SchemeController::class, 'enrollmentShow'])->middleware(['edition:retailer', 'can:sales.view'])->name('schemes.enrollment.show');
    Route::post('/scheme-enrollments/{enrollment}/pay', [SchemeController::class, 'recordPayment'])->middleware(['edition:retailer', 'can:sales.create'])->name('schemes.enrollment.pay');
    Route::post('/scheme-enrollments/{enrollment}/redeem', [SchemeController::class, 'redeemToInvoice'])->middleware(['edition:retailer', 'can:sales.create'])->name('schemes.enrollment.redeem');
    Route::post('/scheme-enrollments/{enrollment}/cancel', [SchemeController::class, 'cancelEnrollment'])->middleware(['edition:retailer', 'can:sales.void'])->name('schemes.enrollment.cancel');

    // --- Loyalty Points (read = customers.view; adjust = customers.edit; the two redirects don't need stricter gating since they redirect to customer views which are themselves gated) ---
    Route::get('/loyalty', fn () => redirect()->route('customers.index', ['view' => 'loyalty']))->middleware(['edition:retailer', 'can:customers.view'])->name('loyalty.index');
    Route::get('/loyalty/{customer}/history', fn (\App\Models\Customer $customer) => redirect()->route('customers.show', $customer))->middleware(['edition:retailer', 'can:customers.view'])->name('loyalty.history');
    Route::get('/loyalty/{customer}/adjust', [LoyaltyController::class, 'adjustForm'])->middleware(['edition:retailer', 'can:customers.edit'])->name('loyalty.adjust.form');
    Route::post('/loyalty/{customer}/adjust', [LoyaltyController::class, 'adjust'])->middleware(['edition:retailer', 'can:customers.edit'])->name('loyalty.adjust');

    // --- EMI / Installments (sales.* — installments are a sales settlement option) ---
    Route::get('/installments', [InstallmentController::class, 'index'])->middleware(['edition:retailer', 'can:sales.view'])->name('installments.index');
    Route::get('/installments/create', [InstallmentController::class, 'create'])->middleware(['edition:retailer', 'can:sales.create'])->name('installments.create');
    Route::post('/installments', [InstallmentController::class, 'store'])->middleware(['edition:retailer', 'can:sales.create'])->name('installments.store');
    // Discard the POS-EMI draft when the cashier cancels the EMI form (declared
    // before the {plan} wildcard so it isn't captured as a plan id).
    Route::post('/installments/discard-draft', [InstallmentController::class, 'discardDraft'])->middleware(['edition:retailer', 'can:sales.create'])->name('installments.discard-draft');
    Route::get('/installments/{plan}', [InstallmentController::class, 'show'])->middleware(['edition:retailer', 'can:sales.view'])->name('installments.show');
    Route::post('/installments/{plan}/pay', [InstallmentController::class, 'recordPayment'])->middleware(['edition:retailer', 'can:sales.create'])->name('installments.pay');
    Route::post('/installments/{plan}/default', [InstallmentController::class, 'markDefaulted'])->middleware(['edition:retailer', 'can:sales.void'])->name('installments.default');
    Route::get('/installments/{plan}/payments/{payment}/receipt', [InstallmentController::class, 'receipt'])->middleware(['edition:retailer', 'can:sales.view'])->name('installments.receipt');

    // --- Reorder Alerts (inventory.view to see; inventory.edit to mutate rules) ---
    Route::get('/reorder', [ReorderController::class, 'index'])->middleware(['edition:retailer', 'can:inventory.view'])->name('reorder.index');
    Route::get('/reorder/create', [ReorderController::class, 'create'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('reorder.create');
    Route::post('/reorder', [ReorderController::class, 'store'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('reorder.store');
    Route::get('/reorder/{rule}/edit', [ReorderController::class, 'edit'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('reorder.edit');
    Route::put('/reorder/{rule}', [ReorderController::class, 'update'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('reorder.update');
    Route::delete('/reorder/{rule}', [ReorderController::class, 'destroy'])->middleware(['edition:retailer', 'can:inventory.edit'])->name('reorder.destroy');

    // --- Tag / Label Printing (inventory-level view permission) ---
    Route::get('/tags', [TagPrintController::class, 'index'])->middleware(['edition:retailer', 'can:inventory.view'])->name('tags.index');
    Route::post('/tags/print', [TagPrintController::class, 'print'])->middleware(['edition:retailer', 'can:inventory.view'])->name('tags.print');

    // --- Retailer Reports & Analytics (reports.view; catalog.manage for catalog management) ---
    Route::get('/report/stock-aging', [RetailerDashboardController::class, 'stockAging'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.stock-aging');
    Route::get('/report/sellers', [RetailerDashboardController::class, 'sellers'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.sellers');
    Route::get('/report/occasions', [RetailerDashboardController::class, 'occasions'])->middleware(['edition:retailer', 'can:reports.view'])->name('report.occasions');
    Route::get('/catalog', [CatalogController::class, 'index'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('catalog.index');
    Route::post('/catalog/collections', [CatalogController::class, 'storeCollection'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('catalog.collections.store');
    // Legacy aliases — keep old names resolving so any bookmarked links or nav items still work
    Route::get('/report/whatsapp', [CatalogController::class, 'index'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('report.whatsapp');
    Route::post('/report/whatsapp/collection-link', [CatalogController::class, 'storeCollection'])->middleware(['edition:retailer', 'can:catalog.manage'])->name('report.whatsapp.collection-link');

    // ======= DHIRAN (Gold Loan / Pledge Module) =======
    // The real Dhiran routes are registered first (below). The main-domain →
    // subdomain redirect catch-all is registered AFTER them so it only catches
    // paths the real routes didn't, and never shadows them on the subdomain.

    // realm:dhiran — an ERP-realm account can never reach Dhiran app routes
    // (it is redirected to its own dashboard), and vice-versa. Belt-and-suspenders
    // on top of the per-host session cookie isolation.
    Route::prefix('dhiran')->name('dhiran.')->middleware(['realm:dhiran', 'dhiran.email-verified'])->group(function () {
        // Email-verification gate. A Dhiran account has no email at registration,
        // so it cannot recover a forgotten password. This page (exempt from the
        // gate middleware) captures + verifies an email via the email-OTP flow;
        // until verified, every other Dhiran route redirects here.
        Route::get('/verify-email', [\App\Http\Controllers\DhiranController::class, 'verifyEmail'])->name('verify-email');
        // Dhiran-local email-OTP endpoints — reachable by any authed Dhiran user
        // (no shop/subscription requirement), so the gate can never deadlock.
        Route::post('/verify-email/send',   [\App\Http\Controllers\EmailVerificationOtpController::class, 'sendOtp'])->middleware('throttle:5,1')->name('verify-email.send');
        Route::post('/verify-email/verify', [\App\Http\Controllers\EmailVerificationOtpController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('verify-email.verify');
        Route::post('/verify-email/resend', [\App\Http\Controllers\EmailVerificationOtpController::class, 'resend'])->middleware('throttle:3,1')->name('verify-email.resend');

        // Dashboard & Activation — no dhiran.enabled check (dashboard shows activation page)
        Route::get('/', [\App\Http\Controllers\DhiranController::class, 'dashboard'])->middleware('can:dhiran.view')->name('dashboard');
        Route::post('/activate', [\App\Http\Controllers\DhiranController::class, 'activate'])->middleware('can:dhiran.settings')->name('activate');

        // All remaining routes require dhiran to be enabled
        Route::middleware(['dhiran.enabled'])->group(function () {
            // Loan CRUD — create permission
            Route::get('/create', [\App\Http\Controllers\DhiranController::class, 'create'])->middleware('can:dhiran.create')->name('create');
            Route::post('/', [\App\Http\Controllers\DhiranController::class, 'store'])->middleware('can:dhiran.create')->name('store');

            // Inline borrower creation from the New Loan page (Dhiran-scoped).
            Route::post('/customers', [\App\Http\Controllers\DhiranController::class, 'storeCustomer'])->middleware('can:dhiran.create')->name('customers.store');

            // Private evidence attachments (item photos, ID proof, loan documents).
            Route::post('/attachments', [\App\Http\Controllers\DhiranController::class, 'storeAttachment'])->middleware('can:dhiran.create')->name('attachments.store');
            Route::get('/attachments/{attachment}', [\App\Http\Controllers\DhiranController::class, 'showAttachment'])->middleware('can:dhiran.view')->name('attachments.show');

            // Loan listing & detail — view permission
            Route::get('/loans', [\App\Http\Controllers\DhiranController::class, 'loans'])->middleware('can:dhiran.view')->name('loans');
            Route::get('/loans/{loan}', [\App\Http\Controllers\DhiranController::class, 'show'])->middleware('can:dhiran.view')->name('show');
            // Activate a pending-evidence loan (evidence-gated in the service).
            Route::post('/loans/{loan}/activate', [\App\Http\Controllers\DhiranController::class, 'activateLoan'])->middleware('can:dhiran.create')->name('activate-loan');

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

            // Borrowers (Dhiran customer profiles) — view permission. Shop-scoped.
            Route::get('/borrowers', [\App\Http\Controllers\DhiranController::class, 'borrowers'])->middleware('can:dhiran.view')->name('borrowers.index');
            Route::get('/borrowers/{customer}', [\App\Http\Controllers\DhiranController::class, 'borrowerProfile'])->middleware('can:dhiran.view')->name('borrowers.show');

            // Pledged item detail — view permission. Shop-scoped.
            Route::get('/items/{item}', [\App\Http\Controllers\DhiranController::class, 'itemDetail'])->middleware('can:dhiran.view')->name('items.show');

            // Legacy customer-loans route → redirect into the borrower profile (no duplicate page).
            Route::get('/customers/{customer}', function (\App\Models\Customer $customer) {
                return redirect()->route('dhiran.borrowers.show', $customer);
            })->middleware('can:dhiran.view')->name('customer-loans');

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

    // Main-domain → subdomain redirect for /dhiran/*. Registered AFTER the real
    // Dhiran routes so it only catches paths they didn't (it never shadows the
    // dashboard or module routes on the subdomain). On the subdomain a genuinely
    // unknown /dhiran/* path 404s as expected.
    Route::get('/dhiran/{any}', function (string $any) {
        if (! str_starts_with(request()->getHost(), 'dhiran.')) {
            return redirect('https://dhiran.jewelflows.com/' . request()->path(), 301);
        }
        abort(404);
    })->where('any', '.*')->name('dhiran.maindomain-redirect');
});

/*
|--------------------------------------------------------------------------
| AUTH ROUTES (Breeze / Fortify / Jetstream)
|--------------------------------------------------------------------------
*/
require __DIR__.'/auth.php';
