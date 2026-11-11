@php
    $hasFilters = filled($search) || $status !== 'active';
    $vendorTotal = $vendors->total();
    $vendorStartIndex = ($vendors->firstItem() ?? 1) - 1;
@endphp

<x-app-layout>
    <x-page-header class="vendors-page-header" title="Vendors / Suppliers" subtitle="Manage your jewellery suppliers and vendors">
        <x-slot:actions>
            @can('vendors.manage')
            <a href="{{ route('vendors.create') }}" class="btn btn-primary btn-sm vendors-add-btn" aria-label="Add Vendor" title="Add Vendor">
                <x-icon name="plus" size="16" />
                <span class="vendors-add-label">Add Vendor</span>
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vendor-directory" data-list-workspace>
        @unless(auth()->user()->can('vendors.manage'))
            @include('partials.view-only-banner', ['permission' => 'vendors.manage', 'message' => 'vendor management'])
        @endunless

        <section class="vendors-list-card" data-list-frame aria-labelledby="vendor-directory-title">
            <header class="vendors-list-head" data-vendor-register-head>
                <div class="vendors-list-titleblock">
                    <h2 id="vendor-directory-title">Vendor Directory</h2>
                    <dl class="vendors-summary" aria-label="Vendor summary">
                        <div class="vendors-metric"><dt>Total Vendors</dt><dd>{{ number_format($stats->total_count ?? 0) }}</dd></div>
                        <div class="vendors-metric"><dt>Active</dt><dd>{{ number_format($stats->active_count ?? 0) }}</dd></div>
                        <div class="vendors-metric"><dt>GST Registered</dt><dd>{{ number_format($stats->gst_count ?? 0) }}</dd></div>
                    </dl>
                </div>

                <x-responsive-filter-panel id="vendors-filter-panel" label="Vendor filters">
                    <form method="GET" action="{{ route('vendors.index') }}" class="vendors-filter-form"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js($hasFilters) }"
                          @input="dirty = true" @change="dirty = true">
                        <div class="vendors-search-field">
                            <label for="vendors-search" class="jf-sr-only vendors-search-label">Search vendors</label>
                            <span class="vendors-search-icon" aria-hidden="true"><x-icon name="search" size="16" /></span>
                            <input id="vendors-search" class="vendors-filter-input" type="search" name="search"
                                   value="{{ $search }}" placeholder="Name, mobile or GST…" data-suggest="vendors" autocomplete="off">
                        </div>
                        <div class="vendors-status-field">
                            <label for="vendors-status" class="jf-sr-only vendors-status-label">Vendor status</label>
                            <select id="vendors-status" name="status" class="vendors-filter-input">
                                {{-- Search-scoped option counts remain separate from global KPI totals. --}}
                                <option value="active" @selected($status === 'active')>Active ({{ (int) $statusCounts->active_count }})</option>
                                <option value="archived" @selected($status === 'archived')>Archived ({{ (int) $statusCounts->archived_count }})</option>
                                <option value="all" @selected($status === 'all')>All ({{ (int) $statusCounts->all_count }})</option>
                            </select>
                        </div>
                        <div class="vendors-filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm" data-vendor-filter-apply x-show="dirty || !hasFilters">Apply</button>
                            <a href="{{ route('vendors.index') }}" class="btn btn-primary btn-sm" data-vendor-filter-clear x-show="!dirty && hasFilters" x-cloak>Clear</a>
                            @if($hasFilters)
                                <noscript><a href="{{ route('vendors.index') }}" class="btn btn-secondary btn-sm">Clear</a></noscript>
                            @endif
                        </div>
                    </form>
                </x-responsive-filter-panel>
            </header>

            @if($vendors->count())
                <div class="vendors-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Vendor records">
                    <table class="vendors-table jf-table">
                        <thead>
                            <tr>
                                <th scope="col" class="text-left vendors-index-cell">#</th>
                                <th scope="col" class="text-left">Vendor</th>
                                <th scope="col" class="text-left">Contact</th>
                                <th scope="col" class="text-left">GST</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vendors as $vendor)
                                <tr>
                                    <td class="vendors-index-cell">{{ $vendorStartIndex + $loop->iteration }}</td>
                                    <td>
                                        <a href="{{ route('vendors.show', $vendor) }}" class="vendors-name vendors-name-link">{{ $vendor->name }}</a>
                                        <p class="vendors-subtext">{{ $vendor->city ? $vendor->city . ($vendor->state ? ', ' . $vendor->state : '') : 'Location not added' }}</p>
                                    </td>
                                    <td>
                                        <p class="vendors-name text-[14px]">{{ $vendor->contact_person ?: 'Not added' }}</p>
                                        <p class="vendors-subtext">{{ \App\Support\Mobile::forDisplay($vendor->mobile) ?: 'No mobile number' }}</p>
                                    </td>
                                    <td class="vendors-muted vendors-gst">{{ $vendor->gst_number ?: 'Not available' }}</td>
                                    <td class="text-center">
                                        <span class="vendors-status-badge {{ $vendor->is_active ? 'vendors-status-badge--active' : 'vendors-status-badge--inactive' }}">
                                            {{ $vendor->is_active ? 'Active' : 'Archived' }}
                                        </span>
                                    </td>
                                    <td class="text-left">
                                        <div class="vendors-table-actions">
                                            <a href="{{ route('vendors.show', $vendor) }}" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View {{ $vendor->name }}"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                            @can('vendors.manage')
                                            <a href="{{ route('vendors.edit', $vendor) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit" aria-label="Edit {{ $vendor->name }}"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>
                                            {{-- MASTERS PART 3: explicit lifecycle action, right where
                                                 the status badge is, so it is never mistaken for an edit. --}}
                                            @if($vendor->is_active)
                                                <form action="{{ route('vendors.archive', $vendor) }}" method="POST" class="inline"
                                                      data-confirm-message="Archive {{ $vendor->name }}? Existing items and purchases are kept — the vendor just stops appearing when you create new ones.">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Archive this vendor" class="jf-row-action jf-row-action--danger" data-tip="Archive"><x-icon name="archive" size="16" /><span class="jf-sr-only">Archive</span></button>
                                                </form>
                                            @else
                                                <form action="{{ route('vendors.reactivate', $vendor) }}" method="POST" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" aria-label="Reactivate this vendor" class="jf-row-action jf-row-action--success" data-tip="Reactivate"><x-icon name="rotate-ccw" size="16" /><span class="jf-sr-only">Reactivate</span></button>
                                                </form>
                                            @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="vendors-mobile-list">
                    @foreach($vendors as $vendor)
                        <article class="vendor-mobile-card">
                            <div class="vendor-mobile-top">
                                <div>
                                    <div class="vendor-mobile-heading">
                                        <span class="vendor-mobile-index">#{{ $vendorStartIndex + $loop->iteration }}</span>
                                        <a href="{{ route('vendors.show', $vendor) }}" class="vendor-mobile-name">{{ $vendor->name }}</a>
                                    </div>
                                    <p class="vendor-mobile-location">{{ $vendor->city ? $vendor->city . ($vendor->state ? ', ' . $vendor->state : '') : 'Location not added' }}</p>
                                </div>
                                <span class="vendors-status-badge {{ $vendor->is_active ? 'vendors-status-badge--active' : 'vendors-status-badge--inactive' }}">
                                    {{ $vendor->is_active ? 'Active' : 'Archived' }}
                                </span>
                            </div>

                            <dl class="vendor-mobile-grid">
                                <div>
                                    <dt>Contact</dt>
                                    <dd>{{ $vendor->contact_person ?: 'Not added' }}</dd>
                                </div>
                                <div>
                                    <dt>Mobile</dt>
                                    <dd>{{ \App\Support\Mobile::forDisplay($vendor->mobile) ?: 'Not available' }}</dd>
                                </div>
                                <div>
                                    <dt>GST</dt>
                                    <dd>{{ $vendor->gst_number ?: 'Not available' }}</dd>
                                </div>
                                <div>
                                    <dt>State</dt>
                                    <dd>{{ $vendor->state ?: 'Not added' }}</dd>
                                </div>
                            </dl>

                            <div class="vendor-mobile-actions">
                                <a href="{{ route('vendors.show', $vendor) }}" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View {{ $vendor->name }}"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                @can('vendors.manage')
                                <a href="{{ route('vendors.edit', $vendor) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit" aria-label="Edit {{ $vendor->name }}"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>
                                @if($vendor->is_active)
                                    <form action="{{ route('vendors.archive', $vendor) }}" method="POST" class="inline"
                                          data-confirm-message="Archive {{ $vendor->name }}? Existing items and purchases are kept — the vendor just stops appearing when you create new ones.">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="jf-row-action jf-row-action--danger" data-tip="Archive" aria-label="Archive {{ $vendor->name }}"><x-icon name="archive" size="16" /><span class="jf-sr-only">Archive</span></button>
                                    </form>
                                @else
                                    <form action="{{ route('vendors.reactivate', $vendor) }}" method="POST" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="jf-row-action jf-row-action--success" data-tip="Reactivate" aria-label="Reactivate {{ $vendor->name }}"><x-icon name="rotate-ccw" size="16" /><span class="jf-sr-only">Reactivate</span></button>
                                    </form>
                                @endif
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="vendors-empty">
                    @if($hasFilters)
                        <p class="vendors-empty-title">No vendors match these filters.</p>
                        <p class="vendors-empty-copy">Try another name, contact, mobile number or GST number.</p>
                        <a href="{{ route('vendors.index') }}" class="btn btn-secondary btn-sm">Clear filters</a>
                    @elseif($status === 'archived')
                        <p class="vendors-empty-title">No archived vendors.</p>
                    @else
                        <p class="vendors-empty-title">No vendors yet.</p>
                        @can('vendors.manage')
                        <a href="{{ route('vendors.create') }}" class="btn btn-primary btn-sm">Add your first vendor</a>
                        @endcan
                    @endif
                </div>
            @endif

            <footer class="vendors-pagination">
                @if($vendors->hasPages())
                    {{ $vendors->links() }}
                @else
                    <p>{{ number_format($vendorTotal) }} {{ $vendorTotal === 1 ? 'vendor' : 'vendors' }}{{ $hasFilters ? ' matching filters' : '' }}</p>
                @endif
            </footer>
        </section>
    </div>
</x-app-layout>
