<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = ['key', 'value'];

    // ── Static helpers ─────────────────────────────────────────────────────

    /**
     * Read a setting value, with an optional default.
     * Results are cached for 5 minutes to avoid per-request DB hits.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("platform_setting:{$key}", 300, function () use ($key) {
            return static::where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    /**
     * Write (upsert) a setting and clear its cache.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("platform_setting:{$key}");
    }

    // ── Typed convenience helpers ──────────────────────────────────────────

    public static function bool(string $key, bool $default = true): bool
    {
        $raw = static::get($key);
        if ($raw === null) return $default;
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    // ── Shop-type availability ─────────────────────────────────────────────

    public static function retailerEnabled(): bool
    {
        return static::bool('retailer_enabled', true);
    }

    public static function manufacturerEnabled(): bool
    {
        return static::bool('manufacturer_enabled', true);
    }

    public static function dhiranEnabled(): bool
    {
        return static::bool('dhiran_enabled', true);
    }

    /**
     * Returns the list of editions currently open for registration.
     * e.g. ['retailer'], ['retailer','manufacturer','dhiran'], etc.
     */
    public static function enabledShopTypes(): array
    {
        $types = [];
        if (static::retailerEnabled())     $types[] = 'retailer';
        if (static::manufacturerEnabled()) $types[] = 'manufacturer';
        if (static::dhiranEnabled())       $types[] = 'dhiran';
        return $types;
    }

    /**
     * If exactly one edition is enabled for registration, return it. Otherwise null.
     */
    public static function onlyEnabledType(): ?string
    {
        $types = static::enabledShopTypes();
        return count($types) === 1 ? $types[0] : null;
    }
}
