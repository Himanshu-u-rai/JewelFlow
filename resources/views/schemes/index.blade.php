@php
    $hasFilters = request()->hasAny(['search', 'type']);
    $searchActive = request()->filled('search');
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
        class="customers-page-header customers-directory-header schemes-page-header"
        title="Schemes"
        subtitle="Manage savings plans and store offers"
    >
        <x-slot:actions>
            @can('catalog.manage')
                <a href="{{ route('schemes.create') }}" class="btn btn-success btn-sm customers-add-btn" aria-label="Create Scheme" title="Create Scheme">
                    <x-icon name="plus" class="w-4 h-4 mr-2" />
                    <span class="customers-add-label">Create Scheme</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div
        class="content-inner customers-index-page schemes-index-page jf-skeleton-host is-loading"
        data-list-workspace
    >
        @unless(auth()->user()->can('catalog.manage') || auth()->user()->can('sales.create'))
            @include('partials.view-only-banner', ['permission' => 'catalog.manage', 'message' => 'schemes and enrollments'])
        @endunless

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden customers-table-card schemes-table-card" data-list-frame>
            <div class="customers-table-card-header customers-register-head customers-directory-head schemes-register-head p-5 border-b border-slate-200">
                <div class="customers-register-titleblock">
                    <h2 class="text-lg font-semibold text-slate-900">Scheme register</h2>
                    <dl class="customers-directory-summary" aria-label="Scheme summary">
                        <div><dt>Gold Savings</dt><dd>{{ number_format($stats->gold_savings_count ?? 0) }}</dd></div>
                        <div><dt>Offers / Sales</dt><dd>{{ number_format($stats->offers_count ?? 0) }}</dd></div>
                        <div><dt>Active</dt><dd>{{ number_format($stats->active_count ?? 0) }}</dd></div>
                    </dl>
                </div>

                <x-responsive-filter-panel id="schemes-filter-panel" label="Scheme filters">
                    <form method="GET" action="{{ route('schemes.index') }}" class="customers-directory-controls"
                          x-data="{ searchOpen: {{ $searchActive ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                          @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                        <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                             @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                            <button type="button" data-scheme-search-toggle x-cloak x-ref="searchToggle"
                                    class="customers-directory-search-toggle" aria-label="Search schemes"
                                    aria-controls="schemes-search-panel" aria-expanded="{{ $searchActive ? 'true' : 'false' }}"
                                    :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide scheme search' : 'Search schemes'"
                                    :title="searchOpen ? 'Hide search' : 'Search schemes'"
                                    @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                <x-icon name="search" size="18" />
                            </button>
                            <div id="schemes-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                <label for="schemes-search" class="jf-sr-only">Search schemes</label>
                                <input id="schemes-search" type="search" x-ref="searchInput" name="search"
                                       value="{{ request('search') }}" placeholder="Scheme or description..."
                                       autocomplete="off" enterkeyhint="search">
                            </div>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Scheme type"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="schemes-type" class="jf-sr-only">Type</label>
                            <select id="schemes-type" name="type">
                                <option value="">All types</option>
                                <option value="gold_savings" {{ request('type') === 'gold_savings' ? 'selected' : '' }}>Gold Savings</option>
                                <option value="festival_sale" {{ request('type') === 'festival_sale' ? 'selected' : '' }}>Festival Sale</option>
                                <option value="discount_offer" {{ request('type') === 'discount_offer' ? 'selected' : '' }}>Discount Offer</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('schemes.index') }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                        @endif
                    </form>
                </x-responsive-filter-panel>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell schemes-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Scheme records">
                <table class="w-full customers-data-table schemes-data-table jf-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Scheme</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Period</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Enrollments</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th scope="col" class="px-6 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($schemes as $scheme)
                            <tr>
                                <td class="jf-table-index">{{ $schemes->firstItem() + $loop->index }}</td>
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
                                <td class="px-6 py-4 text-left">
                                    <div class="jf-table-actions">
                                        <a href="{{ route('schemes.show', $scheme) }}" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View {{ $scheme->name }}"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
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
                                <td colspan="7" class="jf-table-empty">
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
                                    <a href="{{ route('schemes.show', $scheme) }}" class="customers-mobile-card__title"><span class="jf-record-index">#{{ $schemes->firstItem() + $loop->index }}</span> {{ $scheme->name }}</a>
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

    </div>
</x-app-layout>
