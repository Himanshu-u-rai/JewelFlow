<x-super-admin.layout>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="text-xl font-semibold text-white">{{ $shop->name }}</h2>
            <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-400">
                <span>Owner Mobile: {{ $shop->owner_mobile ?? 'N/A' }}</span>
                @forelse(($activeEditions ?? []) as $ed)
                    @php
                        $cls = match($ed) {
                            'retailer'     => 'admin-badge-sky',
                            'manufacturer' => 'admin-badge-amber',
                            'dhiran'       => 'admin-badge-emerald',
                            default        => 'admin-badge-slate',
                        };
                    @endphp
                    <span class="admin-badge {{ $cls }}">{{ ucfirst($ed) }}</span>
                @empty
                    <span class="admin-badge admin-badge-rose">No editions</span>
                @endforelse
                <span class="admin-badge {{ $shop->access_mode === 'suspended' ? 'admin-badge-rose' : ($shop->access_mode === 'read_only' ? 'admin-badge-amber' : 'admin-badge-emerald') }}">
                    {{ $shop->access_mode === 'read_only' ? 'Read Only' : ucfirst($shop->access_mode ?? 'active') }}
                </span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if(auth('platform_admin')->user()?->isSuperAdmin())
                <form method="POST" action="{{ route('admin.shops.impersonate', $shop) }}">
                    @csrf
                    <button class="admin-btn admin-btn-primary">Login as Shop Owner</button>
                </form>
            @endif
            <a href="{{ route('admin.shops.index') }}" class="admin-btn admin-btn-secondary">Back to Shops</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="admin-kpi"><div class="admin-kpi__label">Users</div><div class="admin-kpi__value text-2xl">{{ $stats['users'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Customers</div><div class="admin-kpi__value text-2xl">{{ $stats['customers'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Items</div><div class="admin-kpi__value text-2xl">{{ $stats['items'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Invoices</div><div class="admin-kpi__value text-2xl">{{ $stats['invoices'] }}</div></div>
        <div class="admin-kpi"><div class="admin-kpi__label">Repairs</div><div class="admin-kpi__value text-2xl">{{ $stats['repairs'] }}</div></div>
    </div>

    <div class="admin-panel p-4 mb-6">
        <h3 class="font-semibold text-white mb-1">Platform Control</h3>
        <p class="text-xs text-slate-400 mb-4">
            <strong class="text-slate-300">Active</strong> — full access &nbsp;·&nbsp;
            <strong class="text-slate-300">Read-Only</strong> — can view, writes blocked &nbsp;·&nbsp;
            <strong class="text-slate-300">Suspended</strong> — fully blocked, logged out
        </p>

        <form method="POST" action="{{ route('admin.shops.status', $shop) }}" class="space-y-3">
            @csrf
            @method('PATCH')

            <div class="flex flex-wrap gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="active"
                        {{ ($shop->access_mode ?? 'active') === 'active' ? 'checked' : '' }}
                        class="accent-emerald-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ ($shop->access_mode ?? 'active') === 'active'
                            ? 'border-emerald-500 bg-emerald-500/20 text-emerald-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        ✅ Active
                    </span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="read_only"
                        {{ $shop->access_mode === 'read_only' ? 'checked' : '' }}
                        class="accent-amber-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ $shop->access_mode === 'read_only'
                            ? 'border-amber-500 bg-amber-500/20 text-amber-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        🔒 Read-Only
                    </span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="access_mode" value="suspended"
                        {{ $shop->access_mode === 'suspended' ? 'checked' : '' }}
                        class="accent-rose-500">
                    <span class="text-sm font-medium px-3 py-1.5 rounded-lg border
                        {{ $shop->access_mode === 'suspended'
                            ? 'border-rose-500 bg-rose-500/20 text-rose-200'
                            : 'border-slate-700 bg-slate-800 text-slate-300' }}">
                        ⛔ Suspended
                    </span>
                </label>
            </div>

            <div>
                <label class="block text-xs text-slate-400 mb-1">Reason (optional — shown in audit log)</label>
                <input type="text" name="reason"
                       value="{{ old('reason', $shop->suspension_reason) }}"
                       placeholder="e.g. Payment overdue, compliance hold..."
                       class="admin-control">
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="admin-btn admin-btn-primary">
                    Apply Access Mode
                </button>

                @if($shop->suspended_at)
                    <span class="text-xs text-slate-400">
                        Last changed {{ $shop->suspended_at->format('d M Y, h:i A') }}
                        @if($shop->suspension_reason)
                            · {{ $shop->suspension_reason }}
                        @endif
                    </span>
                @endif
            </div>
        </form>
    </div>

    {{-- ── Edition management ──────────────────────────────── --}}
    <div class="admin-panel p-4 mb-6">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="font-semibold text-white mb-1">Services (Editions)</h3>
                <p class="text-xs text-slate-400">
                    Active services determine which features and modules this shop can use.
                    Every grant or revoke requires a reason and is recorded in the audit log.
                </p>
            </div>
        </div>

        @if($errors->any())
            <div class="mb-3 rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
                {{ $errors->first() }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-3 rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">
                {{ session('error') }}
            </div>
        @endif
        @if(session('success'))
            <div class="mb-3 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
            {{-- Active editions with revoke controls --}}
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Active</div>
                @forelse($activeEditions as $ed)
                    <details class="mb-2 last:mb-0">
                        <summary class="flex items-center justify-between cursor-pointer list-none rounded-md bg-slate-800/60 px-3 py-2">
                            <span class="text-sm text-slate-100 font-medium">{{ ucfirst($ed) }}</span>
                            <span class="text-xs text-rose-300 hover:text-rose-200">Revoke →</span>
                        </summary>
                        <form method="POST" action="{{ route('admin.shops.editions.revoke', $shop) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3">
                            @csrf
                            <input type="hidden" name="edition" value="{{ $ed }}">
                            <label class="block text-xs text-slate-400">Reason (required, audit log)</label>
                            <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                      class="admin-control w-full"
                                      placeholder="e.g. Customer requested removal of Dhiran service on 2026-04-18 ticket #4421"></textarea>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="admin-btn admin-btn-danger admin-btn-xs">Revoke {{ ucfirst($ed) }}</button>
                                <span class="text-xs text-slate-500">Shop must keep at least one active edition.</span>
                            </div>
                        </form>
                    </details>
                @empty
                    <div class="text-xs text-rose-300">No active editions. Grant one below.</div>
                @endforelse
            </div>

            {{-- Grant (available) editions --}}
            <div class="rounded-lg border border-slate-700 bg-slate-900/40 p-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Available to grant</div>
                @forelse($availableEditions as $ed)
                    <details class="mb-2 last:mb-0">
                        <summary class="flex items-center justify-between cursor-pointer list-none rounded-md bg-slate-800/60 px-3 py-2">
                            <span class="text-sm text-slate-100 font-medium">{{ ucfirst($ed) }}</span>
                            <span class="text-xs text-emerald-300 hover:text-emerald-200">Grant →</span>
                        </summary>
                        <form method="POST" action="{{ route('admin.shops.editions.grant', $shop) }}" class="mt-2 space-y-2 rounded-md border border-slate-700 bg-slate-900/60 p-3">
                            @csrf
                            <input type="hidden" name="edition" value="{{ $ed }}">
                            <label class="block text-xs text-slate-400">Reason (required, audit log)</label>
                            <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                                      class="admin-control w-full"
                                      placeholder="e.g. Shop upgraded to include manufacturing on 2026-04-19 via support ticket #4521"></textarea>
                            <button type="submit" class="admin-btn admin-btn-primary admin-btn-xs">Grant {{ ucfirst($ed) }}</button>
                        </form>
                    </details>
                @empty
                    <div class="text-xs text-slate-500">All editions already granted.</div>
                @endforelse
            </div>
        </div>

        {{-- History (audit trail) --}}
        @if($editionHistory->isNotEmpty())
            <div class="mt-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">History</div>
                <div class="overflow-x-auto rounded-lg border border-slate-800">
                    <table class="w-full text-xs admin-table">
                        <thead class="bg-slate-800/70 text-slate-300">
                            <tr>
                                <th class="px-3 py-2 text-left">Edition</th>
                                <th class="px-3 py-2 text-left">Granted</th>
                                <th class="px-3 py-2 text-left">Revoked</th>
                                <th class="px-3 py-2 text-left">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($editionHistory as $entry)
                                <tr class="border-t border-slate-800 text-slate-200">
                                    <td class="px-3 py-2 font-medium">{{ ucfirst($entry->edition) }}</td>
                                    <td class="px-3 py-2">{{ optional($entry->activated_at)->format('d M Y, H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if($entry->deactivated_at)
                                            <span class="text-rose-300">{{ $entry->deactivated_at->format('d M Y, H:i') }}</span>
                                        @else
                                            <span class="text-emerald-300">Active</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-slate-400">{{ $entry->deactivation_reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">Shop Users</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Mobile</th>
                        <th class="px-4 py-2 text-left">Role</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $ownerName = trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? ''));
                    @endphp
                    @foreach($shop->users as $user)
                        <tr class="border-t border-slate-800 text-slate-200">
                            @php
                                $userFullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                                if ($userFullName === '') {
                                    $userFullName = $user->name ?? '';
                                }
                                if ($user->mobile_number === $shop->owner_mobile && $ownerName !== '') {
                                    $userFullName = $ownerName;
                                }
                            @endphp
                            <td class="px-4 py-3">{{ $userFullName !== '' ? $userFullName : '-' }}</td>
                            <td class="px-4 py-3">{{ $user->mobile_number }}</td>
                            <td class="px-4 py-3">{{ $user->role?->display_name ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <span class="admin-badge {{ $user->is_active ? 'admin-badge-emerald' : 'admin-badge-rose' }}">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <a href="{{ route('admin.users.show', $user) }}" class="admin-btn admin-btn-secondary admin-btn-xs">Inspect</a>
                                    @if(auth('platform_admin')->user()?->isSuperAdmin())
                                        <form method="POST" action="{{ route('admin.shops.impersonate', $shop) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                                            <button class="admin-btn admin-btn-primary admin-btn-xs">Impersonate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
