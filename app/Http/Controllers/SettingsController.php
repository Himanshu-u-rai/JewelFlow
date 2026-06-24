<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Models\ShopBillingSettings;
use App\Models\ShopPreferences;
use App\Models\ShopPaymentMethod;
use App\Models\ShopCounter;
use App\Models\Invoice;
use App\Models\AuditLog;
use App\Services\BusinessIdentifierService;
use App\Services\ShopPricingService;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SettingsController extends Controller
{
    /**
     * Show the settings page with tabs.
     */
    public function edit(Request $request)
    {
        $user = Auth::user();

        if (!$user->shop_id) {
            return redirect()->route('shops.create');
        }

        $shop = $user->shop;

        // Active tab — resolve first so per-tab queries can be gated.
        $activeTab = $request->get('tab', 'shop');

        // ─── Per-tab access guard ────────────────────────────────────────────
        // Each tab has its own permission requirement. If the requested tab is
        // beyond the user's reach, redirect to the first tab they CAN see
        // instead of rendering empty content (which broke Turbo Drive frames).
        $tabRequirements = [
            'general'         => null, // any authenticated user
            'shop'            => 'settings.view',
            'billing'         => 'settings.view',
            'gst'             => 'settings.view',
            'payment-methods' => 'settings.view',
            'preferences'     => 'settings.view',
            'return-policy'   => 'settings.view',
            'pricing'         => 'pricing.update',
            'materials'       => 'settings.view',
            'website'         => 'settings.view',
            'roles'           => '__owner_only__',
            'staff'           => 'staff.view',
            'audit'           => 'settings.view',
            'services'        => 'settings.view',
            'subscription'    => 'settings.view',
            'devices'         => 'settings.view',
        ];
        $canSee = function (string $tab) use ($user, $tabRequirements): bool {
            $req = $tabRequirements[$tab] ?? null;
            if ($req === null) return true;
            if ($req === '__owner_only__') return $user->isOwner();
            return $user->can($req);
        };
        if (! $canSee($activeTab)) {
            // Pick the first tab the user CAN see and redirect there.
            foreach (array_keys($tabRequirements) as $tab) {
                if ($canSee($tab)) {
                    return redirect()->route('settings.edit', ['tab' => $tab]);
                }
            }
            // Fallback: dashboard (shouldn't happen — General is always accessible).
            return redirect()->route('dashboard');
        }

        // Settings rows referenced by Invoice + Preferences tabs — single-row reads.
        $billing = $shop->billingSettings ?? ShopBillingSettings::create(['shop_id' => $shop->id]);
        $preferences = $shop->preferences ?? ShopPreferences::create(['shop_id' => $shop->id]);

        // Roles + Permissions — only the Roles tab uses these (heavier queries).
        $roles = collect();
        $permissions = collect();
        $permissionGroups = collect();
        if ($activeTab === 'roles') {
            $roles = Role::with('permissions')->get();
            $permissions = Permission::orderBy('group')->orderBy('name')->get();
            $permissionGroups = $permissions->groupBy('group');
        }

        // Staff list — only the Staff tab uses this.
        $staff = collect();
        $staffLimit = 0;
        $staffCount = 0;
        if ($activeTab === 'staff') {
            $staff = User::with('role')
                ->where('shop_id', $shop->id)
                ->orderByRaw('COALESCE(name, mobile_number) ASC')
                ->get();
            $staffLimit = $shop->staffLimit();
            $staffCount = $shop->currentStaffCount();
        }

        // Catalog Website tab
        $catalogWebsiteSettings = null;
        $catalogPages = collect();
        if ($activeTab === 'website') {
            $catalogWebsiteSettings = $shop->catalogWebsiteSettings;
            $catalogPages = $shop->catalogPages()->orderBy('sort_order')->orderBy('title')->get();
        }

        $logs = null;
        $stats = null;
        $auditActions = collect();
        $auditUsers = collect();
        if ($activeTab === 'audit') {
            // Filters: action, user, date range. Each is applied to the listing
            // AND mirrored in the count queries so the stats reflect the filter.
            $fAction = $request->string('audit_action')->value() ?: null;
            $fUser   = $request->filled('audit_user') ? (int) $request->input('audit_user') : null;
            $fFrom   = $request->filled('audit_from') ? $request->date('audit_from') : null;
            $fTo     = $request->filled('audit_to') ? $request->date('audit_to') : null;

            $base = fn () => AuditLog::where('shop_id', $shop->id)
                ->when($fAction, fn ($q) => $q->where('action', $fAction))
                ->when($fUser, fn ($q) => $q->where('user_id', $fUser))
                ->when($fFrom, fn ($q) => $q->whereDate('created_at', '>=', $fFrom))
                ->when($fTo, fn ($q) => $q->whereDate('created_at', '<=', $fTo));

            // Eager-load the actor to avoid an N+1 across the 50 rendered rows.
            $logs = $base()->with('user:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();

            // Owner-meaningful stats (shop-wide, today) — not vanity log counts.
            // "Sensitive today" = refunds, deletions, reversals, voids, overrides,
            // staff removals, vault/gold adjustments, permission changes — the
            // events an owner most wants to notice. Pull today's actions once and
            // classify in PHP via AuditLog::isSensitive() so the rule lives in one
            // place (the model), not duplicated in SQL.
            $todayActions = AuditLog::where('shop_id', $shop->id)
                ->whereDate('created_at', now()->toDateString())
                ->get(['id', 'action']);

            $sensitiveToday = $todayActions->filter->isSensitive()->count();

            $stats = [
                'today'     => $todayActions->count(),
                'sensitive' => $sensitiveToday,
                'total'     => AuditLog::where('shop_id', $shop->id)->count(),
            ];

            // Distinct actions + users for the filter dropdowns (shop-scoped).
            $auditActions = AuditLog::where('shop_id', $shop->id)
                ->whereNotNull('action')->distinct()->orderBy('action')->pluck('action');
            $auditUsers = User::where('shop_id', $shop->id)->get(['id', 'name', 'mobile_number']);
        }

        $methods = collect();
        if ($activeTab === 'payment-methods') {
            $methods = ShopPaymentMethod::where('shop_id', $shop->id)
                ->orderBy('sort_order')
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->groupBy('type');
        }

        $pricingData = null;
        $pricingTimezones = [];
        if ($activeTab === 'pricing' && $shop->isRetailer()) {
            $pricing = app(ShopPricingService::class);
            $allPurityProfiles = $pricing->allPurityProfiles($shop);
            $historyFilters = $pricing->normalizeResolvedRateHistoryFilters([
                'date_from' => $request->query('history_date_from'),
                'date_to' => $request->query('history_date_to'),
                'metal_type' => $request->query('history_metal_type'),
                'purity_value' => $request->query('history_purity_value'),
                'entry_type' => $request->query('history_entry_type'),
                'sort_by' => $request->query('history_sort_by'),
                'sort_dir' => $request->query('history_sort_dir'),
            ]);
            $pricingData = [
                'timezone' => $pricing->pricingTimezone($shop),
                'business_date' => $pricing->businessDateString($shop),
                'today_rate' => $pricing->currentDailyRate($shop),
                'profiles' => $allPurityProfiles->groupBy('metal_type'),
                'resolved_rates' => $pricing->resolvedRateGrid($shop),
                'legacy_items' => $pricing->legacyItemsNeedingReview($shop),
                'rates_ready' => $pricing->hasCurrentDailyRates($shop),
                'history_filters' => $historyFilters,
                // One business day per page — each page shows that day's purity rates.
                'history_rows' => $pricing->resolvedRateHistory($shop, $historyFilters, 1),
                'history_purity_options' => $allPurityProfiles
                    ->map(function ($profile) use ($pricing): array {
                        return [
                            'metal_type' => (string) $profile->metal_type,
                            'value' => $pricing->normalizePurityString((float) $profile->purity_value),
                            'label' => (string) $profile->label,
                        ];
                    })
                    ->unique(fn (array $option): string => $option['metal_type'] . ':' . $option['value'])
                    ->values(),
            ];
            $pricingTimezones = DateTimeZone::listIdentifiers();
        }

        // Materials tab — current Tier 2 opt-in state. Tier 1 (gold/silver) is
        // always available; Tier 2 (platinum/copper) is opt-in per shop.
        // For enabled Tier-2 metals, also surface the most recent Class-B
        // reference price (memo, display hint only — see pricing-control-plan).
        $materialsData = null;
        if ($activeTab === 'materials') {
            $enabled = \App\Services\MetalRegistry::enabledMetalsForShop((int) $shop->id);
            $refSvc  = app(\App\Services\ReferencePriceService::class);

            $materialsData = [
                'primary' => \App\Services\MetalRegistry::tier1Metals(),
                'tier2' => array_map(
                    function (string $metal) use ($enabled, $refSvc, $shop) {
                        $isEnabled = in_array($metal, $enabled, true);
                        return [
                            'metal'             => $metal,
                            'enabled'           => $isEnabled,
                            // Reference price exists ONLY when the metal is enabled
                            // (no point showing a memo for a metal the shop doesn't sell).
                            'latest_reference'  => $isEnabled
                                ? $refSvc->latestReference((int) $shop->id, $metal)
                                : null,
                        ];
                    },
                    \App\Services\MetalRegistry::tier2Metals()
                ),
            ];
        }

        // Services tab — which editions (services) this shop has, can add, plus
        // pending requests + recent activity. Mirrors ShopServicesController::index.
        $servicesData = null;
        if ($activeTab === 'services') {
            $active = $shop->editionList();
            $platformEnabled = \App\Models\Platform\PlatformSetting::enabledShopTypes();
            $available = array_values(array_intersect(array_diff(\App\Support\ShopEdition::ALL, $active), $platformEnabled));

            $servicesData = [
                'active'          => $active,
                'all'             => \App\Support\ShopEdition::ALL,
                'available'       => $available,
                // For each available service, work out whether the owner can buy
                // it themselves right now (active product + an active plan that
                // carries a real price). This drives the buy-now card vs the
                // request-add fallback. Keyed by edition string so the Blade loop
                // can look its option up cheaply.
                'purchase'        => $this->buildServicePurchaseOptions($available),
                'pendingRequests' => \App\Models\ShopEditionRequest::where('shop_id', $shop->id)->where('status', \App\Models\ShopEditionRequest::STATUS_PENDING)->latest()->get(),
                'history'         => \App\Models\ShopEditionRequest::where('shop_id', $shop->id)->whereIn('status', [\App\Models\ShopEditionRequest::STATUS_APPROVED, \App\Models\ShopEditionRequest::STATUS_DENIED, \App\Models\ShopEditionRequest::STATUS_CANCELLED])->latest()->limit(10)->get(),
                'assignments'     => \App\Models\ShopEditionAssignment::where('shop_id', $shop->id)->whereNull('deactivated_at')->get()->keyBy('edition'),
            ];
        }

        // Plan & Billing tab — current subscription status + billing history.
        // Replicates SubscriptionController::status()'s data contract exactly so
        // the ported status display renders identically. status() redirects to
        // subscription.plans when there is no shop/subscription; inside a tab we
        // can't redirect mid-render cleanly, so we flag needs_plan and render a
        // small "choose a plan" panel instead.
        $subscriptionData = null;
        if ($activeTab === 'subscription') {
            $sub = \App\Models\Platform\ShopSubscription::where('shop_id', $shop->id)
                ->with('plan')
                ->latest('id')
                ->first();

            if ($sub) {
                $daysRemaining = $sub->daysRemaining();
                $isExpired = $daysRemaining !== null && $daysRemaining < 0;
                $isInGrace = $isExpired
                    && $sub->grace_ends_at
                    && \Carbon\Carbon::now()->lte($sub->grace_ends_at);

                $subscriptionData = [
                    'subscription'  => $sub,
                    'plan'          => $sub->plan,
                    'daysRemaining' => $daysRemaining,
                    'isInGrace'     => $isInGrace,
                    'isExpired'     => $isExpired,
                    'featureLabels' => \App\Http\Controllers\SubscriptionController::featureLabels(),
                    'invoices'      => \App\Models\Platform\PlatformInvoice::where('shop_id', $shop->id)
                        ->with('plan')
                        ->latest('issued_at')
                        ->paginate(10)
                        ->withQueryString(),
                ];
            } else {
                $subscriptionData = ['needs_plan' => true];
            }
        }

        // Devices tab — the mobile devices currently signed in for this shop.
        // Mirrors the proven SessionController::index() listing query: active
        // sessions only (logged_out_at IS NULL), newest first. Owners and anyone
        // with returns.approve see every staff member's devices; everyone else
        // sees only their own.
        $deviceSessions = collect();
        if ($activeTab === 'devices') {
            $u = auth()->user();
            $canViewAll = $u->isOwner() || $u->can('returns.approve');
            $q = \App\Models\MobileDeviceSession::where('shop_id', $shop->id)
                ->whereNull('logged_out_at')
                ->with('user:id,name,mobile_number,role_id')
                ->orderByDesc('logged_in_at');
            if (! $canViewAll) {
                $q->where('user_id', $u->id);
            }
            $deviceSessions = $q->get();
        }

        $user = auth()->user();

        // Per-metal GST rate overrides + the metals this shop can set them for.
        $gstCategories = \App\Models\GstCategory::where('shop_id', $shop->id)
            ->orderByDesc('is_default')
            ->orderBy('metal_type')
            ->get();
        $gstEnabledMetals = \App\Services\MetalRegistry::enabledMetalsForShop((int) $shop->id);

        return view('settings', compact(
            'shop',
            'billing',
            'preferences',
            'materialsData',
            'roles',
            'permissions',
            'permissionGroups',
            'staff',
            'staffLimit',
            'staffCount',
            'activeTab',
            'logs',
            'stats',
            'auditActions',
            'auditUsers',
            'catalogWebsiteSettings',
            'catalogPages',
            'pricingData',
            'pricingTimezones',
            'methods',
            'user',
            'gstCategories',
            'gstEnabledMetals',
            'servicesData',
            'subscriptionData',
            'deviceSessions'
        ));
    }

    /**
     * Update shop identity information.
     */
    public function updateShop(Request $request)
    {
        $shop = Auth::user()->shop;
        $oldLogoPath = $shop->logo_path;

        $rules = [
            'name'                      => 'required|string|max:255',
            'phone'                     => 'required|string|digits:10',
            'shop_whatsapp'             => 'nullable|string|digits:10',
            'shop_email'                => 'nullable|email|max:100',
            'established_year'          => 'nullable|integer|min:1900|max:' . now()->year,
            'shop_registration_number'  => 'nullable|string|max:100',
            'logo'                      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo'               => 'nullable|boolean',
            'address_line1'             => 'required|string|max:255',
            'address_line2'             => 'nullable|string|max:255',
            'city'                      => 'required|string|max:100',
            'state'                     => 'required|string|max:100',
            'state_code'                => 'nullable|string|max:5',
            'pincode'                   => 'required|string|digits:6',
            'owner_first_name'          => 'required|string|max:255',
            'owner_last_name'           => 'required|string|max:255',
            'owner_mobile'              => 'required|string|digits:10',
            'owner_email'               => 'nullable|email|max:255',
            // gst_number + gst_rate moved to the dedicated GST & Tax tab (updateGst).
        ];

        if ($shop->isManufacturer()) {
            $rules['wastage_recovery_percent'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['wastage_recovery_percent'] = 'nullable|numeric|min:0|max:100';
        }

        $validated = $request->validate($rules);

        unset($validated['logo'], $validated['remove_logo']);

        if (!$shop->isManufacturer()) {
            unset($validated['wastage_recovery_percent']);
        }

        // Build full address for backwards compatibility. address_line2 is
        // nullable, so it may be absent from $validated — guard against it.
        $validated['address'] = trim(
            $validated['address_line1'] . ', ' .
            (($validated['address_line2'] ?? null) ? $validated['address_line2'] . ', ' : '') .
            $validated['city'] . ', ' .
            $validated['state'] . ' - ' .
            $validated['pincode']
        );

        // Old owner-details name (before the update), to detect whether the
        // owner user's login name was ever personalised on the Profile tab.
        $previousOwnerName = trim((string) $shop->owner_first_name . ' ' . (string) $shop->owner_last_name);

        $shop->update($validated);

        // Seed the owner user's display name from the shop owner details, but
        // ONLY when it hasn't been personalised on the Profile tab. If the owner
        // deliberately set a different login name there, don't clobber it — the
        // two are independent (login identity vs registered shop owner).
        $ownerUser = \App\Models\User::where('shop_id', $shop->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
            ->first();
        if ($ownerUser) {
            $newOwnerName = trim($validated['owner_first_name'] . ' ' . $validated['owner_last_name']);
            $currentName  = trim((string) $ownerUser->name);
            if ($currentName === '' || $currentName === $previousOwnerName) {
                $ownerUser->name = $newOwnerName;
                $ownerUser->save();
            }
        }

        if ($request->hasFile('logo')) {
            $newLogoPath = app(\App\Services\ImageOptimizer::class)->optimizeAndStore($request->file('logo'), 'shop-logos', 'public');
            $shop->update(['logo_path' => $newLogoPath]);

            if (!empty($oldLogoPath)) {
                Storage::disk('public')->delete($oldLogoPath);
            }
        } elseif ($request->boolean('remove_logo') && !empty($oldLogoPath)) {
            $shop->update(['logo_path' => null]);
            Storage::disk('public')->delete($oldLogoPath);
        }

        return redirect()->route('settings.edit', ['tab' => 'shop'])
            ->with('success', 'Shop information updated successfully.');
    }


    /**
     * Update invoice/billing settings.
     */
    public function updateBilling(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            // Existing
            'invoice_prefix'        => 'required|string|max:20',
            'invoice_start_number'  => 'required|integer|min:1',
            'terms_and_conditions'  => [
                'nullable', 'string', 'max:600',
                function ($attribute, $value, $fail) {
                    if (!$value) return;
                    $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)));
                    if (count($lines) > 6) {
                        $fail('Terms & Conditions can have a maximum of 6 points. You have ' . count($lines) . '.');
                    }
                },
            ],
            'bank_details'           => 'nullable|string|max:500',
            'bank_name'              => 'nullable|string|max:100',
            'bank_account_number'    => 'nullable|string|max:30',
            'bank_ifsc'              => 'nullable|string|max:20',
            'bank_account_type'      => 'nullable|string|in:current,savings,overdraft',
            'bank_account_holder'    => 'nullable|string|max:100',
            'bank_branch'            => 'nullable|string|max:100',
            'upi_id'                 => 'nullable|string|max:100',
            'show_digital_signature' => 'nullable|boolean',
            'show_bis_logo'          => 'nullable|boolean',
            // Branding
            'theme_color'            => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_size'              => 'nullable|string|in:compact,normal,large',
            'shop_subtitle'          => 'nullable|string|max:100',
            'custom_tagline'         => 'nullable|string|max:150',
            // Invoice copy
            'invoice_copy_label'     => 'nullable|string|max:20',
            'copy_count'             => 'nullable|integer|in:1,2',
            // Invoice number
            'invoice_suffix'         => 'nullable|string|max:20',
            'year_reset'             => 'nullable|boolean',
            // Column visibility
            'show_huid'              => 'nullable|boolean',
            'show_stone_columns'     => 'nullable|boolean',
            'show_purity'            => 'nullable|boolean',
            'show_gstin'             => 'nullable|boolean',
            'show_customer_address'  => 'nullable|boolean',
            'show_customer_id_pan'   => 'nullable|boolean',
            'show_mode'              => 'nullable|boolean',
            'show_time'              => 'nullable|boolean',
            // Tax (igst_mode + HSN codes) moved to the dedicated GST & Tax tab.
            // Footer / print
            'second_signature_label' => 'nullable|string|max:100',
            'paper_size'             => 'nullable|string|in:a4,a5,thermal',
        ]);

        $billing = $shop->billingSettings ?? new ShopBillingSettings(['shop_id' => $shop->id]);

        // Handle digital signature upload
        if ($request->hasFile('digital_signature')) {
            $request->validate(['digital_signature' => 'image|mimes:png,jpg,jpeg|max:512']);

            if ($billing->digital_signature_path) {
                Storage::disk('public')->delete($billing->digital_signature_path);
            }
            $validated['digital_signature_path'] = app(\App\Services\ImageOptimizer::class)
                ->optimizeAndStore($request->file('digital_signature'), 'signatures', 'public');
        } elseif ($request->boolean('remove_digital_signature') && $billing->digital_signature_path) {
            Storage::disk('public')->delete($billing->digital_signature_path);
            $validated['digital_signature_path'] = null;
        }

        // Boolean toggles (hidden-input pattern: unchecked = "0" sent by hidden input)
        $validated['show_digital_signature'] = $request->boolean('show_digital_signature');
        $validated['show_bis_logo']          = $request->boolean('show_bis_logo');
        $validated['year_reset']             = $request->boolean('year_reset');
        $validated['show_huid']              = $request->boolean('show_huid');
        $validated['show_stone_columns']     = $request->boolean('show_stone_columns');
        $validated['show_purity']            = $request->boolean('show_purity');
        $validated['show_gstin']             = $request->boolean('show_gstin');
        $validated['show_customer_address']  = $request->boolean('show_customer_address');
        $validated['show_customer_id_pan']   = $request->boolean('show_customer_id_pan');

        // Defaults for optional fields
        $validated['theme_color']  = $validated['theme_color']  ?? '#111111';
        $validated['font_size']    = $validated['font_size']    ?? 'normal';
        $validated['paper_size']   = $validated['paper_size']   ?? 'a4';
        $validated['copy_count']   = $validated['copy_count']   ?? 1;
        $validated['invoice_copy_label'] = $validated['invoice_copy_label'] ?? 'Original';

        // Auto-compose bank_details from structured fields for invoice printing
        $bankParts = array_filter([
            $validated['bank_account_holder'] ?? null ? 'A/C Holder: ' . $validated['bank_account_holder'] : null,
            $validated['bank_name'] ?? null ? 'Bank: ' . $validated['bank_name'] : null,
            $validated['bank_account_number'] ?? null ? 'A/C No: ' . $validated['bank_account_number'] : null,
            $validated['bank_ifsc'] ?? null ? 'IFSC: ' . strtoupper($validated['bank_ifsc']) : null,
            $validated['bank_account_type'] ?? null ? 'Type: ' . ucfirst($validated['bank_account_type']) : null,
            $validated['bank_branch'] ?? null ? 'Branch: ' . $validated['bank_branch'] : null,
        ]);
        $validated['bank_details'] = !empty($bankParts) ? implode("\n", $bankParts) : null;

        // Uppercase IFSC
        if (!empty($validated['bank_ifsc'])) {
            $validated['bank_ifsc'] = strtoupper($validated['bank_ifsc']);
        }

        $billing->fill($validated);
        $billing->save();

        // Keep invoice counter aligned with settings.
        // It can only move forward (never backward) to avoid duplicate sequences.
        DB::transaction(function () use ($shop, $billing): void {
            $maxIssuedSequence = (int) Invoice::where('shop_id', $shop->id)->max('invoice_sequence');
            $settingsBase = max(0, (int) $billing->invoice_start_number - 1);

            $counter = ShopCounter::query()
                ->where('shop_id', $shop->id)
                ->where('counter_key', BusinessIdentifierService::KEY_INVOICE)
                ->lockForUpdate()
                ->first();

            $currentValue = (int) ($counter?->current_value ?? 0);
            $nextSafeBase = max($currentValue, $maxIssuedSequence, $settingsBase);

            if ($counter) {
                if ($nextSafeBase !== $currentValue) {
                    $counter->current_value = $nextSafeBase;
                    $counter->save();
                }
            } else {
                ShopCounter::query()->create([
                    'shop_id' => $shop->id,
                    'counter_key' => BusinessIdentifierService::KEY_INVOICE,
                    'current_value' => $nextSafeBase,
                ]);
            }
        });

        return redirect()->route('settings.edit', ['tab' => 'billing'])
            ->with('success', 'Invoice settings updated successfully.');
    }

    /**
     * Update GST & Tax settings (the dedicated GST tab): GSTIN + default rate on
     * the shop, IGST mode + HSN codes on billing settings. Per-metal GST rates
     * are managed separately via GstCategoryController. Consolidates the GST
     * fields that previously lived across the Shop and Invoice tabs.
     */
    public function updateGst(Request $request)
    {
        $shop = Auth::user()->shop;

        // Normalise GSTIN to canonical form (uppercase, trimmed) before validating,
        // so a correctly-typed lowercase GSTIN isn't rejected by the format rule.
        if ($request->filled('gst_number')) {
            $request->merge(['gst_number' => strtoupper(trim((string) $request->input('gst_number')))]);
        }

        // #3 — validate GSTIN format when present (15 chars: 2 state + 10 PAN +
        // 1 entity + 'Z' + 1 check). Still optional, but a typo can't silently
        // reach invoices / GSTR-1 exports. #5 — HSN must be 4/6/8 digits.
        $hsnRule = ['nullable', 'string', 'regex:/^\d{4}(\d{2})?(\d{2})?$/'];
        $validated = $request->validate([
            'gst_number'   => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'gst_rate'     => 'required|numeric|min:0|max:100',
            'igst_mode'    => 'nullable|boolean',
            'hsn_gold'     => $hsnRule,
            'hsn_silver'   => $hsnRule,
            'hsn_diamond'  => $hsnRule,
            'hsn_platinum' => $hsnRule,
            'hsn_copper'   => $hsnRule,
        ], [
            'gst_number.regex' => 'Enter a valid 15-character GSTIN (e.g. 08ABCDE1234F1Z5), or leave it blank.',
            'hsn_gold.regex'     => 'HSN must be 4, 6, or 8 digits.',
            'hsn_silver.regex'   => 'HSN must be 4, 6, or 8 digits.',
            'hsn_diamond.regex'  => 'HSN must be 4, 6, or 8 digits.',
            'hsn_platinum.regex' => 'HSN must be 4, 6, or 8 digits.',
            'hsn_copper.regex'   => 'HSN must be 4, 6, or 8 digits.',
        ]);

        // Shop-level: GST identity + the flat default rate (fallback for the
        // per-metal resolver).
        $shop->update([
            'gst_number' => $validated['gst_number'] ?? null,
            'gst_rate'   => $validated['gst_rate'],
        ]);

        // Billing-level: tax presentation on the printed invoice. HSN fields are
        // nullable, so a key may be absent from $validated (not just empty) —
        // null-coalesce before the default to avoid an undefined-array-key error.
        // Defaults come from the single HSN_DEFAULTS source on the model.
        $d = ShopBillingSettings::HSN_DEFAULTS;
        $billing = $shop->billingSettings ?? new ShopBillingSettings(['shop_id' => $shop->id]);
        $billing->fill([
            'igst_mode'    => $request->boolean('igst_mode'),
            'hsn_gold'     => ($validated['hsn_gold']     ?? null) ?: $d['gold'],
            'hsn_silver'   => ($validated['hsn_silver']   ?? null) ?: $d['silver'],
            'hsn_diamond'  => ($validated['hsn_diamond']  ?? null) ?: $d['diamond'],
            'hsn_platinum' => ($validated['hsn_platinum'] ?? null) ?: $d['platinum'],
            'hsn_copper'   => ($validated['hsn_copper']   ?? null) ?: $d['copper'],
        ]);
        $billing->save();

        return redirect()->route('settings.edit', ['tab' => 'gst'])
            ->with('success', 'GST settings updated.');
    }

    /**
     * Update which optional (Tier 2) materials the shop offers.
     *
     * Gold and silver (Tier 1) are always available and are not togglable.
     * Platinum and copper (Tier 2) are opt-in per shop via shop_enabled_metals.
     */
    public function updateMaterials(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            'metals'   => 'nullable|array',
            'metals.*' => 'boolean',
        ]);

        $submitted = $validated['metals'] ?? [];

        // Items that are melted / reversed / written off have physically left the
        // shop's stock, so a metal can be turned off once only those remain. Any
        // other status (in stock, sold, with karigar, returned, …) means the metal
        // is still live: turning it off would lock the owner out of editing those
        // existing items, because the item create/edit form only accepts enabled
        // metals (ItemController validates metal_type against enabledMetalsForShop).
        $disposedStatuses = ['melted', 'reversed', 'written_off'];

        $currentlyEnabled = \App\Services\MetalRegistry::enabledMetalsForShop((int) $shop->id);

        foreach (\App\Services\MetalRegistry::tier2Metals() as $metal) {
            $enabled = (bool) ($submitted[$metal] ?? false);
            $wasEnabled = in_array($metal, $currentlyEnabled, true);

            // Guard: refuse to turn a metal off while it still has live stock.
            if ($wasEnabled && ! $enabled) {
                $liveCount = DB::table('items')
                    ->where('shop_id', $shop->id)
                    ->where('metal_type', $metal)
                    ->whereNotIn('status', $disposedStatuses)
                    ->count();

                if ($liveCount > 0) {
                    return redirect()->route('settings.edit', ['tab' => 'materials'])
                        ->withErrors(['metals' => ucfirst($metal) . " can't be turned off — you still have "
                            . $liveCount . " " . $metal . " item" . ($liveCount === 1 ? '' : 's')
                            . " in stock. Sell, melt, or remove them first."]);
                }
            }

            // shop_enabled_metals has no Eloquent model; use the query builder.
            // PostgreSQL rejects integer 0/1 for boolean columns, so the flag is
            // a raw boolean literal (see CLAUDE.md). Set created_at only when the
            // row is first inserted so re-saving the form never overwrites the
            // original creation time.
            $key = ['shop_id' => $shop->id, 'metal_type' => $metal];
            $exists = DB::table('shop_enabled_metals')->where($key)->exists();

            $values = ['enabled' => DB::raw($enabled ? 'true' : 'false'), 'updated_at' => now()];
            if (! $exists) {
                $values['created_at'] = now();
            }

            DB::table('shop_enabled_metals')->updateOrInsert($key, $values);
        }

        \App\Services\MetalRegistry::clearShopCache((int) $shop->id);

        return redirect()->route('settings.edit', ['tab' => 'materials'])
            ->with('success', 'Materials updated successfully.');
    }

    /**
     * Record a NEW reference price (Class-B memo, append-only) for an
     * opted-in Tier-2 metal. Each save records a new row; previous notes are
     * preserved as history. Class A (gold/silver) and unsupported metals are
     * rejected at validation; the service rejects them again at the source.
     */
    public function updateMaterialReference(Request $request, \App\Services\ReferencePriceService $references)
    {
        $shop = Auth::user()->shop;

        $tier2 = \App\Services\MetalRegistry::tier2Metals();
        $enabled = \App\Services\MetalRegistry::enabledMetalsForShop((int) $shop->id);

        $validated = $request->validate([
            'metal_type'      => ['required', \Illuminate\Validation\Rule::in($tier2)],
            'reference_price' => 'required|numeric|min:0|max:9999999.99',
            'note'            => 'nullable|string|max:255',
        ]);

        if (! in_array($validated['metal_type'], $enabled, true)) {
            return back()
                ->withErrors(['metal_type' => 'Turn this metal on first before noting a reference price.'])
                ->withInput();
        }

        $references->recordReference(
            shopId:          (int) $shop->id,
            metalType:       (string) $validated['metal_type'],
            referencePrice:  (float) $validated['reference_price'],
            notedByUserId:   (int) Auth::id(),
            note:            $validated['note'] ?? null,
        );

        return redirect()->route('settings.edit', ['tab' => 'materials'])
            ->with('success', ucfirst($validated['metal_type']) . ' reference price noted.');
    }

    /**
     * Update UI/behavior preferences.
     */
    public function updatePreferences(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            'low_stock_threshold'        => 'required|integer|min:0|max:100',
            'loyalty_points_per_hundred' => 'required|integer|min:0|max:100',
            'loyalty_point_value'        => 'required|numeric|min:0|max:100',
            'loyalty_expiry_months'      => 'required|integer|in:0,6,12,18,24',
            'auto_logout_minutes'        => 'nullable|integer|min:0|max:480',
            'stock_value_display'        => 'nullable|in:total,per_gram',
            // App interface language — must be one of the configured locales.
            'language'                   => ['nullable', \Illuminate\Validation\Rule::in(array_keys(config('app.supported_locales', ['en' => 'English'])))],
            'compliance_enabled'           => 'nullable|boolean',
            'compliance_threshold'         => 'nullable|numeric|min:10000|max:10000000',
            'compliance_pan_mandatory'     => 'nullable|boolean',
            'compliance_mobile_mandatory'  => 'nullable|boolean',
            'compliance_address_mandatory' => 'nullable|boolean',
            // POS pricing engine rounding / discount caps.
            // round_off_nearest is stored as an unsigned smallint of rupee
            // units (1 = nearest rupee, 5 = nearest 5 rupees, ...).
            'rounding_method'              => 'nullable|in:none,normal,upward,downward',
            'max_manual_discount_percent'  => 'nullable|numeric|min:0|max:100',
            'round_off_nearest'            => 'nullable|integer|in:1,5,10',
        ]);

        // Defaults
        $validated['auto_logout_minutes']  = $validated['auto_logout_minutes']  ?? 0;
        $validated['stock_value_display']  = $validated['stock_value_display']  ?? 'total';
        // Keep the existing language if the field wasn't submitted; else app default.
        $validated['language'] = $validated['language']
            ?? $shop->preferences?->language
            ?? config('app.locale', 'en');
        $validated['compliance_enabled']           = (bool) ($validated['compliance_enabled'] ?? false);
        $validated['compliance_pan_mandatory']     = (bool) ($validated['compliance_pan_mandatory'] ?? true);
        $validated['compliance_mobile_mandatory']  = (bool) ($validated['compliance_mobile_mandatory'] ?? true);
        $validated['compliance_address_mandatory'] = (bool) ($validated['compliance_address_mandatory'] ?? true);

        $preferences = $shop->preferences ?? new ShopPreferences(['shop_id' => $shop->id]);
        $preferences->fill($validated);
        $preferences->save();

        return redirect()->route('settings.edit', ['tab' => 'preferences'])
            ->with('success', 'Preferences updated successfully.');
    }

    /**
     * Update the shop's return / exchange policy.
     *
     * Isolated from updatePreferences() so this surface is reversible. Persists
     * the refund flags + windows and stamps return_policy_configured_at, which
     * is what unblocks the returns/exchange flow (ReturnsController::create()
     * redirects here until the policy is configured). No accounting logic here —
     * RefundPolicyResolver reads these persisted values at return time.
     */
    public function updateReturnPolicy(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            'refund_making_charges'      => 'nullable|boolean',
            'refund_stone_charges'       => 'nullable|boolean',
            'refund_hallmark_charges'    => 'nullable|boolean',
            'refund_gst'                 => 'nullable|boolean',
            'wear_loss_pct'              => 'nullable|numeric|min:0|max:25',
            'restocking_fee_pct'         => 'nullable|numeric|min:0|max:25',
            'return_window_days'         => 'nullable|integer|min:0|max:3650',
            'exchange_window_days'       => 'nullable|integer|min:0|max:3650',
            'return_settlement_mode'     => 'nullable|in:cash_only,store_credit_only,cash_or_credit',
            'exchange_rate_basis_locked' => 'nullable|boolean',
        ]);

        // Safe defaults — refunds default to refundable (matches the column
        // defaults and the documented zero-config "refund everything" intent);
        // fees default to 0; windows 0 = unlimited.
        $validated['refund_making_charges']   = (bool) ($validated['refund_making_charges']   ?? true);
        $validated['refund_stone_charges']    = (bool) ($validated['refund_stone_charges']    ?? true);
        $validated['refund_hallmark_charges'] = (bool) ($validated['refund_hallmark_charges'] ?? true);
        $validated['refund_gst']              = (bool) ($validated['refund_gst']              ?? true);
        $validated['wear_loss_pct']           = $validated['wear_loss_pct']        ?? 0;
        $validated['restocking_fee_pct']      = $validated['restocking_fee_pct']   ?? 0;
        $validated['return_window_days']      = $validated['return_window_days']   ?? 0;
        $validated['exchange_window_days']    = $validated['exchange_window_days'] ?? 0;
        $validated['return_settlement_mode']  = $validated['return_settlement_mode'] ?? 'cash_or_credit';
        $validated['exchange_rate_basis_locked'] = (bool) ($validated['exchange_rate_basis_locked'] ?? false);

        // The stamp that unblocks the returns flow.
        $validated['return_policy_configured_at'] = now();

        $preferences = $shop->preferences ?? new ShopPreferences(['shop_id' => $shop->id]);
        $preferences->fill($validated);
        $preferences->save();

        return redirect()->route('settings.edit', ['tab' => 'return-policy'])
            ->with('success', 'Return policy saved. Returns and exchanges are now active.');
    }

    /**
     * Update role permissions.
     */
    public function updateRolePermissions(Request $request, Role $role)
    {
        // Owner role cannot be modified. This `if` is the ONLY guard — there is
        // no owner short-circuit in Gate::before, so the owner's all-access comes
        // solely from its synced permission set. Never remove this check.
        if ($role->name === 'owner') {
            return redirect()->route('settings.edit', ['tab' => 'roles'])
                ->with('error', 'Owner role permissions cannot be modified.');
        }

        $validated = $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // Snapshot the current permission set so we can audit the diff.
        $beforeIds = $role->permissions()->pluck('permissions.id')->sort()->values()->all();

        // Dhiran is a separate product whose permissions are intentionally NOT
        // manageable from the JewelFlow Roles UI. The form never renders them,
        // but `exists:permissions,id` alone would accept a crafted payload that
        // injects a Dhiran id. Constrain the writable set to the non-Dhiran
        // catalogue so a hand-built request can never attach a hidden permission.
        $manageableIds = \App\Models\Permission::where('group', '!=', 'dhiran')
            ->orWhereNull('group')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $afterIds = collect($validated['permissions'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->intersect($manageableIds);

        // Preserve any Dhiran permissions the role already has — saving here must
        // neither strip nor add them; they are owned by the Dhiran product.
        $preserveIds = $role->permissions()
            ->where('permissions.group', 'dhiran')
            ->pluck('permissions.id');
        $afterIds = $afterIds->merge($preserveIds)->unique()->sort()->values()->all();

        $role->permissions()->sync($afterIds);

        // Audit the change — added/removed permission names for accountability.
        $beforeSet = collect($beforeIds);
        $afterSet = collect($afterIds);
        $addedIds = $afterSet->diff($beforeSet)->all();
        $removedIds = $beforeSet->diff($afterSet)->all();
        $added = empty($addedIds) ? [] : \App\Models\Permission::whereIn('id', $addedIds)->pluck('name')->all();
        $removed = empty($removedIds) ? [] : \App\Models\Permission::whereIn('id', $removedIds)->pluck('name')->all();

        if (! empty($added) || ! empty($removed)) {
            AuditLog::create([
                'shop_id' => auth()->user()->shop_id,
                'user_id' => auth()->id(),
                'action' => 'role_permissions_updated',
                'model_type' => 'Role',
                'model_id' => $role->id,
                'description' => sprintf(
                    'Updated permissions for %s. Added: %s. Removed: %s.',
                    $role->display_name,
                    empty($added) ? 'none' : implode(', ', $added),
                    empty($removed) ? 'none' : implode(', ', $removed)
                ),
            ]);
        }

        return redirect()->route('settings.edit', ['tab' => 'roles'])
            ->with('success', "Permissions for {$role->display_name} updated successfully.");
    }

    /**
     * Export the audit log as CSV, honoring the same action/user/date filters
     * as the Audit tab. Shop-scoped; gated by settings.view at the route. The
     * row_hash is included so an exported file is independently verifiable
     * against the tamper-evident chain.
     */
    public function exportAudit(Request $request)
    {
        $shop = Auth::user()->shop;

        $fAction = $request->string('audit_action')->value() ?: null;
        $fUser   = $request->filled('audit_user') ? (int) $request->input('audit_user') : null;
        $fFrom   = $request->filled('audit_from') ? $request->date('audit_from') : null;
        $fTo     = $request->filled('audit_to') ? $request->date('audit_to') : null;

        $shopId = (int) $shop->id;
        $filename = 'audit-log-' . $shopId . '-' . now()->format('Y-m-d') . '.csv';

        // streamDownload's closure runs AFTER the request lifecycle, when the
        // tenant middleware has already cleared TenantContext. So the closure
        // builds the query with withoutGlobalScopes() and an explicit shop_id
        // filter (which is the real tenant boundary) — otherwise BelongsToShop's
        // scope resolves a null tenant and returns zero rows.
        $build = fn () => AuditLog::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->with('user:id,name,mobile_number')
            ->when($fAction, fn ($q) => $q->where('action', $fAction))
            ->when($fUser, fn ($q) => $q->where('user_id', $fUser))
            ->when($fFrom, fn ($q) => $q->whereDate('created_at', '>=', $fFrom))
            ->when($fTo, fn ($q) => $q->whereDate('created_at', '<=', $fTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->streamDownload(function () use ($build): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders ₹ and accented names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date & Time', 'User', 'Action', 'What happened', 'Sensitive', 'Entity', 'Verification hash']);

            // chunk to keep memory flat on large logs
            $build()->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        optional($log->created_at)->format('Y-m-d H:i:s'),
                        $log->user->name ?? $log->user->mobile_number ?? 'System',
                        $log->action,
                        $log->summaryLine(),
                        $log->isSensitive() ? 'Yes' : '',
                        trim(($log->model_type ? \Illuminate\Support\Str::headline($log->model_type) : '') . ($log->model_id ? ' #' . $log->model_id : '')),
                        $log->row_hash,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Save the custom WhatsApp template.
     */
    public function saveWhatsappTemplate(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            'wa_custom_header' => 'nullable|string|max:255',
            'wa_custom_body' => 'nullable|string|max:2000',
            'wa_custom_footer' => 'nullable|string|max:255',
        ]);

        $preferences = $shop->preferences ?? ShopPreferences::firstOrCreate(['shop_id' => $shop->id]);
        $preferences->fill($validated);
        $preferences->save();

        return redirect()->back()->with('success', 'WhatsApp template saved.');
    }

    /**
     * For each available service (edition string), describe whether the owner
     * can buy it themselves now, plus the product code + monthly/yearly prices
     * the buy-now card shows. The view posts only `product` + `billing_cycle`
     * to settings.services.initiate-add, so the card only needs the product
     * code and the two prices.
     *
     * A service is `purchasable` only when its platform product is active AND it
     * has an active plan carrying a real (> 0) price for that billing cycle. The
     * monthly/yearly plan resolution mirrors ShopServicesController::planForProduct()
     * exactly (monthly = a plan with price_yearly NULL; yearly = a plan that
     * carries a price_yearly), so the card never offers a cycle the backend would
     * then reject. Anything not purchasable falls back to the request-add form.
     *
     * @param  array<int, string>  $available  edition strings (e.g. ['retailer','dhiran'])
     * @return array<string, array<string, mixed>>  keyed by edition string
     */
    private function buildServicePurchaseOptions(array $available): array
    {
        $options = [];

        foreach ($available as $edition) {
            $productCode = \App\Models\Platform\PlatformProduct::codeForEdition($edition);
            $product     = \App\Models\Platform\PlatformProduct::where('code', $productCode)->first();

            $option = [
                'edition'       => $edition,
                'product_code'  => $productCode,
                'product_name'  => $product?->name,
                'description'   => $product?->description,
                'purchasable'   => false,
                'monthly_price' => null,
                'yearly_price'  => null,
            ];

            // No product row, or an inactive ("coming soon") product → request-add only.
            if ($product && $product->is_active) {
                $plans = \App\Models\Platform\Plan::whereRaw('is_active IS TRUE')
                    ->where('platform_product_id', $product->id)
                    ->orderBy('price_monthly')
                    ->get();

                // Monthly plan: prefer a pure-monthly plan (no yearly price), else any.
                $monthlyPlan = $plans->first(fn ($p) => is_null($p->price_yearly)) ?? $plans->first();
                // Yearly plan: the first plan that actually carries a yearly price.
                $yearlyPlan  = $plans->first(fn ($p) => ! is_null($p->price_yearly));

                $monthlyPrice = $monthlyPlan && (float) $monthlyPlan->price_monthly > 0
                    ? (float) $monthlyPlan->price_monthly
                    : null;
                $yearlyPrice = $yearlyPlan && (float) $yearlyPlan->price_yearly > 0
                    ? (float) $yearlyPlan->price_yearly
                    : null;

                $option['monthly_price'] = $monthlyPrice;
                $option['yearly_price']  = $yearlyPrice;
                // Purchasable when at least one cycle has a real price to charge.
                $option['purchasable']   = $monthlyPrice !== null || $yearlyPrice !== null;
            }

            $options[$edition] = $option;
        }

        return $options;
    }
}
