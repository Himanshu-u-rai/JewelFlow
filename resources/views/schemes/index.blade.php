@php
    $hasFilters = request()->hasAny(['search', 'type']);
    $activeFilterCount = collect(['search', 'type'])->filter(fn ($key) => request()->filled($key))->count();
    $schemeTotal = method_exists($schemes, 'total') ? $schemes->total() : $schemes->count();
    $typeColors = [
        'gold_savings' => 'schemes-type-badge--gold',
        'festival_sale' => 'schemes-type-badge--festival',
        'discount_offer' => 'schemes-type-badge--discount',
    ];
    $typeLabels = [
        'gold_savings' => 'Gold Savings',
        'festival_sale' => 'Festival Sale',
        'discount_offer' => 'Discount Offer',
    ];
@endphp

<x-app-layout>
    <x-page-header
        class="customers-page-header schemes-page-header"
        title="Schemes"
        subtitle="Manage savings plans and store offers"
    >
        <x-slot:actions>
            @can('catalog.manage')
                <a href="{{ route('schemes.create') }}" class="btn btn-success btn-sm customers-add-btn">
                    <x-icon name="plus" class="w-4 h-4 mr-2" />
                    <span class="schemes-add-label-full">Create Scheme</span>
                    <span class="schemes-add-label-short">Create</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div
        class="content-inner customers-index-page schemes-index-page jf-skeleton-host is-loading"
        x-data="{ schemesFiltersOpen: false }"
        x-effect="document.body.classList.toggle('overflow-hidden', schemesFiltersOpen)"
        @keydown.escape.window="schemesFiltersOpen = false"
    >
        @unless(auth()->user()->can('catalog.manage') || auth()->user()->can('sales.create'))
            @include('partials.view-only-banner', ['permission' => 'catalog.manage', 'message' => 'schemes and enrollments'])
        @endunless

        <div class="ui-stats-grid ui-stats-grid-3 customers-kpi-grid schemes-kpi-grid mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <x-icon name="coins" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Gold Savings</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($stats->gold_savings_count ?? 0) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5 schemes-kpi-icon--rose">
                        <x-icon name="tag" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Offers / Sales</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($stats->offers_count ?? 0) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 customers-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <x-icon name="badge-check" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Active</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($stats->active_count ?? 0) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden customers-table-card schemes-table-card">
            <div class="customers-table-card-header customers-register-head p-5 border-b border-slate-200">
                <div class="customers-register-titleblock">
                    <h2 class="text-lg font-semibold text-slate-900">Scheme register</h2>
                    <p class="text-sm text-slate-500 mt-1">
                        {{ number_format($schemeTotal) }} {{ Str::plural('record', $schemeTotal) }}
                        @if($hasFilters)
                            <span class="schemes-filter-summary">Filtered</span>
                        @endif
                    </p>
                </div>

                <form method="GET" action="{{ route('schemes.index') }}" class="ui-filter-bar customers-register-toolbar schemes-register-toolbar schemes-desktop-filters" data-enhance-selects="true" data-enhance-selects-variant="standard">
                    <div class="ui-filter-field">
                        <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2" for="schemes-search">Search</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                                <x-icon name="search" size="16" />
                            </span>
                            <input id="schemes-search" type="text" name="search" value="{{ request('search') }}" placeholder="Search scheme or description..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none" autocomplete="off">
                        </div>
                    </div>

                    <div class="ui-filter-field">
                        <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2" for="schemes-type">Type</label>
                        <select id="schemes-type" name="type" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                            <option value="">All types</option>
                            <option value="gold_savings" {{ request('type') === 'gold_savings' ? 'selected' : '' }}>Gold Savings</option>
                            <option value="festival_sale" {{ request('type') === 'festival_sale' ? 'selected' : '' }}>Festival Sale</option>
                            <option value="discount_offer" {{ request('type') === 'discount_offer' ? 'selected' : '' }}>Discount Offer</option>
                        </select>
                    </div>

                    <div class="ui-filter-actions schemes-filter-actions">
                        @if($hasFilters)
                            <a href="{{ route('schemes.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Clear</a>
                        @endif
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">
                            Filter
                        </button>
                    </div>
                </form>

                <div class="schemes-mobile-controls">
                    <form method="GET" action="{{ route('schemes.index') }}" class="schemes-mobile-search">
                        <input type="hidden" name="type" value="{{ request('type') }}">
                        <label class="sr-only" for="schemes-mobile-search">Search schemes</label>
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-600">
                            <x-icon name="search" size="15" />
                        </span>
                        <input id="schemes-mobile-search" type="text" name="search" value="{{ request('search') }}" placeholder="Search schemes..." autocomplete="off">
                    </form>

                    <button type="button" class="schemes-filter-trigger" @click="schemesFiltersOpen = true" :aria-expanded="schemesFiltersOpen.toString()">
                        <x-icon name="funnel" size="15" />
                        Filters
                        @if($activeFilterCount)
                            <span>{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell schemes-table-shell">
                <table class="w-full customers-data-table schemes-data-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Scheme</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Period</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Enrollments</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($schemes as $scheme)
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900 schemes-name">{{ $scheme->name }}</div>
                                    <div class="mt-1 text-sm text-slate-500 schemes-description">{{ $scheme->description ?: 'No description added yet.' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="schemes-type-badge {{ $typeColors[$scheme->type] ?? 'schemes-type-badge--discount' }}">
                                        {{ $typeLabels[$scheme->type] ?? $scheme->type }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600">
                                    {{ $scheme->start_date?->format('d M Y') ?? 'Not set' }}
                                    @if($scheme->end_date)
                                        <span class="text-slate-400">to</span> {{ $scheme->end_date->format('d M Y') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center tabular-nums text-slate-900">{{ number_format($scheme->enrollments_count ?? 0) }}</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="schemes-status-badge {{ $scheme->is_active ? 'schemes-status-badge--active' : 'schemes-status-badge--inactive' }}">
                                        {{ $scheme->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="{{ route('schemes.show', $scheme) }}" class="customers-row-action customers-row-action--primary">
                                            View
                                        </a>
                                        @if($scheme->isGoldSavings())
                                            @can('sales.create')
                                                <a href="{{ route('schemes.enroll.form', $scheme) }}" class="customers-row-action">
                                                    Enroll
                                                </a>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center">
                                    <div class="customers-mobile-empty schemes-empty-inline">
                                        <strong>No schemes found</strong>
                                        <span>Create a gold savings scheme or promotional offer to get started.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="customers-mobile-cards schemes-mobile-cards">
                @forelse($schemes as $scheme)
                    <article class="customers-mobile-card">
                        <div class="customers-mobile-card__top">
                            <div class="customers-mobile-card__identity">
                                <span class="customers-mobile-avatar">{{ Str::of($scheme->name)->trim()->substr(0, 1)->upper() }}</span>
                                <div>
                                    <a href="{{ route('schemes.show', $scheme) }}" class="customers-mobile-card__title">{{ $scheme->name }}</a>
                                    <span class="customers-mobile-card__sub">{{ $scheme->description ?: 'No description added yet.' }}</span>
                                </div>
                            </div>
                            <span class="customers-mobile-pill {{ $scheme->is_active ? '' : 'is-danger' }}">{{ $scheme->is_active ? 'Active' : 'Inactive' }}</span>
                        </div>

                        <div class="customers-mobile-card__metrics">
                            <div>
                                <span>Type</span>
                                <strong>{{ $typeLabels[$scheme->type] ?? $scheme->type }}</strong>
                            </div>
                            <div>
                                <span>Enrollments</span>
                                <strong>{{ number_format($scheme->enrollments_count ?? 0) }}</strong>
                            </div>
                            <div>
                                <span>Starts</span>
                                <strong>{{ $scheme->start_date?->format('d M Y') ?? 'Not set' }}</strong>
                            </div>
                            <div>
                                <span>Ends</span>
                                <strong>{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended' }}</strong>
                            </div>
                        </div>

                        <div class="customers-mobile-card__actions">
                            <a href="{{ route('schemes.show', $scheme) }}" class="customers-row-action customers-row-action--primary">View</a>
                            @if($scheme->isGoldSavings())
                                @can('sales.create')
                                    <a href="{{ route('schemes.enroll.form', $scheme) }}" class="customers-row-action">Enroll</a>
                                @endcan
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="customers-mobile-empty">
                        <strong>No schemes found</strong>
                        <span>Create a gold savings scheme or promotional offer to get started.</span>
                    </div>
                @endforelse
            </div>

            @if($schemes->hasPages())
                <div class="schemes-pagination">{{ $schemes->links() }}</div>
            @endif
        </section>

        <div class="schemes-filter-sheet" x-cloak x-show="schemesFiltersOpen" x-transition.opacity>
            <button type="button" class="schemes-filter-backdrop" aria-label="Close filters" @click="schemesFiltersOpen = false"></button>
            <aside class="schemes-filter-panel" x-transition>
                <div class="schemes-filter-panel__head">
                    <div>
                        <h3>Filters</h3>
                        <p>Search and filter the scheme register.</p>
                    </div>
                    <button type="button" class="schemes-filter-close" aria-label="Close filters" @click="schemesFiltersOpen = false">
                        <x-icon name="x" size="18" />
                    </button>
                </div>

                <form method="GET" action="{{ route('schemes.index') }}" class="schemes-filter-panel__body">
                    <div class="ui-filter-field">
                        <label for="schemes-drawer-search">Search</label>
                        <input id="schemes-drawer-search" type="text" name="search" value="{{ request('search') }}" placeholder="Scheme or description" autocomplete="off">
                    </div>

                    <div class="ui-filter-field">
                        <label for="schemes-drawer-type">Type</label>
                        <select id="schemes-drawer-type" name="type">
                            <option value="">All types</option>
                            <option value="gold_savings" {{ request('type') === 'gold_savings' ? 'selected' : '' }}>Gold Savings</option>
                            <option value="festival_sale" {{ request('type') === 'festival_sale' ? 'selected' : '' }}>Festival Sale</option>
                            <option value="discount_offer" {{ request('type') === 'discount_offer' ? 'selected' : '' }}>Discount Offer</option>
                        </select>
                    </div>

                    <div class="schemes-filter-panel__actions">
                        @if($hasFilters)
                            <a href="{{ route('schemes.index') }}" class="customers-row-action">Clear</a>
                        @endif
                        <button type="submit" class="customers-row-action customers-row-action--primary">Apply filters</button>
                    </div>
                </form>
            </aside>
        </div>
    </div>
</x-app-layout>
