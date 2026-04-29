<x-app-layout>
    <style>
        .mxr-page-header-mobile-subtitle-hide .page-subtitle {
            display: block;
        }

        .metal-exchange-report-page {
            --mxr-ink: #0f172a;
            --mxr-muted: #64748b;
            --mxr-line: #dbe3ee;
            --mxr-surface: rgba(255, 255, 255, 0.96);
            --mxr-soft: #f8fafc;
            --mxr-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            position: relative;
        }

        .metal-exchange-report-page::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 0% 0%, rgba(15, 118, 110, 0.05), transparent 24%),
                radial-gradient(circle at 100% 8%, rgba(217, 119, 6, 0.05), transparent 22%);
        }

        .mxr-stack {
            display: grid;
            gap: 16px;
        }

        .mxr-card {
            border: 1px solid var(--mxr-line);
            border-radius: 22px;
            background: var(--mxr-surface);
            box-shadow: var(--mxr-shadow);
        }

        .mxr-filter-card {
            padding: 14px 16px;
        }

        .mxr-filter-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .mxr-kicker {
            margin: 0 0 3px;
            color: #0f766e;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .mxr-title {
            margin: 0;
            color: var(--mxr-ink);
            font-size: 17px;
            font-weight: 950;
            letter-spacing: -0.03em;
        }

        .mxr-copy {
            margin: 2px 0 0;
            color: var(--mxr-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .mxr-range-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 24px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            padding: 3px 8px;
            color: #475569;
            font-size: 10px;
            font-weight: 900;
            white-space: nowrap;
        }

        .mxr-filter-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
            gap: 8px;
            align-items: center;
        }

        .mxr-filter-field {
            min-width: 0;
        }

        .mxr-filter-input,
        .mxr-filter-button {
            min-height: 40px;
            border-radius: 11px;
            font-size: 13px;
        }

        .mxr-filter-input {
            width: 100%;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: var(--mxr-ink);
            padding-inline: 12px;
        }

        .mxr-filter-input:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .mxr-filter-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid #0f172a;
            background: #0f172a;
            color: #ffffff;
            padding: 0 18px;
            font-weight: 900;
        }

        .mxr-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .mxr-summary-card {
            padding: 18px;
        }

        .mxr-summary-card--gold {
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 251, 235, 0.96)),
                radial-gradient(circle at 0% 0%, rgba(245, 158, 11, 0.08), transparent 36%);
        }

        .mxr-summary-card--silver {
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96)),
                radial-gradient(circle at 0% 0%, rgba(100, 116, 139, 0.08), transparent 36%);
        }

        .mxr-summary-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .mxr-summary-meta {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mxr-summary-icon {
            display: inline-flex;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            flex: 0 0 auto;
        }

        .mxr-summary-card--gold .mxr-summary-icon {
            border: 1px solid rgba(217, 119, 6, 0.16);
            background: #fffbeb;
            color: #b45309;
        }

        .mxr-summary-card--silver .mxr-summary-icon {
            border: 1px solid rgba(100, 116, 139, 0.16);
            background: #f8fafc;
            color: #475569;
        }

        .mxr-summary-title {
            margin: 0;
            color: var(--mxr-ink);
            font-size: 15px;
            font-weight: 950;
            letter-spacing: -0.02em;
        }

        .mxr-summary-subtitle {
            margin: 4px 0 0;
            color: var(--mxr-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .mxr-summary-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: rgba(255, 255, 255, 0.92);
            padding: 6px 10px;
            color: #475569;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .mxr-summary-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .mxr-metric {
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.82);
            padding: 12px;
        }

        .mxr-metric span {
            display: block;
            color: var(--mxr-muted);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .mxr-metric strong {
            display: block;
            margin-top: 5px;
            color: var(--mxr-ink);
            font-size: 18px;
            font-weight: 950;
            letter-spacing: -0.04em;
            line-height: 1.1;
        }

        .mxr-metric small {
            margin-left: 3px;
            color: var(--mxr-muted);
            font-size: 0.62em;
            font-weight: 800;
        }

        .mxr-register-card {
            overflow: hidden;
        }

        .mxr-register-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 18px;
            border-bottom: 1px solid #e2e8f0;
            background:
                linear-gradient(135deg, #ffffff, #f8fafc),
                radial-gradient(circle at 100% 0%, rgba(15, 118, 110, 0.05), transparent 34%);
        }

        .mxr-register-head h2 {
            margin: 0;
            color: var(--mxr-ink);
            font-size: 16px;
            font-weight: 950;
            letter-spacing: -0.02em;
        }

        .mxr-register-head p {
            margin: 4px 0 0;
            color: var(--mxr-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .mxr-register-totals {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .mxr-register-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            padding: 6px 10px;
            color: #475569;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .mxr-empty {
            padding: 56px 18px;
            text-align: center;
            color: #94a3b8;
        }

        .mxr-empty p {
            margin: 0;
            color: var(--mxr-muted);
            font-size: 14px;
            font-weight: 700;
        }

        .mxr-table-shell {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .mxr-table {
            width: 100%;
            min-width: 980px;
            border-collapse: separate;
            border-spacing: 0;
            color: #334155;
            font-size: 13px;
        }

        .mxr-table thead {
            background: #f8fafc;
        }

        .mxr-table th {
            padding: 12px 14px;
            color: var(--mxr-muted);
            font-size: 10px;
            font-weight: 950;
            letter-spacing: 0.08em;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .mxr-table td {
            border-top: 1px solid #edf2f7;
            padding: 13px 14px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .mxr-table tbody tr:hover {
            background: #f8fafc;
        }

        .mxr-table tfoot {
            background: #f8fafc;
            color: var(--mxr-ink);
            font-weight: 900;
        }

        .mxr-table tfoot td {
            border-top: 1px solid #dbe3ee;
        }

        .mxr-right {
            text-align: right;
        }

        .mxr-center {
            text-align: center;
        }

        .mxr-tabular {
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;
        }

        .mxr-date-cell strong,
        .mxr-customer-cell strong {
            display: block;
            color: #1e293b;
            font-weight: 850;
        }

        .mxr-date-cell span,
        .mxr-customer-cell span {
            display: block;
            margin-top: 2px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
        }

        .mxr-invoice-link {
            color: #0f766e;
            font-weight: 900;
            text-decoration: none;
        }

        .mxr-invoice-link:hover {
            text-decoration: underline;
        }

        .mxr-type-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 900;
            line-height: 1;
        }

        .mxr-type-pill--gold {
            background: #fffbeb;
            color: #b45309;
        }

        .mxr-type-pill--silver {
            background: #f1f5f9;
            color: #475569;
        }

        .mxr-fine.is-gold {
            color: #b45309;
            font-weight: 950;
        }

        .mxr-fine.is-silver {
            color: #475569;
            font-weight: 950;
        }

        .mxr-value {
            color: var(--mxr-ink);
            font-weight: 950;
        }

        .mxr-mobile-list {
            display: none;
        }

        .mxr-mobile-card {
            border-top: 1px solid #edf2f7;
            padding: 15px 16px;
        }

        .mxr-mobile-card:first-child {
            border-top: 0;
        }

        .mxr-mobile-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .mxr-mobile-card-meta {
            margin-top: 4px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
        }

        .mxr-mobile-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .mxr-mobile-label {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .mxr-mobile-value {
            margin-top: 4px;
            color: var(--mxr-ink);
            font-size: 13px;
            font-weight: 800;
        }

        @media (max-width: 980px) {
            .mxr-summary-grid {
                grid-template-columns: 1fr;
            }

            .mxr-summary-metrics {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .mxr-page-header-mobile-subtitle-hide .page-subtitle {
                display: none;
            }

            .mxr-filter-card {
                padding: 14px;
            }

            .mxr-filter-head {
                align-items: flex-start;
            }

            .mxr-filter-head > div {
                min-width: 0;
                flex: 1 1 auto;
            }

            .mxr-range-badge {
                min-height: 22px;
                padding: 2px 7px;
                font-size: 9px;
            }

            .mxr-register-head {
                flex-direction: column;
                align-items: stretch;
            }

            .mxr-filter-form {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
                gap: 7px;
            }

            .mxr-filter-input,
            .mxr-filter-button {
                min-height: 36px;
                border-radius: 10px;
                font-size: 12px;
            }

            .mxr-filter-input {
                padding-inline: 8px;
            }

            .mxr-filter-button {
                min-width: 78px;
                padding: 0 12px;
            }

            .mxr-summary-grid {
                gap: 12px;
            }

            .mxr-summary-card {
                padding: 14px;
            }

            .mxr-summary-head {
                margin-bottom: 12px;
            }

            .mxr-summary-metrics {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .mxr-metric {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 10px 11px;
            }

            .mxr-metric strong {
                margin-top: 0;
                text-align: right;
                font-size: 17px;
            }

            .mxr-register-totals {
                justify-content: flex-start;
            }

            .mxr-table-shell {
                display: none;
            }

            .mxr-mobile-list {
                display: block;
            }

            .mxr-mobile-card-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 420px) {
            .mxr-filter-card {
                padding: 12px;
            }

            .mxr-filter-input,
            .mxr-filter-button {
                min-height: 34px;
                font-size: 12px;
            }

            .mxr-filter-button {
                min-width: 74px;
                padding: 0 10px;
            }

            .mxr-mobile-card-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $dateRangeLabel = \Carbon\Carbon::parse($from)->format('d M Y') . ' — ' . \Carbon\Carbon::parse($to)->format('d M Y');
        $dateRangeShortLabel = \Carbon\Carbon::parse($from)->format('d M') . ' — ' . \Carbon\Carbon::parse($to)->format('d M');
        $totalTransactions = $rows->count();
        $totalGross = (float) $rows->sum('metal_gross_weight');
        $totalFine = (float) $rows->sum('metal_fine_weight');
        $totalValue = (float) $rows->sum('amount');
    @endphp

    <x-page-header class="mxr-page-header-mobile-subtitle-hide" title="Metal Exchange Report" subtitle="Old gold and old silver received as payment through POS invoices" />

    <div class="content-inner metal-exchange-report-page">
        <div class="mxr-stack">
            <section class="mxr-card mxr-filter-card">
                <div class="mxr-filter-head">
                    <div>
                        <p class="mxr-kicker">Report Window</p>
                        <h2 class="mxr-title">Track old metal received</h2>
                    </div>
                    <span class="mxr-range-badge">{{ $dateRangeShortLabel }}</span>
                </div>

                <form method="GET" action="{{ route('report.metal-exchange') }}" class="mxr-filter-form">
                    <div class="mxr-filter-field">
                        <input id="mxr-from" type="date" name="from" value="{{ $from }}" class="mxr-filter-input" aria-label="From date">
                    </div>
                    <div class="mxr-filter-field">
                        <input id="mxr-to" type="date" name="to" value="{{ $to }}" class="mxr-filter-input" aria-label="To date">
                    </div>
                    <button type="submit" class="mxr-filter-button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Filter
                    </button>
                </form>
            </section>

            <section class="mxr-summary-grid">
                <article class="mxr-card mxr-summary-card mxr-summary-card--gold">
                    <div class="mxr-summary-head">
                        <div class="mxr-summary-meta">
                            <div class="mxr-summary-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </div>
                            <div>
                                <h3 class="mxr-summary-title">Old Gold Received</h3>
                                <p class="mxr-summary-subtitle">Recovered through POS exchange entries.</p>
                            </div>
                        </div>
                        <span class="mxr-summary-chip">{{ $goldSummary['count'] }} transaction{{ $goldSummary['count'] == 1 ? '' : 's' }}</span>
                    </div>

                    <div class="mxr-summary-metrics">
                        <div class="mxr-metric">
                            <span>Gross Weight</span>
                            <strong>{{ number_format($goldSummary['gross'], 3) }}<small>g</small></strong>
                        </div>
                        <div class="mxr-metric">
                            <span>Fine Weight</span>
                            <strong>{{ number_format($goldSummary['fine'], 3) }}<small>g</small></strong>
                        </div>
                        <div class="mxr-metric">
                            <span>Total Value</span>
                            <strong>₹{{ number_format($goldSummary['value'], 2) }}</strong>
                        </div>
                    </div>
                </article>

                <article class="mxr-card mxr-summary-card mxr-summary-card--silver">
                    <div class="mxr-summary-head">
                        <div class="mxr-summary-meta">
                            <div class="mxr-summary-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            </div>
                            <div>
                                <h3 class="mxr-summary-title">Old Silver Received</h3>
                                <p class="mxr-summary-subtitle">Recovered through POS exchange entries.</p>
                            </div>
                        </div>
                        <span class="mxr-summary-chip">{{ $silverSummary['count'] }} transaction{{ $silverSummary['count'] == 1 ? '' : 's' }}</span>
                    </div>

                    <div class="mxr-summary-metrics">
                        <div class="mxr-metric">
                            <span>Gross Weight</span>
                            <strong>{{ number_format($silverSummary['gross'], 3) }}<small>g</small></strong>
                        </div>
                        <div class="mxr-metric">
                            <span>Fine Weight</span>
                            <strong>{{ number_format($silverSummary['fine'], 3) }}<small>g</small></strong>
                        </div>
                        <div class="mxr-metric">
                            <span>Total Value</span>
                            <strong>₹{{ number_format($silverSummary['value'], 2) }}</strong>
                        </div>
                    </div>
                </article>
            </section>

            <section class="mxr-card mxr-register-card">
                <div class="mxr-register-head">
                    <div>
                        <h2>Transaction Register</h2>
                        <p>Every old-metal payment entry recorded on invoices during the selected period.</p>
                    </div>
                    <div class="mxr-register-totals">
                        <span class="mxr-register-pill">{{ $totalTransactions }} entries</span>
                        <span class="mxr-register-pill">{{ number_format($totalGross, 3) }} g gross</span>
                        <span class="mxr-register-pill">{{ number_format($totalFine, 3) }} g fine</span>
                        <span class="mxr-register-pill">₹{{ number_format($totalValue, 2) }}</span>
                    </div>
                </div>

                @if($rows->isEmpty())
                    <div class="mxr-empty">
                        <p>No metal exchange transactions in this period.</p>
                    </div>
                @else
                    <div class="mxr-table-shell">
                        <table class="mxr-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th class="mxr-center">Type</th>
                                    <th class="mxr-right">Gross Wt (g)</th>
                                    <th class="mxr-right">Purity</th>
                                    <th class="mxr-right">Test Loss %</th>
                                    <th class="mxr-right">Fine Wt (g)</th>
                                    <th class="mxr-right">Rate / g</th>
                                    <th class="mxr-right">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    @php
                                        $isGold = $row->mode === 'old_gold';
                                        $customer = $row->invoice?->customer;
                                    @endphp
                                    <tr>
                                        <td class="mxr-date-cell">
                                            <strong>{{ $row->created_at->format('d M Y') }}</strong>
                                            <span>{{ $row->created_at->format('h:i A') }}</span>
                                        </td>
                                        <td>
                                            @if($row->invoice)
                                                <a href="{{ route('invoices.show', $row->invoice) }}" class="mxr-invoice-link">{{ $row->invoice->invoice_number }}</a>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="mxr-customer-cell">
                                            <strong>{{ $customer?->name ?? '—' }}</strong>
                                            @if($customer?->phone)
                                                <span>{{ $customer->phone }}</span>
                                            @endif
                                        </td>
                                        <td class="mxr-center">
                                            @if($isGold)
                                                <span class="mxr-type-pill mxr-type-pill--gold">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                    Gold
                                                </span>
                                            @else
                                                <span class="mxr-type-pill mxr-type-pill--silver">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="12" cy="12" r="10"/></svg>
                                                    Silver
                                                </span>
                                            @endif
                                        </td>
                                        <td class="mxr-right mxr-tabular">{{ number_format($row->metal_gross_weight, 3) }}</td>
                                        <td class="mxr-right">{{ $row->metal_purity }}{{ $isGold ? 'K' : '‰' }}</td>
                                        <td class="mxr-right">{{ $row->metal_test_loss ?? 0 }}%</td>
                                        <td class="mxr-right mxr-tabular mxr-fine {{ $isGold ? 'is-gold' : 'is-silver' }}">{{ number_format($row->metal_fine_weight, 3) }}</td>
                                        <td class="mxr-right mxr-tabular">{{ number_format($row->metal_rate_per_gram, 2) }}</td>
                                        <td class="mxr-right mxr-tabular mxr-value">₹{{ number_format($row->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="mxr-right">Totals</td>
                                    <td class="mxr-right mxr-tabular">{{ number_format($totalGross, 3) }} g</td>
                                    <td></td>
                                    <td></td>
                                    <td class="mxr-right mxr-tabular">{{ number_format($totalFine, 3) }} g</td>
                                    <td></td>
                                    <td class="mxr-right mxr-tabular">₹{{ number_format($totalValue, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="mxr-mobile-list">
                        @foreach($rows as $row)
                            @php
                                $isGold = $row->mode === 'old_gold';
                                $customer = $row->invoice?->customer;
                            @endphp
                            <article class="mxr-mobile-card">
                                <div class="mxr-mobile-card-head">
                                    <div>
                                        @if($row->invoice)
                                            <a href="{{ route('invoices.show', $row->invoice) }}" class="mxr-invoice-link">{{ $row->invoice->invoice_number }}</a>
                                        @else
                                            <span class="mxr-invoice-link">No invoice</span>
                                        @endif
                                        <div class="mxr-mobile-card-meta">{{ $row->created_at->format('d M Y · h:i A') }}</div>
                                    </div>
                                    @if($isGold)
                                        <span class="mxr-type-pill mxr-type-pill--gold">Gold</span>
                                    @else
                                        <span class="mxr-type-pill mxr-type-pill--silver">Silver</span>
                                    @endif
                                </div>

                                <div class="mxr-mobile-card-grid">
                                    <div>
                                        <div class="mxr-mobile-label">Customer</div>
                                        <div class="mxr-mobile-value">{{ $customer?->name ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Gross Weight</div>
                                        <div class="mxr-mobile-value mxr-tabular">{{ number_format($row->metal_gross_weight, 3) }} g</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Purity</div>
                                        <div class="mxr-mobile-value">{{ $row->metal_purity }}{{ $isGold ? 'K' : '‰' }}</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Test Loss</div>
                                        <div class="mxr-mobile-value">{{ $row->metal_test_loss ?? 0 }}%</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Fine Weight</div>
                                        <div class="mxr-mobile-value mxr-tabular {{ $isGold ? 'mxr-fine is-gold' : 'mxr-fine is-silver' }}">{{ number_format($row->metal_fine_weight, 3) }} g</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Rate / g</div>
                                        <div class="mxr-mobile-value mxr-tabular">₹{{ number_format($row->metal_rate_per_gram, 2) }}</div>
                                    </div>
                                    <div>
                                        <div class="mxr-mobile-label">Value</div>
                                        <div class="mxr-mobile-value mxr-tabular mxr-value">₹{{ number_format($row->amount, 2) }}</div>
                                    </div>
                                    @if($customer?->phone)
                                        <div>
                                            <div class="mxr-mobile-label">Phone</div>
                                            <div class="mxr-mobile-value">{{ $customer->phone }}</div>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
