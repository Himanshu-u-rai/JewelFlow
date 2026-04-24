<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope('shop', function (Builder $builder) {
            $shopId = static::resolveTenantShopId();

            if ($shopId !== null) {
                $builder->where($builder->qualifyColumn('shop_id'), $shopId);
                return;
            }

            // Fail closed by default: in console/background/no-auth contexts
            // tenant models require explicit context injection (TenantContext::runFor).
            $builder->whereRaw('1 = 0');
        });

        static::creating(function ($model) {
            $shopId = static::resolveTenantShopId();

            if ($shopId !== null && empty($model->shop_id)) {
                $model->shop_id = $shopId;
            }
        });
    }

    public function scopeWithoutTenant(Builder $query): Builder
    {
        return $query->withoutGlobalScope('shop');
    }

    protected static function resolveTenantShopId(): ?int
    {
        $shopId = TenantContext::get();
        if ($shopId !== null) {
            return (int) $shopId;
        }

        // In console/background contexts, tenant access must be explicit.
        if (app()->runningInConsole()) {
            return null;
        }

        if (!Auth::check()) {
            return null;
        }

        return Auth::user()?->shop_id !== null ? (int) Auth::user()->shop_id : null;
    }
}
