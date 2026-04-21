<x-super-admin.layout>
    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">All Shops</h3>
            <p class="text-sm text-slate-400">Tenant registry with activation controls.</p>
        </div>
    </div>

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

    <div class="admin-panel overflow-hidden">
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
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
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 font-medium">{{ $shop->name }}</td>
                            <td class="px-4 py-3">{{ trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? '')) ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $shop->phone }}</td>
                            <td class="px-4 py-3">{{ $shop->shop_type === 'retailer' ? 'Retail' : 'Manufacturer' }}</td>
                            <td class="px-4 py-3">{{ $shop->users_count }}</td>
                            <td class="px-4 py-2">
                                <span class="admin-badge {{ $shop->access_mode === 'suspended' ? 'admin-badge-rose' : ($shop->access_mode === 'read_only' ? 'admin-badge-amber' : 'admin-badge-emerald') }}">
                                    {{ $shop->access_mode === 'read_only' ? 'Read Only' : ucfirst($shop->access_mode ?? 'active') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.shops.show', ['shop' => $shop->id]) }}" class="admin-btn admin-btn-secondary admin-btn-xs">Inspect</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="7">No shops found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $shops->links() }}</div>
    </div>
</x-super-admin.layout>
