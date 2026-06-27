<x-super-admin.layout>
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-white">Platform Admins</h3>
            <p class="text-sm text-slate-400">Access governance accounts for control-tower operations.</p>
        </div>
        <button onclick="document.getElementById('create-admin-panel').classList.toggle('hidden')"
                class="admin-btn admin-btn-primary admin-btn-sm">
            + New Admin
        </button>
    </div>

@if($errors->any())
        <div class="mb-4 rounded-md bg-rose-900/40 border border-rose-700 px-4 py-2 text-sm text-rose-300">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Create Admin Form ────────────────────────────────────────────── --}}
    <div id="create-admin-panel" class="admin-panel p-5 mb-5 hidden">
        <h4 class="text-sm font-semibold text-slate-200 mb-4 pb-3 border-b border-slate-700">Create Platform Admin</h4>
        <form method="POST" action="{{ route('admin.platform-admins.store') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs text-slate-400 mb-1">First Name</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           class="admin-control w-full" placeholder="Ravi">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Last Name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           class="admin-control w-full" placeholder="Sharma">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Mobile Number</label>
                    <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" required
                           maxlength="10" class="admin-control w-full" placeholder="10-digit mobile">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Email <span class="text-slate-500">(optional)</span></label>
                    <input type="email" name="email" value="{{ old('email') }}"
                           class="admin-control w-full" placeholder="admin@jewelflow.in">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Password</label>
                    <input type="password" name="password" required minlength="8"
                           class="admin-control w-full" autocomplete="new-password">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Confirm Password</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                           class="admin-control w-full" autocomplete="new-password">
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Role</label>
                    <select name="role" class="admin-control admin-select w-full">
                        <option value="platform_operator" {{ old('role') === 'platform_operator' ? 'selected' : '' }}>Platform Operator</option>
                        <option value="super_admin" {{ old('role') === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Super admin has full access. Platform operator is read-only + limited actions.</p>
                </div>

            </div>

            <div class="flex items-center gap-3 mt-4 pt-3 border-t border-slate-700">
                <button type="submit" class="admin-btn admin-btn-primary">Create Admin</button>
                <button type="button" onclick="document.getElementById('create-admin-panel').classList.add('hidden')"
                        class="admin-btn admin-btn-secondary">Cancel</button>
            </div>
        </form>
    </div>

    {{-- ── Admin Table ──────────────────────────────────────────────────── --}}
    {{-- overflow:visible so the row "Password ▾" reset dropdown isn't clipped under the table.
         (admin-table-wrap's overflow-x:auto would otherwise clip it vertically too.) --}}
    <div class="admin-panel">
        <div class="admin-table-wrap" style="overflow: visible;">
        <table class="w-full text-sm admin-table">
            <thead class="bg-slate-800/80 text-slate-300">
                <tr>
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">Mobile</th>
                    <th class="px-4 py-2 text-left">Email</th>
                    <th class="px-4 py-2 text-left">Role</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Permissions</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($platformAdmins as $admin)
                    @php $isSelf = auth('platform_admin')->id() === $admin->id; @endphp
                    <tr class="border-t border-slate-800 text-slate-200 align-top">
                        <td class="px-4 py-3 font-medium">
                            {{ $admin->name }}
                            @if($isSelf)
                                <span class="ml-1 text-xs text-indigo-400">(you)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $admin->mobile_number }}</td>
                        <td class="px-4 py-3 text-slate-400 text-xs">{{ $admin->email ?: '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="admin-badge admin-badge-sky">{{ $admin->role }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="admin-badge {{ $admin->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                                {{ $admin->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>

                        {{-- Permissions --}}
                        <td class="px-4 py-3 min-w-[220px]">
                            @if($admin->isSuperAdmin())
                                <span class="text-xs text-amber-300 font-medium">All permissions</span>
                            @else
                                <details class="group">
                                    <summary class="flex cursor-pointer list-none items-center justify-between rounded-md bg-slate-800/60 px-3 py-2 hover:bg-slate-700/60 transition-colors">
                                        @php
                                            $adminPerms = $admin->permissions ?? [];
                                            $permCount  = count($adminPerms);
                                        @endphp
                                        <span class="text-xs text-slate-300">
                                            {{ $permCount > 0 ? $permCount . ' permission' . ($permCount !== 1 ? 's' : '') : 'No permissions' }}
                                        </span>
                                        <span class="text-xs text-sky-400 group-open:hidden ml-2">Edit ▾</span>
                                        <span class="text-xs text-sky-400 hidden group-open:inline ml-2">Close ▴</span>
                                    </summary>

                                    <form method="POST"
                                          action="{{ route('admin.platform-admins.permissions', $admin) }}"
                                          class="mt-2 rounded-md border border-slate-700 bg-slate-900/60 p-3 space-y-3">
                                        @csrf
                                        @method('PATCH')

                                        <div class="grid grid-cols-1 gap-1.5">
                                            @foreach($permissions as $perm)
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           name="permissions[]"
                                                           value="{{ $perm }}"
                                                           {{ in_array($perm, $adminPerms) ? 'checked' : '' }}
                                                           class="h-3.5 w-3.5 rounded border-slate-600 bg-slate-800 text-sky-500">
                                                    <span class="text-xs text-slate-300 font-mono">{{ $perm }}</span>
                                                </label>
                                            @endforeach
                                        </div>

                                        <input type="text" name="reason" maxlength="500"
                                               placeholder="Reason (optional, audit log)"
                                               class="admin-control w-full text-xs">

                                        <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs">
                                            Save Permissions
                                        </button>
                                    </form>
                                </details>
                            @endif
                        </td>

                        {{-- Actions --}}
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1 flex-wrap">

                                {{-- Toggle active/inactive --}}
                                @if(!$isSelf)
                                    <form method="POST"
                                          action="{{ route('admin.platform-admins.status', $admin) }}"
                                          onsubmit="return confirm('{{ $admin->is_active ? 'Deactivate' : 'Activate' }} this admin?')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $admin->is_active ? '0' : '1' }}">
                                        <button type="submit"
                                                class="admin-btn admin-btn-xs {{ $admin->is_active ? 'admin-btn-secondary' : 'admin-btn-primary' }}">
                                            {{ $admin->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endif

                                {{-- Change role (super admins only, can't demote self) --}}
                                @if(!$isSelf)
                                    <details class="relative">
                                        <summary class="admin-btn admin-btn-secondary admin-btn-xs list-none cursor-pointer">
                                            Role ▾
                                        </summary>
                                        <div class="absolute right-0 top-full mt-1 z-20 w-52 rounded-md border border-slate-600 bg-slate-800 shadow-xl p-3">
                                            <form method="POST" action="{{ route('admin.platform-admins.role', $admin) }}">
                                                @csrf
                                                @method('PATCH')
                                                <label class="block text-xs text-slate-400 mb-1">Change Role</label>
                                                <select name="role" class="admin-control admin-select w-full text-xs mb-2">
                                                    <option value="platform_operator" {{ $admin->role === 'platform_operator' ? 'selected' : '' }}>Platform Operator</option>
                                                    <option value="super_admin" {{ $admin->role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                                                </select>
                                                <input type="text" name="reason" placeholder="Reason (optional)"
                                                       class="admin-control w-full text-xs mb-2" maxlength="500">
                                                <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs w-full">
                                                    Update Role
                                                </button>
                                            </form>
                                        </div>
                                    </details>
                                @endif

                                {{-- Reset password --}}
                                <details class="relative">
                                    <summary class="admin-btn admin-btn-secondary admin-btn-xs list-none cursor-pointer">
                                        Password ▾
                                    </summary>
                                    <div class="absolute right-0 top-full mt-1 z-20 w-64 rounded-md border border-slate-600 bg-slate-800 shadow-xl p-3">
                                        <form method="POST" action="{{ route('admin.platform-admins.password', $admin) }}">
                                            @csrf
                                            @method('PATCH')
                                            <label class="block text-xs text-slate-400 mb-1">New Password</label>
                                            <input type="password" name="password" required minlength="8"
                                                   class="admin-control w-full text-xs mb-2"
                                                   autocomplete="new-password" placeholder="Min 8 characters">
                                            <input type="password" name="password_confirmation" required minlength="8"
                                                   class="admin-control w-full text-xs mb-2"
                                                   autocomplete="new-password" placeholder="Confirm password">
                                            <input type="text" name="reason" placeholder="Reason (optional)"
                                                   class="admin-control w-full text-xs mb-2" maxlength="500">
                                            <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs w-full"
                                                    onclick="return confirm('Reset password for {{ addslashes($admin->name) }}?')">
                                                Reset Password
                                            </button>
                                        </form>
                                    </div>
                                </details>

                                {{-- Delete (not self) --}}
                                @if(!$isSelf)
                                    <form method="POST"
                                          action="{{ route('admin.platform-admins.destroy', $admin) }}"
                                          onsubmit="return confirm('Permanently delete {{ addslashes($admin->name) }}? This cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-btn admin-btn-xs text-rose-400 hover:text-rose-300 border border-rose-800 hover:border-rose-600 bg-transparent hover:bg-rose-900/20 transition-colors">
                                            Delete
                                        </button>
                                    </form>
                                @endif

                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $platformAdmins->links() }}</div>
    </div>
</x-super-admin.layout>
