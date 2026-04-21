@csrf
@php
    $featureCatalog = [
        'pos' => ['label' => 'Point of Sale', 'type' => 'boolean'],
        'inventory' => ['label' => 'Inventory Management', 'type' => 'boolean'],
        'customers' => ['label' => 'Customer Management', 'type' => 'boolean'],
        'repairs' => ['label' => 'Repair Tracking', 'type' => 'boolean'],
        'invoices' => ['label' => 'Invoicing & Billing', 'type' => 'boolean'],
        'reports' => ['label' => 'Business Reports', 'type' => 'boolean'],
        'vendors' => ['label' => 'Vendor Management', 'type' => 'boolean'],
        'schemes' => ['label' => 'Gold Saving Schemes', 'type' => 'boolean'],
        'loyalty' => ['label' => 'Loyalty Points', 'type' => 'boolean'],
        'installments' => ['label' => 'EMI / Installments', 'type' => 'boolean'],
        'reorder_alerts' => ['label' => 'Reorder Alerts', 'type' => 'boolean'],
        'tag_printing' => ['label' => 'Tag Printing', 'type' => 'boolean'],
        'whatsapp_catalog' => ['label' => 'WhatsApp Catalog', 'type' => 'boolean'],
        'bulk_imports' => ['label' => 'Bulk Imports (CSV)', 'type' => 'boolean'],
        'gold_inventory' => ['label' => 'Gold Lot Inventory', 'type' => 'boolean'],
        'manufacturing' => ['label' => 'Manufacturing Workflow', 'type' => 'boolean'],
        'customer_gold' => ['label' => 'Customer Gold Ledger', 'type' => 'boolean'],
        'exchange' => ['label' => 'Gold Exchange', 'type' => 'boolean'],
        'public_catalog' => ['label' => 'Public Item Catalog', 'type' => 'boolean'],
        'staff_limit' => ['label' => 'Staff Accounts', 'type' => 'number'],
        'max_items' => ['label' => 'Item Limit', 'type' => 'number'],
    ];
    $initialFeatures = old('features', isset($plan) ? json_encode($plan->features, JSON_PRETTY_PRINT) : '{}');
    $inputClass = 'admin-control mt-2';
    $labelClass = 'block text-sm font-medium text-slate-200';
    $helpClass = 'text-sm text-slate-400 mt-1';
@endphp
<div
    class="space-y-6"
    x-data="{
        featureCatalog: @js($featureCatalog),
        featureState: {},
        syncTextarea() {
            const payload = {};
            Object.entries(this.featureCatalog).forEach(([key, config]) => {
                const value = this.featureState[key];
                if (config.type === 'boolean') {
                    if (value) payload[key] = true;
                } else if (value !== '' && value !== null && value !== undefined) {
                    payload[key] = Number(value);
                }
            });
            this.$refs.features.value = JSON.stringify(payload, null, 2);
        },
        init() {
            let parsed = {};
            try {
                parsed = JSON.parse(this.$refs.features.value || '{}') || {};
            } catch (e) {
                parsed = {};
            }
            Object.entries(this.featureCatalog).forEach(([key, config]) => {
                if (config.type === 'boolean') {
                    this.featureState[key] = Boolean(parsed[key]);
                } else {
                    this.featureState[key] = parsed[key] ?? '';
                }
            });
            this.syncTextarea();
        }
    }"
