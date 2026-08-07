<x-app-layout>
    <x-page-header title="Karigars" subtitle="Job-work artisans linked to this shop">
        <x-slot:actions>
            @can('karigar.manage')
            <a href="{{ route('karigars.create') }}" class="btn btn-primary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Karigar
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner karigars-index-page">

        @unless(auth()->user()->can('karigar.manage'))
            @include('partials.view-only-banner', ['permission' => 'karigar.manage', 'message' => 'karigar management'])
        @endunless

        @php
            // Counts come from the full dataset (see controller $counts), not the
            // filtered page, so the KPI cards and filter tabs stay honest.
            $allKarigars = (int) ($counts->all_count ?? $karigars->count());
            $activeKarigars = (int) ($counts->active_count ?? $karigars->where('is_active', true)->count());
            $inactiveKarigars = (int) ($counts->archived_count ?? ($allKarigars - $activeKarigars));
            $totalJobOrders = (int) $karigars->sum('job_orders_count');
            $totalInvoices = (int) $karigars->sum('invoices_count');
            $status = $status ?? 'active';
        @endphp

        <div class="karigars-kpi-grid jobwork-kpi-grid">
            <section class="karigars-kpi-card jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--gold" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a3 3 0 0 0-2-2.83"/></svg>
                </span>
                <div>
                    <span class="karigars-kpi-label">Karigars</span>
                    <strong>{{ $allKarigars }}</strong>
                </div>
            </section>
            <section class="karigars-kpi-card jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--green" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <div>
                    <span class="karigars-kpi-label">Active</span>
                    <strong>{{ $activeKarigars }}</strong>
                </div>
            </section>
            <section class="karigars-kpi-card jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--slate" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 6h4"/><path d="M5 8h14v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8Z"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </span>
                <div>
                    <span class="karigars-kpi-label">Job Orders</span>
                    <strong>{{ $totalJobOrders }}</strong>
                </div>
            </section>
            <section class="karigars-kpi-card jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--rose" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6"/></svg>
                </span>
                <div>
                    <span class="karigars-kpi-label">Invoices</span>
                    <strong>{{ $totalInvoices }}</strong>
                </div>
            </section>
        </div>

        <nav class="karigars-filter-tabs" aria-label="Filter karigars by status">
            @php
                $tabs = [
                    'active'   => ['Active', $activeKarigars],
                    'archived' => ['Disabled', $inactiveKarigars],
                    'all'      => ['All', $allKarigars],
                ];
            @endphp
            @foreach($tabs as $key => [$label, $count])
                <a href="{{ route('karigars.index', $key === 'active' ? [] : ['status' => $key]) }}"
                   class="karigars-filter-tab{{ $status === $key ? ' karigars-filter-tab--active' : '' }}"
                   @if($status === $key) aria-current="page" @endif>
                    {{ $label }} <span class="karigars-filter-tab-count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        <div class="karigars-surface-card jobwork-register-card">
            <div class="karigars-surface-head jobwork-register-head">
                <div class="jobwork-register-titleblock">
                    <h2>Karigar Directory</h2>
                    <p>Manage artisan profiles, their activity, and status from one list.</p>
                </div>
                <span>{{ $karigars->count() }} record{{ $karigars->count() === 1 ? '' : 's' }}</span>
            </div>

            @if($karigars->isEmpty())
                <div class="karigars-empty-state">
                    @if($status === 'archived')
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
                <div class="karigars-table-shell">
                    <table class="karigars-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th>GST</th>
                                <th>City</th>
                                <th class="text-right">Job Orders</th>
                                <th class="text-right">Invoices</th>
                                <th class="text-right">Gold Held</th>
                                <th class="text-center">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($karigars as $k)
                                <tr class="karigars-table-row" onclick="window.location='{{ route('karigars.show', $k) }}'">
                                    <td>
                                        <div class="karigars-name-cell">
                                            <a href="{{ route('karigars.show', $k) }}" class="karigars-name-link">{{ $k->name }}</a>
                                            @if($k->shop_name)
                                                <span>{{ $k->shop_name }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ $k->mobile ?? '—' }}</td>
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
                                        @can('karigar.manage')
                                        <div class="karigars-row-actions">
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
                                            <a href="{{ route('karigars.edit', $k) }}" class="karigars-row-btn karigars-row-btn--edit">Edit</a>
                                        </div>
                                        @endcan
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
                                    <dd>{{ $k->mobile ?? '—' }}</dd>
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

                            @can('karigar.manage')
                            <div class="karigars-mobile-actions" onclick="event.stopPropagation()">
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
                                <a href="{{ route('karigars.edit', $k) }}" class="karigars-row-btn karigars-row-btn--edit">Edit</a>
                            </div>
                            @endcan
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
