@php
    $billingRisk = ($subscriptionCounts['grace'] ?? 0)
        + ($subscriptionCounts['read_only'] ?? 0)
        + ($subscriptionCounts['suspended'] ?? 0)
        + ($subscriptionCounts['cancelled'] ?? 0)
        + ($subscriptionCounts['expired'] ?? 0);

    $suspiciousCount = $recentAudit
        ->whereIn('action', [
            'platform_admin.role_change_blocked',
            'platform_admin.status_change_blocked',
            'platform_admin.delete_blocked',
        ])->count();

    $impersonationCount = $recentAudit
        ->whereIn('action', [
            'admin.impersonation_started',
            'admin.impersonation_ended',
        ])->count();

    $recentUserCounts = $recentUsers->groupBy('shop_id')->map->count();
    $subscriptionTotal = collect($subscriptionCounts)->sum();
    $activeScanSessionsTotal = $activeScanSessionsByShop->sum('active_sessions');
    $shopsWithActiveScanSessions = $activeScanSessionsByShop->count();
@endphp

<div class="grid grid-cols-1 xl:grid-cols-[1fr_280px] gap-6">
    <div class="space-y-6">
        <section>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-xl font-bold text-white">Platform Health</h2>
                    <p class="text-sm text-slate-400">At-a-glance platform stability and access posture.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <x-admin.kpi-card label="Total Shops" :value="$stats['shops_total']" description="All tenants onboarded" tone="slate" />
                <x-admin.kpi-card label="Active Shops" :value="$stats['shops_active']" description="Live and operational" tone="emerald" />
                <x-admin.kpi-card label="Read-Only Shops" :value="$stats['shops_read_only']" description="Writes blocked" tone="amber" />
                <x-admin.kpi-card label="Suspended Shops" :value="$stats['shops_suspended']" description="Full access blocked" tone="rose" />
            </div>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="admin-panel p-4">
                <h3 class="text-lg font-semibold text-white">Security Signals</h3>
                <p class="text-sm text-slate-400">Platform risk indicators (24h).</p>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-admin.kpi-card label="Failed Admin Logins" :value="$failedAdminLogins" description="Last 24h" tone="rose" />
                    <x-admin.kpi-card label="Disabled Users" :value="$stats['users_inactive']" description="Inactive tenant users" tone="amber" />
                    <x-admin.kpi-card label="Suspicious Activity" :value="$suspiciousCount" description="Blocked admin actions" tone="rose" />
                    <x-admin.kpi-card label="Impersonation Sessions" :value="$impersonationCount" description="Recent support sessions" tone="sky" />
                </div>
            </div>

            <div class="admin-panel p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-white">Subscription Health</h3>
                        <p class="text-sm text-slate-400">Distribution across billing states.</p>
                    </div>
                    <span class="text-sm text-amber-200">Risk: {{ $billingRisk }}</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <x-admin.status-chip label="Active" :value="($subscriptionCounts['active'] ?? 0)" tone="emerald" />
                    <x-admin.status-chip label="Trial" :value="($subscriptionCounts['trial'] ?? 0)" tone="sky" />
                    <x-admin.status-chip label="Grace" :value="($subscriptionCounts['grace'] ?? 0)" tone="amber" />
                    <x-admin.status-chip label="Read-Only" :value="($subscriptionCounts['read_only'] ?? 0)" tone="amber" />
                    <x-admin.status-chip label="Expired" :value="($subscriptionCounts['expired'] ?? 0)" tone="rose" />
                    <x-admin.status-chip label="Cancelled" :value="($subscriptionCounts['cancelled'] ?? 0)" tone="slate" />
                </div>
                <div class="mt-4">
                    <div class="h-2 rounded-full bg-slate-800 overflow-hidden flex">
                        @foreach(['active' => 'bg-emerald-500','trial' => 'bg-sky-500','grace' => 'bg-amber-500','read_only' => 'bg-orange-500','expired' => 'bg-rose-500','cancelled' => 'bg-slate-500'] as $status => $color)
                            @php
                                $count = $subscriptionCounts[$status] ?? 0;
                                $width = $subscriptionTotal > 0 ? ($count / $subscriptionTotal) * 100 : 0;
                            @endphp
                            <div class="{{ $color }}" style="width: {{ $width }}%"></div>
                        @endforeach
                    </div>
                    <div class="mt-2 text-sm text-slate-400">Total subscriptions: {{ $subscriptionTotal }}</div>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="admin-panel p-4">
                <h3 class="text-lg font-semibold text-white">Scan Security Telemetry</h3>
                <p class="text-sm text-slate-400">Current scan session posture and 24h abuse indicators.</p>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <x-admin.kpi-card label="Active Scan Sessions" :value="$activeScanSessionsTotal" description="Live right now" tone="sky" />
                    <x-admin.kpi-card label="Throttled Scan Attempts" :value="$scanThrottledAttemptsCount" description="Last 24h" tone="amber" />
                    <x-admin.kpi-card label="Invalid Signature Hits" :value="$scanInvalidSignatureHitsCount" description="Last 24h" tone="rose" />
                </div>

                <div class="mt-4 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-4 py-3 text-sm font-semibold text-slate-200 bg-slate-900/60">
                        Current Active Scan Sessions Per Shop
                        <span class="text-xs text-slate-400 font-normal ml-2">({{ $shopsWithActiveScanSessions }} shops)</span>
                    </div>
                    <div class="divide-y divide-slate-800">
                        @forelse($activeScanSessionsByShop as $row)
                            <div class="px-4 py-3 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-slate-100">{{ $row->shop_name }}</div>
                                    <div class="text-xs text-slate-400">Expires: {{ \Illuminate\Support\Carbon::parse($row->latest_expiry)->format('d M Y, H:i') }}</div>
                                </div>
                                <span class="admin-badge admin-badge-sky">{{ $row->active_sessions }}</span>
                            </div>
                        @empty
                            <div class="px-4 py-4 text-sm text-slate-400">No active scan sessions right now.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="admin-panel p-4">
                <h3 class="text-lg font-semibold text-white">Recent Throttled Scan Attempts</h3>
                <p class="text-sm text-slate-400">Most recent scan requests blocked by throttling (24h).</p>

                <div class="mt-4 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="divide-y divide-slate-800">
                        @forelse($recentThrottledScanAttempts as $log)
                            @php
                                $path = data_get($log->after, 'path', 'unknown');
                                $method = data_get($log->after, 'method', 'N/A');
                                $routeName = data_get($log->after, 'route_name', 'N/A');
                            @endphp
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-medium text-slate-100">{{ $method }} {{ $path }}</div>
                                    <div class="text-xs text-slate-400">{{ $log->created_at?->format('d M, H:i:s') }}</div>
                                </div>
                                <div class="mt-1 text-xs text-slate-400">Route: {{ $routeName }} · IP: {{ $log->ip ?? 'unknown' }}</div>
                            </div>
                        @empty
                            <div class="px-4 py-4 text-sm text-slate-400">No throttled scan attempts in the last 24 hours.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section>
            <h3 class="text-lg font-semibold text-white mb-3">Tenant Activity Snapshot</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <x-admin.kpi-card label="Invoices Created" :value="$stats['invoices_total']" description="System total" tone="slate" />
                <x-admin.kpi-card label="Imports Executed" value="—" description="See Tenant Activity" tone="slate" />
                <x-admin.kpi-card label="Items Created" value="—" description="See Tenant Activity" tone="slate" />
                <x-admin.kpi-card label="Active POS Sessions" value="—" description="See Tenant Activity" tone="slate" />
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <x-admin.alert-table
                title="Suspended Shops"
                subtitle="Immediate attention required"
                :headers="['Shop','Owner','Reason','Suspended At','Action']"
            >
                @forelse($suspendedShops as $shop)
                    <tr class="border-t border-slate-800 text-slate-200">
                        <td class="px-4 py-2 font-medium">{{ $shop->name }}</td>
                        <td class="px-4 py-2 text-sm text-slate-300">{{ $shop->owner_mobile ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-slate-400">{{ $shop->suspension_reason ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-slate-400">{{ $shop->suspended_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.shops.show', ['shop' => $shop->id]) }}" class="admin-btn admin-btn-xs admin-btn-secondary">Inspect</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-slate-400">No suspended shops.</td></tr>
                @endforelse
            </x-admin.alert-table>

            <x-admin.alert-table
                title="Read-Only Shops"
                subtitle="Writes blocked for billing or compliance"
                :headers="['Shop','Owner','Reason','Action']"
            >
                @forelse($readOnlyShops as $shop)
                    <tr class="border-t border-slate-800 text-slate-200">
                        <td class="px-4 py-2 font-medium">{{ $shop->name }}</td>
                        <td class="px-4 py-2 text-sm text-slate-300">{{ $shop->owner_mobile ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-slate-400">{{ $shop->suspension_reason ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.shops.show', ['shop' => $shop->id]) }}" class="admin-btn admin-btn-xs admin-btn-secondary">Inspect</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-center text-slate-400">No read-only shops.</td></tr>
                @endforelse
            </x-admin.alert-table>
        </section>

        <section>
            <x-admin.activity-table
                title="Recent Tenants"
                subtitle="Latest shop onboarding activity"
                :headers="['Shop','Owner','Type','Users','Status','Created','Action']"
            >
                @foreach($recentShops as $shop)
                    @php
                        $ownerName = trim(($shop->owner_first_name ?? '') . ' ' . ($shop->owner_last_name ?? ''));
                        $owner = $ownerName !== '' ? $ownerName : ($shop->owner_mobile ?? $shop->phone ?? '—');
                        $typeLabel = $shop->shop_type === 'manufacturer' ? 'Manufacturer' : 'Retail';
                        $typeBadge = $shop->shop_type === 'manufacturer' ? 'admin-badge-amber' : 'admin-badge-sky';
                        $userCount = $recentUserCounts[$shop->id] ?? 0;
                    @endphp
                    <tr class="border-t border-slate-800 text-slate-200">
                        <td class="px-4 py-2 font-medium">{{ $shop->name }}</td>
                        <td class="px-4 py-2 text-sm text-slate-300">{{ $owner }}</td>
                        <td class="px-4 py-2">
                            <span class="admin-badge {{ $typeBadge }}">{{ $typeLabel }}</span>
                        </td>
                        <td class="px-4 py-2 text-center">{{ $userCount }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="admin-badge {{ $shop->access_mode === 'suspended' ? 'admin-badge-rose' : ($shop->access_mode === 'read_only' ? 'admin-badge-amber' : 'admin-badge-emerald') }}">
                                {{ $shop->access_mode === 'read_only' ? 'Read Only' : ucfirst($shop->access_mode ?? 'active') }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-slate-400">{{ $shop->created_at?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.shops.show', ['shop' => $shop->id]) }}" class="admin-btn admin-btn-xs admin-btn-secondary">Inspect</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.activity-table>
        </section>

        <section>
            <x-admin.activity-table
                title="Recent Admin Activity"
                subtitle="Latest platform actions"
                :headers="['Action','Admin','Target','Timestamp']"
            >
                @forelse($recentAudit as $log)
                    @php
                        $iconMap = [
                            'platform_admin.login_failed' => '⚠️',
                            'shop.suspend' => '⛔',
                            'shop.restore' => '✅',
                            'shop.subscription_changed' => '💳',
                            'platform_admin.created' => '👤',
                        ];
                        $icon = $iconMap[$log->action] ?? '•';
                    @endphp
                    <tr class="border-t border-slate-800 text-slate-200">
                        <td class="px-4 py-2">{{ $icon }} {{ $log->action }}</td>
                        <td class="px-4 py-2 text-sm text-slate-300">{{ $log->actor?->name ?? 'System' }}</td>
                        <td class="px-4 py-2 text-sm text-slate-300">{{ class_basename($log->target_type) }} #{{ $log->target_id }}</td>
                        <td class="px-4 py-2 text-sm text-slate-400">{{ $log->created_at?->format('d M Y, H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-4 text-center text-slate-400">No recent activity.</td></tr>
                @endforelse
            </x-admin.activity-table>
        </section>
    </div>

    <aside class="space-y-4">
        <div class="admin-panel p-4">
            <h3 class="text-lg font-semibold text-white">Quick Controls</h3>
            <div class="mt-4 space-y-2">
                <a href="{{ route('admin.shops.index') }}" class="admin-btn admin-btn-secondary w-full justify-between">Open Shops <span>→</span></a>
                <a href="{{ route('admin.users.index') }}" class="admin-btn admin-btn-secondary w-full justify-between">Open Users <span>→</span></a>
                <a href="{{ route('admin.security.index') }}" class="admin-btn admin-btn-secondary w-full justify-between">View Security <span>→</span></a>
                <a href="{{ route('admin.system.jobs.index') }}" class="admin-btn admin-btn-secondary w-full justify-between">View Jobs <span>→</span></a>
            </div>
        </div>
    </aside>
</div>
