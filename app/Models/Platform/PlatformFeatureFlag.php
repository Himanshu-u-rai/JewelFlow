<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformFeatureFlag extends Model
{
    protected $table    = 'platform_feature_flags';
    protected $fillable = ['key', 'enabled', 'scope', 'scope_id', 'description', 'updated_by'];
    protected $casts    = ['enabled' => 'boolean'];

    public static function isEnabled(string $key, ?int $shopId = null): bool
    {
        // Shop-level override takes precedence over global
        if ($shopId) {
            $shopCacheKey = "ff:{$key}:shop:{$shopId}";
            $shopVal = Cache::remember($shopCacheKey, 120, function () use ($key, $shopId) {
                return static::where('key', $key)->where('scope', 'shop')->where('scope_id', $shopId)->value('enabled');
            });
            if ($shopVal !== null) {
                return (bool) $shopVal;
            }
        }

        $globalCacheKey = "ff:{$key}:global";
        $globalVal = Cache::remember($globalCacheKey, 120, function () use ($key) {
            return static::where('key', $key)->where('scope', 'global')->value('enabled');
        });

        return (bool) $globalVal;
    }

    public static function forgetCache(string $key, ?int $scopeId = null): void
    {
        Cache::forget("ff:{$key}:global");
        if ($scopeId) {
            Cache::forget("ff:{$key}:shop:{$scopeId}");
        }
    }

    public static function allGlobal(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('scope', 'global')->orderBy('key')->get();
    }
}
