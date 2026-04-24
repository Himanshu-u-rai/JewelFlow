<x-app-layout>
    <x-page-header class="cashbook-page-header" title="Cash Ledger" subtitle="Transaction-by-transaction cash history">
        <x-slot:actions>
            <a href="{{ route('report.cash') }}"
               class="btn btn-secondary btn-sm cashbook-dashboard-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
                </svg>
                <span class="cashbook-dashboard-label-full">Cash Flow Dashboard</span>
                <span class="cashbook-dashboard-label-short">Cash Flow</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner cashbook-index-page">
        <div class="cashbook-quick-action-card">
            <div>
                <p class="cashbook-quick-action-kicker">Quick Action</p>
                <p class="cashbook-quick-action-title">Add a new cash ledger entry</p>
            </div>
            <a href="{{ route('cashbook.create') }}" class="btn btn-success btn-sm cashbook-add-entry-card-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                Add Ledger Entry
            </a>
        </div>

        <x-app-alerts class="mb-6" />
        @php
            $todayNet = $stats['today_in'] - $stats['today_out'];
            $monthNet = $stats['month_in'] - $stats['month_out'];
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 cashbook-kpi-grid">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 cashbook-kpi-card cashbook-kpi-card--in">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-lg p-2 cashbook-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Today's Cash In</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($stats['today_in'], 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 cashbook-kpi-card cashbook-kpi-card--out">
                <div class="flex items-center gap-3">
                    <div class="bg-rose-100 text-rose-700 rounded-lg p-2 cashbook-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Today's Cash Out</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($stats['today_out'], 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 cashbook-kpi-card cashbook-kpi-card--today-net">
                <div class="flex items-center gap-3">
                    <div class="{{ $todayNet >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }} rounded-lg p-2 cashbook-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Today's Net</p>
                        <p class="text-xl font-semibold {{ $todayNet >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $todayNet >= 0 ? '+' : '' }}₹{{ number_format($todayNet, 2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 cashbook-kpi-card cashbook-kpi-card--month-net">
                <div class="flex items-center gap-3">
                    <div class="{{ $monthNet >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }} rounded-lg p-2 cashbook-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">This Month Net</p>
                        <p class="text-xl font-semibold {{ $monthNet >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $monthNet >= 0 ? '+' : '' }}₹{{ number_format($monthNet, 2) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap cashbook-filter-card">
            <form method="GET" action="{{ route('cashbook.index') }}" class="flex flex-wrap gap-4 items-end cashbook-filter-form" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="flex-1 min-w-[200px] cashbook-filter-field cashbook-filter-field--search">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input
                        type="text"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Description or source..."
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                    >
                </div>
                <div class="cashbook-filter-field cashbook-filter-field--type">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" class="rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All Types</option>
                        <option value="in" {{ request('type') === 'in' ? 'selected' : '' }}>Cash In</option>
                        <option value="out" {{ request('type') === 'out' ? 'selected' : '' }}>Cash Out</option>
                    </select>
                </div>
                <div class="cashbook-filter-field cashbook-filter-field--from">
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input
                        type="date"
                        name="from_date"
                        value="{{ request('from_date') }}"
                        class="cashbook-filter-control cashbook-date-pill rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                    >
                </div>
                <div class="cashbook-filter-field cashbook-filter-field--to">
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input
                        type="date"
                        name="to_date"
                        value="{{ request('to_date') }}"
                        class="cashbook-filter-control cashbook-date-pill rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                    >
                </div>
                @php
                    $hasActiveFilters = request()->hasAny(['search', 'type', 'from_date', 'to_date']);
                @endphp
                <div class="flex gap-2 cashbook-filter-actions">
                    @if($hasActiveFilters)
                        <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Clear
                        </a>
                    @else
                        <button type="submit" class="btn btn-secondary btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden cashbook-table-card">
            <div class="overflow-x-auto cashbook-table-shell">
                <table class="w-full cashbook-data-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date &amp; Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mode</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($transactions as $tx)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $tx->created_at->format('d M Y, h:i A') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($tx->type === 'in')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>Cash In
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>Cash Out
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ ucfirst(str_replace('_', ' ', $tx->source_type ?? 'Unknown')) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-mono">
                                    {{ $tx->invoice?->invoice_number ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 max-w-sm truncate">
                                    {{ $tx->description ?: '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($tx->payment_mode)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                            {{ ucfirst($tx->payment_mode) }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold {{ $tx->type === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $tx->type === 'in' ? '+' : '-' }}₹{{ number_format($tx->amount, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <svg class="w-10 h-10 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                    </svg>
                                    <p class="text-lg font-medium mb-1">No transactions found</p>
                                    <p class="text-sm">Start recording transactions in the ledger</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($transactions->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
