<x-super-admin.layout>
    <div class="admin-toolbar mb-4">
        <div>
            <h3 class="text-lg font-semibold text-white">Compliance Alerts</h3>
            <p class="text-sm text-slate-400">Cross-tenant compliance monitoring for split transactions, missing PAN, and threshold breaches.</p>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="admin-panel p-4 mb-4">
        <form method="GET" class="admin-filter-bar flex-wrap gap-3">
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Status</label>
                <select name="resolved" class="admin-control admin-select">
                    <option value="0" @selected(request('resolved', '0') === '0')>Unresolved</option>
                    <option value="1" @selected(request('resolved') === '1')>Resolved</option>
                    <option value=""  @selected(request('resolved') === '')>All</option>
                </select>
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Alert Type</label>
                <select name="alert_type" class="admin-control admin-select">
                    <option value="">All Types</option>
                    <option value="split_transaction"  @selected(request('alert_type') === 'split_transaction')>Split Transaction</option>
                    <option value="missing_pan"        @selected(request('alert_type') === 'missing_pan')>Missing PAN</option>
                    <option value="threshold_breach"   @selected(request('alert_type') === 'threshold_breach')>Threshold Breach</option>
                </select>
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">Shop ID</label>
                <input type="number" name="shop_id" value="{{ request('shop_id') }}"
                       placeholder="Shop ID" min="1" class="admin-control">
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="admin-control">
            </div>
            <div class="admin-filter-field-sm">
                <label class="block text-sm mb-1 text-slate-300">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="admin-control">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="admin-btn admin-btn-primary">Apply</button>
                <a href="{{ route('admin.compliance-alerts.index') }}" class="admin-btn admin-btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="admin-table-wrap">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Customer</th>
                        <th class="px-4 py-2 text-left">Alert Type</th>
                        <th class="px-4 py-2 text-right">Same-day Total</th>
                        <th class="px-4 py-2 text-right">Threshold</th>
                        <th class="px-4 py-2 text-left">Created</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Invoice</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        @php
                            $data       = $alert->alert_data ?? [];
                            $sameDay    = $data['same_day_total'] ?? $data['total'] ?? null;
                            $threshold  = $data['threshold'] ?? null;
                            $resolved   = (bool) $alert->resolved;

                            $typeBadge = match ($alert->alert_type) {
                                'split_transaction' => 'admin-badge-amber',
                                'missing_pan'       => 'admin-badge-sky',
                                default             => 'admin-badge-rose',
                            };
                            $typeLabel = match ($alert->alert_type) {
                                'split_transaction' => 'Split Txn',
                                'missing_pan'       => 'Missing PAN',
                                'threshold_breach'  => 'Threshold',
                                default             => ucfirst($alert->alert_type),
                            };
                        @endphp
                        <tr class="border-t border-slate-800 text-slate-200 {{ $resolved ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3 {{ $resolved ? 'line-through text-slate-500' : '' }}">
                                {{ $alert->shop_name ?? "Shop #{$alert->shop_id}" }}
                            </td>
                            <td class="px-4 py-3 {{ $resolved ? 'line-through text-slate-500' : '' }}">
                                {{ $alert->customer?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="admin-badge {{ $typeBadge }}">{{ $typeLabel }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ $sameDay !== null ? '₹' . number_format((float) $sameDay, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                {{ $threshold !== null ? '₹' . number_format((float) $threshold, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-400 whitespace-nowrap">
                                {{ $alert->created_at?->format('d M Y, H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                @if($resolved)
                                    <span class="admin-badge admin-badge-emerald">Resolved</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Open</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($alert->invoice_id)
                                    <a href="{{ route('admin.invoices.index', ['invoice_id' => $alert->invoice_id]) }}"
                                       class="text-sky-400 hover:underline text-xs">#{{ $alert->invoice_id }}</a>
                                @else
                                    <span class="text-slate-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="8">No compliance alerts found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-slate-800">{{ $alerts->links() }}</div>
    </div>
</x-super-admin.layout>
