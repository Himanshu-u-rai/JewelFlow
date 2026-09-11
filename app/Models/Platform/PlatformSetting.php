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

    /**
     * Free-trial length in days. Source of truth for both new trials and the
     * admin "apply to existing trials" action. Precedence: admin setting →
     * config('business.subscription_trial_days') → 30. Clamped to a sane range.
     */
    public static function trialDays(): int
    {
        $raw = static::get('subscription_trial_days');
        $days = $raw !== null
            ? (int) $raw
            : (int) config('business.subscription_trial_days', 30);

        return max(1, min(365, $days));
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

    /**
     * Editions that are never offered on the ERP shop-type chooser because they
     * are separate products served on their own subdomain, with their own
     * onboarding. Dhiran signs up at dhiran.jewelflows.com and never reaches the
     * ERP chooser — DhiranOnboardingTest asserts that in both directions.
     */
    private const SERVED_ON_OWN_SUBDOMAIN = ['dhiran'];

    /**
     * The editions the ERP chooser can actually put on screen.
     *
     * This — not enabledShopTypes() — is what that screen must count. Counting
     * platform-enabled editions meant an enabled-but-never-rendered edition
     * (Dhiran) kept the chooser alive showing a single card, asking the user a
     * question with exactly one possible answer.
     */
    public static function erpSelectableShopTypes(): array
    {
        return array_values(
            array_diff(static::enabledShopTypes(), static::SERVED_ON_OWN_SUBDOMAIN)
        );
    }

    /**
     * Is there actually a business type to choose between?
     *
     * The chooser skips itself when the answer is no, so anything that LINKS to
     * that screen ("Change business type" on the plan and shop-creation pages)
     * must ask the same question — otherwise it renders a control that bounces
     * the user straight back to the page they clicked it on.
     */
    public static function erpChooserOffersAChoice(): bool
    {
        return count(static::erpSelectableShopTypes()) > 1;
    }
}
