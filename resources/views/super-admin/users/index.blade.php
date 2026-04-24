<x-super-admin.layout>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-white">All Users</h3>
            <p class="text-sm text-slate-400">Global identity controls for shops and platform admins.</p>
        </div>
    </div>

    <div class="admin-panel p-4 mb-4">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="min-w-[220px] flex-1">
                <label class="block text-sm mb-1 text-slate-300">Search User</label>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Mobile / Name / Email"
                       class="admin-control">
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">Status</label>
                <select name="status" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">Scope</label>
                <select name="scope" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="shop_users" @selected(request('scope') === 'shop_users')>Shop Users</option>
                </select>
            </div>
            <button class="admin-btn admin-btn-primary">Apply</button>
        </form>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">User</th>
                        <th class="px-4 py-2 text-left">Mobile</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3">{{ trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->name ?? '-') }}</td>
                            <td class="px-4 py-3">{{ $user->mobile_number }}</td>
                            <td class="px-4 py-3">
                                <span class="admin-badge admin-badge-sky">Shop User</span>
                            </td>
                            <td class="px-4 py-3">{{ $user->shop?->name ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <span class="admin-badge {{ $user->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.users.show', $user) }}" class="admin-btn admin-btn-secondary admin-btn-xs">Inspect</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="6">No users found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $users->links() }}</div>
    </div>
</x-super-admin.layout>
