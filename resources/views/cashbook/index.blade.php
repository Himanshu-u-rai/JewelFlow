<x-app-layout>
    <div class="cb-shell" x-data="{ drawerOpen: false }" @keydown.escape.window="drawerOpen = false">
    <x-page-header class="cashbook-page-header" title="Cash book" subtitle="Transaction-by-transaction cash history">
        <x-slot:actions>
            @can('cash.create')
                <button type="button" class="cb-header-btn cb-drawer-header-btn" @click="drawerOpen = true; $nextTick(() => $refs.countedCash?.focus())" aria-label="Match your drawer">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2" stroke-width="2"/>
                        <path d="M7 10h10M8 14h2M14 14h2" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="cb-action-label">Match drawer</span>
                </button>
                <a href="{{ route('cashbook.create') }}" class="cb-add-btn" aria-label="Add entry">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19" stroke-width="2" stroke-linecap="round"/>
                        <line x1="5" y1="12" x2="19" y2="12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="cb-action-label">Add entry</span>
                </a>
            @endcan
            <a href="{{ route('report.cash') }}" class="cb-header-btn" aria-label="Open cash flow dashboard">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="17 6 23 6 23 12" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="cb-action-label">Cash flow</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner cb-page">
        @unless(auth()->user()->can('cash.create'))
            @include('partials.view-only-banner', ['permission' => 'cash.create', 'message' => 'adding entries'])
        @endunless

        @php
            $todayNet = $stats['today_in'] - $stats['today_out'];
            $monthNet = $stats['month_in'] - $stats['month_out'];
            $hasActiveFilters = request()->hasAny(['search', 'type', 'from_date', 'payment_mode', 'to_date']);

            // Money on Hand window label (matches the per-mode panel's date range).
            $moneyRangeLabel = (request('from_date') || request('to_date'))
                ? trim((request('from_date') ?: 'start') . ' to ' . (request('to_date') ?: 'today'))
                : 'This month';

            // Plain-English labels for each money mode.
            $modeLabels = [
                'cash' => 'Cash in hand', 'upi' => 'UPI', 'bank' => 'Bank',
                'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other',
            ];
            $typeLabels = ['in' => 'Money In', 'out' => 'Money Out'];
            $paymentModeLabels = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'];
            $activeFilters = [];

            if (filled(request('search'))) {
                $activeFilters[] = 'Search: ' . Str::limit(request('search'), 24);
            }
            if (request('type') && isset($typeLabels[request('type')])) {
                $activeFilters[] = 'Type: ' . $typeLabels[request('type')];
            }
            if (request('payment_mode') && isset($paymentModeLabels[request('payment_mode')])) {
                $activeFilters[] = 'Mode: ' . $paymentModeLabels[request('payment_mode')];
            }
            if (request('from_date') || request('to_date')) {
                $activeFilters[] = 'Date: ' . (request('from_date') ?: 'start') . ' to ' . (request('to_date') ?: 'today');
            }

            $cashRow = $perMode->cash();
            $otherModes = $perMode->modes->reject(fn ($m) => $m->mode === 'cash');
        @endphp

        <div class="cb-flow">
            @if(session('drawer_check_result'))
                <div class="cb-drawer-result cb-drawer-result--page">{{ session('drawer_check_result') }}</div>
            @endif

            <div class="cb-overview">
                {{-- KPI snapshot strip --}}
                <div class="cb-snapshot">
                    <div class="cb-snap">
                        <span class="cb-snap-icon cb-snap-icon--in" aria-hidden="true">
                            <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M12 5v14M5 12l7-7 7 7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span>
                            <p class="cb-snap-label">Today's cash in</p>
                            <p class="cb-snap-value cb-snap-value--in">₹{{ number_format($stats['today_in'], 2) }}</p>
                        </span>
                    </div>
                    <div class="cb-snap">
                        <span class="cb-snap-icon cb-snap-icon--out" aria-hidden="true">
                            <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M12 19V5M5 12l7 7 7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span>
                            <p class="cb-snap-label">Today's cash out</p>
                            <p class="cb-snap-value cb-snap-value--out">₹{{ number_format($stats['today_out'], 2) }}</p>
                        </span>
                    </div>
                    <div class="cb-snap">
                        <span class="cb-snap-icon cb-snap-icon--net" aria-hidden="true">
                            <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M4 7h16M7 12h10M10 17h4" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span>
                            <p class="cb-snap-label">Today's net</p>
                            <p class="cb-snap-value {{ $todayNet >= 0 ? 'cb-snap-value--pos' : 'cb-snap-value--neg' }}">{{ $todayNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($todayNet), 2) }}</p>
                        </span>
                    </div>
                    <div class="cb-snap">
                        <span class="cb-snap-icon cb-snap-icon--month" aria-hidden="true">
                            <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M3 3v18h18M7 15l4-4 3 3 5-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span>
                            <p class="cb-snap-label">This month net</p>
                            <p class="cb-snap-value {{ $monthNet >= 0 ? 'cb-snap-value--pos' : 'cb-snap-value--neg' }}">{{ $monthNet >= 0 ? '+' : '−' }}₹{{ number_format(abs($monthNet), 2) }}</p>
                        </span>
                    </div>
                </div>

                <section class="cb-moh" aria-label="Money on hand">
                    <div class="cb-moh-head">
                        <div>
                            <h2 class="cb-moh-title">Cash position</h2>
                            <p class="cb-moh-copy">Money by payment mode</p>
                        </div>
                        <span class="cb-moh-range">{{ $moneyRangeLabel }}</span>
                    </div>

                    <div class="cb-moh-grid">
                        <div class="cb-moh-cash">
                            <p class="cb-moh-cash-label">Cash in hand</p>
                            <p class="cb-moh-cash-value">₹{{ number_format($cashRow->closing, 2) }}</p>
                            <div class="cb-moh-breakdown" aria-label="Cash in hand movement">
                                <span><small>Opening</small><strong>₹{{ number_format($cashRow->opening, 2) }}</strong></span>
                                <span><small>In</small><strong>₹{{ number_format($cashRow->moneyIn, 2) }}</strong></span>
                                <span><small>Out</small><strong>₹{{ number_format($cashRow->moneyOut, 2) }}</strong></span>
                            </div>
                        </div>

                        <div class="cb-moh-modes">
                            @forelse($otherModes as $m)
                                <div class="cb-moh-mode">
                                    <p class="cb-moh-mode-label">{{ $modeLabels[$m->mode] ?? ucfirst($m->mode) }}</p>
                                    <p class="cb-moh-mode-value">₹{{ number_format($m->closing, 2) }}</p>
                                    <div class="cb-moh-breakdown cb-moh-breakdown--mode" aria-label="{{ $modeLabels[$m->mode] ?? ucfirst($m->mode) }} movement">
                                        <span><small>In</small><strong>₹{{ number_format($m->moneyIn, 2) }}</strong></span>
                                        <span><small>Out</small><strong>₹{{ number_format($m->moneyOut, 2) }}</strong></span>
                                    </div>
                                </div>
                            @empty
                                <div class="cb-moh-mode cb-moh-mode--empty">
                                    <p class="cb-moh-mode-sub">No UPI, bank, or card money in this period.</p>
                                </div>
                            @endforelse
                        </div>

                        <div class="cb-moh-total">
                            <p class="cb-moh-total-label">Total money</p>
                            <p class="cb-moh-total-value">₹{{ number_format($perMode->totalClosing, 2) }}</p>
                            <p class="cb-moh-total-sub">Cash + all other modes</p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Filter + table card --}}
            <div class="cb-card" x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false">
                <div class="cb-register-head">
                    <div>
                        <h2 class="cb-register-title">Entries</h2>
                        <p class="cb-register-copy">{{ number_format($transactions->total()) }} {{ Str::plural('entry', $transactions->total()) }} in this view</p>
                    </div>
                    <button type="button" class="cb-filter-trigger" @click="filtersOpen = true" :aria-expanded="filtersOpen.toString()" aria-controls="cashbook-filter-sheet">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 6h16M7 12h10M10 18h4" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Filters
                        @if($hasActiveFilters)
                            <span>{{ count($activeFilters) }}</span>
                        @endif
                    </button>
                </div>

                @if($hasActiveFilters)
                    <div class="cb-active-filters">
                        @foreach($activeFilters as $filter)
                            <span>{{ $filter }}</span>
                        @endforeach
                        <a href="{{ route('cashbook.index') }}">Clear all</a>
                    </div>
                @endif

                <button type="button" class="cb-filter-backdrop" x-show="filtersOpen" x-cloak @click="filtersOpen = false" aria-label="Close filters"></button>

                <form method="GET" action="{{ route('cashbook.index') }}" id="cashbook-filter-sheet" class="cb-filter" :class="{ 'is-open': filtersOpen }" @submit="filtersOpen = false">
                    <div class="cb-filter-sheet-head">
                        <div>
                            <p>Filters</p>
                            <span>Refine entries</span>
                        </div>
                        <button type="button" @click="filtersOpen = false" aria-label="Close filters">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                    <div class="cb-filter-field cb-filter-field--search">
                        <span class="cb-filter-label">Search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Description or source..." class="cb-input">
                    </div>
                    <div class="cb-filter-field">
                        <span class="cb-filter-label">Type</span>
                        <select name="type" class="cb-input">
                            <option value="">All Types</option>
                            <option value="in"  {{ request('type') === 'in'  ? 'selected' : '' }}>Money In</option>
                            <option value="out" {{ request('type') === 'out' ? 'selected' : '' }}>Money Out</option>
                        </select>
                    </div>
                    <div class="cb-filter-field">
                        <span class="cb-filter-label">Mode</span>
                        <select name="payment_mode" class="cb-input">
                            <option value="">All Modes</option>
                            @foreach($paymentModeLabels as $val => $label)
                                <option value="{{ $val }}" {{ request('payment_mode') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
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
                                        <span class="cb-mode">{{ ucfirst($tx->payment_mode ?: 'cash') }}</span>
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
                                            <p class="cb-empty-copy">Cash transactions appear here as they are recorded in the cash book.</p>
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
                                    <dd>{{ ucfirst($tx->payment_mode ?: 'cash') }}</dd>
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

    @can('cash.create')
        <div class="cb-drawer-popout-layer" x-show="drawerOpen" x-cloak aria-labelledby="cashbook-drawer-title" role="dialog" aria-modal="true">
            <button type="button" class="cb-drawer-popout-backdrop" @click="drawerOpen = false" aria-label="Close drawer check"></button>
            <section class="cb-drawer-popout" x-show="drawerOpen" x-transition:enter="cb-popout-enter" x-transition:enter-start="cb-popout-enter-start" x-transition:enter-end="cb-popout-enter-end" x-transition:leave="cb-popout-leave" x-transition:leave-start="cb-popout-leave-start" x-transition:leave-end="cb-popout-leave-end">
                <div class="cb-drawer-popout-head">
                    <div>
                        <h2 id="cashbook-drawer-title" class="cb-drawer-title">Match your drawer</h2>
                        <p class="cb-drawer-copy">Count physical cash against expected cash.</p>
                    </div>
                    <button type="button" class="cb-drawer-close" @click="drawerOpen = false" aria-label="Close drawer check">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <form method="POST" action="{{ route('cashbook.drawer-check') }}" class="cb-drawer-form" data-turbo-frame="_top">
                    @csrf
                    <div class="cb-drawer-expected">
                        <span class="cb-drawer-expected-label">Expected cash</span>
                        <span class="cb-drawer-expected-value">₹{{ number_format($expectedCashToday, 2) }}</span>
                    </div>
                    <label class="cb-drawer-field">
                        <span class="cb-drawer-label">Counted cash *</span>
                        <input type="number" step="0.01" min="0" name="counted_cash" required
                               value="{{ old('counted_cash') }}" placeholder="What you counted"
                               class="cb-input" x-ref="countedCash">
                        @error('counted_cash') <p class="cb-drawer-error">{{ $message }}</p> @enderror
                    </label>
                    <label class="cb-drawer-field">
                        <span class="cb-drawer-label">Note (optional)</span>
                        <input type="text" name="note" maxlength="500" value="{{ old('note') }}"
                               placeholder="e.g. end of day count" class="cb-input">
                    </label>
                    <button type="submit" class="cb-drawer-save">Save drawer check</button>
                </form>

                @if($recentDrawerChecks->isNotEmpty())
                    <div class="cb-drawer-history">
                        <p class="cb-drawer-history-title">Recent counts</p>
                        <ul class="cb-drawer-history-list">
                            @foreach($recentDrawerChecks as $check)
                                <li class="cb-drawer-history-row">
                                    <span class="cb-drawer-history-date">{{ $check->created_at->format('d M Y, h:i A') }}</span>
                                    <span class="cb-drawer-history-amts">
                                        Counted ₹{{ number_format($check->counted_cash, 2) }}
                                        · Expected ₹{{ number_format($check->expected_cash, 2) }}
                                    </span>
                                    @php $d = (float) $check->difference; @endphp
                                    <span class="cb-drawer-history-diff {{ abs($d) < 0.01 ? 'is-ok' : ($d > 0 ? 'is-over' : 'is-short') }}">
                                        @if(abs($d) < 0.01) Matched
                                        @elseif($d > 0) Over ₹{{ number_format($d, 2) }}
                                        @else Short ₹{{ number_format(abs($d), 2) }}
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        </div>
    @endcan
    </div>

    <style>
        [x-cloak] { display: none !important; }

        .cb-shell,
        .cashbook-page-header,
        .cb-page {
            --cb-border: #cbd5e1;
            --cb-border-soft: #e2e8f0;
            --cb-surface: #ffffff;
            --cb-surface-muted: #f8fafc;
            --cb-surface-nested: #f3f5f8;
            --cb-ink: #1f2430;
            --cb-ink-2: #4a4334;
            --cb-muted: #64748b;
            --cb-soft: #faf6ee;
            --cb-primary: #b45309;
            --cb-primary-hover: #92400e;
            --cb-accent: #b45309;
            --cb-accent-hover: #92400e;
            --cb-focus: rgba(245, 158, 11, .2);
            --cb-pos: #047857;
            --cb-neg: #b42318;
            --cb-ease: cubic-bezier(0.23, 1, 0.32, 1);
        }

        .cb-page {
            width: 100%;
            max-width: none;
            color: var(--cb-ink);
        }
        .cb-flow { display: flex; flex-direction: column; gap: 16px; }

        .cb-overview {
            display: grid;
            grid-template-columns: 1fr;
            align-items: stretch;
            gap: 12px;
        }

        .cashbook-page-header {
            flex-wrap: nowrap;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--cb-border-soft);
            background: #ffffff;
            box-shadow: none !important;
        }
        .cashbook-page-header > .min-w-0 { min-width: 0; }
        .cashbook-page-header .page-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            margin-left: auto;
        }

        .cb-add-btn,
        .cb-header-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color 140ms var(--cb-ease), border-color 140ms var(--cb-ease), transform 120ms var(--cb-ease);
        }
        .cashbook-page-header .page-actions > .cb-add-btn,
        .cb-add-btn {
            border: 1px solid #b45309 !important;
            background: #b45309 !important;
            color: #ffffff !important;
            box-shadow: none !important;
        }
        .cashbook-page-header .page-actions > .cb-add-btn:hover,
        .cb-add-btn:hover {
            border-color: #92400e !important;
            background: #92400e !important;
            color: #ffffff !important;
        }
        .cashbook-page-header .page-actions > .cb-header-btn,
        .cb-header-btn {
            border: 1px solid #cbd5e1 !important;
            background: #ffffff !important;
            color: #4a4334 !important;
            box-shadow: none !important;
        }
        .cashbook-page-header .page-actions > .cb-header-btn:hover,
        .cb-header-btn:hover {
            border-color: #94a3b8 !important;
            background: #f8fafc !important;
            color: #1f2430 !important;
        }
        .cb-add-btn:active,
        .cb-header-btn:active,
        .cb-drawer-toggle:active,
        .cb-drawer-save:active,
        .cb-filter-trigger:active,
        .cb-apply:active,
        .cb-clear:active {
            transform: scale(.98);
        }

        .cb-moh,
        .cb-drawer,
        .cb-card {
            border: 1px solid var(--cb-border-soft);
            border-radius: 14px;
            background: var(--cb-surface);
            box-shadow: none;
            overflow: hidden;
        }

        .cb-moh-head,
        .cb-register-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--cb-border-soft);
            background: var(--cb-surface);
        }
        .cb-moh-title,
        .cb-drawer-title,
        .cb-register-title {
            margin: 0;
            color: var(--cb-ink);
            font-size: 17px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.15px;
        }
        .cb-moh-range,
        .cb-register-copy,
        .cb-drawer-copy {
            margin: 3px 0 0;
            color: var(--cb-muted);
            font-size: 13px;
            line-height: 1.35;
        }
        .cb-moh-range {
            margin: 0;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .cb-moh-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(0, 2fr) minmax(0, .9fr);
        }
        .cb-overview .cb-moh {
            height: 100%;
        }
        .cb-overview .cb-moh-grid {
            grid-template-columns: minmax(190px, .95fr) minmax(0, 2.5fr) minmax(180px, .85fr);
            grid-template-areas: "cash modes total";
        }
        .cb-overview .cb-moh-cash {
            grid-area: cash;
        }
        .cb-overview .cb-moh-total {
            grid-area: total;
        }
        .cb-overview .cb-moh-modes {
            grid-area: modes;
            grid-template-columns: repeat(auto-fit, minmax(112px, 1fr));
            border-right: 1px solid var(--cb-border-soft);
            border-top: 0;
        }
        .cb-overview .cb-moh-mode:nth-child(2n) {
            border-right: 1px solid var(--cb-border-soft);
        }
        .cb-overview .cb-moh-mode:last-child {
            border-right: 0;
        }
        .cb-moh-cash {
            padding: 18px 20px;
            border-right: 1px solid var(--cb-border-soft);
            background: var(--cb-surface-muted);
        }
        .cb-moh-cash-label,
        .cb-moh-total-label {
            margin: 0 0 6px;
            color: var(--cb-muted);
            font-size: 12px;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }
        .cb-moh-cash-value,
        .cb-moh-total-value {
            margin: 0;
            color: var(--cb-ink);
            font-size: 22px;
            font-weight: 650;
            line-height: 1.1;
            letter-spacing: -0.25px;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        .cb-moh-cash-sub,
        .cb-moh-total-sub,
        .cb-moh-mode-sub {
            margin: 7px 0 0;
            color: var(--cb-muted);
            font-size: 11.5px;
            line-height: 1.45;
            font-variant-numeric: tabular-nums;
        }
        .cb-moh-breakdown {
            display: grid;
            gap: 6px;
            margin-top: 10px;
            font-variant-numeric: tabular-nums;
        }
        .cb-moh-breakdown span {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            align-items: center;
            gap: 8px;
            min-width: 0;
            min-height: 28px;
            padding: 4px 7px;
            border: 1px solid var(--cb-border-soft);
            border-radius: 8px;
            background: #ffffff;
        }
        .cb-moh-breakdown small {
            min-width: 0;
            color: var(--cb-muted);
            font-size: 10.5px;
            font-weight: 600;
            line-height: 1.15;
        }
        .cb-moh-breakdown strong {
            min-width: 0;
            color: var(--cb-ink);
            font-size: 11.5px;
            font-weight: 650;
            line-height: 1.15;
            text-align: right;
            overflow-wrap: anywhere;
        }
        .cb-moh-breakdown--mode {
            margin-top: 8px;
            gap: 5px;
        }
        .cb-moh-cash-hint {
            margin: 6px 0 0;
            color: var(--cb-soft);
            font-size: 11.5px;
        }
        .cb-moh-modes {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            border-right: 1px solid var(--cb-border-soft);
        }
        .cb-moh-mode {
            padding: 14px 16px;
            border-right: 1px solid var(--cb-border-soft);
            border-bottom: 1px solid var(--cb-border-soft);
            min-width: 0;
        }
        .cb-moh-mode:nth-child(2n) { border-right: 0; }
        .cb-moh-mode--empty {
            grid-column: 1 / -1;
            border: 0;
            display: flex;
            align-items: center;
        }
        .cb-moh-mode-label {
            margin: 0 0 4px;
            color: var(--cb-muted);
            font-size: 12px;
            font-weight: 500;
        }
        .cb-moh-mode-value {
            margin: 0;
            color: var(--cb-ink);
            font-size: 17px;
            font-weight: 600;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        .cb-moh-total {
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--cb-surface-muted);
        }

        .cb-drawer-result {
            margin: 0;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            font-size: 13px;
            font-weight: 600;
        }
        .cb-drawer-result--page {
            border-radius: 12px;
        }
        .cb-drawer-toggle,
        .cb-drawer-save,
        .cb-apply {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 15px;
            border: 1px solid var(--cb-primary);
            border-radius: 10px;
            background: var(--cb-primary);
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: background-color 140ms var(--cb-ease), border-color 140ms var(--cb-ease), transform 120ms var(--cb-ease);
        }
        .cb-drawer-save:hover,
        .cb-apply:hover {
            border-color: var(--cb-primary-hover);
            background: var(--cb-primary-hover);
        }
        .cb-drawer-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            align-items: end;
        }
        .cb-drawer-expected {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid var(--cb-border);
            background: var(--cb-surface-nested);
        }
        .cb-drawer-expected-label,
        .cb-drawer-label,
        .cb-filter-label {
            color: var(--cb-muted);
            font-size: 12px;
            font-weight: 500;
        }
        .cb-drawer-expected-label {
            font-size: 12px;
            text-transform: none;
            letter-spacing: 0;
        }
        .cb-drawer-expected-value {
            color: var(--cb-ink);
            font-size: 18px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }
        .cb-drawer-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .cb-drawer-error {
            margin: 2px 0 0;
            color: var(--cb-neg);
            font-size: 12px;
        }
        .cb-drawer-history {
            margin-top: 18px;
            border-top: 1px solid var(--cb-border-soft);
            padding-top: 14px;
        }
        .cb-drawer-history-title {
            margin: 0 0 8px;
            color: var(--cb-muted);
            font-size: 12px;
            font-weight: 600;
        }
        .cb-drawer-history-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .cb-drawer-history-row {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 6px 14px;
            font-size: 12.5px;
            color: var(--cb-ink-2);
            font-variant-numeric: tabular-nums;
        }
        .cb-drawer-history-date { color: var(--cb-muted); }
        .cb-drawer-history-diff { font-weight: 650; }
        .cb-drawer-history-diff.is-ok,
        .cb-drawer-history-diff.is-over { color: var(--cb-pos); }
        .cb-drawer-history-diff.is-short { color: var(--cb-neg); }

        .cb-drawer-popout-layer {
            position: fixed;
            inset: 0;
            z-index: 120;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .cb-drawer-popout-backdrop {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
            background: rgba(15, 23, 42, .38);
            cursor: pointer;
        }
        .cb-drawer-popout {
            position: relative;
            z-index: 1;
            width: min(460px, calc(100vw - 32px));
            max-height: calc(100dvh - 48px);
            overflow: auto;
            border: 1px solid var(--cb-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: none;
        }
        .cb-drawer-popout-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--cb-border-soft);
            background: #ffffff;
        }
        .cb-drawer-popout .cb-drawer-form {
            padding: 16px 18px 18px;
        }
        .cb-drawer-popout .cb-drawer-history {
            margin: 0 18px 18px;
        }
        .cb-drawer-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border: 1px solid var(--cb-border);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cb-ink-2);
            cursor: pointer;
            transition: background-color 140ms var(--cb-ease), border-color 140ms var(--cb-ease), transform 120ms var(--cb-ease);
        }
        .cb-drawer-close:hover {
            border-color: #94a3b8;
            background: var(--cb-surface-muted);
        }
        .cb-drawer-close:active {
            transform: scale(.98);
        }
        .cb-popout-enter,
        .cb-popout-leave {
            transition: opacity 180ms var(--cb-ease), transform 180ms var(--cb-ease);
        }
        .cb-popout-enter-start,
        .cb-popout-leave-end {
            opacity: 0;
            transform: translateY(8px) scale(.98);
        }
        .cb-popout-enter-end,
        .cb-popout-leave-start {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .cb-snapshot {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }
        .cb-snap {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 76px;
            padding: 14px 15px;
            border: 1px solid var(--cb-border-soft);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: none;
            min-width: 0;
        }
        .cb-snap > span:not(.cb-snap-icon) {
            min-width: 0;
        }
        .cb-snap-icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            flex: 0 0 36px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .cb-snap-icon--in {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: var(--cb-pos);
        }
        .cb-snap-icon--out {
            border: 1px solid #fecdca;
            background: #fef2f2;
            color: var(--cb-neg);
        }
        .cb-snap-icon--net,
        .cb-snap-icon--month {
            border: 1px solid #f3dcb6;
            background: #fdf6ec;
            color: var(--cb-accent);
        }
        .cb-snap-label {
            margin: 0 0 6px;
            color: var(--cb-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.25;
        }
        .cb-snap-value {
            margin: 0;
            color: var(--cb-ink);
            font-size: 22px;
            font-weight: 650;
            line-height: 1.15;
            letter-spacing: 0;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        .cb-snap-value--in,
        .cb-snap-value--pos { color: var(--cb-pos); }
        .cb-snap-value--out,
        .cb-snap-value--neg { color: var(--cb-neg); }

        @media (hover: hover) and (pointer: fine) {
            .cb-snap:hover,
            .cb-mobile-card:hover {
                border-color: #d8dee8;
            }
        }

        .cb-register-head { background: var(--cb-surface); }
        .cb-filter-trigger {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 36px;
            padding: 0 12px;
            border: 1px solid var(--cb-border);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cb-ink-2);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 140ms var(--cb-ease), border-color 140ms var(--cb-ease), transform 120ms var(--cb-ease);
        }
        .cb-filter-trigger:hover {
            border-color: #94a3b8;
            background: var(--cb-surface-muted);
        }
        .cb-filter-trigger span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: var(--cb-accent);
            color: #ffffff;
            font-size: 11px;
            line-height: 1;
        }
        .cb-active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px 18px;
            border-bottom: 1px solid var(--cb-border-soft);
            background: var(--cb-surface-muted);
        }
        .cb-active-filters span,
        .cb-active-filters a {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid var(--cb-border);
            border-radius: 999px;
            background: #ffffff;
            color: var(--cb-ink-2);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }
        .cb-active-filters a {
            color: var(--cb-accent-hover);
            border-color: #fed7aa;
            background: #fff7ed;
        }

        .cb-filter {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) repeat(4, minmax(132px, auto)) auto;
            align-items: flex-end;
            gap: 10px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--cb-border-soft);
            background: #ffffff;
        }
        .cb-filter-sheet-head { display: none; }
        .cb-filter-backdrop { display: none; }
        .cb-filter-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cb-filter-field--search {
            min-width: 0;
        }
        .cb-filter-field--search .cb-input {
            width: 100%;
            min-width: 0;
        }
        .cb-drawer-popout .cb-input {
            width: 100%;
            min-width: 0;
        }
        .cb-input {
            height: 40px;
            min-width: 140px;
            padding: 0 11px;
            border: 1px solid var(--cb-border);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cb-ink);
            font-size: 14px;
            font-weight: 400;
            box-shadow: none;
            transition: border-color 140ms var(--cb-ease), box-shadow 140ms var(--cb-ease), background-color 140ms var(--cb-ease);
        }
        .cb-input:focus {
            border-color: var(--cb-accent);
            outline: none;
            box-shadow: 0 0 0 3px var(--cb-focus);
        }
        .cb-input::placeholder { color: #94a3b8; }
        .cb-filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }
        .cb-clear {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--cb-border);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cb-ink-2);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 140ms var(--cb-ease), border-color 140ms var(--cb-ease), transform 120ms var(--cb-ease);
        }
        .cb-clear:hover {
            border-color: #94a3b8;
            background: var(--cb-surface-muted);
        }

        .cb-table-wrap { overflow-x: auto; }
        .cb-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;
        }
        .cb-table thead th {
            padding: 12px 18px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--cb-muted);
            background: var(--cb-surface-muted);
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }
        .cb-table thead th.text-right { text-align: right; }
        .cb-table tbody td {
            padding: 14px 18px;
            vertical-align: middle;
            border-bottom: 1px solid #edf2f7;
            color: var(--cb-ink-2);
            font-weight: 400;
            line-height: 1.35;
        }
        .cb-table tbody tr:last-child td { border-bottom: 0; }
        .cb-table tbody tr:nth-child(even) { background: #f8fbff; }
        .cb-table tbody tr:hover { background: #edf5ff; }
        .cb-table td.text-right { text-align: right; }

        .cb-muted { color: var(--cb-muted); }
        .cb-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12.5px;
            color: var(--cb-ink-2);
        }
        .cb-desc {
            color: var(--cb-ink-2);
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cb-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .cb-pill::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 999px;
            background: currentColor;
            flex-shrink: 0;
        }
        .cb-pill--in {
            background: #ecfdf5;
            color: var(--cb-pos);
        }
        .cb-pill--out {
            background: #fef2f2;
            color: var(--cb-neg);
        }
        .cb-mode {
            display: inline-flex;
            align-items: center;
            padding: 3px 9px;
            border: 1px solid var(--cb-border-soft);
            border-radius: 7px;
            background: var(--cb-surface-nested);
            color: var(--cb-ink-2);
            font-size: 12px;
            font-weight: 600;
        }
        .cb-amount {
            font-size: 14px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .cb-amount--in { color: var(--cb-pos); }
        .cb-amount--out { color: var(--cb-neg); }

        .cb-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 56px 24px;
            text-align: center;
        }
        .cb-empty-icon {
            width: 42px;
            height: 42px;
            color: #94a3b8;
        }
        .cb-empty-title {
            margin: 0;
            color: var(--cb-ink);
            font-size: 15px;
            font-weight: 650;
        }
        .cb-empty-copy {
            margin: 0;
            max-width: 36ch;
            color: var(--cb-muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .cb-pagination {
            padding: 14px 18px;
            border-top: 1px solid var(--cb-border-soft);
            background: var(--cb-surface-muted);
        }

        .cb-mobile-list { display: none; }
        .cb-mobile-card {
            display: grid;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--cb-border-soft);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: none;
        }
        .cb-mobile-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 0;
        }
        .cb-mobile-date {
            margin: 6px 0 0;
            color: var(--cb-muted);
            font-size: 11.5px;
        }
        .cb-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 0;
        }
        .cb-mobile-grid div {
            min-width: 0;
            padding: 9px 10px;
            border: 1px solid var(--cb-border-soft);
            border-radius: 10px;
            background: #f8fafc;
        }
        .cb-mobile-grid dt {
            margin: 0;
            color: var(--cb-muted);
            font-size: 11px;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }
        .cb-mobile-grid dd {
            margin: 4px 0 0;
            color: var(--cb-ink);
            font-size: 13px;
            font-weight: 600;
            word-break: break-word;
        }

        @media (max-width: 1024px) {
            .cb-overview {
                grid-template-columns: 1fr;
            }
            .cb-overview .cb-moh-grid {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                grid-template-areas:
                    "cash total"
                    "modes modes";
            }
            .cb-overview .cb-moh-cash,
            .cb-overview .cb-moh-modes {
                border-right: 0;
                border-bottom: 1px solid var(--cb-border-soft);
            }
            .cb-overview .cb-moh-modes {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                border-top: 1px solid var(--cb-border-soft);
            }
            .cb-overview .cb-moh-mode:nth-child(2n) {
                border-right: 0;
            }
        }

        @media (max-width: 900px) {
            .cb-snapshot {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .content-header.cashbook-page-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 8px;
            }
            .cashbook-page-header .content-header-nav {
                margin-right: 0;
            }
            .cashbook-page-header .page-title {
                font-size: 17px;
                line-height: 1.15;
            }
            .cashbook-page-header .page-subtitle {
                display: none;
            }
            .cashbook-page-header .page-actions {
                width: auto;
                flex: 0 0 auto;
                gap: 6px;
            }
            .cashbook-page-header .cb-add-btn,
            .cashbook-page-header .cb-header-btn {
                width: 34px;
                min-width: 34px;
                min-height: 34px;
                padding: 0;
                border-radius: 10px;
            }
            .cashbook-page-header .cb-action-label {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
            }

            .cb-flow { gap: 12px; }
            .cb-overview {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .cb-moh-head,
            .cb-register-head,
            .cb-drawer-head {
                padding: 12px 14px;
            }
            .cb-moh-title,
            .cb-drawer-title,
            .cb-register-title {
                font-size: 14px;
            }
            .cb-moh-range,
            .cb-register-copy,
            .cb-drawer-copy {
                font-size: 11.5px;
            }
            .cb-moh-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-areas:
                    "cash total"
                    "modes modes";
            }
            .cb-moh-cash {
                grid-area: cash;
                border-right: 1px solid var(--cb-border-soft);
                border-bottom: 1px solid var(--cb-border-soft);
                padding: 12px;
            }
            .cb-moh-total {
                grid-area: total;
                border-bottom: 1px solid var(--cb-border-soft);
                padding: 12px;
            }
            .cb-moh-modes {
                grid-area: modes;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                border-right: 0;
                border-bottom: 0;
            }
            .cb-moh-mode {
                padding: 10px 12px;
            }
            .cb-moh-cash-label,
            .cb-moh-total-label {
                font-size: 11px;
                letter-spacing: 0;
            }
            .cb-moh-cash-value,
            .cb-moh-total-value {
                font-size: 19px;
                line-height: 1.12;
            }
            .cb-moh-mode-value {
                font-size: 15px;
                overflow-wrap: anywhere;
            }
            .cb-moh-cash-sub,
            .cb-moh-total-sub,
            .cb-moh-mode-sub {
                font-size: 10.5px;
                line-height: 1.35;
            }
            .cb-moh-breakdown {
                gap: 5px;
                margin-top: 8px;
            }
            .cb-moh-breakdown span {
                grid-template-columns: minmax(0, .62fr) minmax(0, 1fr);
                min-height: 27px;
                padding: 4px 6px;
            }
            .cb-moh-breakdown small {
                font-size: 10px;
            }
            .cb-moh-breakdown strong {
                font-size: 10.5px;
            }
            .cb-moh-cash-hint {
                display: none;
            }

            .cb-drawer-form {
                grid-template-columns: 1fr;
            }
            .cb-drawer-popout-layer {
                align-items: end;
                padding: 12px;
            }
            .cb-drawer-popout {
                width: 100%;
                max-height: calc(100dvh - 24px);
                border-radius: 16px;
            }
            .cb-drawer-popout-head,
            .cb-drawer-popout .cb-drawer-form {
                padding-left: 14px;
                padding-right: 14px;
            }
            .cb-drawer-popout .cb-drawer-history {
                margin-left: 14px;
                margin-right: 14px;
            }

            .cb-snapshot {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .cb-snap {
                display: grid;
                grid-template-columns: 22px minmax(0, 1fr);
                grid-template-rows: 22px minmax(31px, 1fr);
                grid-template-areas:
                    "icon label"
                    "value value";
                align-items: stretch;
                min-height: 82px;
                padding: 8px 10px;
                row-gap: 4px;
            }
            .cb-snap-icon {
                grid-area: icon;
                justify-self: start;
                width: 22px;
                height: 22px;
                flex-basis: 22px;
                border-radius: 7px;
            }
            .cb-snap-icon svg {
                width: 13px !important;
                height: 13px !important;
            }
            .cb-snap > span:not(.cb-snap-icon) {
                display: contents;
            }
            .cb-snap-label {
                grid-area: label;
                align-self: center;
                justify-self: start;
                margin-bottom: 0;
                font-size: 10.5px;
                line-height: 1.2;
                text-align: left;
                overflow-wrap: anywhere;
            }
            .cb-snap-value {
                grid-area: value;
                align-self: center;
                justify-self: center;
                max-width: 100%;
                font-size: 15px;
                line-height: 1.15;
                text-align: center;
                white-space: normal;
                overflow-wrap: anywhere;
                word-break: normal;
            }

            .cb-register-head {
                position: relative;
                align-items: center;
            }
            .cb-filter-trigger {
                display: inline-flex;
            }
            .cb-active-filters {
                padding: 10px 14px;
                gap: 6px;
            }
            .cb-active-filters span,
            .cb-active-filters a {
                min-height: 26px;
                font-size: 11.5px;
            }

            .cb-filter-backdrop {
                position: fixed;
                inset: 0;
                z-index: 60;
                display: block;
                width: 100%;
                height: 100%;
                border: 0;
                background: rgba(15, 23, 42, .38);
            }
            .cb-filter {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 70;
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
                max-height: 86dvh;
                padding: 14px;
                overflow-y: auto;
                border: 1px solid var(--cb-border);
                border-bottom: 0;
                border-radius: 16px 16px 0 0;
                background: #ffffff;
                transform: translateY(105%);
                opacity: 0;
                pointer-events: none;
                transition: transform 220ms var(--cb-ease), opacity 180ms var(--cb-ease);
            }
            .cb-filter.is-open {
                transform: translateY(0);
                opacity: 1;
                pointer-events: auto;
            }
            .cb-filter-sheet-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding-bottom: 2px;
            }
            .cb-filter-sheet-head p {
                margin: 0;
                color: var(--cb-ink);
                font-size: 15px;
                font-weight: 650;
            }
            .cb-filter-sheet-head span {
                display: block;
                margin-top: 2px;
                color: var(--cb-muted);
                font-size: 12px;
            }
            .cb-filter-sheet-head button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                border: 1px solid var(--cb-border);
                border-radius: 10px;
                background: #ffffff;
                color: var(--cb-ink-2);
            }
            .cb-filter-field,
            .cb-input,
            .cb-filter-actions {
                width: 100%;
                min-width: 0;
            }
            .cb-input {
                height: 42px;
            }
            .cb-filter-actions {
                display: grid;
                grid-template-columns: 1fr;
                gap: 8px;
                padding-top: 2px;
            }
            .cb-apply,
            .cb-clear {
                width: 100%;
                min-height: 42px;
            }

            .cb-table-wrap {
                display: none;
            }
            .cb-mobile-list {
                display: grid;
                gap: 10px;
                padding: 12px;
                border-top: 1px solid var(--cb-border-soft);
                background: #fbfcfd;
            }
            .cb-mobile-head .cb-amount {
                font-size: 13.5px;
                text-align: right;
                max-width: 48%;
                white-space: normal;
            }
        }

        @media (max-width: 380px) {
            .cb-overview .cb-moh-modes {
                grid-template-columns: 1fr;
            }
            .cb-overview .cb-moh-mode,
            .cb-overview .cb-moh-mode:nth-child(2n) {
                border-right: 0;
            }
        }
    </style>
</x-app-layout>
