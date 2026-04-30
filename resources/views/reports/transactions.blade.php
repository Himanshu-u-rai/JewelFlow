<x-app-layout>
    @php
        $periodOptions = [
            'today' => 'Today',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_month' => 'This month',
            'this_quarter' => 'This quarter',
            'this_year' => 'This year',
            'month' => 'By month',
            'quarter' => 'By quarter',
            'year' => 'By year',
            'custom' => 'Custom range',
        ];

        $selectedPeriod = $filters['period'] ?? 'last_30_days';
        $selectedYear = (int) ($filters['period_year'] ?? now()->year);
        $yearOptions = range(now()->year + 1, 2020);

        $totalIn = (float) ($totals->total_in ?? 0);
        $totalOut = (float) ($totals->total_out ?? 0);
        $netTotal = (float) ($totals->net_total ?? 0);
        $txnCount = (int) ($totals->txn_count ?? 0);
    @endphp

    <x-page-header class="transactions-report-header jf-header-auto-mobile">
        <div>
            <h1 class="page-title">Transactions</h1>
            <p class="transactions-page-subtitle text-sm text-gray-500 mt-1">Unified incoming and outgoing movement across sales, purchases, ledgers, schemes, and payouts.</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <span class="header-badge">
                {{ \Carbon\Carbon::parse($dateFrom)->format('d M') }} - {{ \Carbon\Carbon::parse($dateTo)->format('d M') }}
            </span>
        </div>
    </x-page-header>

    <div class="content-inner transactions-report-page">
        <form method="GET" action="{{ route('report.transactions') }}" class="transactions-filter-card" data-enhance-selects="true" data-enhance-selects-variant="standard">
            <div class="transactions-mobile-filter-trigger">
                <div class="field transactions-search-field-mobile">
                    <label class="field-label">Search</label>
                    <div class="transactions-search-wrap">
                        <svg class="transactions-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.3-4.3"></path>
                        </svg>
                        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="field-input transactions-search-input" data-search-mobile placeholder="Reference, party or note">
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm transactions-mobile-filter-open" data-mobile-filter-open aria-expanded="false" aria-controls="transactions-mobile-filter-panel">Filters</button>
            </div>

            <div class="transactions-mobile-filter-backdrop" data-mobile-filter-backdrop></div>

            <div class="transactions-mobile-filter-panel" id="transactions-mobile-filter-panel" data-mobile-filter-panel aria-hidden="true">
                <div class="transactions-filter-head">
                    <div>
                        <p class="transactions-kicker">Report Window</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm transactions-mobile-filter-close" data-mobile-filter-close>Close</button>
                </div>

                <div class="transactions-filter-grid">
                <div class="field">
                    <label class="field-label">Period</label>
                    <select name="period" class="field-input">
                        @foreach($periodOptions as $key => $label)
                            <option value="{{ $key }}" {{ $selectedPeriod === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? $dateFrom }}" class="field-input">
                </div>

                <div class="field">
                    <label class="field-label">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? $dateTo }}" class="field-input">
                </div>

                <div class="field">
                    <label class="field-label">Month</label>
                    <input type="month" name="period_month" value="{{ $filters['period_month'] ?? '' }}" class="field-input">
                </div>

                <div class="field">
                    <label class="field-label">Year</label>
                    <select name="period_year" class="field-input">
                        @foreach($yearOptions as $yearOption)
                            <option value="{{ $yearOption }}" {{ $selectedYear === (int) $yearOption ? 'selected' : '' }}>{{ $yearOption }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">Quarter</label>
                    <select name="period_quarter" class="field-input">
                        @foreach([1, 2, 3, 4] as $qtr)
                            <option value="{{ $qtr }}" {{ (string) ($filters['period_quarter'] ?? '1') === (string) $qtr ? 'selected' : '' }}>Q{{ $qtr }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">Flow</label>
                    <select name="flow" class="field-input">
                        <option value="all" {{ ($filters['flow'] ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                        <option value="in" {{ ($filters['flow'] ?? '') === 'in' ? 'selected' : '' }}>Incoming</option>
                        <option value="out" {{ ($filters['flow'] ?? '') === 'out' ? 'selected' : '' }}>Outgoing</option>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">Type</label>
                    <select name="txn_type" class="field-input">
                        <option value="all">All types</option>
                        @foreach($availableTypes as $type)
                            <option value="{{ $type }}" {{ ($filters['txn_type'] ?? 'all') === $type ? 'selected' : '' }}>
                                {{ \Illuminate\Support\Str::of($type)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">Mode</label>
                    <select name="payment_mode" class="field-input">
                        <option value="all">All modes</option>
                        @foreach($availableModes as $mode)
                            <option value="{{ $mode }}" {{ ($filters['payment_mode'] ?? 'all') === $mode ? 'selected' : '' }}>
                                {{ \Illuminate\Support\Str::of($mode)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label class="field-label">Payment Method</label>
                    <select name="payment_method_id" class="field-input">
                        <option value="">All accounts</option>
                        @foreach($paymentMethods as $method)
                            <option value="{{ $method->id }}" {{ (int) ($filters['payment_method_id'] ?? 0) === (int) $method->id ? 'selected' : '' }}>
                                {{ $method->account_label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field transactions-search-field transactions-search-field-desktop">
                    <label class="field-label">Search</label>
                    <div class="transactions-search-wrap">
                        <svg class="transactions-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.3-4.3"></path>
                        </svg>
                        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="field-input transactions-search-input" data-search-desktop placeholder="Reference, party or note">
                    </div>
                </div>
            </div>

                <div class="transactions-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
                    <a href="{{ route('report.transactions') }}" class="btn btn-secondary btn-sm">Clear</a>
                </div>
            </div>
        </form>

        <div class="transactions-kpi-grid">
            <div class="transactions-kpi-card transactions-kpi-card--in">
                <div class="transactions-kpi-top">
                    <span class="transactions-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"></path><path d="m5 12 7-7 7 7"></path></svg>
                    </span>
                    <span class="transactions-kpi-tag">Money In</span>
                </div>
                <div class="transactions-kpi-label">Incoming</div>
                <div class="transactions-kpi-value transactions-kpi-value-in">₹{{ number_format($totalIn, 2) }}</div>
                <p class="transactions-kpi-note">Receipts captured in the selected window.</p>
            </div>

            <div class="transactions-kpi-card transactions-kpi-card--out">
                <div class="transactions-kpi-top">
                    <span class="transactions-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"></path><path d="m19 12-7 7-7-7"></path></svg>
                    </span>
                    <span class="transactions-kpi-tag">Money Out</span>
                </div>
                <div class="transactions-kpi-label">Outgoing</div>
                <div class="transactions-kpi-value transactions-kpi-value-out">₹{{ number_format($totalOut, 2) }}</div>
                <p class="transactions-kpi-note">Payouts and purchase-side outflow.</p>
            </div>

            <div class="transactions-kpi-card transactions-kpi-card--net">
                <div class="transactions-kpi-top">
                    <span class="transactions-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17 9 11l4 4 8-8"></path><path d="M14 7h7v7"></path></svg>
                    </span>
                    <span class="transactions-kpi-tag">Position</span>
                </div>
                <div class="transactions-kpi-label">Net</div>
                <div class="transactions-kpi-value {{ $netTotal >= 0 ? 'transactions-kpi-value-in' : 'transactions-kpi-value-out' }}">
                    {{ $netTotal >= 0 ? '+' : '-' }}₹{{ number_format(abs($netTotal), 2) }}
                </div>
                <p class="transactions-kpi-note">Difference between inflow and outflow.</p>
            </div>

            <div class="transactions-kpi-card transactions-kpi-card--count">
                <div class="transactions-kpi-top">
                    <span class="transactions-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"></rect><path d="M7 8h10"></path><path d="M7 12h10"></path><path d="M7 16h6"></path></svg>
                    </span>
                    <span class="transactions-kpi-tag">Volume</span>
                </div>
                <div class="transactions-kpi-label">Transactions</div>
                <div class="transactions-kpi-value">#{{ number_format($txnCount) }}</div>
                <p class="transactions-kpi-note">Matched rows in the current filter result.</p>
            </div>
        </div>

        <div class="transactions-breakdown-grid">
            <section class="transactions-surface-card">
                <div class="transactions-surface-head">
                    <div>
                        <h3>By Transaction Type</h3>
                        <p class="transactions-panel-copy">See which operational buckets are driving the movement.</p>
                    </div>
                    <span class="transactions-count-pill">{{ count($byType) }} groups</span>
                </div>
                <div class="transactions-surface-body">
                    @forelse($byType as $row)
                        <div class="transactions-breakdown-row">
                            <div>
                                <div class="transactions-breakdown-title">{{ \Illuminate\Support\Str::of($row->txn_type)->replace('_', ' ')->title() }}</div>
                                <div class="transactions-breakdown-caption">{{ (int) $row->txn_count }} transaction{{ (int) $row->txn_count === 1 ? '' : 's' }}</div>
                            </div>
                            <div class="transactions-breakdown-values">
                                <span class="transactions-breakdown-stat transactions-in">In ₹{{ number_format((float) $row->total_in, 2) }}</span>
                                <span class="transactions-breakdown-stat transactions-out">Out ₹{{ number_format((float) $row->total_out, 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="transactions-empty">No data for selected filters.</p>
                    @endforelse
                </div>
            </section>

            <section class="transactions-surface-card">
                <div class="transactions-surface-head">
                    <div>
                        <h3>By Payment Mode</h3>
                        <p class="transactions-panel-copy">Understand movement by cash, bank, UPI, wallet, or special settlement modes.</p>
                    </div>
                    <span class="transactions-count-pill">{{ count($byMode) }} modes</span>
                </div>
                <div class="transactions-surface-body">
                    @forelse($byMode as $row)
                        <div class="transactions-breakdown-row">
                            <div>
                                <div class="transactions-breakdown-title">{{ \Illuminate\Support\Str::of($row->payment_mode)->replace('_', ' ')->title() }}</div>
                                <div class="transactions-breakdown-caption">{{ (int) $row->txn_count }} transaction{{ (int) $row->txn_count === 1 ? '' : 's' }}</div>
                            </div>
                            <div class="transactions-breakdown-values">
                                <span class="transactions-breakdown-stat transactions-in">In ₹{{ number_format((float) $row->total_in, 2) }}</span>
                                <span class="transactions-breakdown-stat transactions-out">Out ₹{{ number_format((float) $row->total_out, 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="transactions-empty">No mode-wise data for selected filters.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="transactions-surface-card transactions-register-card">
            <div class="transactions-surface-head">
                <div>
                    <h3>Transaction Register</h3>
                    <p class="transactions-panel-copy">Reverse chronological view of every entry matching the selected filter set.</p>
                </div>
                <span class="transactions-count-pill">{{ number_format($txnCount) }} results</span>
            </div>

            <div class="transactions-table-wrap">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Flow</th>
                            <th>Payment</th>
                            <th>Reference</th>
                            <th>Party</th>
                            <th class="text-right">Amount</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $txn)
                            <tr>
                                <td class="transactions-date-cell">
                                    <strong>{{ \Carbon\Carbon::parse($txn->txn_at)->format('d M Y') }}</strong>
                                    <span>{{ \Carbon\Carbon::parse($txn->txn_at)->format('h:i A') }}</span>
                                </td>
                                <td>
                                    <span class="transactions-type-pill">{{ \Illuminate\Support\Str::of($txn->txn_type)->replace('_', ' ')->title() }}</span>
                                </td>
                                <td>
                                    <span class="transactions-flow-badge {{ $txn->flow === 'in' ? 'is-in' : 'is-out' }}">
                                        {{ $txn->flow === 'in' ? 'Incoming' : 'Outgoing' }}
                                    </span>
                                </td>
                                <td class="transactions-payment-cell">
                                    <div class="transactions-payment-mode">{{ \Illuminate\Support\Str::of($txn->payment_mode)->replace('_', ' ')->title() }}</div>
                                    @if(!empty($txn->payment_method_name))
                                        <small class="transactions-muted">{{ $txn->payment_method_name }}</small>
                                    @endif
                                </td>
                                <td>{{ $txn->reference_no ?: '—' }}</td>
                                <td>{{ $txn->party_name ?: '—' }}</td>
                                <td class="text-right transactions-amount-cell {{ $txn->flow === 'in' ? 'transactions-in' : 'transactions-out' }}">
                                    {{ $txn->flow === 'in' ? '+' : '-' }}₹{{ number_format((float) $txn->amount, 2) }}
                                </td>
                                <td class="transactions-notes-cell">{{ $txn->notes ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="transactions-empty-table">No transactions found for selected filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="transactions-mobile-list">
                @forelse($transactions as $txn)
                    <article class="transactions-mobile-card">
                        <div class="transactions-mobile-head">
                            <div>
                                <div class="transactions-mobile-title">{{ \Illuminate\Support\Str::of($txn->txn_type)->replace('_', ' ')->title() }}</div>
                                <div class="transactions-mobile-meta">{{ \Carbon\Carbon::parse($txn->txn_at)->format('d M Y, h:i A') }}</div>
                            </div>
                            <div class="transactions-mobile-amount {{ $txn->flow === 'in' ? 'transactions-in' : 'transactions-out' }}">
                                {{ $txn->flow === 'in' ? '+' : '-' }}₹{{ number_format((float) $txn->amount, 2) }}
                            </div>
                        </div>

                        <div class="transactions-mobile-chip-row">
                            <span class="transactions-flow-badge {{ $txn->flow === 'in' ? 'is-in' : 'is-out' }}">
                                {{ $txn->flow === 'in' ? 'Incoming' : 'Outgoing' }}
                            </span>
                            <span class="transactions-mobile-chip">{{ \Illuminate\Support\Str::of($txn->payment_mode)->replace('_', ' ')->title() }}</span>
                            @if(!empty($txn->payment_method_name))
                                <span class="transactions-mobile-chip">{{ $txn->payment_method_name }}</span>
                            @endif
                        </div>

                        <div class="transactions-mobile-grid">
                            <div>
                                <span>Reference</span>
                                <strong>{{ $txn->reference_no ?: '—' }}</strong>
                            </div>
                            <div>
                                <span>Party</span>
                                <strong>{{ $txn->party_name ?: '—' }}</strong>
                            </div>
                            <div class="transactions-mobile-grid-full">
                                <span>Notes</span>
                                <strong>{{ $txn->notes ?: '—' }}</strong>
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="transactions-empty">No transactions found for selected filters.</p>
                @endforelse
            </div>

            <div class="transactions-pagination">
                {{ $transactions->links() }}
            </div>
        </section>
    </div>

    <style>
        .transactions-report-page {
            --tx-ink: #0f172a;
            --tx-muted: #64748b;
            --tx-line: #d8e1ec;
            --tx-soft-line: #e8eef5;
            --tx-surface: rgba(255, 255, 255, 0.96);
            --tx-soft: #f8fafc;
            --tx-shadow: 0 18px 36px rgba(15, 23, 42, 0.07);
            --tx-shadow-soft: 0 10px 24px rgba(15, 23, 42, 0.05);
            --tx-green: #047857;
            --tx-green-soft: #ecfdf5;
            --tx-red: #b42318;
            --tx-red-soft: #fff1f2;
            --tx-blue: #0f766e;
            --tx-blue-soft: #ecfeff;
            position: relative;
            display: grid;
            gap: 16px;
        }

        .transactions-report-page::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 0% 0%, rgba(15, 118, 110, 0.05), transparent 24%),
                radial-gradient(circle at 100% 8%, rgba(217, 119, 6, 0.05), transparent 24%);
        }

        .transactions-filter-card,
        .transactions-surface-card,
        .transactions-kpi-card {
            border: 1px solid var(--tx-line);
            border-radius: 22px;
            background: var(--tx-surface);
            box-shadow: var(--tx-shadow);
        }

        .transactions-kicker {
            margin: 0 0 4px;
            color: var(--tx-blue);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .transactions-filter-card {
            padding: 16px 18px;
            position: relative;
        }

        .transactions-mobile-filter-trigger {
            display: none;
        }

        .transactions-search-field-mobile {
            display: none;
        }

        .transactions-mobile-filter-open {
            min-height: 42px;
            align-self: end;
        }

        .transactions-mobile-filter-backdrop {
            display: none;
        }

        .transactions-mobile-filter-close {
            display: none;
        }

        .transactions-filter-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        .transactions-filter-head h3,
        .transactions-surface-head h3 {
            margin: 0;
            color: var(--tx-ink);
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .transactions-filter-head p:last-child,
        .transactions-panel-copy {
            margin: 5px 0 0;
            color: var(--tx-muted);
            font-size: 12px;
            line-height: 1.5;
            font-weight: 600;
        }

        .transactions-report-page .field {
            display: grid;
            gap: 6px;
            align-content: start;
            min-width: 0;
        }

        .transactions-report-page .field-label {
            margin: 0;
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .transactions-report-page .field-input,
        .transactions-report-page .ui-filter-select-trigger {
            width: 100%;
            min-height: 42px;
            border: 1px solid #cfd9e4;
            border-radius: 12px;
            background: #fff;
            color: var(--tx-ink);
            font-size: 14px;
            box-shadow: none;
            transition: border-color 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease;
        }

        .transactions-report-page .field-input {
            padding: 0 12px;
        }

        .transactions-report-page .field-input:focus,
        .transactions-report-page .ui-filter-select-trigger:focus,
        .transactions-report-page .ui-filter-select-trigger.is-open {
            border-color: var(--tx-blue);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
            outline: none;
        }

        .transactions-report-page .ui-filter-select,
        .transactions-report-page .ui-filter-select-host {
            width: 100%;
        }

        .transactions-report-page .ui-filter-select-trigger {
            padding: 0 40px 0 12px;
        }

        .transactions-report-page .ui-filter-select-trigger-text {
            color: var(--tx-ink);
            font-size: 14px;
            font-weight: 600;
        }

        .transactions-report-page .ui-filter-select-menu {
            margin-top: 8px;
            padding: 6px;
            border: 1px solid #d6e0ea;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
        }

        .transactions-report-page .ui-filter-select-option {
            min-height: 38px;
            border-radius: 10px;
            padding: 8px 12px;
            color: var(--tx-ink);
            font-size: 13px;
            font-weight: 600;
        }

        .transactions-report-page .ui-filter-select-option.is-selected {
            background: #eefbf9;
            color: var(--tx-blue);
        }

        .transactions-report-page .ui-filter-select-option:hover {
            background: #f8fafc;
        }

        .transactions-filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
        }

        .transactions-search-field {
            grid-column: span 2;
        }

        .transactions-search-wrap {
            position: relative;
        }

        .transactions-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            width: 16px;
            height: 16px;
            color: #64748b;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .transactions-search-input {
            padding-left: 38px !important;
        }

        .transactions-filter-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .transactions-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .transactions-kpi-card {
            padding: 14px;
            box-shadow: var(--tx-shadow-soft);
        }

        .transactions-kpi-card--in {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(236, 253, 245, 0.98));
        }

        .transactions-kpi-card--out {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 241, 242, 0.98));
        }

        .transactions-kpi-card--net {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(236, 254, 255, 0.98));
        }

        .transactions-kpi-card--count {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
        }

        .transactions-kpi-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .transactions-kpi-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: 1px solid var(--tx-line);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.84);
            color: var(--tx-ink);
        }

        .transactions-kpi-icon svg {
            width: 18px;
            height: 18px;
        }

        .transactions-kpi-tag {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid var(--tx-line);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.88);
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 900;
        }

        .transactions-kpi-label {
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .transactions-kpi-value {
            margin-top: 8px;
            color: var(--tx-ink);
            font-size: 30px;
            line-height: 1;
            font-weight: 950;
            letter-spacing: -0.05em;
        }

        .transactions-kpi-value-in,
        .transactions-in {
            color: var(--tx-green);
        }

        .transactions-kpi-value-out,
        .transactions-out {
            color: var(--tx-red);
        }

        .transactions-kpi-note {
            margin: 9px 0 0;
            color: var(--tx-muted);
            font-size: 12px;
            line-height: 1.45;
            font-weight: 600;
        }

        .transactions-breakdown-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .transactions-surface-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--tx-soft-line);
        }

        .transactions-count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid var(--tx-line);
            border-radius: 999px;
            background: #fff;
            color: #475569;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .transactions-surface-body {
            padding: 10px 16px 14px;
        }

        .transactions-breakdown-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #eef3f8;
        }

        .transactions-breakdown-row:last-child {
            border-bottom: 0;
        }

        .transactions-breakdown-title {
            color: var(--tx-ink);
            font-size: 14px;
            font-weight: 800;
        }

        .transactions-breakdown-caption {
            margin-top: 3px;
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .transactions-breakdown-values {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .transactions-breakdown-stat {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .transactions-breakdown-stat.transactions-in {
            background: var(--tx-green-soft);
        }

        .transactions-breakdown-stat.transactions-out {
            background: var(--tx-red-soft);
        }

        .transactions-register-card {
            overflow: hidden;
        }

        .transactions-table-wrap {
            overflow-x: auto;
        }

        .transactions-table {
            width: 100%;
            min-width: 1040px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .transactions-table th,
        .transactions-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            font-size: 13px;
            color: var(--tx-ink);
            background: transparent;
        }

        .transactions-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: #f8fafc;
            white-space: nowrap;
        }

        .transactions-table tbody tr:hover td {
            background: #fbfdff;
        }

        .transactions-date-cell strong,
        .transactions-payment-mode,
        .transactions-amount-cell {
            display: block;
            font-weight: 800;
        }

        .transactions-date-cell span {
            display: block;
            margin-top: 3px;
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .transactions-type-pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid var(--tx-line);
            border-radius: 999px;
            background: #fff;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
        }

        .transactions-flow-badge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.02em;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .transactions-flow-badge.is-in {
            border-color: #a7f3d0;
            background: var(--tx-green-soft);
            color: var(--tx-green);
        }

        .transactions-flow-badge.is-out {
            border-color: #fecdd3;
            background: var(--tx-red-soft);
            color: var(--tx-red);
        }

        .transactions-muted {
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .transactions-notes-cell {
            max-width: 280px;
            color: #475569;
        }

        .transactions-empty-table,
        .transactions-empty {
            padding: 14px;
            color: var(--tx-muted);
            font-size: 13px;
            font-weight: 600;
        }

        .transactions-mobile-list {
            display: none;
            padding: 12px 14px 14px;
            gap: 10px;
            flex-direction: column;
        }

        .transactions-mobile-card {
            border: 1px solid #e1e8f0;
            border-radius: 16px;
            background: #fff;
            padding: 12px;
        }

        .transactions-mobile-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .transactions-mobile-title {
            color: var(--tx-ink);
            font-size: 14px;
            font-weight: 900;
            line-height: 1.25;
        }

        .transactions-mobile-meta {
            margin-top: 4px;
            color: var(--tx-muted);
            font-size: 11px;
            font-weight: 700;
        }

        .transactions-mobile-amount {
            flex: 0 0 auto;
            font-size: 18px;
            font-weight: 950;
            letter-spacing: -0.03em;
            text-align: right;
        }

        .transactions-mobile-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .transactions-mobile-chip {
            display: inline-flex;
            align-items: center;
            min-height: 26px;
            padding: 0 9px;
            border: 1px solid #dde5ef;
            border-radius: 999px;
            background: #fff;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
        }

        .transactions-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .transactions-mobile-grid > div {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .transactions-mobile-grid span {
            color: var(--tx-muted);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .transactions-mobile-grid strong {
            color: var(--tx-ink);
            font-size: 12px;
            font-weight: 800;
            line-height: 1.4;
            word-break: break-word;
        }

        .transactions-mobile-grid-full {
            grid-column: 1 / -1;
        }

        .transactions-pagination {
            padding: 0 16px 16px;
        }

        @media (max-width: 1360px) {
            .transactions-filter-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 1180px) {
            .transactions-filter-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .transactions-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            body.transactions-mobile-filter-lock {
                overflow: hidden;
            }

            .content-header.transactions-report-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 8px;
            }

            .content-header.transactions-report-header > :nth-child(n+3) {
                flex: 0 0 auto;
            }

            .content-header.transactions-report-header .page-actions {
                width: auto;
                margin-left: auto;
                justify-content: flex-end;
                gap: 6px;
            }

            .content-header.transactions-report-header .header-badge {
                white-space: nowrap;
            }

            .transactions-page-subtitle {
                display: none;
            }

            .transactions-report-page {
                gap: 12px;
            }

            .transactions-hero-card,
            .transactions-filter-card,
            .transactions-kpi-card,
            .transactions-surface-card {
                border-radius: 18px;
            }

            .transactions-filter-card {
                padding: 14px;
            }

            .transactions-mobile-filter-trigger {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: end;
                gap: 8px;
                margin-bottom: 10px;
            }

            .transactions-search-field-mobile {
                display: grid;
            }

            .transactions-mobile-filter-open {
                min-height: 40px;
                padding-inline: 12px;
            }

            .transactions-mobile-filter-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.44);
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease;
                z-index: 69;
            }

            .transactions-mobile-filter-panel {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                max-height: 88vh;
                overflow-y: auto;
                padding: 14px;
                border-radius: 18px 18px 0 0;
                border: 1px solid var(--tx-line);
                border-bottom: 0;
                background: rgba(255, 255, 255, 0.98);
                box-shadow: 0 -18px 36px rgba(15, 23, 42, 0.2);
                transform: translateY(105%);
                transition: transform 0.22s ease;
                z-index: 70;
            }

            .transactions-filter-card.is-mobile-filter-open .transactions-mobile-filter-backdrop {
                opacity: 1;
                pointer-events: auto;
            }

            .transactions-filter-card.is-mobile-filter-open .transactions-mobile-filter-panel {
                transform: translateY(0);
            }

            .transactions-filter-head {
                position: sticky;
                top: 0;
                z-index: 3;
                padding: 2px 0 10px;
                background: rgba(255, 255, 255, 0.98);
                border-bottom: 1px solid rgba(151, 164, 184, 0.24);
                margin-bottom: 10px;
            }

            .transactions-mobile-filter-close {
                display: inline-flex;
                min-height: 34px;
                padding: 0 10px;
                font-size: 12px;
            }

            .transactions-filter-head h3,
            .transactions-surface-head h3 {
                font-size: 16px;
            }

            .transactions-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .transactions-search-field {
                grid-column: span 2;
            }

            .transactions-search-field-desktop {
                display: none;
            }

            .transactions-report-page .field-label {
                font-size: 10px;
            }

            .transactions-report-page .field-input,
            .transactions-report-page .ui-filter-select-trigger {
                min-height: 40px;
                font-size: 13px;
            }

            .transactions-report-page .ui-filter-select-trigger-text {
                font-size: 13px;
            }

            .transactions-filter-actions {
                justify-content: stretch;
            }

            .transactions-filter-actions .btn {
                flex: 1 1 0;
                justify-content: center;
            }

            .transactions-kpi-grid {
                gap: 8px;
            }

            .transactions-kpi-card {
                padding: 12px;
                min-width: 0;
            }

            .transactions-kpi-top {
                gap: 8px;
                margin-bottom: 10px;
            }

            .transactions-kpi-icon {
                width: 34px;
                height: 34px;
                border-radius: 11px;
            }

            .transactions-kpi-icon svg {
                width: 15px;
                height: 15px;
            }

            .transactions-kpi-tag {
                min-height: 24px;
                padding: 0 8px;
                font-size: 10px;
            }

            .transactions-kpi-label {
                font-size: 10px;
                letter-spacing: 0.06em;
            }

            .transactions-kpi-value {
                margin-top: 6px;
                font-size: clamp(18px, 4.5vw, 22px);
                line-height: 1.08;
            }

            .transactions-kpi-note {
                display: none;
            }

            .transactions-breakdown-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .transactions-surface-head {
                padding: 12px 14px;
            }

            .transactions-surface-body {
                padding: 8px 14px 12px;
            }

            .transactions-table-wrap {
                display: none;
            }

            .transactions-mobile-list {
                display: flex;
            }

            .transactions-pagination {
                padding-top: 0;
            }
        }

        @media (max-width: 520px) {
            .transactions-filter-grid {
                grid-template-columns: 1fr;
            }

            .transactions-search-field {
                grid-column: span 1;
            }

            .transactions-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .transactions-kpi-value {
                font-size: clamp(16px, 4.9vw, 19px);
            }

            .transactions-mobile-grid {
                grid-template-columns: 1fr;
            }

            .transactions-mobile-grid-full {
                grid-column: auto;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const filterForm = document.querySelector('.transactions-filter-card');
            if (!filterForm) return;

            const mobileSearch = filterForm.querySelector('[data-search-mobile]');
            const desktopSearch = filterForm.querySelector('[data-search-desktop]');
            const openButton = filterForm.querySelector('[data-mobile-filter-open]');
            const closeButton = filterForm.querySelector('[data-mobile-filter-close]');
            const backdrop = filterForm.querySelector('[data-mobile-filter-backdrop]');
            const mobilePanel = filterForm.querySelector('[data-mobile-filter-panel]');
            const mobileBreakpoint = window.matchMedia('(max-width: 768px)');

            const syncDesktopFromMobile = () => {
                if (!mobileSearch || !desktopSearch) return;
                desktopSearch.value = mobileSearch.value;
            };

            const syncMobileFromDesktop = () => {
                if (!mobileSearch || !desktopSearch) return;
                mobileSearch.value = desktopSearch.value;
            };

            const setOpenState = (isOpen) => {
                filterForm.classList.toggle('is-mobile-filter-open', isOpen);
                document.body.classList.toggle('transactions-mobile-filter-lock', isOpen);
                if (openButton) openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (mobilePanel) mobilePanel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            };

            const openPanel = () => {
                if (!mobileBreakpoint.matches) return;
                syncDesktopFromMobile();
                setOpenState(true);
            };

            const closePanel = () => {
                setOpenState(false);
            };

            syncMobileFromDesktop();
            setOpenState(false);

            if (mobileSearch) {
                mobileSearch.addEventListener('input', syncDesktopFromMobile);
            }

            if (desktopSearch) {
                desktopSearch.addEventListener('input', syncMobileFromDesktop);
            }

            if (openButton) {
                openButton.addEventListener('click', openPanel);
            }

            if (closeButton) {
                closeButton.addEventListener('click', closePanel);
            }

            if (backdrop) {
                backdrop.addEventListener('click', closePanel);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closePanel();
                }
            });

            window.addEventListener('resize', function () {
                if (!mobileBreakpoint.matches) {
                    closePanel();
                }
            });

            filterForm.addEventListener('submit', function () {
                syncDesktopFromMobile();
                closePanel();
            });
        });
    </script>
</x-app-layout>
