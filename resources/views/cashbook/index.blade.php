<x-app-layout>
    <x-page-header class="cashbook-page-header" title="Cash Ledger" subtitle="Transaction-by-transaction cash history">
        <x-slot:actions>
            @can('cash.create')
                <a href="{{ route('cashbook.create') }}" class="cb-add-btn">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <line x1="12" y1="5" x2="12" y2="19" stroke-width="2" stroke-linecap="round"/><line x1="5" y1="12" x2="19" y2="12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add Ledger Entry
                </a>
            @endcan
            <a href="{{ route('report.cash') }}" class="cb-header-btn cashbook-dashboard-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
                </svg>
                <span class="cashbook-dashboard-label-full">Cash Flow Dashboard</span>
                <span class="cashbook-dashboard-label-short">Cash Flow</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner cb-page">
        @unless(auth()->user()->can('cash.create'))
            @include('partials.view-only-banner', ['permission' => 'cash.create', 'message' => 'adding ledger entries'])
        @endunless

        @php
            $todayNet = $stats['today_in'] - $stats['today_out'];
            $monthNet = $stats['month_in'] - $stats['month_out'];
            $hasActiveFilters = request()->hasAny(['search', 'type', 'from_date', 'to_date']);
        @endphp

        <div class="cb-flow">
            {{-- KPI snapshot strip --}}
            <div class="cb-snapshot">
                <div class="cb-snap">
                    <p class="cb-snap-label">Today's Cash In</p>
                    <p class="cb-snap-value cb-snap-value--in">₹{{ number_format($stats['today_in'], 2) }}</p>
                </div>
                <div class="cb-snap">
                    <p class="cb-snap-label">Today's Cash Out</p>
                    <p class="cb-snap-value cb-snap-value--out">₹{{ number_format($stats['today_out'], 2) }}</p>
                </div>
                <div class="cb-snap">
                    <p class="cb-snap-label">Today's Net</p>
                    <p class="cb-snap-value {{ $todayNet >= 0 ? 'cb-snap-value--pos' : 'cb-snap-value--neg' }}">{{ $todayNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($todayNet), 2) }}</p>
                </div>
                <div class="cb-snap">
                    <p class="cb-snap-label">This Month Net</p>
                    <p class="cb-snap-value {{ $monthNet >= 0 ? 'cb-snap-value--pos' : 'cb-snap-value--neg' }}">{{ $monthNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($monthNet), 2) }}</p>
                </div>
            </div>

            {{-- Filter + table card --}}
            <div class="cb-card">
                <form method="GET" action="{{ route('cashbook.index') }}" class="cb-filter">
                    <div class="cb-filter-field cb-filter-field--search">
                        <span class="cb-filter-label">Search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Description or source..." class="cb-input">
                    </div>
                    <div class="cb-filter-field">
                        <span class="cb-filter-label">Type</span>
                        <select name="type" class="cb-input">
                            <option value="">All Types</option>
                            <option value="in"  {{ request('type') === 'in'  ? 'selected' : '' }}>Cash In</option>
                            <option value="out" {{ request('type') === 'out' ? 'selected' : '' }}>Cash Out</option>
                        </select>
                    </div>
                    <div class="cb-filter-field">
                        <span class="cb-filter-label">From Date</span>
                        <input type="date" name="from_date" value="{{ request('from_date') }}" class="cb-input">
                    </div>
                    <div class="cb-filter-field">
                        <span class="cb-filter-label">To Date</span>
                        <input type="date" name="to_date" value="{{ request('to_date') }}" class="cb-input">
                    </div>
                    <div class="cb-filter-actions">
                        @if($hasActiveFilters)
                            <button type="submit" class="cb-apply">Apply</button>
                            <a href="{{ route('cashbook.index') }}" class="cb-clear">Clear</a>
                        @else
                            <button type="submit" class="cb-apply">Apply</button>
                        @endif
                    </div>
                </form>

                {{-- Desktop table --}}
                <div class="cb-table-wrap">
                    <table class="cb-table">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Type</th>
                                <th>Source</th>
                                <th>Invoice #</th>
                                <th>Description</th>
                                <th>Mode</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions as $tx)
                                <tr>
                                    <td class="cb-muted">{{ $tx->created_at->format('d M Y, h:i A') }}</td>
                                    <td>
                                        @if($tx->type === 'in')
                                            <span class="cb-pill cb-pill--in">Cash In</span>
                                        @else
                                            <span class="cb-pill cb-pill--out">Cash Out</span>
                                        @endif
                                    </td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $tx->source_type ?? 'Unknown')) }}</td>
                                    <td class="cb-mono">{{ $tx->invoice?->invoice_number ?? '-' }}</td>
                                    <td class="cb-desc">{{ $tx->description ?: '-' }}</td>
                                    <td>
                                        @if($tx->payment_mode)
                                            <span class="cb-mode">{{ ucfirst($tx->payment_mode) }}</span>
                                        @else
                                            <span class="cb-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <span class="cb-amount {{ $tx->type === 'in' ? 'cb-amount--in' : 'cb-amount--out' }}">
                                            {{ $tx->type === 'in' ? '+' : '−' }}₹{{ number_format($tx->amount, 2) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="cb-empty">
                                            <svg class="cb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                                            </svg>
                                            <p class="cb-empty-title">No transactions found</p>
                                            <p class="cb-empty-copy">Cash transactions appear here as they are recorded in the ledger.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Mobile card list --}}
                <div class="cb-mobile-list">
                    @forelse($transactions as $tx)
                        <article class="cb-mobile-card">
                            <div class="cb-mobile-head">
                                <div>
                                    @if($tx->type === 'in')
                                        <span class="cb-pill cb-pill--in">Cash In</span>
                                    @else
                                        <span class="cb-pill cb-pill--out">Cash Out</span>
                                    @endif
                                    <p class="cb-mobile-date">{{ $tx->created_at->format('d M Y, h:i A') }}</p>
                                </div>
                                <span class="cb-amount {{ $tx->type === 'in' ? 'cb-amount--in' : 'cb-amount--out' }}">
                                    {{ $tx->type === 'in' ? '+' : '−' }}₹{{ number_format($tx->amount, 2) }}
                                </span>
                            </div>
                            <dl class="cb-mobile-grid">
                                <div>
                                    <dt>Source</dt>
                                    <dd>{{ ucfirst(str_replace('_', ' ', $tx->source_type ?? 'Unknown')) }}</dd>
                                </div>
                                <div>
                                    <dt>Invoice</dt>
                                    <dd class="cb-mono">{{ $tx->invoice?->invoice_number ?? '-' }}</dd>
                                </div>
                                <div>
                                    <dt>Mode</dt>
                                    <dd>{{ $tx->payment_mode ? ucfirst($tx->payment_mode) : '-' }}</dd>
                                </div>
                                <div>
                                    <dt>Description</dt>
                                    <dd>{{ $tx->description ?: '-' }}</dd>
                                </div>
                            </dl>
                        </article>
                    @empty
                        <div class="cb-empty">
                            <svg class="cb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <p class="cb-empty-title">No transactions found</p>
                            <p class="cb-empty-copy">Cash transactions appear here as they are recorded.</p>
                        </div>
                    @endforelse
                </div>

                @if($transactions->hasPages())
                    <div class="cb-pagination">
                        {{ $transactions->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <style>
        /* ── Cash Ledger — calm teal/hairline system (matches vault/returns/closing) ── */
        .cb-page {
            --cb-border:        #e7ebf1;
            --cb-border-soft:   #eef1f6;
            --cb-border-strong: #d9dfe8;
            --cb-ink:           #0f172a;
            --cb-ink-2:         #3d4861;
            --cb-muted:         #6a7588;
            --cb-accent:        #0d9488;
            --cb-accent-deep:   #0f766e;
            --cb-pos:           #0f766e;
            --cb-neg:           #b42318;
            --cb-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
            --cb-ease:          cubic-bezier(0.23,1,0.32,1);
            max-width: 1360px;
        }

        .cb-flow { display: flex; flex-direction: column; gap: 20px; }

        @media (prefers-reduced-motion: no-preference) {
            .cb-page .cb-snapshot, .cb-page .cb-card {
                animation: cbRise .5s var(--cb-ease) both;
            }
            .cb-page .cb-card { animation-delay: .05s; }
            @keyframes cbRise {
                from { opacity: 0; transform: translateY(8px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        }

        /* Header buttons */
        .cb-add-btn {
            display: inline-flex; align-items: center; gap: 7px;
            min-height: 40px; padding: 0 15px;
            border: 1px solid var(--cb-accent-deep); border-radius: 10px;
            background: var(--cb-accent-deep); color: #fff;
            font-size: 13px; font-weight: 650; text-decoration: none;
            transition: background-color .15s var(--cb-ease), transform .15s var(--cb-ease);
        }
        .cb-add-btn:hover { background: #115e56; }
        .cb-add-btn:active { transform: scale(.98); }
        .cb-header-btn {
            display: inline-flex; align-items: center;
            min-height: 40px; padding: 0 14px;
            border: 1px solid var(--cb-border-strong); border-radius: 10px;
            background: #fff; color: var(--cb-ink-2);
            font-size: 13px; font-weight: 600; text-decoration: none;
            transition: background-color .15s var(--cb-ease);
        }
        .cb-header-btn:hover { background: #f7f9fc; }

        /* KPI snapshot strip */
        .cb-snapshot {
            display: grid; grid-template-columns: repeat(4, minmax(0,1fr));
            border: 1px solid var(--cb-border); border-radius: 16px;
            background: #fff; box-shadow: var(--cb-shadow); overflow: hidden;
        }
        .cb-snap { padding: 16px 18px; border-right: 1px solid var(--cb-border); }
        .cb-snap:last-child { border-right: 0; }
        .cb-snap-label { margin: 0 0 6px; color: var(--cb-muted); font-size: 12px; font-weight: 500; }
        .cb-snap-value {
            margin: 0; color: var(--cb-ink); font-size: 20px; font-weight: 700;
            line-height: 1.15; letter-spacing: -.01em; font-variant-numeric: tabular-nums;
        }
        .cb-snap-value--in  { color: var(--cb-accent-deep); }
        .cb-snap-value--out { color: var(--cb-neg); }
        .cb-snap-value--pos { color: var(--cb-pos); }
        .cb-snap-value--neg { color: var(--cb-neg); }

        /* Card wrapper (filter + table) */
        .cb-card {
            border: 1px solid var(--cb-border); border-radius: 16px;
            background: #fff; box-shadow: var(--cb-shadow); overflow: hidden;
        }

        /* Filter bar */
        .cb-filter {
            display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
            padding: 18px 20px; border-bottom: 1px solid var(--cb-border-soft);
            background: #fafbfd;
        }
        .cb-filter-field { display: flex; flex-direction: column; gap: 6px; }
        .cb-filter-field--search { flex: 1; min-width: 200px; }
        .cb-filter-label { color: var(--cb-muted); font-size: 11.5px; font-weight: 600; }
        .cb-input {
            height: 38px; padding: 0 11px;
            border: 1px solid var(--cb-border-strong); border-radius: 10px;
            background: #f4f6fa; color: var(--cb-ink); font-size: 13px; min-width: 140px;
            transition: border-color .15s var(--cb-ease), box-shadow .15s var(--cb-ease), background-color .15s var(--cb-ease);
        }
        .cb-filter-field--search .cb-input { width: 100%; min-width: 0; }
        .cb-input:focus {
            border-color: var(--cb-accent-deep); background: #fff;
            box-shadow: 0 0 0 3px rgba(15,118,110,.12); outline: none;
        }
        .cb-input::placeholder { color: #9aa6b8; }
        .cb-filter-actions { display: flex; gap: 8px; align-items: flex-end; }
        .cb-apply {
            display: inline-flex; align-items: center; justify-content: center;
            height: 38px; padding: 0 16px;
            border: 1px solid var(--cb-accent-deep); border-radius: 10px;
            background: var(--cb-accent-deep); color: #fff; font-size: 13px; font-weight: 650;
            cursor: pointer; transition: background-color .15s var(--cb-ease), transform .15s var(--cb-ease);
        }
        .cb-apply:hover { background: #115e56; }
        .cb-apply:active { transform: scale(.98); }
        .cb-clear {
            display: inline-flex; align-items: center; justify-content: center;
            height: 38px; padding: 0 14px;
            border: 1px solid var(--cb-border-strong); border-radius: 10px;
            background: #fff; color: var(--cb-ink-2); font-size: 13px; font-weight: 600; text-decoration: none;
            transition: background-color .15s var(--cb-ease);
        }
        .cb-clear:hover { background: #f7f9fc; }

        /* Table */
        .cb-table-wrap { overflow-x: auto; }
        .cb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cb-table thead th {
            padding: 11px 18px; text-align: left;
            font-size: 11.5px; font-weight: 600; color: var(--cb-muted);
            background: #fafbfd; border-bottom: 1px solid var(--cb-border); white-space: nowrap;
        }
        .cb-table thead th.text-right { text-align: right; }
        .cb-table tbody td {
            padding: 13px 18px; vertical-align: middle;
            border-bottom: 1px solid var(--cb-border-soft); color: var(--cb-ink-2);
        }
        .cb-table tbody tr:last-child td { border-bottom: 0; }
        .cb-table tbody tr:hover { background: #fafbfd; }
        .cb-table td.text-right { text-align: right; }

        .cb-muted { color: var(--cb-muted); }
        .cb-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: var(--cb-ink-2); }
        .cb-desc { color: var(--cb-ink-2); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .cb-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 650; white-space: nowrap;
        }
        .cb-pill::before { content: ''; width: 5px; height: 5px; border-radius: 999px; background: currentColor; flex-shrink: 0; }
        .cb-pill--in  { background: #ecfdf5; color: #0f766e; }
        .cb-pill--out { background: #fef2f2; color: #b42318; }

        .cb-mode {
            display: inline-flex; align-items: center; padding: 2px 9px;
            border-radius: 6px; background: #f1f4f8; color: var(--cb-ink-2);
            font-size: 11.5px; font-weight: 600;
        }

        .cb-amount { font-size: 13.5px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .cb-amount--in  { color: var(--cb-pos); }
        .cb-amount--out { color: var(--cb-neg); }

        /* Empty state */
        .cb-empty {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 10px; padding: 56px 24px; text-align: center;
        }
        .cb-empty-icon { width: 42px; height: 42px; color: #c8d0db; }
        .cb-empty-title { margin: 0; color: var(--cb-ink); font-size: 15px; font-weight: 650; }
        .cb-empty-copy { margin: 0; max-width: 36ch; color: var(--cb-muted); font-size: 13px; line-height: 1.6; }

        /* Pagination */
        .cb-pagination { padding: 14px 18px; border-top: 1px solid var(--cb-border-soft); background: #fafbfd; }

        /* Mobile card list */
        .cb-mobile-list { display: none; }
        .cb-mobile-card { padding: 16px; border-bottom: 1px solid var(--cb-border-soft); }
        .cb-mobile-card:last-child { border-bottom: 0; }
        .cb-mobile-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .cb-mobile-date { margin: 6px 0 0; color: var(--cb-muted); font-size: 11.5px; }
        .cb-mobile-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; margin: 0; }
        .cb-mobile-grid dt { font-size: 11px; font-weight: 600; color: var(--cb-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
        .cb-mobile-grid dd { margin: 0; font-size: 13px; font-weight: 600; color: var(--cb-ink); word-break: break-word; }

        /* Responsive */
        @media (max-width: 900px) {
            .cb-snapshot { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .cb-snap { border-bottom: 1px solid var(--cb-border); }
            .cb-snap:nth-child(2n) { border-right: 0; }
            .cb-snap:nth-last-child(-n+2) { border-bottom: 0; }
        }
        @media (max-width: 767px) {
            .cb-table-wrap { display: none; }
            .cb-mobile-list { display: block; }
            .cb-filter-field { width: 100%; }
            .cb-input { width: 100%; min-width: 0; }
            .cb-filter-actions { width: 100%; }
            .cb-apply, .cb-clear { flex: 1; height: 42px; }
        }
        @media (max-width: 460px) {
            .cb-snapshot { grid-template-columns: 1fr; }
            .cb-snap { border-right: 0; border-bottom: 1px solid var(--cb-border); }
            .cb-snap:last-child { border-bottom: 0; }
        }
    </style>
</x-app-layout>
