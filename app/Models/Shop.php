<?php

namespace App\Models;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'shop_type',
        'phone',
        'shop_whatsapp',
        'shop_email',
        'established_year',
        'shop_registration_number',
        'logo_path',
        'address',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'state_code',
        'pincode',
        'country',
        'gst_number',
        'owner_first_name',
        'owner_last_name',
        'owner_mobile',
        'owner_email',
        'gst_rate',
        'wastage_recovery_percent',
        'catalog_slug',
        'shop_code',
        'access_mode',
        'is_active',
        'deactivated_at',
        'suspended_at',
        'suspended_by',
        'suspended_until',
        'suspension_reason',
    ];

    protected $casts = [
        'gst_rate' => 'decimal:2',
        'wastage_recovery_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'suspended_until' => 'datetime',
    ];

    // Environment classification (operational-clarity metadata ONLY).
    // Read for labels/annotations; never branch accounting on these.
    // Deliberately NOT in $fillable — set by platform admins, not shop owners.
    public const ENV_PRODUCTION = 'production';
    public const ENV_DEMO = 'demo';
    public const ENV_INTERNAL_TEST = 'internal_test';

    public const ENVIRONMENTS = [
        self::ENV_PRODUCTION,
        self::ENV_DEMO,
        self::ENV_INTERNAL_TEST,
    ];

    public function isProduction(): bool
    {
        return ($this->environment ?? self::ENV_PRODUCTION) === self::ENV_PRODUCTION;
    }

    public function isDemo(): bool
    {
        return $this->environment === self::ENV_DEMO;
    }

    /**
     * Any non-production environment (demo or internal_test) — i.e. data whose
     * anomalies may originate from seeding rather than live operations.
     */
    public function isNonProduction(): bool
    {
        return ! $this->isProduction();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /**
     * Get the gold & POS calculation rules for this shop.
     */
    public function rules(): HasOne
    {
        return $this->hasOne(ShopRules::class);
    }

    /**
     * Get the billing/invoice settings for this shop.
     */
    public function billingSettings(): HasOne
    {
        return $this->hasOne(ShopBillingSettings::class);
    }

    /**
     * Get the UI/behavior preferences for this shop.
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(ShopPreferences::class);
    }

    public function metalPurityProfiles(): HasMany
    {
        return $this->hasMany(ShopMetalPurityProfile::class);
    }

    public function dailyMetalRates(): HasMany
    {
        return $this->hasMany(ShopDailyMetalRate::class);
    }

    public function catalogWebsiteSettings(): HasOne
    {
        return $this->hasOne(CatalogWebsiteSettings::class);
    }

    public function catalogPages(): HasMany
    {
        return $this->hasMany(CatalogPage::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'suspended_by');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ShopSubscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(ShopSubscription::class)->latest('id');
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function scopeInactive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS FALSE');
    }

    /* ── Edition helpers ─────────────────────────────────── */

    public function editions(): HasMany
    {
        return $this->hasMany(ShopEditionAssignment::class);
    }

    public function activeEditions(): HasMany
    {
        return $this->hasMany(ShopEditionAssignment::class)->whereNull('deactivated_at');
    }

    /**
     * Active editions as a flat array, e.g. ['retailer', 'dhiran'].
     *
     * Source of truth is the shop_editions table. shops.shop_type is kept in
     * sync for backward compatibility during the editions refactor but must
     * not be read directly by new code — use this or hasEdition() instead.
     */
    public function editionList(): array
    {
        return $this->activeEditions->pluck('edition')->all();
    }

    public function hasEdition(string $edition): bool
    {
        return in_array($edition, $this->editionList(), true);
    }

    public function hasAnyEdition(string ...$editions): bool
    {
        return count(array_intersect($editions, $this->editionList())) > 0;
    }

    public function hasAllEditions(string ...$editions): bool
    {
        return count(array_diff($editions, $this->editionList())) === 0;
    }

    public function isRetailer(): bool
    {
        return $this->hasEdition('retailer');
    }

    public function isManufacturer(): bool
    {
        return $this->hasEdition('manufacturer');
    }

    public function hasDhiran(): bool
    {
        return $this->hasEdition('dhiran');
    }

    /**
     * Auto-seed a shop_editions row whenever a Shop is created with a
     * retailer/manufacturer shop_type. Keeps the invariant "every shop has
     * at least one active edition row" true without requiring every write
     * path (controllers, seeders, tests) to remember to create the pivot.
     */
    protected static function booted(): void
    {
        static::saving(function (Shop $shop): void {
            if (!empty($shop->owner_email)) {
                $shop->owner_email = strtolower(trim($shop->owner_email));
            }
            if (!empty($shop->shop_email)) {
                $shop->shop_email = strtolower(trim($shop->shop_email));
            }
        });

        static::creating(function (Shop $shop): void {
            if (empty($shop->shop_code)) {
                $shop->shop_code = \App\Services\BusinessIdentifierService::nextShopCode();
            }
        });

        static::created(function (Shop $shop): void {
            if (in_array($shop->shop_type, ['retailer', 'manufacturer', 'dhiran'], true)) {
                // firstOrCreate keeps this idempotent against a subscription
                // grant that may have already created the row (UNIQUE shop_id +
                // edition). The 'seed' source means it is treated like an admin
                // grant for lapse purposes — never auto-revoked.
                ShopEditionAssignment::firstOrCreate(
                    ['shop_id' => $shop->id, 'edition' => $shop->shop_type],
                    [
                        'source'       => ShopEditionAssignment::SOURCE_SEED,
                        'activated_at' => now(),
                    ]
                );
            }
        });
    }

    /**
     * The most-recent subscription for a given platform product code that is
     * still entitling (active / trial / grace / read_only).
     *
     * This is the multi-product-aware lookup new code should use instead of the
     * legacy singular subscription() (which is just ->latest('id') and assumes
     * one-subscription-per-shop). Returns null if the shop has no entitling
     * subscription for that product.
     */
    public function activeSubscriptionForProduct(string $productCode): ?ShopSubscription
    {
        $edition = \App\Models\Platform\PlatformProduct::editionStringFor($productCode);
        $entitling = ['active', 'trial', 'grace', 'read_only'];

        return $this->subscriptions()
            ->whereIn('status', $entitling)
            ->with('plan.platformProduct')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ShopSubscription $sub) => $sub->plan && $sub->plan->grantsEdition() === $edition);
    }

    /* ── Staff limit helpers ──────────────────────────────── */

    /**
     * Maximum non-owner staff allowed, derived from the RETAIL/ERP product
     * subscription. Returns -1 for unlimited.
     *
     * Multi-product decision: staff seats are a RETAIL-ERP concept (POS,
     * inventory, multiple counter staff). A Dhiran-only shop, or the Dhiran
     * subscription on a retail+dhiran shop, does not define the seat count —
     * so we read staff_limit from the retail subscription's plan when present.
     *
     * Resolution order:
     *   1. retail product subscription (if any) → its plan's staff_limit
     *   2. else fall back to the latest subscription's plan (back-compat for
     *      single-product shops such as a manufacturer-only or dhiran-only shop)
     *   3. else -1 (unlimited / unconfigured)
     */
    public function staffLimit(): int
    {
        $retailSub = $this->activeSubscriptionForProduct(
            \App\Models\Platform\PlatformProduct::CODE_RETAIL
        );

        $plan = $retailSub?->plan ?? $this->subscription?->plan;

        $limit = $plan?->features['staff_limit'] ?? null;

        return $limit === null ? -1 : (int) $limit;
    }

    /**
     * Count of non-owner staff currently in this shop.
     */
    public function currentStaffCount(): int
    {
        return $this->users()
            ->active()
            ->whereHas('role', fn ($q) => $q->where('name', '!=', 'owner'))
            ->count();
    }

    /**
     * Whether another non-owner staff member can be added.
     */
    public function canAddStaff(): bool
    {
        $limit = $this->staffLimit();
        return $limit === -1 || $this->currentStaffCount() < $limit;
    }

    /* ── Catalog website helpers ─────────────────────────── */

    public static function generateUniqueCatalogSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name) ?: 'shop';
        $slug = $base;
        $suffix = 1;

        while (
            static::where('catalog_slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
