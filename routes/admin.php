<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillingManagementController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlatformAdminManagementController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\SecurityController;
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
    Route::middleware('guest:platform_admin')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.store');
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->name('register.store');
    });

    Route::middleware(['admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/shops', [ShopManagementController::class, 'index'])->name('shops.index');
        Route::get('/shops/{shop}', [ShopManagementController::class, 'show'])->name('shops.show');
        Route::patch('/shops/{shop}/status', [ShopManagementController::class, 'updateStatus'])->name('shops.status');
        Route::patch('/shops/{shop}/subscription', [BillingManagementController::class, 'updateShopSubscription'])->name('shops.subscription');
        Route::post('/shops/{shop}/editions/grant', [ShopEditionManagementController::class, 'grant'])->name('shops.editions.grant');
        Route::post('/shops/{shop}/editions/revoke', [ShopEditionManagementController::class, 'revoke'])->name('shops.editions.revoke');

        Route::get('/edition-requests', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'index'])->name('edition-requests.index');
        Route::post('/edition-requests/{editionRequest}/approve', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'approve'])->name('edition-requests.approve');
        Route::post('/edition-requests/{editionRequest}/deny', [\App\Http\Controllers\Admin\ShopEditionRequestController::class, 'deny'])->name('edition-requests.deny');

        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('users.show');
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

        Route::get('/subscriptions', [\App\Http\Controllers\Admin\SubscriptionManagementController::class, 'index'])->name('subscriptions.index');

        Route::get('/tenant-activity', [TenantActivityController::class, 'index'])->name('tenant-activity.index');
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::get('/settings', [PlatformSettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [PlatformSettingsController::class, 'update'])->name('settings.update');

        Route::get('/system/jobs', [SystemJobController::class, 'index'])->name('system.jobs.index');
        Route::get('/system/jobs/{id}', [SystemJobController::class, 'show'])->name('system.jobs.show');
        Route::post('/system/jobs/{id}/retry', [SystemJobController::class, 'retry'])->name('system.jobs.retry');

        Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');

        Route::middleware('platform.role:super_admin')->group(function () {
            Route::post('/shops/{shop}/impersonate', [ImpersonationController::class, 'start'])->name('shops.impersonate');
            Route::get('/platform-admins', [PlatformAdminManagementController::class, 'index'])->name('platform-admins.index');
            Route::post('/platform-admins', [PlatformAdminManagementController::class, 'store'])->name('platform-admins.store');
            Route::patch('/platform-admins/{platformAdmin}/role', [PlatformAdminManagementController::class, 'updateRole'])->name('platform-admins.role');
            Route::patch('/platform-admins/{platformAdmin}/status', [PlatformAdminManagementController::class, 'updateStatus'])->name('platform-admins.status');
            Route::patch('/platform-admins/{platformAdmin}/password', [PlatformAdminManagementController::class, 'resetPassword'])->name('platform-admins.password');
            Route::delete('/platform-admins/{platformAdmin}', [PlatformAdminManagementController::class, 'destroy'])->name('platform-admins.destroy');
        });
    });
});

/*
|--------------------------------------------------------------------------
| LEGACY SUPER-ADMIN URL COMPATIBILITY
|--------------------------------------------------------------------------
*/
Route::prefix('super-admin')->group(function () {
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
        Route::patch('/shops/{shop}/status', [ShopManagementController::class, 'updateStatus'])->name('superadmin.shops.status');
        Route::get('/users', [UserManagementController::class, 'index'])->name('superadmin.users.index');
        Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('superadmin.users.show');
        Route::patch('/users/{user}/status', [UserManagementController::class, 'updateStatus'])->name('superadmin.users.status');
        Route::patch('/users/{user}/password', [UserManagementController::class, 'resetPassword'])->name('superadmin.users.password');
    });
});
