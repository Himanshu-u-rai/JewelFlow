<x-super-admin.layout>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-white">{{ $user->name ?? 'User' }}</h2>
            <p class="text-sm text-slate-400">Mobile: {{ $user->mobile_number }}</p>
        </div>
        <a href="{{ route('admin.users.index') }}" class="admin-btn admin-btn-secondary">Back to Users</a>
    </div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        {{-- User Info --}}
        <div class="admin-panel p-4">
            <h3 class="font-semibold text-white mb-3">User Information</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-400">Name</span><span class="font-medium text-slate-100">{{ $user->name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Mobile</span><span class="font-medium text-slate-100">{{ $user->mobile_number }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Email</span><span class="font-medium text-slate-100">{{ $user->email ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Shop</span><span class="font-medium text-slate-100">{{ $user->shop?->name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Role</span><span class="font-medium text-slate-100">{{ $user->role?->display_name ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Scope</span><span class="font-medium text-slate-100">Shop User</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Status</span>
                    <span class="admin-badge {{ $user->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>
                <div class="flex justify-between"><span class="text-slate-400">Created</span><span class="font-medium text-slate-100">{{ $user->created_at?->format('d M Y, h:i A') }}</span></div>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Account Status --}}
            @if(auth('platform_admin')->user()?->isSuperAdmin())
            <div class="admin-panel p-4">
                <h3 class="font-semibold text-white mb-3">Account Status</h3>
                <form method="POST" action="{{ route('admin.users.status', $user) }}" class="flex items-center gap-3">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="is_active" value="{{ $user->is_active ? '0' : '1' }}">
                    <button class="admin-btn {{ $user->is_active ? 'admin-btn-danger' : 'admin-btn-success' }}">
                        {{ $user->is_active ? 'Deactivate User' : 'Activate User' }}
                    </button>
                    <span class="text-xs text-slate-500">Current: {{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                </form>
            </div>

            {{-- Reset Password --}}
            <div class="admin-panel p-4">
                <h3 class="font-semibold text-white mb-3">Reset Password</h3>
                <form method="POST" action="{{ route('admin.users.password', $user) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="block text-sm mb-1 text-slate-300">New Password</label>
                        <input type="password" name="password" required minlength="8"
                               class="admin-control {{ $errors->has('password') ? 'border-rose-500' : '' }}">
                        @error('password')<p class="mt-1 text-xs text-rose-300">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm mb-1 text-slate-300">Confirm Password</label>
                        <input type="password" name="password_confirmation" required minlength="8"
                               class="admin-control {{ $errors->has('password_confirmation') ? 'border-rose-500' : '' }}">
                    </div>
                    <button class="admin-btn admin-btn-primary">Update Password</button>
                </form>
            </div>

            {{-- Change Role --}}
            @if($user->shop_id)
            <div class="admin-panel p-4">
                <h3 class="font-semibold text-white mb-3">Change Role</h3>
                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="space-y-3">
                    @csrf
                    @method('PATCH')
                    @php
                        $shopRoles = \App\Models\Role::withoutTenant()
                            ->where('shop_id', $user->shop_id)
                            ->orderBy('display_name')
                            ->get();
                    @endphp
                    <div>
                        <label class="block text-sm mb-1 text-slate-300">Role</label>
                        <select name="role_id" class="admin-control {{ $errors->has('role_id') ? 'border-rose-500' : '' }}">
                            @foreach($shopRoles as $shopRole)
                                <option value="{{ $shopRole->id }}" {{ $user->role_id === $shopRole->id ? 'selected' : '' }}>
                                    {{ $shopRole->display_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('role_id')<p class="mt-1 text-xs text-rose-300">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm mb-1 text-slate-300">Reason <span class="text-slate-500">(optional)</span></label>
                        <input type="text" name="reason" maxlength="500"
                               class="admin-control"
                               placeholder="e.g. Promoted to manager">
                    </div>
                    <button class="admin-btn admin-btn-primary">Update Role</button>
                </form>
            </div>
            @endif
            @endif
        </div>
    </div>
</x-super-admin.layout>
