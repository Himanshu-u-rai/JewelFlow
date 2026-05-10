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
        $view = $view ?? 'transactions';
        if ($view === 'transactions') {
            $totalTransactions = $rows->count();
            $totalGross = (float) $rows->sum('metal_gross_weight');
            $totalFine  = (float) $rows->sum('metal_fine_weight');
            $totalValue = (float) $rows->sum('amount');
        }
    @endphp

    <x-page-header class="mxr-page-header-mobile-subtitle-hide" title="Metal Exchange Report" subtitle="Old gold and old silver received as payment through POS invoices" />

    <div class="content-inner metal-exchange-report-page">
        <div class="mxr-stack">

            {{-- Tab switcher --}}
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="{{ route('report.metal-exchange', ['view' => 'transactions', 'from' => $from, 'to' => $to]) }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;border:1px solid {{ $view === 'transactions' ? '#0f172a' : '#dbe3ee' }};background:{{ $view === 'transactions' ? '#0f172a' : '#fff' }};color:{{ $view === 'transactions' ? '#fff' : '#475569' }};">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    Transactions
                </a>
                <a href="{{ route('report.metal-exchange', ['view' => 'lots', 'from' => $from, 'to' => $to]) }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;border:1px solid {{ $view === 'lots' ? '#0f172a' : '#dbe3ee' }};background:{{ $view === 'lots' ? '#0f172a' : '#fff' }};color:{{ $view === 'lots' ? '#fff' : '#475569' }};">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                    Weekly Lots
                </a>
            </div>

            <section class="mxr-card mxr-filter-card">
                <div class="mxr-filter-head">
                    <div>
                        <p class="mxr-kicker">Report Window</p>
                        <h2 class="mxr-title">{{ $view === 'lots' ? 'Weekly lot batches' : 'Track old metal received' }}</h2>
                    </div>
                    <span class="mxr-range-badge">{{ $dateRangeShortLabel }}</span>
                </div>

                <form method="GET" action="{{ route('report.metal-exchange') }}" class="mxr-filter-form">
                    <input type="hidden" name="view" value="{{ $view }}">
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

            @if($view === 'lots')
            {{-- ===== WEEKLY LOTS VIEW ===== --}}
            <section class="mxr-card" style="padding:20px 20px 8px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;">
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:950;color:#0f172a;letter-spacing:-0.02em;">Weekly Lot Batches</h2>
                        <p style="margin:2px 0 0;font-size:12px;color:#64748b;font-weight:600;">Old gold and silver grouped by ISO calendar week (Mon–Sun). Each lot tracks the aggregate fine weight until dispatched.</p>
                    </div>
                    <span style="font-size:11px;font-weight:800;color:#475569;white-space:nowrap;">{{ $weeklyLots->total() }} lot{{ $weeklyLots->total() == 1 ? '' : 's' }}</span>
                </div>

                @if($weeklyLots->isEmpty())
                    <div class="mxr-empty" style="padding:32px 0 24px;">
                        <p>No weekly lots found. Old gold/silver received through POS will appear here grouped by week.</p>
                    </div>
                @else
                <div x-data="{ expanded: null }">
                    @foreach($weeklyLots as $lot)
                    @php
                        $isGoldLot = $lot->source === 'old_gold_weekly';
                        $lotLabel  = 'Week ' . $lot->iso_week . ' / ' . $lot->iso_year;
                        $remaining = (float) $lot->fine_weight_remaining;
                        $total     = (float) $lot->fine_weight_total;
                        $usedPct   = $total > 0 ? min(100, round(($total - $remaining) / $total * 100)) : 0;
                    @endphp
                    <div style="border:1px solid #e2e8f0;border-radius:14px;margin-bottom:12px;overflow:hidden;">
                        {{-- Lot header row --}}
                        <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;background:#fafbfc;"
                             @click="expanded = (expanded === {{ $lot->id }}) ? null : {{ $lot->id }}">
                            <span style="flex:0 0 auto;display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:10px;{{ $isGoldLot ? 'background:#fffbeb;color:#b45309;border:1px solid rgba(217,119,6,.16)' : 'background:#f8fafc;color:#475569;border:1px solid rgba(100,116,139,.16)' }}">
                                @if($isGoldLot)
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="12" cy="12" r="10"/></svg>
                                @endif
                            </span>
                            <div style="flex:1 1 0;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-size:13px;font-weight:900;color:#0f172a;">{{ $lotLabel }}</span>
                                    <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;{{ $isGoldLot ? 'background:#fef3c7;color:#92400e' : 'background:#f1f5f9;color:#475569' }}">
                                        {{ $isGoldLot ? 'Gold' : 'Silver' }}
                                    </span>
                                    @if($lot->is_dispatched)
                                        <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;">
                                            Dispatched {{ $lot->dispatched_at?->format('d M Y') }}
                                        </span>
                                    @else
                                        <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:#fef9c3;color:#854d0e;">Open</span>
                                    @endif
                                </div>
                                <div style="display:flex;gap:16px;margin-top:4px;flex-wrap:wrap;">
                                    <span style="font-size:11px;color:#64748b;font-weight:700;">Total: <strong style="color:#0f172a;">{{ number_format($total, 3) }}g fine</strong></span>
                                    <span style="font-size:11px;color:#64748b;font-weight:700;">Remaining: <strong style="color:{{ $remaining > 0 ? '#0f172a' : '#94a3b8' }};">{{ number_format($remaining, 3) }}g</strong></span>
                                    <span style="font-size:11px;color:#64748b;font-weight:700;">Avg: <strong style="color:#0f172a;">₹{{ number_format($lot->cost_per_fine_gram, 0) }}/g</strong></span>
                                    <span style="font-size:11px;color:#64748b;font-weight:700;">Value: <strong style="color:#0f172a;">₹{{ number_format($total * (float)$lot->cost_per_fine_gram, 0) }}</strong></span>
                                    <span style="font-size:11px;color:#64748b;font-weight:700;">{{ $lot->payments->count() }} transaction{{ $lot->payments->count() == 1 ? '' : 's' }}</span>
                                </div>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                 style="flex:0 0 auto;transition:transform .2s;" :style="expanded === {{ $lot->id }} ? 'transform:rotate(180deg)' : ''">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </div>

                        {{-- Expanded: transactions + dispatch --}}
                        <div x-show="expanded === {{ $lot->id }}" x-cloak style="border-top:1px solid #e2e8f0;">
                            {{-- Dispatch form (shown only if not yet dispatched) --}}
                            @if(!$lot->is_dispatched)
                            <div x-data="{ showDispatch: false }" style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                                <div x-show="!showDispatch">
                                    <button @click="showDispatch = true"
                                            style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:9px;font-size:12px;font-weight:800;border:1px solid #0f172a;background:#0f172a;color:#fff;cursor:pointer;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                        Mark as Dispatched
                                    </button>
                                </div>
                                <div x-show="showDispatch" x-cloak>
                                    <form method="POST" action="{{ route('old-metal-lots.dispatch', $lot) }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                                        @csrf
                                        <div style="flex:1 1 240px;">
                                            <label style="display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px;">Dispatch notes (required)</label>
                                            <textarea name="dispatch_notes" rows="2" required minlength="4" maxlength="500"
                                                      placeholder="e.g. Sent to Mehul Refinery — 42.5g fine gold"
                                                      style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;font-size:12px;resize:vertical;"></textarea>
                                        </div>
                                        <div style="display:flex;gap:6px;">
                                            <button type="submit" style="padding:8px 14px;border-radius:9px;font-size:12px;font-weight:800;border:1px solid #16a34a;background:#16a34a;color:#fff;cursor:pointer;">Confirm</button>
                                            <button type="button" @click="showDispatch = false" style="padding:8px 14px;border-radius:9px;font-size:12px;font-weight:800;border:1px solid #dbe3ee;background:#fff;color:#475569;cursor:pointer;">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @else
                            <div style="padding:10px 16px;background:#f0fdf4;border-bottom:1px solid #e2e8f0;">
                                <p style="margin:0;font-size:12px;color:#166534;font-weight:700;">
                                    <strong>Dispatched {{ $lot->dispatched_at?->format('d M Y') }}:</strong> {{ $lot->dispatch_notes }}
                                </p>
                            </div>
                            @endif

                            {{-- Transaction drill-down --}}
                            @if($lot->payments->isNotEmpty())
                            <div style="overflow-x:auto;">
                                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                    <thead>
                                        <tr style="background:#f8fafc;">
                                            <th style="padding:8px 12px;text-align:left;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Date</th>
                                            <th style="padding:8px 12px;text-align:left;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Invoice</th>
                                            <th style="padding:8px 12px;text-align:left;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Customer</th>
                                            <th style="padding:8px 12px;text-align:right;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Gross (g)</th>
                                            <th style="padding:8px 12px;text-align:right;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Purity</th>
                                            <th style="padding:8px 12px;text-align:right;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Fine (g)</th>
                                            <th style="padding:8px 12px;text-align:right;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Rate ₹/g</th>
                                            <th style="padding:8px 12px;text-align:right;font-weight:800;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($lot->payments as $pmt)
                                        @php $pmtCustomer = $pmt->invoice?->customer; @endphp
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:8px 12px;color:#475569;white-space:nowrap;">{{ $pmt->created_at?->format('d M Y') ?? '—' }}</td>
                                            <td style="padding:8px 12px;">
                                                @if($pmt->invoice)
                                                    <a href="{{ route('invoices.show', $pmt->invoice) }}" class="mxr-invoice-link">{{ $pmt->invoice->invoice_number }}</a>
                                                @else
                                                    <span style="color:#94a3b8;">—</span>
                                                @endif
                                            </td>
                                            <td style="padding:8px 12px;color:#0f172a;font-weight:700;">{{ $pmtCustomer?->name ?? '—' }}</td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;">{{ number_format($pmt->metal_gross_weight, 3) }}</td>
                                            <td style="padding:8px 12px;text-align:right;">{{ $pmt->metal_purity }}{{ $pmt->mode === 'old_gold' ? 'K' : '‰' }}</td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;font-weight:800;{{ $isGoldLot ? 'color:#b45309' : 'color:#475569' }}">{{ number_format($pmt->metal_fine_weight, 3) }}</td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;">₹{{ number_format($pmt->metal_rate_per_gram, 0) }}</td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;font-weight:800;">₹{{ number_format($pmt->amount, 0) }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr style="background:#f8fafc;">
                                            <td colspan="3" style="padding:8px 12px;font-weight:800;color:#475569;">Totals</td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;font-weight:800;">{{ number_format($lot->payments->sum('metal_gross_weight'), 3) }}</td>
                                            <td></td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;font-weight:800;{{ $isGoldLot ? 'color:#b45309' : 'color:#475569' }}">{{ number_format($lot->payments->sum('metal_fine_weight'), 3) }}</td>
                                            <td></td>
                                            <td style="padding:8px 12px;text-align:right;font-family:ui-monospace,monospace;font-weight:800;">₹{{ number_format($lot->payments->sum('amount'), 0) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            @else
                            <div style="padding:16px;color:#94a3b8;font-size:12px;font-weight:700;text-align:center;">No transactions linked to this lot.</div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                {{ $weeklyLots->appends(['view' => 'lots', 'from' => $from, 'to' => $to])->links() }}
                @endif
            </section>
            @endif

            @if($view === 'transactions')

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
            @endif {{-- end transactions view --}}

        </div>
    </div>
</x-app-layout>
