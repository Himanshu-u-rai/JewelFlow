<x-super-admin.layout>
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-white">Platform Admins</h3>
            <p class="text-sm text-slate-400">Access governance accounts for control-tower operations.</p>
        </div>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="admin-table-wrap">
        <table class="w-full text-sm admin-table">
            <thead class="bg-slate-800/80 text-slate-300">
                <tr>
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">Mobile</th>
                    <th class="px-4 py-2 text-left">Role</th>
                    <th class="px-4 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($platformAdmins as $admin)
                    <tr class="border-t border-slate-800 text-slate-200">
                        <td class="px-4 py-3">{{ $admin->name }}</td>
                        <td class="px-4 py-3">{{ $admin->mobile_number }}</td>
                        <td class="px-4 py-3"><span class="admin-badge admin-badge-sky">{{ $admin->role }}</span></td>
                        <td class="px-4 py-3">
                            <span class="admin-badge {{ $admin->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                                {{ $admin->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $platformAdmins->links() }}</div>
    </div>
</x-super-admin.layout>
