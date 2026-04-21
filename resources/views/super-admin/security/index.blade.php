<x-super-admin.layout>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-kpi admin-tone-rose">
            <div class="text-xs text-slate-400">Failed Admin Logins (24h)</div>
            <div class="mt-1 text-2xl font-semibold text-rose-200">{{ $snapshot['failed_logins'] }}</div>
        </div>
        <div class="admin-kpi admin-tone-amber">
            <div class="text-xs text-slate-400">Admin Password Resets (24h)</div>
            <div class="mt-1 text-2xl font-semibold text-amber-200">{{ $snapshot['password_resets'] }}</div>
        </div>
        <div class="admin-kpi admin-tone-sky">
            <div class="text-xs text-slate-400">Billing Write Blocks (24h)</div>
            <div class="mt-1 text-2xl font-semibold text-sky-200">{{ $snapshot['billing_blocks'] }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h3 class="text-sm font-semibold text-white">Suspicious Admin Actions</h3>
                <p class="text-xs text-slate-400">Role/status changes blocked, deletion attempts.</p>
            </div>
            <div class="divide-y divide-slate-800">
                @forelse($snapshot['suspicious_actions'] as $entry)
                    <div class="px-4 py-3">
                        <div class="text-sm text-white font-medium">{{ $entry->action }}</div>
                        <div class="text-xs text-slate-400">Target: {{ $entry->target_id }} • {{ $entry->created_at }}</div>
                        @if($entry->reason)
                            <div class="text-xs text-rose-200 mt-1">{{ $entry->reason }}</div>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-4 text-sm text-slate-400">No suspicious actions in the last 24h.</div>
                @endforelse
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h3 class="text-sm font-semibold text-white">Recent Impersonation Sessions</h3>
                <p class="text-xs text-slate-400">Support access history.</p>
            </div>
            <div class="divide-y divide-slate-800">
                @forelse($snapshot['recent_impersonations'] as $session)
                    <div class="px-4 py-3">
                        <div class="text-sm text-white">Admin #{{ $session->admin_id }} → Shop #{{ $session->shop_id }}</div>
                        <div class="text-xs text-slate-400">User #{{ $session->user_id }} • Started {{ $session->started_at }} • Expires {{ $session->expires_at }}</div>
                        @if($session->ended_at)
                            <div class="text-xs text-amber-200">Ended {{ $session->ended_at }}</div>
                        @else
                            <div class="text-xs text-emerald-200">Active</div>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-4 text-sm text-slate-400">No impersonations yet.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3 class="text-sm font-semibold text-white">Recent Platform Audit Events</h3>
            <p class="text-xs text-slate-400">Latest security-related actions.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Action</th>
                        <th class="px-4 py-2 text-left">Target</th>
                        <th class="px-4 py-2 text-left">Timestamp</th>
                        <th class="px-4 py-2 text-left">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($snapshot['recent_audit'] as $entry)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-2">{{ $entry->action }}</td>
                            <td class="px-4 py-2">{{ class_basename($entry->target_type) }} #{{ $entry->target_id }}</td>
                            <td class="px-4 py-2">{{ $entry->created_at }}</td>
                            <td class="px-4 py-2 text-xs text-slate-400">{{ $entry->reason ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-4 text-center text-slate-400">No audit events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
