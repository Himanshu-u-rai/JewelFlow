<x-super-admin.layout title="Unresolved Payments" subtitle="Captured Razorpay payments that were not applied to a subscription.">
    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Unresolved Payments</h3>
            <p class="text-sm text-slate-400">Money captured by Razorpay but not yet applied. Transient rows keep auto-retrying; permanent rows need a manual review or refund.</p>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="admin-panel p-4 mb-4">
        <form method="GET" class="admin-filter-bar flex-wrap gap-3">
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Status</label>
                <select name="status" class="admin-control admin-select">
                    <option value="open"     @selected($status === 'open')>Open</option>
                    <option value="resolved" @selected($status === 'resolved')>Resolved</option>
                    <option value="all"      @selected($status === 'all')>All</option>
                </select>
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Payment ID</label>
                <input type="text" name="payment_id" value="{{ request('payment_id') }}"
                       placeholder="pay_..." class="admin-control">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="admin-btn admin-btn-primary">Apply</button>
                <a href="{{ route('admin.unresolved-payments.index') }}" class="admin-btn admin-btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="admin-panel admin-table-panel">
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left w-16">#</th>
                        <th class="px-4 py-2 text-left">Payment ID</th>
                        <th class="px-4 py-2 text-left">Order ID</th>
                        <th class="px-4 py-2 text-left">Kind</th>
                        <th class="px-4 py-2 text-right">Attempts</th>
                        <th class="px-4 py-2 text-left">First Failed</th>
                        <th class="px-4 py-2 text-left">Last Failed</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        @php
                            $after      = $event->after ?? [];
                            $resolvedAt = $after['resolved_at'] ?? null;
                            $transient  = (bool) ($after['transient'] ?? false);
                        @endphp
                        <tr class="border-t border-slate-800 text-slate-200 {{ $resolvedAt ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 admin-table-index">{{ $events->firstItem() + $loop->index }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $after['payment_id'] ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $after['order_id'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($transient)
                                    <span class="admin-badge admin-badge-amber">Transient</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Permanent</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">{{ $after['attempt_count'] ?? 1 }}</td>
                            <td class="px-4 py-3 text-slate-400 whitespace-nowrap">
                                {{ isset($after['first_failed_at']) ? \Illuminate\Support\Carbon::parse($after['first_failed_at'])->format('d M Y, H:i') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-400 whitespace-nowrap">
                                {{ isset($after['last_failed_at']) ? \Illuminate\Support\Carbon::parse($after['last_failed_at'])->format('d M Y, H:i') : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($resolvedAt)
                                    <span class="admin-badge admin-badge-emerald">Resolved</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Open</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-300 max-w-md">{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="9">No unresolved payments — every captured payment is applied.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $events->links() }}</div>
    </div>
</x-super-admin.layout>
