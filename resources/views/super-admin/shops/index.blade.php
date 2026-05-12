<x-super-admin.layout>
    <div x-data="shopBulk()" x-init="init()">

    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Shop Management</h3>
            <p class="text-sm text-slate-400">Search, inspect, activate, and deactivate tenant shops.</p>
        </div>
        <a href="{{ route('admin.shops.export') }}" class="admin-btn admin-btn-secondary admin-btn-sm">
            ↓ Export CSV
        </a>
    </div>

    {{-- Search / Filter bar --}}
    <div class="admin-panel p-4 mb-4">
        <form method="GET" class="admin-filter-bar">
            <div class="admin-filter-field">
                <label class="block text-sm mb-1 text-slate-300">Search Shop</label>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Name / Phone / Owner Mobile"
                       class="admin-control">
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Status</label>
                <select name="status" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Type</label>
                <select name="type" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="retailer" @selected(request('type') === 'retailer')>Retail</option>
                    <option value="manufacturer" @selected(request('type') === 'manufacturer')>Manufacturer</option>
                </select>
            </div>
            <button class="admin-btn admin-btn-primary">Apply</button>
        </form>
    </div>

    {{-- Shop table --}}
    <div class="admin-panel overflow-hidden">
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-3 py-2 w-8">
                            <input type="checkbox" @change="toggleAll($event)"
                                   :checked="allSelected" :indeterminate.prop="someSelected && !allSelected"
                                   class="rounded border-slate-600 bg-slate-700 text-indigo-500 cursor-pointer">
                        </th>
                        <th class="px-4 py-2 text-left text-slate-400 font-medium w-24">Code</th>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Owner</th>
                        <th class="px-4 py-2 text-left">Phone</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Users</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shops as $shop)
                        <tr class="border-t border-slate-800 text-slate-200"
                            :class="{ 'bg-indigo-900/20': selected.includes({{ $shop->id }}) }">
                            <td class="px-3 py-3">
                                <input type="checkbox"
                                       :checked="selected.includes({{ $shop->id }})"
                                       @change="toggle({{ $shop->id }})"
                                       class="rounded border-slate-600 bg-slate-700 text-indigo-500 cursor-pointer"
                                       value="{{ $shop->id }}">
                            </td>
                            <td class="px-4 py-3 text-slate-300 font-mono text-xs font-medium">{{ $shop->shop_code }}</td>
                            <td class="px-4 py-3 font-medium">{{ $shop->name }}</td>
                            <td class="px-4 py-3">{{ trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? '')) ?: ($shop->owner_name ?? '-') }}</td>
                            <td class="px-4 py-3">{{ $shop->phone }}</td>
                            <td class="px-4 py-3">{{ $shop->shop_type === 'retailer' ? 'Retail' : 'Manufacturer' }}</td>
                            <td class="px-4 py-3">{{ $shop->users_count }}</td>
                            <td class="px-4 py-2">
                                <span class="admin-badge {{ $shop->access_mode === 'suspended' ? 'admin-badge-rose' : ($shop->access_mode === 'read_only' ? 'admin-badge-amber' : 'admin-badge-emerald') }}">
                                    {{ $shop->access_mode === 'read_only' ? 'Read Only' : ucfirst($shop->access_mode ?? 'active') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.shops.show', ['shop' => $shop->id]) }}"
                                   class="admin-btn admin-btn-secondary admin-btn-xs">Inspect</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="9">No shops found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $shops->links() }}</div>
    </div>

    {{-- Bulk action bar — slides up from bottom when rows are selected --}}
    <div x-show="selected.length > 0"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-4 opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-4 opacity-0"
         style="display:none"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-full max-w-3xl px-4">

        <form method="POST" action="{{ route('admin.shops.bulk') }}" @submit.prevent="submitBulk($event)">
            @csrf
            <div id="bulkShopIdsContainer"></div>

            <div class="bg-slate-800 border border-slate-600 rounded-xl shadow-2xl ring-1 ring-black/30 px-4 py-3 flex items-center gap-3 flex-wrap">

                {{-- Count badge --}}
                <div class="flex items-center gap-2 shrink-0">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-600 text-white text-xs font-bold" x-text="selected.length"></span>
                    <span class="text-sm text-slate-300">shop<span x-show="selected.length !== 1">s</span></span>
                    <button type="button" @click="selected = []"
                            class="text-xs text-slate-500 hover:text-slate-300 transition-colors ml-1">
                        ✕ clear
                    </button>
                </div>

                <div class="w-px h-6 bg-slate-700 shrink-0 hidden sm:block"></div>

                {{-- Action picker --}}
                <div class="flex items-center gap-2 flex-1 min-w-[160px]">
                    <select x-model="action" name="action"
                            class="admin-control admin-select flex-1 text-sm py-1.5">
                        <option value="suspend">🔒 Suspend</option>
                        <option value="unsuspend">✅ Activate</option>
                        <option value="assign_plan">📋 Assign Plan</option>
                        <option value="send_email">✉️ Send Email</option>
                    </select>
                </div>

                {{-- Plan picker (assign_plan only) --}}
                <div x-show="action === 'assign_plan'" x-cloak class="flex-1 min-w-[160px]">
                    <select name="plan_id" class="admin-control admin-select w-full text-sm py-1.5">
                        <option value="">— Select plan —</option>
                        @foreach($plans ?? [] as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }} (₹{{ number_format($plan->price_monthly ?? 0) }}/mo)</option>
                        @endforeach
                    </select>
                </div>

                {{-- Email fields (send_email only) --}}
                <div x-show="action === 'send_email'" x-cloak class="flex-1 min-w-[200px] space-y-1.5">
                    <input type="text" name="email_subject" placeholder="Subject" class="admin-control w-full text-sm py-1.5" maxlength="255">
                    <textarea name="email_body" rows="2" placeholder="Message body" class="admin-control w-full text-sm py-1.5" maxlength="5000"></textarea>
                </div>

                {{-- Submit --}}
                <button type="submit" class="admin-btn admin-btn-primary shrink-0 text-sm py-1.5 px-4">
                    Apply
                </button>
            </div>
        </form>
    </div>

    </div>{{-- end x-data --}}

<script>
function shopBulk() {
    return {
        selected: [],
        action: 'suspend',
        allIds: @json($shops->pluck('id')->toArray()),

        init() {},

        get allSelected() {
            return this.allIds.length > 0 && this.allIds.every(id => this.selected.includes(id));
        },
        get someSelected() {
            return this.selected.length > 0;
        },

        toggle(id) {
            const idx = this.selected.indexOf(id);
            if (idx === -1) {
                this.selected.push(id);
            } else {
                this.selected.splice(idx, 1);
            }
        },

        toggleAll(event) {
            if (event.target.checked) {
                this.selected = [...this.allIds];
            } else {
                this.selected = [];
            }
        },

        submitBulk(event) {
            if (!confirm(`Run "${this.action}" on ${this.selected.length} shop(s)? This cannot be undone.`)) return;

            const container = document.getElementById('bulkShopIdsContainer');
            container.innerHTML = '';
            this.selected.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'shop_ids[]';
                input.value = id;
                container.appendChild(input);
            });

            event.target.submit();
        },
    };
}
</script>
</x-super-admin.layout>
