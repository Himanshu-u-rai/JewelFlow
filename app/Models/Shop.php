<?php

namespace App\Models;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /**
     * Exact suspension_reason strings the subscription lifecycle writes that do
     * NOT literally begin with "Subscription". Every other subscription-managed
     * reason (from CheckSubscriptionExpiry / EnsureSubscriptionIsActive) starts
     * with the "Subscription" prefix; these are the named exceptions.
     */
    public const SUBSCRIPTION_MANAGED_REASONS = [
        'No active subscription found for shop.',
        'Subscription status is invalid for tenant access.',
        'middleware-check',
    ];

    /**
     * Whether the current suspension was applied by a human platform admin
     * (Contact-Support, NEVER self-service recoverable). This is the authoritative
     * precedence signal: every admin/manual path — ShopManagementController,
     * BillingManagementController, ShopBulkActionController, GdprExportController —
     * stamps suspended_by with the acting platform_admin id, while the
     * subscription lifecycle (CheckSubscriptionExpiry scheduler +
     * EnsureSubscriptionIsActive reconciler) NEVER touches suspended_by. So a
     * non-null suspended_by is proof-positive of an administrative action, no
     * matter what free-text the admin typed (or the reason fallback retained)
     * into suspension_reason.
     */
    public function suspensionIsAdministrative(): bool
    {
        return $this->suspended_by !== null;
    }

    /**
     * Authoritative classifier: is this shop's current suspension caused by the
     * subscription lifecycle (trial/grace expiry, lapse) rather than a manual
     * admin action? Subscription-managed suspensions are RECOVERABLE by the
     * owner buying/renewing a plan; admin suspensions are NOT (Contact Support).
     *
     * Single source of truth for the recovery gates in
     * AuthenticatedSessionController, EnsureSubscriptionIsActive and
     * EnsureAccountIsActive.
     *
     * PRECEDENCE (closes the "admin typed a Subscription reason" / reason-fallback
     * bypass): an administrative suspension always wins — if suspended_by is set,
     * this is NEVER subscription-managed regardless of the reason text. Only when
     * no admin actor is recorded do we corroborate with the reason string that the
     * scheduler / reconciler actually writes. ponytail: reuses the existing
     * suspended_by FK — no schema change; upgrade to a typed origin column only if
     * a non-admin writer ever needs to set suspended_by.
     */
    public function suspensionIsSubscriptionManaged(): bool
    {
        // Precedence: an admin action can never be self-service recoverable.
        if ($this->suspensionIsAdministrative()) {
            return false;
        }

        // UNATTRIBUTED LEGACY read_only (the JF-0001 incident state). `read_only`
        // on the ACCESS axis is exclusively an administrator restriction, and every
        // administrative writer stamps suspended_by. A read_only shop with NO stamp
        // therefore cannot be an admin hold — it can only be a row minted by the old
        // expiry fork: a subscription lapse wearing the wrong mode. The oldest of
        // those rows carry a NULL suspension_reason as well, so the reason text below
        // can never classify them, and they fall through to EnsureAccountIsActive's
        // 423 with no recovery path at all. Recognising them here is what lets both
        // middlewares reconcile the row onto the entitlement axis (enforcement on) or
        // heal it outright (enforcement off).
        //
        // Deliberately keyed on the read_only MODE, never on "suspended_by is null":
        // an unattributed `suspended` shop still needs its reason corroborated.
        if (($this->access_mode ?? '') === 'read_only') {
            return true;
        }

        $reason = (string) ($this->suspension_reason ?? '');

        return str_starts_with($reason, 'Subscription')
            || in_array($reason, self::SUBSCRIPTION_MANAGED_REASONS, true);
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

        // Financial-safety guard (Phase 5, Part A). A hard Shop::delete() would
        // otherwise be the only thing standing between an operator mistake and the
        // destruction of regulated pawn/loan history. The DB-level RESTRICT FKs are
        // the last line of defence; this app-level hook fails earlier with a clear,
        // owner-friendly message. Use deactivation (access_mode) to retire a shop,
        // never a hard delete, while it holds financial records.
        static::deleting(function (Shop $shop): void {
            // Only guards a TRUE hard delete. SoftDeletes (if ever added) sets
            // forceDeleting=false; we let those through.
            if (method_exists($shop, 'isForceDeleting') && ! $shop->isForceDeleting()) {
                return;
            }

            $hasDhiranFinancialData = DB::table('dhiran_loans')->where('shop_id', $shop->id)->exists()
                || DB::table('dhiran_payments')->where('shop_id', $shop->id)->exists()
                || DB::table('dhiran_ledger_entries')->where('shop_id', $shop->id)->exists()
                || DB::table('dhiran_cash_entries')->where('shop_id', $shop->id)->exists();

            if ($hasDhiranFinancialData) {
                throw new \RuntimeException(
                    'This shop has Dhiran gold-loan records and cannot be deleted. '
                    . 'Pawn and loan history must be kept. Deactivate the shop instead.'
                );
            }
        });
    }

    /**
     * The most-recent subscription for a given platform product code that is
     * still entitling (active / trial / grace).
     *
     * This is the multi-product-aware lookup new code should use instead of the
     * legacy singular subscription() (which is just ->latest('id') and assumes
     * one-subscription-per-shop). Returns null if the shop has no entitling
     * subscription for that product.
     *
     * `read_only` is CONDITIONAL for the same reason as
     * ShopEdition::hasOtherActiveSource(): a LEGACY read_only row is a lapse the
     * old expiry fork mislabelled, and treating it as live kept a lapsed shop
     * resolving a plan (and therefore its staff seats) indefinitely. An
     * ADMINISTRATIVE read_only hold is real, deliberate access, and erasing the
     * resolved plan under it would silently drop the shop's seat count during a
     * compliance review. suspended_by is the discriminator.
     */
    public function activeSubscriptionForProduct(string $productCode): ?ShopSubscription
    {
        $edition = \App\Models\Platform\PlatformProduct::editionStringFor($productCode);
        $entitling = ['active', 'trial', 'grace'];

        if ($this->suspensionIsAdministrative()) {
            $entitling[] = 'read_only';
        }

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
