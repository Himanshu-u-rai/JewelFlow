<x-app-layout>
    @php
        $isRetailer = auth()->user()->shop?->isRetailer();
        $searchActive = request()->filled('search');
        $viewParam = request('view', 'customers');
        $validViews = ['customers', 'loyalty', 'emi', 'occasions'];
        $viewDefault = in_array($viewParam, $validViews) ? $viewParam : 'customers';
    @endphp

    <div class="customers-workspace" x-data="{ view: '{{ $viewDefault }}' }">
    <x-page-header
        class="customers-page-header customers-directory-header"
        title="Customers"
        :subtitle="'Manage customer profiles' . ($isRetailer ? '' : ' and balances')"
    >
        <x-slot:actions>
            @if($isRetailer)
            <nav aria-label="Customer views" class="ui-toggle-strip customers-view-toggle inline-flex flex-wrap items-center gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                <button type="button" @click="view = 'customers'" aria-pressed="{{ $viewDefault === 'customers' ? 'true' : 'false' }}" :aria-pressed="view === 'customers'" :class="view === 'customers' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                    <x-icon name="users" size="14" />
                    Customer Directory
                </button>
                <button type="button" @click="view = 'loyalty'" aria-pressed="{{ $viewDefault === 'loyalty' ? 'true' : 'false' }}" :aria-pressed="view === 'loyalty'" :class="view === 'loyalty' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                    <x-icon name="star" size="14" />
                    Loyalty Points
                </button>
                @can('installments.view')
                <button type="button" @click="view = 'emi'" aria-pressed="{{ $viewDefault === 'emi' ? 'true' : 'false' }}" :aria-pressed="view === 'emi'" :class="view === 'emi' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                    <x-icon name="credit-card" size="14" />
                    EMI / Installments
                </button>
                @endcan
                <button type="button" @click="view = 'occasions'" aria-pressed="{{ $viewDefault === 'occasions' ? 'true' : 'false' }}" :aria-pressed="view === 'occasions'" :class="view === 'occasions' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" class="customers-view-tab inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition">
                    <x-icon name="calendar" size="14" />
                    Occasions
                </button>
            </nav>
            @endif
            @can('customers.create')
            <a href="{{ route('customers.create') }}"
               aria-label="Add Customer" title="Add Customer"
               class="btn btn-success btn-sm customers-add-btn">
                <x-icon name="user-plus" class="w-4 h-4 mr-2" />
                <span class="customers-add-label">Add Customer</span>
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page jf-skeleton-host is-loading" data-list-workspace>

        @php
            $canModifyCustomers = auth()->user()->can('customers.create')
                || auth()->user()->can('customers.edit')
                || auth()->user()->can('customers.delete');
        @endphp
        @unless($canModifyCustomers)
            @include('partials.view-only-banner', ['permission' => 'customers.edit', 'message' => 'customer management'])
        @endunless

        {{-- ==================== CUSTOMERS VIEW ==================== --}}
        <div data-list-panel x-show="view === 'customers'" x-cloak>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--directory" data-list-frame>
            <div class="customers-table-card-header customers-register-head customers-directory-head p-5 border-b border-slate-200">
                <div class="customers-register-titleblock">
                    <h2 class="text-lg font-semibold text-slate-900">Customer Directory</h2>
                    <dl class="customers-directory-summary" aria-label="Customer summary">
                        <div><dt>Total Customers</dt><dd>{{ number_format($customers->total()) }}</dd></div>
                        @if($isRetailer)
                            <div><dt>Total Purchases</dt><dd>{{ number_format($retailerInvoiceCount) }}</dd></div>
                        @else
                            <div><dt>Gold on This Page</dt><dd>{{ number_format($pageGoldTotal, 3) }} g</dd></div>
                        @endif
                    </dl>
                </div>

                <x-responsive-filter-panel id="customers-filter-panel" label="Customer filters">
                    <form method="GET" action="{{ route('customers.index') }}" class="customers-directory-controls"
                          x-data="{ searchOpen: {{ $searchActive ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                          @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                        <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                             @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                            <button type="button" data-customer-search-toggle x-cloak x-ref="searchToggle"
                                    class="customers-directory-search-toggle" aria-label="Search customers"
                                    aria-controls="customers-search-panel" aria-expanded="{{ $searchActive ? 'true' : 'false' }}"
                                    :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide customer search' : 'Search customers'"
                                    :title="searchOpen ? 'Hide search' : 'Search customers'"
                                    @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                <x-icon name="search" size="18" />
                            </button>
                            <div id="customers-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                <label for="customers-search" class="jf-sr-only">Search customers</label>
                                <input
                                    id="customers-search" type="search" x-ref="searchInput"
                                    name="search"
                                    value="{{ request('search') }}"
                                    placeholder="Name or mobile number..."
                                    data-suggest="customers"
                                    data-live-filter="customers-tbody"
                                    autocomplete="off" enterkeyhint="search"
                                >
                            </div>
                        </div>
                        {{-- MASTERS PART 3: archived customers are retained, not deleted,
                             so they stay one click away. Counts carry the same search
                             filter as the list, so a count always matches what the
                             option shows. Both values ride through pagination via the
                             paginator's withQueryString(). --}}
                        <div class="customers-directory-status" role="group" aria-label="Customer status"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="customers-status" class="jf-sr-only">Status</label>
                            <select id="customers-status" name="status">
                                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active ({{ (int) $statusCounts->active_count }})</option>
                                <option value="archived" {{ $status === 'archived' ? 'selected' : '' }}>Archived ({{ (int) $statusCounts->archived_count }})</option>
                                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All ({{ (int) $statusCounts->all_count }})</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                        @if($searchActive)
                            <a href="{{ route('customers.index') }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                        @endif
                    </form>
                </x-responsive-filter-panel>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Customer records">
                <table class="w-full jf-table customers-data-table customers-data-table--directory">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
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
                                <td class="jf-table-index">{{ $customers->firstItem() + $loop->index }}</td>
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
                                    <div class="jf-table-actions jf-table-actions--center">
                                        <a href="{{ route('customers.show', $customer) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                        @can('customers.edit')
                                        <a href="{{ route('customers.edit', $customer) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>
                                        @endcan
                                        @can('customers.delete')
                                            @if($customer->is_active)
                                                <form action="{{ route('customers.archive', $customer) }}" method="POST" class="inline"
                                                      onsubmit="return confirm('Archive {{ addslashes(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))) }}? Invoices, balances, EMIs and repairs are all kept — the customer just stops appearing when you start a new transaction.')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Archive customer" class="jf-row-action jf-row-action--danger" data-tip="Archive"><x-icon name="archive" size="16" /><span class="jf-sr-only">Archive</span></button>
                                                </form>
                                            @else
                                                <form action="{{ route('customers.reactivate', $customer) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Reactivate customer" class="jf-row-action jf-row-action--success" data-tip="Reactivate"><x-icon name="rotate-ccw" size="16" /><span class="jf-sr-only">Reactivate</span></button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="jf-table-empty customers-empty-cell">
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
                            <td colspan="5" class="jf-table-empty">
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
                                        <span class="jf-row-number"><span class="jf-sr-only">Row </span>#{{ $customers->firstItem() + $loop->index }}</span>
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
        <div data-list-panel x-show="view === 'loyalty'" x-cloak>
            @php
                $loyaltyCustomers = $loyaltyData['loyaltyCustomers'];
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--loyalty" data-list-frame>
                <div class="customers-table-card-header customers-register-head customers-directory-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">Loyalty Points</h2>
                        <dl class="customers-directory-summary" aria-label="Loyalty summary">
                            <div><dt>Members with Points</dt><dd>{{ number_format($loyaltyCustomers->total()) }}</dd></div>
                        </dl>
                    </div>

                    <x-responsive-filter-panel id="loyalty-filter-panel" label="Loyalty filters">
                        <form method="GET" action="{{ route('customers.index') }}" class="customers-directory-controls"
                              x-data="{ searchOpen: {{ request()->filled('loyalty_search') ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                              @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                            <input type="hidden" name="view" value="loyalty">
                            <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                                 @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                                <button type="button" data-customer-search-toggle x-cloak x-ref="searchToggle"
                                        class="customers-directory-search-toggle" aria-label="Search loyalty members"
                                        aria-controls="loyalty-search-panel" aria-expanded="{{ request()->filled('loyalty_search') ? 'true' : 'false' }}"
                                        :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide loyalty search' : 'Search loyalty members'"
                                        :title="searchOpen ? 'Hide search' : 'Search loyalty members'"
                                        @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                    <x-icon name="search" size="18" />
                                </button>
                                <div id="loyalty-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                    <label for="loyalty-search" class="jf-sr-only">Search loyalty members</label>
                                    <input id="loyalty-search" type="search" x-ref="searchInput" name="loyalty_search"
                                           value="{{ request('loyalty_search') }}" placeholder="Customer name or mobile..."
                                           autocomplete="off" enterkeyhint="search">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                            @if(request()->filled('loyalty_search'))
                                <a href="{{ route('customers.index', ['view' => 'loyalty']) }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                            @endif
                        </form>
                    </x-responsive-filter-panel>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Loyalty records">
                    <table class="w-full jf-table customers-data-table customers-data-table--loyalty">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
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
                                <td class="jf-table-index">{{ $loyaltyCustomers->firstItem() + $loop->index }}</td>
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
                                    <div class="jf-table-actions jf-table-actions--center">
                                        <a href="{{ route('customers.show', $lCustomer) }}" class="jf-row-action jf-row-action--view" data-tip="View Profile"><x-icon name="eye" size="16" /><span class="jf-sr-only">View Profile</span></a>
                                        @can('loyalty.adjust')
                                        <a href="{{ route('loyalty.adjust.form', $lCustomer) }}" class="jf-row-action jf-row-action--edit" data-tip="Adjust points"><x-icon name="sliders-vertical" size="16" /><span class="jf-sr-only">Adjust points</span></a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="jf-table-empty">
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
                                    <span class="customers-mobile-card__title"><span class="jf-row-number"><span class="jf-sr-only">Row </span>#{{ $loyaltyCustomers->firstItem() + $loop->index }}</span>{{ $lCustomer->name }}</span>
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
                                @can('loyalty.adjust')
                                <a href="{{ route('loyalty.adjust.form', $lCustomer) }}" class="customers-row-action">Adjust</a>
                                @endcan
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
        <div data-list-panel x-show="view === 'emi'" x-cloak>
            @php
                $installmentPlans = $installmentData['installmentPlans'];
                $installmentStatus = $installmentData['installmentStatus'];
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--emi" data-list-frame>
                <div class="customers-table-card-header customers-register-head customers-directory-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">EMI / Installments</h2>
                        <dl class="customers-directory-summary" aria-label="Installment summary">
                            <div><dt>Plans in View</dt><dd>{{ number_format($installmentPlans->total()) }}</dd></div>
                        </dl>
                    </div>

                    <div class="customers-directory-controls">
                        <x-responsive-filter-panel id="emi-filter-panel" label="Installment filters">
                            <form method="GET" action="{{ route('customers.index') }}" class="customers-directory-controls">
                                <input type="hidden" name="view" value="emi">
                                <div class="customers-directory-status" role="group" aria-label="Installment status"
                                     data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                                    <label for="customers-emi-status" class="jf-sr-only">Status</label>
                                    <select id="customers-emi-status" name="emi_status">
                                        <option value="active" {{ $installmentStatus === 'active' ? 'selected' : '' }}>Active</option>
                                        <option value="completed" {{ $installmentStatus === 'completed' ? 'selected' : '' }}>Completed</option>
                                        <option value="defaulted" {{ $installmentStatus === 'defaulted' ? 'selected' : '' }}>Defaulted</option>
                                        <option value="" {{ $installmentStatus === '' ? 'selected' : '' }}>All</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                                @if(request()->has('emi_status'))
                                    <a href="{{ route('customers.index', ['view' => 'emi']) }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                                @endif
                            </form>
                        </x-responsive-filter-panel>
                        @can('installments.create')
                            <a href="{{ route('installments.create') }}" class="btn btn-primary btn-sm customers-directory-apply">
                                <x-icon name="plus" size="14" class="mr-1.5" />
                                Create EMI Plan
                            </a>
                        @endcan
                    </div>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Installment records">
                    <table class="w-full jf-table customers-data-table customers-data-table--emi">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
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
                                <td class="jf-table-index">{{ $installmentPlans->firstItem() + $loop->index }}</td>
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
                                    <a href="{{ route('installments.show', $plan) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="jf-table-empty">
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
                                    <span class="customers-mobile-card__title"><span class="jf-row-number"><span class="jf-sr-only">Row </span>#{{ $installmentPlans->firstItem() + $loop->index }}</span>{{ $plan->customer->name ?? '—' }}</span>
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
        <div data-list-panel x-show="view === 'occasions'" x-cloak>
            @php
                $upcomingOccasions = collect($occasionsData['upcoming'] ?? []);
                $occasionDaysAhead = (int) ($occasionsData['daysAhead'] ?? 30);
            @endphp

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden customers-table-card customers-table-card--occasions" data-list-frame>
                <div class="customers-table-card-header customers-register-head customers-directory-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">Customer Occasions</h2>
                    </div>

                    <x-responsive-filter-panel id="occasions-filter-panel" label="Occasion filters">
                        <form method="GET" action="{{ route('customers.index') }}" class="customers-directory-controls">
                            <input type="hidden" name="view" value="occasions">
                            <div class="customers-directory-status" role="group" aria-label="Reminder window"
                                 data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                                <label for="customers-occasion-days" class="jf-sr-only">Reminder window</label>
                                <select id="customers-occasion-days" name="occasion_days">
                                    @foreach([7, 15, 30, 60, 90] as $days)
                                        <option value="{{ $days }}" {{ $occasionDaysAhead === $days ? 'selected' : '' }}>Next {{ $days }} days</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                        </form>
                    </x-responsive-filter-panel>
                </div>
                <div class="overflow-x-auto ui-table-shell customers-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Occasion records">
                    <table class="w-full jf-table customers-data-table customers-data-table--occasions">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
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
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
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
                                        <a href="{{ route('customers.show', $occasion['customer_id']) }}" class="jf-row-action jf-row-action--view" data-tip="View Profile"><x-icon name="eye" size="16" /><span class="jf-sr-only">View Profile</span></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="jf-table-empty">
                                        <p class="text-lg font-semibold mb-1 text-slate-700">No occasions in this window</p>
                                        <p class="text-sm">Increase the reminder window or add occasion dates on customer profiles.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @include('customers._occasion-cards', ['occasions' => $upcomingOccasions])
            </div>
        </div>
        @endif

    </div>
    </div>
</x-app-layout>
