<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackupStatusController;
use App\Http\Controllers\Admin\ComplianceAlertAdminController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\BillingManagementController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\FraudFlagController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlatformAdminManagementController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ShopBulkActionController;
use App\Http\Controllers\Admin\ShopEditionManagementController;
use App\Http\Controllers\Admin\ShopManagementController;
use App\Http\Controllers\Admin\SystemJobController;
use App\Http\Controllers\Admin\TenantActivityController;
use App\Http\Controllers\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ADMIN CONTROL PLANE (SAAS PLATFORM)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    // Route model binding for {user} must bypass BelongsToShop global scope —
    // platform admins are authenticated on the platform_admin guard, not the web
    // guard, so Auth::user() returns null and the scope collapses to 1=0.
    Route::bind('user', fn ($value) => \App\Models\User::withoutGlobalScope('shop')->findOrFail($value));
    Route::bind('editionRequest', fn ($value) => \App\Models\ShopEditionRequest::withoutGlobalScope('shop')->findOrFail($value));

    Route::middleware('guest:platform_admin')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.store');
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->name('register.store');
    });

    // 2FA challenge — no full admin auth yet, but must have pending session token
    Route::get('/2fa',  [AuthController::class, 'show2fa'])->name('2fa.show');
    Route::post('/2fa', [AuthController::class, 'verify2fa'])->name('2fa.verify');

    Route::middleware(['admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // 2FA management — any authenticated admin can manage their own 2FA
        Route::get('/2fa/manage',  [TwoFactorController::class, 'showEnroll'])->name('2fa.enroll');
        Route::post('/2fa/enroll', [TwoFactorController::class, 'confirmEnroll'])->name('2fa.enroll.confirm');
        Route::post('/2fa/disable',[TwoFactorController::class, 'disable'])->name('2fa.disable');

        // Read-only views accessible to all platform admins
        // Static paths must come before {shop}/{user} wildcard routes
        Route::get('/shops', [ShopManagementController::class, 'index'])->name('shops.index');
        Route::get('/shops/export.csv', [ShopManagementController::class, 'export'])->name('shops.export');
        Route::get('/shops/{shop}', [ShopManagementController::class, 'show'])->name('shops.show');
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('users.show');
        Route::get('/subscriptions', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/export.csv', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'export'])->name('subscriptions.export');
        Route::get('/invoices', [\App\Http\Controllers\Admin\PlatformInvoiceAdminController::class, 'index'])->name('invoices.index');
        Route::get('/tenant-activity', [TenantActivityController::class, 'index'])->name('tenant-activity.index');

        // All write/mutating actions + sensitive views require super_admin role
        Route::middleware('platform.role:super_admin')->group(function () {
            Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
            Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
            Route::get('/system/jobs', [SystemJobController::class, 'index'])->name('system.jobs.index');
            Route::get('/system/jobs/{id}', [SystemJobController::class, 'show'])->name('system.jobs.show');
            Route::post('/system/jobs/retry-all', [SystemJobController::class, 'retryAll'])->name('system.jobs.retry-all');
            Route::post('/system/jobs/flush-failed', [SystemJobController::class, 'flushFailed'])->name('system.jobs.flush-failed');
            // P1-1: Bulk Shop Operations — must come before {shop} wildcard
            Route::post('/shops/bulk', [ShopBulkActionController::class, 'bulk'])->name('shops.bulk');
            Route::patch('/shops/{shop}/status', [ShopManagementController::class, 'updateStatus'])->name('shops.status');
            Route::patch('/shops/{shop}/subscription', [BillingManagementController::class, 'updateShopSubscription'])->name('shops.subscription');
            Route::post('/shops/{shop}/editions/grant', [ShopEditionManagementController::class, 'grant'])->name('shops.editions.grant');
            Route::post('/shops/{shop}/editions/revoke', [ShopEditionManagementController::class, 'revoke'])->name('shops.editions.revoke');
            Route::post('/shops/{shop}/impersonate', [ImpersonationController::class, 'start'])->name('shops.impersonate');

            Route::get('/edition-requests', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'index'])->name('edition-requests.index');
            Route::post('/edition-requests/{editionRequest}/approve', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'approve'])->name('edition-requests.approve');
            Route::post('/edition-requests/{editionRequest}/deny', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'deny'])->name('edition-requests.deny');

            Route::patch('/users/{user}/status', [UserManagementController::class, 'updateStatus'])->name('users.status');
            Route::patch('/users/{user}/password', [UserManagementController::class, 'resetPassword'])->name('users.password');
            Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role');
            Route::patch('/users/{user}/mobile', [\App\Http\Controllers\Admin\UserMobileController::class, 'update'])->name('users.mobile');

            Route::get('/plans', [\App\Http\Controllers\Admin\PlanController::class, 'index'])->name('plans.index');
            Route::get('/plans/create', [\App\Http\Controllers\Admin\PlanController::class, 'create'])->name('plans.create');
            Route::post('/plans', [\App\Http\Controllers\Admin\PlanController::class, 'store'])->name('plans.store');
            Route::get('/plans/{plan}/edit', [\App\Http\Controllers\Admin\PlanController::class, 'edit'])->name('plans.edit');
            Route::patch('/plans/{plan}', [\App\Http\Controllers\Admin\PlanController::class, 'update'])->name('plans.update');
            Route::patch('/plans/{plan}/toggle', [\App\Http\Controllers\Admin\PlanController::class, 'toggle'])->name('plans.toggle');

            Route::get('/settings', [PlatformSettingsController::class, 'index'])->name('settings.index');
            Route::patch('/settings', [PlatformSettingsController::class, 'update'])->name('settings.update');

            Route::post('/system/jobs/{id}/retry', [SystemJobController::class, 'retry'])->name('system.jobs.retry');

            Route::get('/platform-admins', [PlatformAdminManagementController::class, 'index'])->name('platform-admins.index');
            Route::post('/platform-admins', [PlatformAdminManagementController::class, 'store'])->name('platform-admins.store');
            Route::patch('/platform-admins/{platformAdmin}/role', [PlatformAdminManagementController::class, 'updateRole'])->name('platform-admins.role');
            Route::patch('/platform-admins/{platformAdmin}/status', [PlatformAdminManagementController::class, 'updateStatus'])->name('platform-admins.status');
            Route::patch('/platform-admins/{platformAdmin}/password', [PlatformAdminManagementController::class, 'resetPassword'])->name('platform-admins.password');
            Route::delete('/platform-admins/{platformAdmin}', [PlatformAdminManagementController::class, 'destroy'])->name('platform-admins.destroy');
            Route::patch('/platform-admins/{platformAdmin}/permissions', [PlatformAdminManagementController::class, 'updatePermissions'])->name('platform-admins.permissions');

            // P2-2: GDPR Shop Data Export
            Route::post('/shops/{shop}/gdpr-export', [\App\Http\Controllers\Admin\GdprExportController::class, 'export'])->name('shops.gdpr-export');
            Route::post('/shops/{shop}/gdpr-delete', [\App\Http\Controllers\Admin\GdprExportController::class, 'scheduleDelete'])->name('shops.gdpr-delete');

            // P2-5: Revenue Analytics
            Route::get('/revenue', [\App\Http\Controllers\Admin\RevenueAnalyticsController::class, 'index'])->name('revenue.index');

            Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
            Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
            Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

            Route::get('/backup', [BackupStatusController::class, 'index'])->name('backup.index');
            Route::post('/backup/trigger', [BackupStatusController::class, 'trigger'])->name('backup.trigger');

            // P1-4: Feature Flags
            Route::get('/feature-flags', [FeatureFlagController::class, 'index'])->name('feature-flags.index');
            Route::post('/feature-flags', [FeatureFlagController::class, 'upsert'])->name('feature-flags.upsert');
            Route::delete('/feature-flags/{flag}', [FeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');

            // P1-6: Compliance Alerts Admin View
            Route::get('/compliance-alerts', [ComplianceAlertAdminController::class, 'index'])->name('compliance-alerts.index');

            // P2-8: Fraud Flags
            Route::get('/fraud-flags', [FraudFlagController::class, 'index'])->name('fraud-flags.index');
            Route::post('/fraud-flags/{flag}/review', [FraudFlagController::class, 'markReviewed'])->name('fraud-flags.review');
        });
    });
});

