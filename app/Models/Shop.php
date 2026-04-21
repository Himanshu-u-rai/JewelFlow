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
    ];

    protected $casts = [
        'gst_rate' => 'decimal:2',
        'wastage_recovery_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'suspended_until' => 'datetime',
    ];

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
        static::created(function (Shop $shop): void {
            if (in_array($shop->shop_type, ['retailer', 'manufacturer', 'dhiran'], true)) {
                ShopEditionAssignment::firstOrCreate(
                    ['shop_id' => $shop->id, 'edition' => $shop->shop_type],
                    ['activated_at' => now()]
                );
            }
        });
    }

    /* ── Staff limit helpers ──────────────────────────────── */

    /**
     * Maximum non-owner staff allowed by the active subscription plan.
     * Returns -1 for unlimited.
     */
    public function staffLimit(): int
    {
        $limit = $this->subscription?->plan?->features['staff_limit'] ?? null;
        return $limit === null ? -1 : (int) $limit;
    }

    /**
     * Count of non-owner staff currently in this shop.
     */
    public function currentStaffCount(): int
    {
        return $this->users()
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