>
    <div class="admin-form-grid admin-form-grid-2">
        <div class="form-group">
            <label for="name" class="{{ $labelClass }}">Plan Name</label>
            <input type="text" id="name" name="name" class="{{ $inputClass }}" value="{{ old('name', $plan->name ?? '') }}" required>
        </div>
        <div class="form-group">
            <label for="code" class="{{ $labelClass }}">Plan Code</label>
            <input type="text" id="code" name="code" class="{{ $inputClass }}" value="{{ old('code', $plan->code ?? '') }}" 
                   @isset($plan) disabled @endisset required>
            @isset($plan)
            <p class="{{ $helpClass }}">Plan code cannot be changed after creation.</p>
            @endisset
        </div>
    </div>
    <div class="admin-form-grid admin-form-grid-2">
        <div class="form-group">
            <label for="price_monthly" class="{{ $labelClass }}">Monthly Price</label>
            <input type="number" id="price_monthly" name="price_monthly" class="{{ $inputClass }}" value="{{ old('price_monthly', $plan->price_monthly ?? '') }}" step="0.01" required>
        </div>
        <div class="form-group">
            <label for="price_yearly" class="{{ $labelClass }}">Yearly Price (optional)</label>
            <input type="number" id="price_yearly" name="price_yearly" class="{{ $inputClass }}" value="{{ old('price_yearly', $plan->price_yearly ?? '') }}" step="0.01">
        </div>
    </div>
     <div class="admin-form-grid admin-form-grid-2">
        <div class="form-group">
            <label for="grace_days" class="{{ $labelClass }}">Grace Days</label>
            <input type="number" id="grace_days" name="grace_days" class="{{ $inputClass }}" value="{{ old('grace_days', $plan->grace_days ?? 7) }}" required>
        </div>
        <div class="form-group">
            <label for="trial_days" class="{{ $labelClass }}">Trial Days</label>
            <input type="number" id="trial_days" name="trial_days" class="{{ $inputClass }}" value="{{ old('trial_days', $plan->trial_days ?? 0) }}" min="0" max="365" required>
            <p class="{{ $helpClass }}">0 = no trial period. Set by admin per plan.</p>
        </div>
        <div class="admin-form-fieldset md:col-span-2">
             <p class="admin-form-fieldset-title">Access Behaviour</p>
             <label for="is_active" class="{{ $labelClass }}">Status</label>
            <div class="mt-2 flex space-x-8">
                <div class="flex items-center">
                    <input id="is_active_true" name="is_active" type="radio" value="1" @if(old('is_active', $plan->is_active ?? true)) checked @endif class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-500">
                    <label for="is_active_true" class="ml-2 block text-sm text-slate-300">Active</label>
                </div>
                <div class="flex items-center">
                    <input id="is_active_false" name="is_active" type="radio" value="0" @if(!old('is_active', $plan->is_active ?? true)) checked @endif class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-500">
                    <label for="is_active_false" class="ml-2 block text-sm text-slate-300">Inactive</label>
                </div>
            </div>
             <label for="downgrade_to_read_only_on_due" class="{{ $labelClass }} mt-4">On Due</label>
             <div class="mt-2 flex space-x-8">
                <div class="flex items-center">
                    <input id="downgrade_true" name="downgrade_to_read_only_on_due" type="radio" value="1" @if(old('downgrade_to_read_only_on_due', $plan->downgrade_to_read_only_on_due ?? true)) checked @endif class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-500">
                    <label for="downgrade_true" class="ml-2 block text-sm text-slate-300">Downgrade to Read-Only</label>
                </div>
                <div class="flex items-center">
                    <input id="downgrade_false" name="downgrade_to_read_only_on_due" type="radio" value="0" @if(!old('downgrade_to_read_only_on_due', $plan->downgrade_to_read_only_on_due ?? true)) checked @endif class="h-4 w-4 text-amber-600 focus:ring-amber-500 border-gray-500">
                    <label for="downgrade_false" class="ml-2 block text-sm text-slate-300">Suspend Access</label>
                </div>
            </div>
        </div>
    </div>
    {{-- ── Usage Limits (dedicated section, always visible) ─────────────── --}}
    <div class="rounded-lg border-2 border-amber-600/40 bg-amber-950/30 p-5 space-y-4">
        <div>
            <h3 class="text-base font-semibold text-amber-300">Usage Limits</h3>
            <p class="{{ $helpClass }}">Set the maximum number of staff accounts and inventory items allowed on this plan. Use <strong class="text-slate-300">-1</strong> for unlimited.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="{{ $labelClass }}">
                    Staff Account Limit
                    <span class="ml-1 text-xs font-normal text-amber-400">(non-owner users per shop)</span>
                </label>
                <input
                    type="number" min="-1" step="1"
                    class="{{ $inputClass }} border-amber-600/50 focus:border-amber-400 text-lg font-semibold"
                    x-model="featureState['staff_limit']"
                    @input="syncTextarea()"
                    placeholder="e.g. 5"
                >
                <p class="{{ $helpClass }}">
                    Examples: <code class="text-slate-300">3</code> = 3 staff · <code class="text-slate-300">10</code> = 10 staff · <code class="text-slate-300">-1</code> = unlimited
                </p>
            </div>
            <div>
                <label class="{{ $labelClass }}">
                    Inventory Item Limit
                    <span class="ml-1 text-xs font-normal text-amber-400">(total items per shop)</span>
                </label>
                <input
                    type="number" min="-1" step="1"
                    class="{{ $inputClass }} border-amber-600/50 focus:border-amber-400 text-lg font-semibold"
                    x-model="featureState['max_items']"
                    @input="syncTextarea()"
                    placeholder="e.g. 2000"
                >
                <p class="{{ $helpClass }}">
                    Examples: <code class="text-slate-300">2000</code> = 2K items · <code class="text-slate-300">5000</code> = 5K items · <code class="text-slate-300">-1</code> = unlimited
                </p>
            </div>
        </div>
    </div>

    {{-- ── Feature Builder (boolean toggles only) ───────────────────────── --}}
    <div class="space-y-4">
        <div>
            <label class="{{ $labelClass }}">Feature Toggles</label>
            <p class="{{ $helpClass }}">Enable or disable individual features for this plan.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 rounded-lg border border-slate-700 bg-slate-900/50 p-4">
            <template x-for="[key, config] in Object.entries(featureCatalog).filter(([k,c]) => c.type === 'boolean')" :key="key">
                <div class="rounded-lg border border-slate-700 bg-slate-800/70 p-4">
                    <label class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-slate-200" x-text="config.label"></span>
                        <input type="checkbox" class="h-4 w-4 rounded border-gray-500 text-amber-600 focus:ring-amber-500" x-model="featureState[key]" @change="syncTextarea()">
                    </label>
                </div>
            </template>
        </div>
    </div>
    <div class="form-group">
        <label for="features" class="{{ $labelClass }}">Features (JSON)</label>
        <textarea id="features" name="features" rows="12" class="{{ $inputClass }} w-full font-mono min-h-[280px] admin-textarea" x-ref="features">{{ $initialFeatures }}</textarea>
        <p class="{{ $helpClass }}">Enter a valid JSON object of features.</p>
    </div>
</div>

<div class="admin-form-actions">
    <a href="{{ route('admin.plans.index') }}" class="admin-btn admin-btn-secondary">Cancel</a>
    <button type="submit" class="admin-btn admin-btn-primary">
        {{ isset($plan) ? 'Update Plan' : 'Create Plan' }}
    </button>
</div>
