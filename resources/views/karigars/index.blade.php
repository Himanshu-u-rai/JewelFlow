<x-app-layout>
    <x-page-header title="Karigars" subtitle="Job-work artisans linked to this shop" class="karigars-page-header">
        <x-slot:actions>
            @can('karigar.manage')
            <a href="{{ route('karigars.create') }}" class="btn btn-primary btn-sm karigars-add" aria-label="Add Karigar" title="Add Karigar">
                <x-icon name="plus" size="16" />
                <span class="karigars-add-label">Add Karigar</span>
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner karigars-index-page" data-list-workspace>

        @unless(auth()->user()->can('karigar.manage'))
            @include('partials.view-only-banner', ['permission' => 'karigar.manage', 'message' => 'karigar management'])
        @endunless

        @php
            // Counts come from the full dataset (see controller $counts), not the
            // filtered list. Activity totals relate to the listed karigars.
            $allKarigars = (int) ($counts->all_count ?? $karigars->count());
            $activeKarigars = (int) ($counts->active_count ?? $karigars->where('is_active', true)->count());
            $inactiveKarigars = (int) ($counts->archived_count ?? ($allKarigars - $activeKarigars));
            $totalJobOrders = (int) $karigars->sum('job_orders_count');
            $totalInvoices = (int) $karigars->sum('invoices_count');
            $status = $status ?? 'active';
        @endphp

        <section class="karigars-register" data-list-frame aria-labelledby="karigars-directory-title">
            <header class="karigars-register-head" data-karigar-register-head>
                <div class="karigars-register-title">
                    <h2 id="karigars-directory-title">Karigar Directory</h2>
                    <p>{{ $karigars->count() }} {{ $search !== '' ? 'matching' : 'listed' }} · {{ $status === 'archived' ? 'Disabled' : ucfirst($status) }}</p>
                </div>
                <div class="karigars-summary" aria-label="Directory summary">
                    <dl class="karigars-metric"><dt>Total Karigars</dt><dd>{{ $allKarigars }}</dd></dl>
                    <dl class="karigars-metric"><dt>Active</dt><dd>{{ $activeKarigars }}</dd></dl>
                    <dl class="karigars-metric"><dt>Listed · Jobs</dt><dd>{{ $totalJobOrders }}</dd></dl>
                    <dl class="karigars-metric"><dt>Listed · Invoices</dt><dd>{{ $totalInvoices }}</dd></dl>
                </div>
                <x-responsive-filter-panel id="karigars-filter-panel" label="Karigar filters">
                    <form method="GET" action="{{ route('karigars.index') }}" class="karigars-filters" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                        <div class="karigars-search-field">
                            <label for="karigars-search">Search</label>
                            <input id="karigars-search" class="karigars-filter-input" type="search" name="search" value="{{ $search }}" maxlength="120" placeholder="Name, mobile, city…" />
                        </div>
                        <div class="karigars-status-field">
                            <label for="karigars-status">Status</label>
                            <select id="karigars-status" class="karigars-filter-input" name="status">
                                <option value="active" @selected($status === 'active')>Active ({{ $activeKarigars }})</option>
                                <option value="archived" @selected($status === 'archived')>Disabled ({{ $inactiveKarigars }})</option>
                                <option value="all" @selected($status === 'all')>All ({{ $allKarigars }})</option>
                            </select>
                        </div>
                        <div class="karigars-filter-actions">
                            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                            @if($search !== '' || $status !== 'active')
                                <a class="btn btn-secondary btn-sm" href="{{ route('karigars.index') }}">Clear</a>
                            @endif
                        </div>
                    </form>
                </x-responsive-filter-panel>
            </header>

            @if($karigars->isEmpty())
                <div class="karigars-empty-state">
                    @if($search !== '')
                        <p>No karigars match your search.</p>
                        <a href="{{ route('karigars.index', ['status' => $status]) }}" class="karigars-empty-link">Clear search</a>
                    @elseif($status === 'archived')
                        <p>No disabled karigars.</p>
                    @elseif($status === 'active')
                        <p>No active karigars.</p>
                        @can('karigar.manage')
                        <a href="{{ route('karigars.create') }}" class="karigars-empty-link">Add your first karigar</a>
                        @endcan
                    @else
                        <p>No karigars yet.</p>
                        @can('karigar.manage')
                        <a href="{{ route('karigars.create') }}" class="karigars-empty-link">Add your first karigar</a>
                        @endcan
                    @endif
                </div>
            @else
                <div class="karigars-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Karigar records">
                    <table class="karigars-table jf-table">
                        <thead>
                            <tr>
                                <th scope="col" class="jf-table-index">#</th>
                                <th scope="col">Name</th>
                                <th scope="col">Mobile</th>
                                <th scope="col">GST</th>
                                <th scope="col">City</th>
                                <th scope="col" class="text-right">Job Orders</th>
                                <th scope="col" class="text-right">Invoices</th>
                                <th scope="col" class="text-right">Gold Held</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($karigars as $k)
                                <tr class="karigars-table-row" onclick="window.location='{{ route('karigars.show', $k) }}'">
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <td>
                                        <div class="karigars-name-cell">
                                            <a href="{{ route('karigars.show', $k) }}" class="karigars-name-link">{{ $k->name }}</a>
                                            @if($k->shop_name)
                                                <span>{{ $k->shop_name }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ \App\Support\Mobile::forDisplay($k->mobile) ?: '—' }}</td>
                                    <td class="karigars-mono">{{ $k->gst_number ?? '—' }}</td>
                                    <td>{{ $k->city ?? '—' }}</td>
                                    <td class="text-right karigars-mono">{{ $k->job_orders_count }}</td>
                                    <td class="text-right karigars-mono">{{ $k->invoices_count }}</td>
                                    @php $heldFine = (float) ($goldHeldByKarigar[$k->id] ?? 0); @endphp
                                    <td class="text-right karigars-mono">{{ $heldFine > 0 ? number_format($heldFine, 3) . 'g' : '—' }}</td>
                                    <td class="text-center">
                                        @if($k->is_active)
                                            <span class="karigars-status-pill karigars-status-pill--active">Active</span>
                                        @else
                                            <span class="karigars-status-pill karigars-status-pill--inactive">Disabled</span>
                                        @endif
                                    </td>
                                    <td class="text-right" onclick="event.stopPropagation()">
                                        <div class="karigars-row-actions">
                                            <a href="{{ route('karigars.show', $k) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View {{ $k->name }}</span></a>
                                            @can('karigar.manage')
                                            @if($k->is_active)
                                                <form method="POST" action="{{ route('karigars.archive', $k) }}" class="inline" onsubmit="return confirm('Disable {{ addslashes($k->name) }}? They keep all history but can\'t take new work until re-enabled.');">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="karigars-row-btn karigars-row-btn--muted" aria-label="Disable {{ $k->name }}">Disable</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('karigars.reactivate', $k) }}" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="karigars-row-btn karigars-row-btn--enable" aria-label="Re-enable {{ $k->name }}">Enable</button>
                                                </form>
                                            @endif
                                            <a href="{{ route('karigars.edit', $k) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit {{ $k->name }}</span></a>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="karigars-mobile-list">
                    @foreach($karigars as $k)
                        <article class="karigars-mobile-card" onclick="window.location='{{ route('karigars.show', $k) }}'">
                            <div class="karigars-mobile-head">
                                <div>
                                    <span class="karigars-mobile-index">#<span data-karigar-mobile-index>{{ $loop->iteration }}</span></span>
                                    <a href="{{ route('karigars.show', $k) }}" class="karigars-name-link">{{ $k->name }}</a>
                                    @if($k->shop_name)
                                        <p>{{ $k->shop_name }}</p>
                                    @endif
                                </div>
                                @if($k->is_active)
                                    <span class="karigars-status-pill karigars-status-pill--active">Active</span>
                                @else
                                    <span class="karigars-status-pill karigars-status-pill--inactive">Disabled</span>
                                @endif
                            </div>

                            <dl class="karigars-mobile-meta">
                                <div>
                                    <dt>Mobile</dt>
                                    <dd>{{ \App\Support\Mobile::forDisplay($k->mobile) ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt>City</dt>
                                    <dd>{{ $k->city ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>GST</dt>
                                    <dd class="karigars-mono">{{ $k->gst_number ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Job Orders</dt>
                                    <dd class="karigars-mono">{{ $k->job_orders_count }}</dd>
                                </div>
                                <div>
                                    <dt>Invoices</dt>
                                    <dd class="karigars-mono">{{ $k->invoices_count }}</dd>
                                </div>
                                @php $heldFine = (float) ($goldHeldByKarigar[$k->id] ?? 0); @endphp
                                <div>
                                    <dt>Gold Held</dt>
                                    <dd class="karigars-mono">{{ $heldFine > 0 ? number_format($heldFine, 3) . 'g' : '—' }}</dd>
                                </div>
                            </dl>

                            <div class="karigars-mobile-actions" onclick="event.stopPropagation()">
                                <a href="{{ route('karigars.show', $k) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View {{ $k->name }}</span></a>
                                @can('karigar.manage')
                                @if($k->is_active)
                                    <form method="POST" action="{{ route('karigars.archive', $k) }}" onsubmit="return confirm('Disable {{ addslashes($k->name) }}? They keep all history but can\'t take new work until re-enabled.');">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="karigars-row-btn karigars-row-btn--muted" aria-label="Disable {{ $k->name }}">Disable</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('karigars.reactivate', $k) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="karigars-row-btn karigars-row-btn--enable" aria-label="Re-enable {{ $k->name }}">Enable</button>
                                    </form>
                                @endif
                                <a href="{{ route('karigars.edit', $k) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit {{ $k->name }}</span></a>
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