/*
|--------------------------------------------------------------------------
| LEGACY SUPER-ADMIN URL COMPATIBILITY
|--------------------------------------------------------------------------
*/
Route::prefix('super-admin')->group(function () {
    Route::bind('user', fn ($value) => \App\Models\User::withoutGlobalScope('shop')->findOrFail($value));

    Route::middleware('guest:platform_admin')->group(function () {
        Route::get('/login', fn () => redirect('/admin/login'))->name('superadmin.login');
        Route::post('/login', [AuthController::class, 'login'])->name('superadmin.login.store');
        Route::get('/register', fn () => redirect('/admin/register'))->name('superadmin.register');
        Route::post('/register', [AuthController::class, 'register'])->name('superadmin.register.store');
    });

    Route::middleware(['admin'])->group(function () {
        Route::get('/', fn () => redirect()->route('admin.dashboard'))->name('superadmin.dashboard');
        Route::post('/logout', [AuthController::class, 'logout'])->name('superadmin.logout');
        Route::get('/shops', [ShopManagementController::class, 'index'])->name('superadmin.shops.index');
        Route::get('/shops/{shop}', [ShopManagementController::class, 'show'])->name('superadmin.shops.show');
        Route::get('/users', [UserManagementController::class, 'index'])->name('superadmin.users.index');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('superadmin.users.show');
    });

    Route::middleware(['admin', 'platform.role:super_admin'])->group(function () {
        Route::patch('/shops/{shop}/status', [ShopManagementController::class, 'updateStatus'])->name('superadmin.shops.status');
        Route::patch('/users/{user}/status', [UserManagementController::class, 'updateStatus'])->name('superadmin.users.status');
        Route::patch('/users/{user}/password', [UserManagementController::class, 'resetPassword'])->name('superadmin.users.password');
    });
});
