<?php

namespace App\Providers;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Repair;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PublicCatalogCollection;
use App\Models\SubCategory;
use App\Models\ReorderRule;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\Vendor;
use App\Policies\CatalogCollectionPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\SubCategoryPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ItemPolicy;
use App\Policies\RepairPolicy;
use App\Policies\ReorderRulePolicy;
use App\Policies\SchemeEnrollmentPolicy;
use App\Policies\SchemePolicy;
use App\Policies\VendorPolicy;
use App\Models\CatalogPage;
use App\Policies\CatalogPagePolicy;
use App\Services\AdminImpersonationService;
use App\Services\ShopPricingService;
use App\Services\ReorderAlertService;
use App\Services\TenantHealthService;
use Illuminate\Support\Facades\Auth;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        // Dotted ability names (e.g. "dhiran.pay", "invoices.create") are treated
        // as permission keys and resolved via User::hasPermission(). Returning
        // null on miss lets Laravel fall through to policies/explicit gates.
        // Non-dotted abilities ("view-dashboard") bypass this and use normal Gate routing.
        Gate::before(function ($user, $ability) {
            if (is_string($ability) && str_contains($ability, '.') && method_exists($user, 'hasPermission')) {
                return $user->hasPermission($ability) ? true : null;
            }
            return null;
        });

        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(SubCategory::class, SubCategoryPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Repair::class, RepairPolicy::class);
        Gate::policy(PublicCatalogCollection::class, CatalogCollectionPolicy::class);
        Gate::policy(ReorderRule::class, ReorderRulePolicy::class);
        Gate::policy(Scheme::class, SchemePolicy::class);
        Gate::policy(SchemeEnrollment::class, SchemeEnrollmentPolicy::class);
        Gate::policy(Vendor::class, VendorPolicy::class);
        Gate::policy(CatalogPage::class, CatalogPagePolicy::class);

        // PostgreSQL in this stack can reject integer literals (0/1) for boolean
        // columns. Normalize dirty boolean attrs to DB-safe '1'/'0' strings
        // before persistence so writes remain safe across the app.
        if (DB::getDriverName() === 'pgsql') {
            Event::listen('eloquent.saving: *', function (string $eventName, array $data): void {
                $model = $data[0] ?? null;
                if (!$model || !method_exists($model, 'getCasts') || !method_exists($model, 'isDirty')) {
                    return;
                }

                $casts = $model->getCasts();
                $dirty = array_keys($model->getDirty());
                if (empty($dirty)) {
                    return;
                }

                static $tableBooleanColumns = [];
                $table = method_exists($model, 'getTable') ? (string) $model->getTable() : '';
                if ($table !== '' && !array_key_exists($table, $tableBooleanColumns)) {
                    $tableBooleanColumns[$table] = DB::table('information_schema.columns')
                        ->whereRaw('table_schema = current_schema()')
                        ->where('table_name', $table)
                        ->where('data_type', 'boolean')
                        ->pluck('column_name')
                        ->map(fn ($name) => (string) $name)
                        ->all();
                }

                $schemaBooleanAttrs = array_fill_keys($tableBooleanColumns[$table] ?? [], true);
                foreach ($dirty as $attribute) {
                    $isCastBoolean = isset($casts[$attribute])
                        && in_array(strtolower((string) $casts[$attribute]), ['bool', 'boolean'], true);
                    $isSchemaBoolean = isset($schemaBooleanAttrs[$attribute]);
                    if (!$isCastBoolean && !$isSchemaBoolean) {
                        continue;
                    }

                    $value = $model->getAttribute($attribute);
                    if ($value === null) {
                        continue;
                    }

                    if (is_bool($value)) {
                        $normalized = $value;
                    } elseif (is_int($value) || is_float($value)) {
                        $normalized = ((int) $value) === 1;
                    } elseif (is_string($value)) {
                        $raw = strtolower(trim($value));
                        if (in_array($raw, ['1', 'true', 't', 'yes', 'on'], true)) {
                            $normalized = true;
                        } elseif (in_array($raw, ['0', 'false', 'f', 'no', 'off'], true)) {
                            $normalized = false;
                        } else {
                            continue;
                        }
                    } else {
                        continue;
                    }

                    $model->setAttribute($attribute, $normalized ? '1' : '0');
                }
            });
        }

        if (app()->environment('production') && !config('platform.enforce_subscriptions')) {
            throw new RuntimeException('Subscription enforcement must be enabled in production.');
        }

        RateLimiter::for('api-pos-read', function (Request $request) {
            $user = $request->user();
            $shopId = (int) ($user?->shop_id ?? 0);
            $identity = sprintf('%s|%s|%s', $shopId, $user?->id ?? 'guest', $request->ip());
            $perMinute = (int) config('performance.rate_limits.pos_read_per_minute', 120);

            return Limit::perMinute($perMinute)
                ->by($identity)
                ->response(function (Request $req, array $headers) {
                    return response()->json([
                        'message' => 'Too many read requests. Please retry shortly.',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('api-pos-sale', function (Request $request) {
            $user = $request->user();
            $shopId = (int) ($user?->shop_id ?? 0);
            $identity = sprintf('%s|%s|%s', $shopId, $user?->id ?? 'guest', $request->ip());
            $perMinute = (int) config('performance.rate_limits.pos_sale_per_minute', 30);

            return Limit::perMinute($perMinute)
                ->by($identity)
                ->response(function (Request $req, array $headers) {
                    return response()->json([
                        'message' => 'Too many sale attempts. Please retry shortly.',
                    ], 429, $headers);
                });
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->guard === 'platform_admin') {
                app(AdminImpersonationService::class)->stop(null, $event->user, 'admin_logout');
            }
        });

        View::composer('layouts.app', function ($view): void {
            $banner = app(AdminImpersonationService::class)->bannerPayload();
            $view->with('impersonationBanner', $banner);

            // Reorder alert count badge — only computed for authenticated retailer users.
            $reorderAlertCount = 0;
            $pricingShellState = null;
            $user = Auth::user();
            if ($user && $user->shop_id && $user->shop?->isRetailer()) {
                $reorderAlertCount = app(ReorderAlertService::class)->alertCount($user->shop_id);

                $pricing = app(ShopPricingService::class);
                $pricingShellState = [
                    'show_owner_modal' => $user->isOwner() && ! $pricing->hasCurrentDailyRates($user->shop),
                    'business_date' => $pricing->businessDateString($user->shop),
                    'timezone' => $pricing->pricingTimezone($user->shop),
                    'today_rate' => $pricing->currentDailyRate($user->shop),
                ];
            }
            $view->with('reorderAlertCount', $reorderAlertCount);
            $view->with('pricingShellState', $pricingShellState);
        });

        View::composer('super-admin.dashboard', function ($view): void {
            $health = app(TenantHealthService::class)->scores();
            $view->with('tenantHealth', $health);
        });
    }
}
