<x-app-layout>
    @php
        $isRetailer = auth()->user()->shop?->isRetailer();
        $searchActive = request()->filled('search');
        $viewParam = request('view', 'customers');
        $validViews = ['customers', 'loyalty', 'emi', 'occasions'];
        $viewDefault = in_array($viewParam, $validViews) ? $viewParam : 'customers';
    @endphp

    <x-page-header
        class="customers-page-header"
        title="Customers"
        :subtitle="'Manage customer profiles' . ($isRetailer ? '' : ' and balances')"
    >
        <x-slot:actions>
            <a href="{{ route('customers.create') }}"
               class="btn btn-success btn-sm customers-add-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
                Add Customer
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page jf-skeleton-host is-loading" x-data="{ view: '{{ $viewDefault }}' }">

        {{-- View Toggle for Retailers --}}
        @if($isRetailer)
        <div class="mt-1 mb-3">
            <div class="ui-toggle-strip customers-view-toggle inline-flex flex-wrap items-center gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
            <button @click="view = 'customers'" :class="view === 'customers' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Customer Directory
            </button>
            <button @click="view = 'loyalty'" :class="view === 'loyalty' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Loyalty Points
            </button>
            <button @click="view = 'emi'" :class="view === 'emi' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                EMI / Installments
            </button>
            <button @click="view = 'occasions'" :class="view === 'occasions' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Occasions
            </button>
            </div>
        </div>
        @endif

        {{-- ==================== CUSTOMERS VIEW ==================== --}}
        <div x-show="view === 'customers'" x-cloak>

        <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5V4H2v16h5m10 0v-2a4 4 0 00-8 0v2m8 0H7m8-10a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Customers</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($customers->total()) }}</p>
                    </div>
                </div>
            </div>

            @if(!$isRetailer)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Gold on This Page</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($pageGoldTotal, 3) }} g</p>
                    </div>
                </div>
            </div>
            @else
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Purchases</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($retailerInvoiceCount) }}</p>
                    </div>
                </div>
            </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12H8m0 0H6a2 2 0 01-2-2V6a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2h-2m-8 0l1 8h6l1-8"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">With Email</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($withEmail) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6">
            <form method="GET" action="{{ route('customers.index') }}" class="ui-filter-bar">
                <div class="ui-filter-field">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            placeholder="Name or mobile number..."
                            class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none"
                            data-suggest="customers"
                            data-live-filter="customers-tbody"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="ui-filter-actions">
                    @if($searchActive)
                        <a href="{{ route('customers.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            Clear
                        </a>
                    @else
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                            Search
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--directory">
            <div class="p-5 border-b border-slate-200 bg-gradient-to-r from-white via-slate-50 to-white">
                <h2 class="text-lg font-semibold text-slate-900">Customer Directory</h2>
                <p class="text-sm text-slate-500 mt-1">{{ $customers->total() }} total customers</p>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell">
                <table class="w-full customers-data-table customers-data-table--directory">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Contact</th>
                            @if(!$isRetailer)
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Gold Balance</th>
                            @else
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Purchases</th>
                            @endif
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100" id="customers-tbody">
                        @forelse($customers as $customer)
                            @php
                                $first = strtoupper(substr($customer->first_name ?? '', 0, 1));
                                $last = strtoupper(substr($customer->last_name ?? '', 0, 1));
                                $initials = trim($first . $last) !== '' ? $first . $last : 'CU';
                                $searchText = strtolower(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '') . ' ' . ($customer->mobile ?? '')));
                            @endphp
                            <tr class="hover:bg-slate-50/70 transition-colors" data-search="{{ $searchText }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 inline-flex items-center justify-center rounded-xl bg-amber-100 text-amber-700 font-semibold text-sm">
                                            {{ $initials }}
                                        </div>
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">
                                                {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) }}
                                            </div>
                                            @if($customer->email)
                                                <div class="text-xs text-slate-500">{{ $customer->email }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-slate-900">{{ $customer->mobile }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    @if(!$isRetailer)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">
                                            {{ number_format((float) ($customer->gold_transactions_sum_fine_gold ?? 0), 3) }} g
                                        </span>
                                    @else
                                        @php $purchaseCount = $customer->invoices_count; @endphp
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">
                                            {{ $purchaseCount }} {{ Str::plural('invoice', $purchaseCount) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                        <a href="{{ route('customers.show', $customer) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View
                                        </a>
                                        <a href="{{ route('customers.edit', $customer) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                            Edit
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No customers found</p>
                                    <p class="text-sm">
                                        @if($searchActive)
                                            Try another search or <a href="{{ route('customers.index') }}" class="text-amber-700 hover:text-amber-800 font-semibold underline">show all customers</a>.
                                        @else
                                            Add your first customer to get started.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                        <tr data-no-match-row style="display:none;">
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500 text-sm">
                                No customers match your search on this page. Press Enter to search all records.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if($customers->hasPages())
                <div class="px-6 py-4 border-t border-slate-200">
                    {{ $customers->links() }}
                </div>
            @endif
        </div>

        </div>{{-- end customers view --}}

        {{-- ==================== LOYALTY POINTS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $loyaltyData)
        <div x-show="view === 'loyalty'" x-cloak>
            @php
                $loyaltyCustomers = $loyaltyData['loyaltyCustomers'];
                $totalPointsIssued = $loyaltyData['totalPointsIssued'];
                $totalPointsRedeemed = $loyaltyData['totalPointsRedeemed'];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 customers-kpi-grid">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Total Issued</p>
                            <p class="text-xl font-semibold text-gray-900">{{ number_format($totalPointsIssued) }}</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-rose-100 text-rose-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Total Redeemed</p>
                            <p class="text-xl font-semibold text-gray-900">{{ number_format($totalPointsRedeemed) }}</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-green-100 text-green-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5V4H2v16h5m10 0v-2a4 4 0 00-8 0v2m8 0H7"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Members with Points</p>
                            <p class="text-xl font-semibold text-gray-900">{{ $loyaltyCustomers->total() }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap">
                <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                    <input type="hidden" name="view" value="loyalty">
                    <div class="flex-1 min-w-[220px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            </span>
                            <input type="text" name="loyalty_search" value="{{ request('loyalty_search') }}" placeholder="Customer name or mobile..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        </div>
                    </div>
                    @if(filled(request('loyalty_search')))
                        <a href="{{ route('customers.index', ['view' => 'loyalty']) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                    @else
                        <button type="submit" class="btn btn-secondary btn-sm">Search</button>
                    @endif
                </form>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden customers-table-card customers-table-card--loyalty">
                <div class="overflow-x-auto customers-table-shell">
                    <table class="w-full customers-data-table customers-data-table--loyalty">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mobile</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Points</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value (₹)</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($loyaltyCustomers as $lCustomer)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                    <a href="{{ route('customers.show', $lCustomer) }}" class="hover:text-amber-600">{{ $lCustomer->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $lCustomer->mobile }}</td>
                                <td class="px-6 py-4 text-sm text-right">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                        {{ number_format($lCustomer->loyalty_points) }} pts
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-right text-gray-700">₹{{ number_format($lCustomer->loyalty_points * 0.25, 2) }}</td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="{{ route('customers.show', $lCustomer) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Profile</a>
                                        <a href="{{ route('loyalty.adjust.form', $lCustomer) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>Adjust</a>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                    <p class="text-lg font-medium mb-1">No loyalty members yet</p>
                                    <p class="text-sm">Points are automatically earned when customers make purchases.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($loyaltyCustomers->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">{{ $loyaltyCustomers->appends(['view' => 'loyalty', 'loyalty_search' => request('loyalty_search')])->links() }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- ==================== EMI / INSTALLMENTS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $installmentData)
        <div x-show="view === 'emi'" x-cloak>
            <div class="mb-4 flex justify-end">
                <a href="{{ route('installments.create') }}" class="btn btn-dark btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Create EMI Plan
                </a>
            </div>
            @php
                $installmentPlans = $installmentData['installmentPlans'];
                $overduePlans = $installmentData['overduePlans'];
                $totalOutstanding = $installmentData['totalOutstanding'];
                $installmentStatus = $installmentData['installmentStatus'];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 customers-kpi-grid">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Active Plans</p>
                            <p class="text-xl font-semibold text-gray-900">{{ $installmentPlans->total() }}</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-rose-100 text-rose-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Overdue</p>
                            <p class="text-xl font-semibold text-rose-600">{{ $overduePlans }}</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 0v8m0 5v-1"/></svg>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Total Outstanding</p>
                            <p class="text-xl font-semibold text-gray-900">₹{{ number_format($totalOutstanding, 2) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap">
                <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                    <input type="hidden" name="view" value="emi">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="emi_status" class="rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="active" {{ $installmentStatus === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="completed" {{ $installmentStatus === 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="defaulted" {{ $installmentStatus === 'defaulted' ? 'selected' : '' }}>Defaulted</option>
                            <option value="" {{ $installmentStatus === '' ? 'selected' : '' }}>All</option>
                        </select>
                    </div>
                    @if(request()->has('emi_status'))
                        <a href="{{ route('customers.index', ['view' => 'emi']) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                    @else
                        <button type="submit" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>Filter</button>
                    @endif
                </form>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden customers-table-card customers-table-card--emi">
                <div class="overflow-x-auto customers-table-shell">
                    <table class="w-full customers-data-table customers-data-table--emi">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">EMI</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Progress</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($installmentPlans as $plan)
                            @php $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active'; @endphp
                            <tr class="hover:bg-gray-50 {{ $isOverdue ? 'bg-rose-50' : '' }}">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $plan->customer->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-right">₹{{ number_format($plan->total_amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-right">₹{{ number_format($plan->emi_amount, 2) }}</td>
                                <td class="px-6 py-4 text-center text-sm">{{ $plan->emis_paid }}/{{ $plan->total_emis }}</td>
                                <td class="px-6 py-4 text-sm {{ $isOverdue ? 'text-rose-600 font-medium' : 'text-gray-500' }}">
                                    {{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : '—' }}
                                    @if($isOverdue) <span class="text-xs">(overdue)</span> @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @php
                                        $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'completed' => 'bg-green-100 text-green-800', 'defaulted' => 'bg-red-100 text-red-800'];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$plan->status] ?? 'bg-gray-100' }}">
                                        {{ ucfirst($plan->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('installments.show', $plan) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <p class="text-lg font-medium mb-1">No installment plans found</p>
                                    <p class="text-sm">Create an EMI plan from Invoices or the EMI module.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($installmentPlans->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">{{ $installmentPlans->appends(['view' => 'emi', 'emi_status' => $installmentStatus])->links() }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- ==================== CUSTOMER OCCASIONS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer)
        <div x-show="view === 'occasions'" x-cloak>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-10 text-center">
                <div class="mx-auto w-14 h-14 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10m-13 9h16a2 2 0 002-2V7a2 2 0 00-2-2H4a2 2 0 00-2 2v11a2 2 0 002 2z" />
                    </svg>
                </div>
                <h2 class="text-xl font-semibold text-gray-900">Coming Soon</h2>
                <p class="text-sm text-gray-500 mt-2">Occasions module is temporarily hidden. It will be available again in a future update.</p>
            </div>
        </div>
        @endif

    </div>
</x-app-layout>
