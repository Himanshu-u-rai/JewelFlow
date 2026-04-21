<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use App\Models\ShopRules;
use App\Models\ShopBillingSettings;
use App\Models\ShopPreferences;
use App\Models\ShopCounter;
use App\Models\Invoice;
use App\Models\AuditLog;
use App\Services\BusinessIdentifierService;
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
        
        // Get or create related settings
        $rules = $shop->rules ?? ShopRules::create(['shop_id' => $shop->id]);
        $billing = $shop->billingSettings ?? ShopBillingSettings::create(['shop_id' => $shop->id]);
        $preferences = $shop->preferences ?? ShopPreferences::create(['shop_id' => $shop->id]);

        // Get roles and permissions for RBAC tab
        $roles = Role::with('permissions')->get();
        $permissions = Permission::orderBy('group')->orderBy('name')->get();
        $permissionGroups = $permissions->groupBy('group');

        // Get staff for Staff tab
        $staff = User::with('role')
            ->where('shop_id', $shop->id)
            ->orderByRaw('COALESCE(name, mobile_number) ASC')
            ->get();

        $staffLimit = $shop->staffLimit();
        $staffCount = $shop->currentStaffCount();

        // Get active tab from query string
        $activeTab = $request->get('tab', 'shop');

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

        return view('settings', compact('shop', 'rules', 'billing', 'preferences', 'roles', 'permissions', 'permissionGroups', 'staff', 'staffLimit', 'staffCount', 'activeTab', 'logs', 'stats', 'catalogWebsiteSettings', 'catalogPages'));
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
     * Update gold & POS calculation rules.
     */
    public function updateRules(Request $request)
    {
        $shop = Auth::user()->shop;

        $validated = $request->validate([
            'default_purity'       => 'required|string|in:24K,22K,18K,14K',
            'default_making_type'  => 'required|in:per_gram,percent',
            'default_making_value' => 'required|numeric|min:0',
            'test_loss_percent'    => 'required|numeric|min:0|max:100',
            'buyback_percent'      => 'required|numeric|min:0|max:100',
            'rounding_precision'   => 'required|integer|min:0|max:4',
            // Per-category GST rates
            'gst_rate_gold'        => 'nullable|numeric|min:0|max:100',
            'gst_rate_silver'      => 'nullable|numeric|min:0|max:100',
            'gst_rate_diamond'     => 'nullable|numeric|min:0|max:100',
            // Wastage rounding
            'wastage_rounding'     => 'nullable|string|in:0.001,0.01,0.1,1',
        ]);

        $rules = $shop->rules ?? new ShopRules(['shop_id' => $shop->id]);
        $rules->fill($validated);
        $rules->save();

        return redirect()->route('settings.edit', ['tab' => 'rules'])
            ->with('success', 'Gold & POS rules updated successfully.');
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
     * Update UI/behavior preferences.
     */
    public function updatePreferences(Request $request)
    {
        $shop = Auth::user()->shop;
        $supportedLocales = array_keys(config('app.supported_locales', ['en' => 'English']));

        $validated = $request->validate([
            'weight_unit'                => 'required|in:grams,tola',
            'date_format'                => 'required|string|in:d/m/Y,m/d/Y,Y-m-d',
            'currency_symbol'            => 'required|string|max:5',
            'language'                   => ['required', 'string', Rule::in($supportedLocales)],
            'low_stock_threshold'        => 'required|integer|min:0|max:100',
            'round_off_nearest'          => 'required|integer|in:1,10,100',
            'loyalty_points_per_hundred' => 'required|integer|min:0|max:100',
            'loyalty_point_value'        => 'required|numeric|min:0|max:100',
            'loyalty_expiry_months'      => 'required|integer|in:0,6,12,18,24',
            // New general settings
            'default_pricing_mode'       => 'nullable|string|in:no_gst,gst_exclusive,gst_inclusive',
            'default_payment_mode'       => 'nullable|string|in:cash,card,upi,bank_transfer,cheque',
            'auto_logout_minutes'        => 'nullable|integer|min:0|max:480',
            'loyalty_welcome_bonus'      => 'nullable|integer|min:0|max:99999',
            'credit_days'                => 'nullable|integer|min:0|max:365',
            'barcode_prefix'             => 'nullable|string|max:20',
        ]);

        // Defaults for new fields
        $validated['default_pricing_mode'] = $validated['default_pricing_mode'] ?? 'gst_exclusive';
        $validated['default_payment_mode'] = $validated['default_payment_mode'] ?? 'cash';
        $validated['auto_logout_minutes']  = $validated['auto_logout_minutes']  ?? 0;
        $validated['loyalty_welcome_bonus']= $validated['loyalty_welcome_bonus'] ?? 0;
        $validated['credit_days']          = $validated['credit_days']          ?? 0;

        $preferences = $shop->preferences ?? new ShopPreferences(['shop_id' => $shop->id]);
        $preferences->fill($validated);
        $preferences->save();

        return redirect()->route('settings.edit', ['tab' => 'preferences'])
            ->with('success', 'Preferences updated successfully.');
    }

    /**
     * Update role permissions.
     */
    public function updateRolePermissions(Request $request, Role $role)
    {
        // Owner role cannot be modified
        if ($role->name === 'owner') {
            return redirect()->route('settings.edit', ['tab' => 'roles'])
                ->with('error', 'Owner role permissions cannot be modified.');
        }

        $validated = $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->permissions()->sync($validated['permissions'] ?? []);

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
