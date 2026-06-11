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
            'payment-methods' => 'settings.view',
            'preferences'     => 'settings.view',
            'return-policy'   => 'settings.view',
            'pricing'         => 'pricing.update',
            'materials'       => 'settings.view',
            'website'         => 'settings.view',
            'roles'           => '__owner_only__',
            'staff'           => 'staff.view',
            'audit'           => 'settings.view',
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
        if ($activeTab === 'audit') {
            $logs = AuditLog::where('shop_id', $shop->id)
                ->orderBy('created_at', 'desc')
                ->paginate(50);

            $stats = [
                'total' => AuditLog::where('shop_id', $shop->id)->count(),
                'today' => AuditLog::where('shop_id', $shop->id)
                    ->whereDate('created_at', now()->toDateString())
                    ->count(),
            ];
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
                'history_rows' => $pricing->resolvedRateHistory($shop, $historyFilters, 50),
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
            'catalogWebsiteSettings',
            'catalogPages',
            'pricingData',
            'pricingTimezones',
            'methods',
            'user',
            'gstCategories',
            'gstEnabledMetals'
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
            'gst_number'                => 'nullable|string|max:50',
            'owner_first_name'          => 'required|string|max:255',
            'owner_last_name'           => 'required|string|max:255',
            'owner_mobile'              => 'required|string|digits:10',
            'owner_email'               => 'nullable|email|max:255',
            'gst_rate'                  => 'required|numeric|min:0|max:100',
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

        // Build full address for backwards compatibility
        $validated['address'] = trim(
            $validated['address_line1'] . ', ' .
            ($validated['address_line2'] ? $validated['address_line2'] . ', ' : '') .
            $validated['city'] . ', ' .
            $validated['state'] . ' - ' .
            $validated['pincode']
        );

        $shop->update($validated);

        // Keep the owner user's display name in sync with shop owner details
        $ownerUser = \App\Models\User::where('shop_id', $shop->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
            ->first();
        if ($ownerUser) {
            $ownerUser->name = trim($validated['owner_first_name'] . ' ' . $validated['owner_last_name']);
            $ownerUser->save();
        }

        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('shop-logos', 'public');
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
            // Tax
            'igst_mode'              => 'nullable|boolean',
            'hsn_gold'               => 'nullable|string|max:20',
            'hsn_silver'             => 'nullable|string|max:20',
            'hsn_diamond'            => 'nullable|string|max:20',
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
            $validated['digital_signature_path'] = $request->file('digital_signature')
                ->store('signatures', 'public');
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
        $validated['igst_mode']              = $request->boolean('igst_mode');

        // Defaults for optional fields
        $validated['theme_color']  = $validated['theme_color']  ?? '#111111';
        $validated['font_size']    = $validated['font_size']    ?? 'normal';
        $validated['paper_size']   = $validated['paper_size']   ?? 'a4';
        $validated['copy_count']   = $validated['copy_count']   ?? 1;
        $validated['invoice_copy_label'] = $validated['invoice_copy_label'] ?? 'Original';
        $validated['hsn_gold']     = $validated['hsn_gold']     ?: '7113';
        $validated['hsn_silver']   = $validated['hsn_silver']   ?: '7113';
        $validated['hsn_diamond']  = $validated['hsn_diamond']  ?: '7114';

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

        foreach (\App\Services\MetalRegistry::tier2Metals() as $metal) {
            $enabled = (bool) ($submitted[$metal] ?? false);

            // shop_enabled_metals has no Eloquent model; use the query builder.
            // PostgreSQL rejects integer 0/1 for boolean columns, so the flag is
            // a raw boolean literal (see CLAUDE.md).
            DB::table('shop_enabled_metals')->updateOrInsert(
                ['shop_id' => $shop->id, 'metal_type' => $metal],
                ['enabled' => DB::raw($enabled ? 'true' : 'false'), 'updated_at' => now(), 'created_at' => now()]
            );
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
        // Owner role cannot be modified — Gate::before short-circuits anyway,
        // but the UI lets the form be submitted; this is the server-side guard.
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
        $afterIds = collect($validated['permissions'] ?? [])->map(fn ($id) => (int) $id);

        // Dhiran permissions are hidden from the JewelFlow Roles UI (separate
        // product). They are therefore never present in this form's payload —
        // preserve any the role already has so saving here never strips them.
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
}
