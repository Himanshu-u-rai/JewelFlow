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
            @can('customers.create')
            <a href="{{ route('customers.create') }}"
               class="btn btn-success btn-sm customers-add-btn">
                <x-icon name="user-plus" class="w-4 h-4 mr-2" />
                Add Customer
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page jf-skeleton-host is-loading" x-data="{ view: '{{ $viewDefault }}' }">

        @php
            $canModifyCustomers = auth()->user()->can('customers.create')
                || auth()->user()->can('customers.edit')
                || auth()->user()->can('customers.delete');
        @endphp
        @unless($canModifyCustomers)
            @include('partials.view-only-banner', ['permission' => 'customers.edit', 'message' => 'customer management'])
        @endunless

        {{-- View Toggle for Retailers --}}
        @if($isRetailer)
        <div class="mt-1 mb-3">
            <div class="ui-toggle-strip customers-view-toggle inline-flex flex-wrap items-center gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
            <button type="button" @click="view = 'customers'" :class="view === 'customers' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <x-icon name="users" size="14" />
                Customer Directory
            </button>
            <button type="button" @click="view = 'loyalty'" :class="view === 'loyalty' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <x-icon name="star" size="14" />
                Loyalty Points
            </button>
            <button type="button" @click="view = 'emi'" :class="view === 'emi' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <x-icon name="credit-card" size="14" />
                EMI / Installments
            </button>
            <button type="button" @click="view = 'occasions'" :class="view === 'occasions' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                <x-icon name="calendar" size="14" />
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
                        <x-icon name="square-user" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Customers</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($customers->total()) }}</p>
                    </div>
                </div>
            </div>

            @if(!$isRetailer)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <x-icon name="dollar-sign" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Gold on This Page</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($pageGoldTotal, 3) }} g</p>
                    </div>
                </div>
            </div>
            @else
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <x-icon name="receipt-text" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Purchases</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($retailerInvoiceCount) }}</p>
                    </div>
                </div>
            </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <x-icon name="mail" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">With Email</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($withEmail) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--directory">
            <div class="customers-table-card-header customers-register-head p-5 border-b border-slate-200">
                <div class="customers-register-titleblock">
                    <h2 class="text-lg font-semibold text-slate-900">Customer Directory</h2>
                    <p class="text-sm text-slate-500 mt-1">{{ $customers->total() }} total customers</p>
                </div>

                <form method="GET" action="{{ route('customers.index') }}" class="ui-filter-bar customers-register-toolbar customers-register-toolbar--search customers-register-toolbar--with-status">
                    <div class="ui-filter-field">
                        <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                                <x-icon name="search" size="16" />
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
                    {{-- MASTERS PART 3: archived customers are retained, not deleted,
                         so they stay one click away. Counts carry the same search
                         filter as the list, so a count always matches what the
                         option shows. Both values ride through pagination via the
                         paginator's withQueryString(). --}}
                    <div class="ui-filter-field">
                        <label for="customers-status" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Status</label>
                        <select id="customers-status" name="status"
                                class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active ({{ (int) $statusCounts->active_count }})</option>
                            <option value="archived" {{ $status === 'archived' ? 'selected' : '' }}>Archived ({{ (int) $statusCounts->archived_count }})</option>
                            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All ({{ (int) $statusCounts->all_count }})</option>
                        </select>
                    </div>
                    <div class="ui-filter-actions">
                        {{-- The submit button is always rendered now: with a status
                             filter in the bar, hiding it would leave no way to apply
                             a status change once a search was active. --}}
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                            {{ $searchActive ? 'Apply' : 'Search' }}
                        </button>
                        @if($searchActive)
                            <a href="{{ route('customers.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                Clear
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell">
                <table class="w-full jf-table customers-data-table customers-data-table--directory">
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
                                $searchText = strtolower(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '') . ' ' . ($customer->mobile ?? '')));
                            @endphp
                            <tr class="customers-table-row hover:bg-slate-50/70 transition-colors" data-search="{{ $searchText }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <x-customer-avatar :customer="$customer" />
                                        <div class="customers-table-identity">
                                            <div class="customers-table-name text-sm font-semibold text-slate-900">
                                                {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) }}
                                                @unless($customer->is_active)
                                                    {{-- MASTERS PART 3: archived rows are visible under the
                                                         Archived/All filters, so they must be labelled. --}}
                                                    <span class="ml-1 inline-flex items-center rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">Archived</span>
                                                @endunless
                                            </div>
                                            @if($customer->email)
                                                <div class="customers-table-meta text-xs text-slate-500">{{ $customer->email }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="customers-table-contact text-sm text-slate-900">{{ \App\Support\Mobile::forDisplay($customer->mobile) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    @if(!$isRetailer)
                                        <span class="customers-table-pill customers-table-pill--gold inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">
                                            {{ number_format((float) ($customer->gold_transactions_sum_fine_gold ?? 0), 3) }} g
                                        </span>
                                    @else
                                        @php $purchaseCount = $customer->invoices_count; @endphp
                                        <span class="customers-table-pill customers-table-pill--success inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">
                                            {{ $purchaseCount }} {{ Str::plural('invoice', $purchaseCount) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                        <a href="{{ route('customers.show', $customer) }}" class="customers-row-action customers-row-action--primary inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            <x-icon name="eye" size="12" />
                                            View
                                        </a>
                                        <a href="{{ route('customers.edit', $customer) }}" class="customers-row-action inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            <x-icon name="pencil" size="12" />
                                            Edit
                                        </a>
                                        @can('customers.delete')
                                            @if($customer->is_active)
                                                <form action="{{ route('customers.archive', $customer) }}" method="POST" class="inline"
                                                      onsubmit="return confirm('Archive {{ addslashes(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))) }}? Invoices, balances, EMIs and repairs are all kept — the customer just stops appearing when you start a new transaction.')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Archive customer" class="inline-flex items-center justify-center gap-1.5 min-h-[44px] rounded-lg px-3 text-xs font-semibold bg-amber-700 text-white shadow-sm transition-colors hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">Archive</button>
                                                </form>
                                            @else
                                                <form action="{{ route('customers.reactivate', $customer) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Reactivate customer" class="inline-flex items-center justify-center gap-1.5 min-h-[44px] rounded-lg px-3 text-xs font-semibold bg-emerald-700 text-white shadow-sm transition-colors hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">Reactivate</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="jf-table-empty customers-empty-cell">
                                    <div class="customers-empty-state">
                                        <span class="customers-empty-icon" aria-hidden="true">
                                            <x-icon name="user-plus" size="20" />
                                        </span>
                                        <div>
                                            <p class="customers-empty-title">No customers found</p>
                                            <p class="customers-empty-copy">
                                                @if($searchActive)
                                                    Try another name or mobile number, or clear the search to return to the full directory.
                                                @else
                                                    Add a customer profile to start tracking purchases, balances, loyalty, and follow-ups.
                                                @endif
                                            </p>
                                        </div>
                                        <div class="customers-empty-actions">
                                            @if($searchActive)
                                                <a href="{{ route('customers.index') }}" class="customers-empty-secondary">
                                                    Clear Search
                                                </a>
                                            @else
                                                @can('customers.create')
                                                    <a href="{{ route('customers.create') }}" class="customers-empty-primary">
                                                        Add Customer
                                                    </a>
                                                @endcan
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        <tr data-no-match-row style="display:none;">
                            <td colspan="4" class="jf-table-empty">
                                <div class="customers-inline-empty">
                                    <strong>No customers match this page search.</strong>
                                    <span>Press Enter to search all customer records.</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="customers-mobile-cards customers-mobile-cards--directory">
                @forelse($customers as $customer)
                    <article class="customers-mobile-card">
                        <div class="customers-mobile-card__top">
                            <div class="customers-mobile-card__identity">
                                <x-customer-avatar :customer="$customer" />
                                <span>
                                    <span class="customers-mobile-card__title">
                                        {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) }}
                                        @unless($customer->is_active)
                                            <span class="ml-1 inline-flex items-center rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">Archived</span>
                                        @endunless
                                    </span>
                                    <span class="customers-mobile-card__sub">{{ \App\Support\Mobile::forDisplay($customer->mobile) ?: 'No mobile' }}</span>
                                </span>
                            </div>
                            @if(!$isRetailer)
                                <span class="customers-mobile-pill">{{ number_format((float) ($customer->gold_transactions_sum_fine_gold ?? 0), 3) }} g</span>
                            @else
                                @php $purchaseCount = $customer->invoices_count; @endphp
                                <span class="customers-mobile-pill">{{ $purchaseCount }} {{ Str::plural('invoice', $purchaseCount) }}</span>
                            @endif
                        </div>

                        @if($customer->email)
                            <div class="customers-mobile-card__line">
                                <span>Email</span>
                                <strong>{{ $customer->email }}</strong>
                            </div>
                        @endif

                        <div class="customers-mobile-card__actions">
                            <a href="{{ route('customers.show', $customer) }}" class="customers-row-action customers-row-action--primary">View</a>
                            <a href="{{ route('customers.edit', $customer) }}" class="customers-row-action">Edit</a>
                            @can('customers.delete')
                                {{-- Inline flex/height here rather than a new app.css rule: the
                                     card actions are a flex row whose children stretch, and the
                                     POST wrapper form is now the child. Keeps the 44px touch
                                     target without a CSS rebuild. --}}
                                @if($customer->is_active)
                                    <form action="{{ route('customers.archive', $customer) }}" method="POST" style="flex:1 1 0;display:flex;"
                                          onsubmit="return confirm('Archive this customer? Invoices, balances, EMIs and repairs are all kept — the customer just stops appearing when you start a new transaction.')">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" aria-label="Archive customer" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-semibold bg-amber-700 text-white transition-colors hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2" style="width:100%;min-height:44px;">Archive</button>
                                    </form>
                                @else
                                    <form action="{{ route('customers.reactivate', $customer) }}" method="POST" style="flex:1 1 0;display:flex;">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" aria-label="Reactivate customer" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-semibold bg-emerald-700 text-white transition-colors hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2" style="width:100%;min-height:44px;">Reactivate</button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="customers-mobile-empty">
                        <strong>No customers found</strong>
                        <span>{{ $searchActive ? 'Try another name or mobile number, or clear the search.' : 'Add a customer profile to start tracking purchases and follow-ups.' }}</span>
                        @if($searchActive)
                            <a href="{{ route('customers.index') }}" class="customers-empty-secondary">
                                Clear Search
                            </a>
                        @else
                            @can('customers.create')
                                <a href="{{ route('customers.create') }}" class="customers-empty-primary">
                                    Add Customer
                                </a>
                            @endcan
                        @endif
                    </div>
                @endforelse
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

            <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid mb-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <x-icon name="star" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Issued</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($totalPointsIssued) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-rose-100 text-rose-700 rounded-xl p-2.5">
                            <x-icon name="gift" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Redeemed</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($totalPointsRedeemed) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-green-100 text-green-700 rounded-xl p-2.5">
                            <x-icon name="award" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Members with Points</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($loyaltyCustomers->total()) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--loyalty">
                <div class="customers-table-card-header customers-register-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">Loyalty Points</h2>
                        <p class="text-sm text-slate-500 mt-1">{{ $loyaltyCustomers->total() }} customers with loyalty balances</p>
                    </div>

                    <form method="GET" action="{{ route('customers.index') }}" class="ui-filter-bar customers-register-toolbar customers-register-toolbar--search">
                        <input type="hidden" name="view" value="loyalty">
                        <div class="ui-filter-field">
                            <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                                    <x-icon name="search" size="16" />
                                </span>
                                <input type="text" name="loyalty_search" value="{{ request('loyalty_search') }}" placeholder="Customer name or mobile..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                            </div>
                        </div>
                        <div class="ui-filter-actions">
                            @if(filled(request('loyalty_search')))
                                <a href="{{ route('customers.index', ['view' => 'loyalty']) }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Clear</a>
                            @else
                                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Search</button>
                            @endif
                        </div>
                    </form>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell">
                    <table class="w-full jf-table customers-data-table customers-data-table--loyalty">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mobile</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Points</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Value (₹)</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse($loyaltyCustomers as $lCustomer)
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4 text-sm font-semibold text-slate-900">
                                    <a href="{{ route('customers.show', $lCustomer) }}" class="hover:text-amber-700 transition-colors">{{ $lCustomer->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ \App\Support\Mobile::forDisplay($lCustomer->mobile) }}</td>
                                <td class="px-6 py-4 text-sm text-right">
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800 tabular-nums">
                                        {{ number_format($lCustomer->loyalty_points) }} pts
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-right text-slate-700 tabular-nums">₹{{ number_format($lCustomer->loyalty_points * 0.25, 2) }}</td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                        <a href="{{ route('customers.show', $lCustomer) }}" class="customers-row-action customers-row-action--primary inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><x-icon name="eye" size="12" />View Profile</a>
                                        <a href="{{ route('loyalty.adjust.form', $lCustomer) }}" class="customers-row-action inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><x-icon name="sliders-vertical" size="12" />Adjust</a>
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="jf-table-empty">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No loyalty members yet</p>
                                    <p class="text-sm">Points are earned automatically when customers make purchases.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="customers-mobile-cards customers-mobile-cards--loyalty">
                    @forelse($loyaltyCustomers as $lCustomer)
                        <article class="customers-mobile-card">
                            <div class="customers-mobile-card__top">
                                <div>
                                    <span class="customers-mobile-card__title">{{ $lCustomer->name }}</span>
                                    <span class="customers-mobile-card__sub">{{ \App\Support\Mobile::forDisplay($lCustomer->mobile) ?: 'No mobile' }}</span>
                                </div>
                                <span class="customers-mobile-pill">{{ number_format($lCustomer->loyalty_points) }} pts</span>
                            </div>

                            <div class="customers-mobile-card__line">
                                <span>Value</span>
                                <strong>₹{{ number_format($lCustomer->loyalty_points * 0.25, 2) }}</strong>
                            </div>

                            <div class="customers-mobile-card__actions">
                                <a href="{{ route('customers.show', $lCustomer) }}" class="customers-row-action customers-row-action--primary">View Profile</a>
                                <a href="{{ route('loyalty.adjust.form', $lCustomer) }}" class="customers-row-action">Adjust</a>
                            </div>
                        </article>
                    @empty
                        <div class="customers-mobile-empty">
                            <strong>No loyalty members yet</strong>
                            <span>Points are earned automatically when customers make purchases.</span>
                        </div>
                    @endforelse
                </div>

                @if($loyaltyCustomers->hasPages())
                    <div class="px-6 py-4 border-t border-slate-200">{{ $loyaltyCustomers->appends(['view' => 'loyalty', 'loyalty_search' => request('loyalty_search')])->links() }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- ==================== EMI / INSTALLMENTS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $installmentData)
        <div x-show="view === 'emi'" x-cloak>
            @php
                $installmentPlans = $installmentData['installmentPlans'];
                $overduePlans = $installmentData['overduePlans'];
                $totalOutstanding = $installmentData['totalOutstanding'];
                $installmentStatus = $installmentData['installmentStatus'];
            @endphp

            <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid mb-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <x-icon name="clipboard" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Active Plans</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($installmentPlans->total()) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-rose-100 text-rose-700 rounded-xl p-2.5">
                            <x-icon name="clock" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Overdue</p>
                            <p class="text-2xl font-semibold text-rose-600 tabular-nums">{{ number_format($overduePlans) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <x-icon name="coins" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Outstanding</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">₹{{ number_format($totalOutstanding, 2) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--emi">
                <div class="customers-table-card-header customers-register-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">EMI / Installments</h2>
                        <p class="text-sm text-slate-500 mt-1">{{ $installmentPlans->total() }} plans in this view</p>
                    </div>

                    <div class="customers-register-toolbar customers-register-toolbar--emi">
                        <form method="GET" action="{{ route('customers.index') }}" class="ui-filter-bar customers-register-inline-form" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <input type="hidden" name="view" value="emi">
                            <div class="ui-filter-field">
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Status</label>
                                <select name="emi_status" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                                    <option value="active" {{ $installmentStatus === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="completed" {{ $installmentStatus === 'completed' ? 'selected' : '' }}>Completed</option>
                                    <option value="defaulted" {{ $installmentStatus === 'defaulted' ? 'selected' : '' }}>Defaulted</option>
                                    <option value="" {{ $installmentStatus === '' ? 'selected' : '' }}>All</option>
                                </select>
                            </div>
                            <div class="ui-filter-actions">
                                @if(request()->has('emi_status'))
                                    <a href="{{ route('customers.index', ['view' => 'emi']) }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Clear</a>
                                @else
                                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Filter</button>
                                @endif
                            </div>
                        </form>

                        @can('sales.create')
                            <a href="{{ route('installments.create') }}" class="btn btn-dark btn-sm customers-primary-action">
                                <x-icon name="plus" size="14" class="mr-1.5" />
                                Create EMI Plan
                            </a>
                        @endcan
                    </div>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell">
                    <table class="w-full jf-table customers-data-table customers-data-table--emi">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">EMI</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Progress</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Next Due</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-100">
                            @forelse($installmentPlans as $plan)
                            @php $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active'; @endphp
                            <tr class="transition-colors {{ $isOverdue ? 'bg-rose-50 hover:bg-rose-100/70' : 'hover:bg-slate-50/70' }}">
                                <td class="px-6 py-4 text-sm font-semibold text-slate-900">{{ $plan->customer->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-right text-slate-700 tabular-nums">₹{{ number_format($plan->total_amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-right text-slate-700 tabular-nums">₹{{ number_format($plan->emi_amount, 2) }}</td>
                                <td class="px-6 py-4 text-center text-sm text-slate-700 tabular-nums">{{ $plan->emis_paid }}/{{ $plan->total_emis }}</td>
                                <td class="px-6 py-4 text-sm {{ $isOverdue ? 'text-rose-600 font-semibold' : 'text-slate-600' }}">
                                    {{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : '—' }}
                                    @if($isOverdue) <span class="text-xs">(overdue)</span> @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @php
                                        $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'completed' => 'bg-emerald-100 text-emerald-800', 'defaulted' => 'bg-rose-100 text-rose-800'];
                                    @endphp
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $statusColors[$plan->status] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ ucfirst($plan->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('installments.show', $plan) }}" class="customers-row-action customers-row-action--primary inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><x-icon name="eye" size="12" />View</a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="jf-table-empty">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No installment plans found</p>
                                    <p class="text-sm">Create an EMI plan from Invoices or the EMI module.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="customers-mobile-cards customers-mobile-cards--emi">
                    @forelse($installmentPlans as $plan)
                        @php $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active'; @endphp
                        <article class="customers-mobile-card {{ $isOverdue ? 'is-warning' : '' }}">
                            <div class="customers-mobile-card__top">
                                <div>
                                    <span class="customers-mobile-card__title">{{ $plan->customer->name ?? '—' }}</span>
                                    <span class="customers-mobile-card__sub">{{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : 'No due date' }}</span>
                                </div>
                                <span class="customers-mobile-pill {{ $isOverdue ? 'is-danger' : '' }}">{{ ucfirst($plan->status) }}</span>
                            </div>

                            <div class="customers-mobile-card__metrics">
                                <div>
                                    <span>Total</span>
                                    <strong>₹{{ number_format($plan->total_amount, 2) }}</strong>
                                </div>
                                <div>
                                    <span>EMI</span>
                                    <strong>₹{{ number_format($plan->emi_amount, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Progress</span>
                                    <strong>{{ $plan->emis_paid }}/{{ $plan->total_emis }}</strong>
                                </div>
                                <div>
                                    <span>Next due</span>
                                    <strong>{{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M') : '—' }}</strong>
                                </div>
                            </div>

                            <div class="customers-mobile-card__actions">
                                <a href="{{ route('installments.show', $plan) }}" class="customers-row-action customers-row-action--primary">View</a>
                            </div>
                        </article>
                    @empty
                        <div class="customers-mobile-empty">
                            <strong>No installment plans found</strong>
                            <span>Create an EMI plan from Invoices or the EMI module.</span>
                        </div>
                    @endforelse
                </div>

                @if($installmentPlans->hasPages())
                    <div class="px-6 py-4 border-t border-slate-200">{{ $installmentPlans->appends(['view' => 'emi', 'emi_status' => $installmentStatus])->links() }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- ==================== CUSTOMER OCCASIONS VIEW (Retailers Only) ==================== --}}
        @if($isRetailer && $occasionsData)
        <div x-show="view === 'occasions'" x-cloak>
            @php
                $upcomingOccasions = collect($occasionsData['upcoming'] ?? []);
                $occasionDaysAhead = (int) ($occasionsData['daysAhead'] ?? 30);
                $occasionThisWeek = $upcomingOccasions->where('days_until', '<=', 7)->count();
                $occasionCustomersCount = $upcomingOccasions->pluck('customer_id')->unique()->count();
            @endphp

            <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid mb-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <x-icon name="calendar" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Upcoming</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($upcomingOccasions->count()) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <x-icon name="clock" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Next 7 Days</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($occasionThisWeek) }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                            <x-icon name="users" class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Customers</p>
                            <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($occasionCustomersCount) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--occasions">
                <div class="customers-table-card-header customers-register-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">Customer Occasions</h2>
                        <p class="text-sm text-slate-500 mt-1">Birthdays and anniversaries due in the next {{ $occasionDaysAhead }} days</p>
                    </div>

                    <form method="GET" action="{{ route('customers.index') }}" class="ui-filter-bar customers-register-toolbar customers-register-toolbar--select" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                        <input type="hidden" name="view" value="occasions">
                        <div class="ui-filter-field">
                            <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Reminder window</label>
                            <select name="occasion_days" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                                @foreach([7, 15, 30, 60, 90] as $days)
                                    <option value="{{ $days }}" {{ $occasionDaysAhead === $days ? 'selected' : '' }}>Next {{ $days }} days</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ui-filter-actions">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">Apply</button>
                        </div>
                    </form>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell">
                    <table class="w-full jf-table customers-data-table customers-data-table--occasions">
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
                            @forelse($upcomingOccasions as $occasion)
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
                                        <a href="{{ route('customers.show', $occasion['customer_id']) }}" class="customers-row-action customers-row-action--primary inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            <x-icon name="eye" size="12" />
                                            View Profile
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="jf-table-empty">
                                        <p class="text-lg font-semibold mb-1 text-slate-700">No occasions in this window</p>
                                        <p class="text-sm">Increase the reminder window or add occasion dates on customer profiles.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="customers-mobile-cards customers-mobile-cards--occasions">
                    @forelse($upcomingOccasions as $occasion)
                        <article class="customers-mobile-card">
                            <div class="customers-mobile-card__top">
                                <div>
                                    <span class="customers-mobile-card__title">{{ $occasion['customer_name'] }}</span>
                                    <span class="customers-mobile-card__sub">{{ $occasion['mobile'] ?: 'No mobile' }}</span>
                                </div>
                                <span class="customers-mobile-pill">{{ $occasion['type'] }}</span>
                            </div>

                            <div class="customers-mobile-card__metrics">
                                <div>
                                    <span>Date</span>
                                    <strong>{{ \Carbon\Carbon::parse($occasion['date'])->format('d M Y') }}</strong>
                                </div>
                                <div>
                                    <span>Due in</span>
                                    <strong>{{ (int) $occasion['days_until'] === 0 ? 'Today' : ((int) $occasion['days_until'] . ' days') }}</strong>
                                </div>
                            </div>

                            <div class="customers-mobile-card__actions">
                                <a href="{{ route('customers.show', $occasion['customer_id']) }}" class="customers-row-action customers-row-action--primary">View Profile</a>
                            </div>
                        </article>
                    @empty
                        <div class="customers-mobile-empty">
                            <strong>No occasions in this window</strong>
                            <span>Increase the reminder window or add occasion dates on customer profiles.</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
        @endif

    </div>
</x-app-layout>
