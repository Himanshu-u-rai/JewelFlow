<x-app-layout>
    <x-page-header class="customers-page-header">
        <div>
            <h1 class="page-title">Customer Occasions</h1>
            <p class="page-subtitle">Birthdays and anniversaries due soon</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('customers.index', ['view' => 'occasions']) }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                Open in Customers
            </a>
        </div>
    </x-page-header>

    <div class="content-inner customers-index-page">
        @php
            $occasionRows = collect($upcoming ?? []);
            $thisWeek = $occasionRows->where('days_until', '<=', 7)->count();
            $customerCount = $occasionRows->pluck('customer_id')->unique()->count();
        @endphp

        <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10m-13 9h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Upcoming</p>
                        <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($occasionRows->count()) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Next 7 Days</p>
                        <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($thisWeek) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Customers</p>
                        <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($customerCount) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('report.occasions') }}" class="ui-filter-bar" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="ui-filter-field">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Reminder window</label>
                    <select name="days" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        @foreach([7, 15, 30, 60, 90] as $days)
                            <option value="{{ $days }}" {{ (int) $daysAhead === $days ? 'selected' : '' }}>Next {{ $days }} days</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-filter-actions">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Apply</button>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--occasions">
            <div class="customers-table-card-header p-5 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Occasion Register</h2>
                <p class="text-sm text-slate-500 mt-1">Customers with dates due in the next {{ (int) $daysAhead }} days</p>
            </div>
            <div class="overflow-x-auto ui-table-shell customers-table-shell">
                <table class="w-full customers-data-table customers-data-table--occasions">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Occasion</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Due In</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($occasionRows as $occasion)
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $occasion['customer_name'] }}</div>
                                    <div class="text-xs text-slate-500">{{ $occasion['mobile'] ?: 'No mobile' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">{{ $occasion['type'] }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700">{{ \Carbon\Carbon::parse($occasion['date'])->format('d M Y') }}</td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-slate-900 tabular-nums">
                                    {{ (int) $occasion['days_until'] === 0 ? 'Today' : ((int) $occasion['days_until'] . ' days') }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('customers.show', $occasion['customer_id']) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                        View Profile
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No occasions in this window</p>
                                    <p class="text-sm">Increase the reminder window or add occasion dates on customer profiles.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
