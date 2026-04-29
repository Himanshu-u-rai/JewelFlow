<x-app-layout>
    <x-page-header title="Karigars" subtitle="Job-work artisans linked to this shop">
        <x-slot:actions>
            <a href="{{ route('karigars.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Karigar
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner karigars-index-page">
        <x-app-alerts class="mb-4" />

        @php
            $activeKarigars = $karigars->where('is_active', true)->count();
            $inactiveKarigars = $karigars->count() - $activeKarigars;
            $totalJobOrders = (int) $karigars->sum('job_orders_count');
            $totalInvoices = (int) $karigars->sum('invoices_count');
        @endphp

        <div class="karigars-kpi-grid">
            <section class="karigars-kpi-card karigars-kpi-card--total">
                <span class="karigars-kpi-label">Karigars</span>
                <strong>{{ $karigars->count() }}</strong>
                <p>Job-work partners on this shop</p>
            </section>
            <section class="karigars-kpi-card karigars-kpi-card--active">
                <span class="karigars-kpi-label">Active</span>
                <strong>{{ $activeKarigars }}</strong>
                <p>{{ $inactiveKarigars }} disabled</p>
            </section>
            <section class="karigars-kpi-card karigars-kpi-card--jobs">
                <span class="karigars-kpi-label">Job Orders</span>
                <strong>{{ $totalJobOrders }}</strong>
                <p>Issued across all karigars</p>
            </section>
            <section class="karigars-kpi-card karigars-kpi-card--invoices">
                <span class="karigars-kpi-label">Invoices</span>
                <strong>{{ $totalInvoices }}</strong>
                <p>Received into the workflow</p>
            </section>
        </div>

        <div class="karigars-surface-card">
            <div class="karigars-surface-head">
                <div>
                    <h2>Karigar Directory</h2>
                    <p>Manage artisan profiles, their activity, and status from one list.</p>
                </div>
                <span>{{ $karigars->count() }} record{{ $karigars->count() === 1 ? '' : 's' }}</span>
            </div>

            @if($karigars->isEmpty())
                <div class="karigars-empty-state">
                    <p>No karigars yet.</p>
                    <a href="{{ route('karigars.create') }}" class="karigars-empty-link">Add your first karigar</a>
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
                                            @if($k->contact_person)
                                                <span>{{ $k->contact_person }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ $k->mobile ?? '—' }}</td>
                                    <td class="karigars-mono">{{ $k->gst_number ?? '—' }}</td>
                                    <td>{{ $k->city ?? '—' }}</td>
                                    <td class="text-right karigars-mono">{{ $k->job_orders_count }}</td>
                                    <td class="text-right karigars-mono">{{ $k->invoices_count }}</td>
                                    <td class="text-center">
                                        @if($k->is_active)
                                            <span class="karigars-status-pill karigars-status-pill--active">Active</span>
                                        @else
                                            <span class="karigars-status-pill karigars-status-pill--inactive">Disabled</span>
                                        @endif
                                    </td>
                                    <td class="text-right" onclick="event.stopPropagation()">
                                        <div class="karigars-row-actions">
                                            <form method="POST" action="{{ route('karigars.toggle', $k) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="karigars-row-btn karigars-row-btn--muted">{{ $k->is_active ? 'Disable' : 'Enable' }}</button>
                                            </form>
                                            <a href="{{ route('karigars.edit', $k) }}" class="karigars-row-btn karigars-row-btn--edit">Edit</a>
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
                                    <a href="{{ route('karigars.show', $k) }}" class="karigars-name-link">{{ $k->name }}</a>
                                    @if($k->contact_person)
                                        <p>{{ $k->contact_person }}</p>
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
                            </dl>

                            <div class="karigars-mobile-actions" onclick="event.stopPropagation()">
                                <form method="POST" action="{{ route('karigars.toggle', $k) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="karigars-row-btn karigars-row-btn--muted">{{ $k->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                                <a href="{{ route('karigars.edit', $k) }}" class="karigars-row-btn karigars-row-btn--edit">Edit</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
