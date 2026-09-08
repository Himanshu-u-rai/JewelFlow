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

        // UNATTRIBUTED LEGACY read_only (the JF-0001 incident state). The buggy
        // expiry fork wrote access_mode=read_only with NO attribution at all — no
        // admin id, and in the oldest rows no reason text either — so the reason
        // match below can never classify them and they fall through to
        // EnsureAccountIsActive's 423 with no recovery path. Recognising them here
        // is what lets the middlewares reconcile the row onto the entitlement axis
        // (enforcement on) or heal it outright (enforcement off).
        //
        // CORROBORATION IS MANDATORY. `read_only` access on its own is NOT evidence
        // of a lapse: it is also how an ordinary read-only hold looks, and it is how
        // every read-only fixture in this suite is built. Keying on the mode alone
        // classified all of them as lapses, and because enforce_subscriptions
        // defaults to FALSE, restoreIfSubscriptionManagedSuspension() then healed
        // them to access_mode=active — handing full write access to every read-only
        // shop, including admin holds old enough to predate suspended_by stamping.
        // The lapse must be corroborated by the subscription row that the fork wrote
        // alongside it. Both live writers of read_only (ShopManagementController::
        // updateStatus, BillingManagementController) stamp suspended_by and are
        // already excluded above, so this query only ever narrows the legacy set.
        //
        // Deliberately keyed on the read_only MODE, never on "suspended_by is null":
        // an unattributed `suspended` shop still needs its reason corroborated.
        //
        // The corroboration must describe the shop's CURRENT entitlement, never its
        // history. "Has this shop ever held a read_only row?" is a false positive on
        // every shop that lapsed once and renewed: the dead row survives forever, so
        // a shop with a perfectly live term today still classified as lapsed, and
        // (enforcement being off by default) got healed to access_mode=active on its
        // very next page view — silently lifting the read-only hold it is actually
        // under. Two bounded, order-aware checks replace that existence scan; either
        // one failing means "not a legacy lapse", so ambiguity fails closed.
        if (($this->access_mode ?? '') === 'read_only') {
            // (1) The LATEST subscription row must itself be the legacy artefact.
            //     An `active`/`trial`/`grace`/`expired`/`cancelled` row written after
            //     it supersedes it, and no row at all is not evidence of anything.
            //     Ordered single-column read — `order by id desc limit 1`.
            if ($this->subscriptions()->orderByDesc('id')->value('status') !== 'read_only') {
                return false;
            }

            // (2) No LIVE entitling term may stand behind it. A shop can carry a
            //     legacy read_only row for one product while a different product's
            //     term is still running (the cross-product case); healing on the
            //     strength of the dead row would hand write access to a shop that is
            //     currently entitled and deliberately frozen. Checked by liveness
            //     (dated), not by presence: a genuine JF-0001 shop still owns the
            //     long-expired `active` row from before its lapse, and that row must
            //     not block its recovery. NULL dates never match, so they fail closed.
            //     ponytail: product-agnostic on purpose — any live term anywhere on
            //     the shop blocks healing, which is stricter than per-product scoping
            //     and needs no plan->product resolution inside a predicate.
            $today = now()->toDateString();

            return ! $this->subscriptions()
                ->where(function ($q) use ($today) {
                    $q->where(function ($w) use ($today) {
                        $w->whereIn('status', ['active', 'trial'])
                            ->whereDate('ends_at', '>=', $today);
                    })->orWhere(function ($w) use ($today) {
                        $w->where('status', 'grace')
                            ->whereDate('grace_ends_at', '>=', $today);
                    });
                })
                ->exists();
        }

        $reason = (string) ($this->suspension_reason ?? '');

        return str_starts_with($reason, 'Subscription')
            || in_array($reason, self::SUBSCRIPTION_MANAGED_REASONS, true);
    }

    /** Memo for accessClassification(); see the ponytail note on that method. */
    private ?string $accessClassificationCache = null;

    /**
     * PRESENTATION classifier: which of the mutually exclusive access states is
     * this shop actually in? Read-only — it decides nothing and writes nothing.
     *
     * Exists because `shops.access_mode` alone cannot answer the only question an
     * operator has ("did WE do this, and must I act?"): `read_only` is written by
     * a deliberate admin hold AND by the pre-2026-08-27 expiry fork. Every admin
     * surface that needs the answer used to re-derive it inline, so the badge, the
     * Platform Control panel and the subscription summary could disagree on one
     * page — which is exactly what shipped.
     *
     * Delegates to suspensionIsSubscriptionManaged(), the same classifier
     * AuthenticatedSessionController and EnsureSubscriptionIsActive route on, so
     * the screen cannot contradict what the owner experiences. Nothing here is
     * re-implemented from a reason string or a missing timestamp.
     *
     * MODE AND CAUSE ARE INDEPENDENT AXES. The mode says how hard the block is
     * (read_only = writes blocked, suspended = fully blocked); attribution says
     * who caused it. `suspended` is NOT self-evidently administrative:
     * CheckSubscriptionExpiry::applyShopModeUnderLock() writes access_mode=
     * 'suspended' with reasons like 'Subscription grace period ended' and never
     * stamps suspended_by, and EnsureAccountIsActive already routes exactly those
     * shops to the plan picker. Short-circuiting `suspended` to an administrator
     * hold made this screen contradict the recovery the owner actually gets. Both
     * restricted modes therefore run through the same classifier.
     *
     * Returns exactly one of:
     *   'admin_suspended'          — deliberate hold, fully blocked, actor recorded.
     *   'admin_read_only'          — deliberate hold, writes blocked, actor recorded.
     *   'subscription_lapse'       — term ended; NOT a restriction; owner self-recovers.
     *                                Reachable from either restricted mode.
     *   'unclassified_suspended'   — suspended with no recorded actor AND no
     *   'unclassified_read_only'     corroborating lapse. Unresolved: neither may
     *                                ever be presented as "no restriction".
     *   'active'                   — no restriction.
     *
     * ponytail: memoised per instance because the detail page asks three times
     * (badge, Platform Control, subscription summary) and the classifier runs two
     * bounded subscription queries.
     */
    public function accessClassification(): string
    {
        if ($this->accessClassificationCache !== null) {
            return $this->accessClassificationCache;
        }

        $mode = $this->access_mode ?? 'active';

        if ($mode !== 'read_only' && $mode !== 'suspended') {
            return $this->accessClassificationCache = 'active';
        }

        // Order is the precedence: the authoritative classifier first (it already
        // applies admin-attribution precedence internally), then attribution, and
        // anything left over is unresolved and fails closed rather than being
        // called unrestricted. The mode only chooses the severity of the label.
        return $this->accessClassificationCache = match (true) {
            $this->suspensionIsSubscriptionManaged() => 'subscription_lapse',
            $this->suspensionIsAdministrative()      => $mode === 'suspended' ? 'admin_suspended' : 'admin_read_only',
            default                                  => $mode === 'suspended' ? 'unclassified_suspended' : 'unclassified_read_only',
        };
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
