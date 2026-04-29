<x-app-layout>
    <style>
        .ki-show-page {
            --ki-show-ink: #0f172a;
            --ki-show-muted: #64748b;
            --ki-show-border: #dbe3ee;
            --ki-show-surface: rgba(255, 255, 255, 0.96);
            --ki-show-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            position: relative;
        }

        .ki-show-page::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 0% 0%, rgba(15, 118, 110, 0.06), transparent 26%),
                radial-gradient(circle at 100% 8%, rgba(217, 119, 6, 0.06), transparent 24%);
        }

        .ki-show-stack {
            display: grid;
            gap: 18px;
        }

        .ki-show-alert,
        .ki-show-panel {
            border: 1px solid var(--ki-show-border);
            border-radius: 24px;
            background: var(--ki-show-surface);
            box-shadow: var(--ki-show-shadow);
        }

        .ki-show-alert {
            border-color: #fecdd3;
            background:
                linear-gradient(135deg, rgba(255, 241, 242, 0.98), rgba(255, 255, 255, 0.96)),
                radial-gradient(circle at 100% 0%, rgba(244, 63, 94, 0.08), transparent 34%);
            padding: 16px 18px;
        }

        .ki-show-alert-title {
            margin: 0 0 8px;
            color: #9f1239;
            font-size: 14px;
            font-weight: 950;
        }

        .ki-show-flag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ki-show-flag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #ffe4e6;
            color: #9f1239;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 900;
            text-transform: capitalize;
        }

        .ki-show-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.8fr);
            gap: 18px;
            align-items: start;
        }

        .ki-show-main {
            padding: 18px;
        }

        .ki-show-main-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .ki-show-eyebrow {
            margin: 0 0 5px;
            color: #0f766e;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .ki-show-title {
            margin: 0;
            color: var(--ki-show-ink);
            font-size: 20px;
            font-weight: 950;
            letter-spacing: -0.03em;
        }

        .ki-show-subtitle {
            margin: 5px 0 0;
            color: var(--ki-show-muted);
            font-size: 13px;
            font-weight: 700;
        }

        .ki-show-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            border-radius: 999px;
            padding: 6px 11px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .ki-show-pill--status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .ki-show-pill--status-partial {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .ki-show-pill--status-unpaid {
            background: #fef3c7;
            color: #b45309;
        }

        .ki-show-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .ki-show-kpi {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            padding: 13px;
        }

        .ki-show-kpi span {
            display: block;
            color: var(--ki-show-muted);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .ki-show-kpi strong,
        .ki-show-kpi a {
            display: block;
            margin-top: 5px;
            color: var(--ki-show-ink);
            font-size: 15px;
            font-weight: 900;
            line-height: 1.2;
            text-decoration: none;
        }

        .ki-show-kpi a {
            color: #0f766e;
        }

        .ki-show-lines-shell {
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: #ffffff;
            overflow: hidden;
        }

        .ki-show-section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 15px 16px;
            border-bottom: 1px solid #e2e8f0;
            background:
                linear-gradient(135deg, #ffffff, #f8fafc),
                radial-gradient(circle at 100% 0%, rgba(15, 118, 110, 0.06), transparent 34%);
        }

        .ki-show-section-head h3 {
            margin: 0;
            color: var(--ki-show-ink);
            font-size: 15px;
            font-weight: 950;
        }

        .ki-show-section-copy {
            margin: 3px 0 0;
            color: var(--ki-show-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .ki-show-count {
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

        .ki-show-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .ki-show-lines-table,
        .ki-show-payments-table {
            width: 100%;
            min-width: 860px;
            border-collapse: separate;
            border-spacing: 0;
            color: #334155;
            font-size: 13px;
        }

        .ki-show-lines-table thead,
        .ki-show-payments-table thead {
            background: #f8fafc;
        }

        .ki-show-lines-table th,
        .ki-show-payments-table th {
            padding: 11px 14px;
            color: var(--ki-show-muted);
            font-size: 10px;
            font-weight: 950;
            letter-spacing: 0.08em;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ki-show-lines-table td,
        .ki-show-payments-table td {
            border-top: 1px solid #edf2f7;
            padding: 12px 14px;
            vertical-align: top;
        }

        .ki-show-text-right {
            text-align: right;
        }

        .ki-show-text-center {
            text-align: center;
        }

        .ki-show-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;
            font-variant-numeric: tabular-nums;
        }

        .ki-show-line-title {
            color: var(--ki-show-ink);
            font-weight: 800;
        }

        .ki-show-line-meta {
            margin-top: 3px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
        }

        .ki-show-lines-mobile,
        .ki-show-payments-mobile {
            display: none;
        }

        .ki-show-line-card,
        .ki-show-payment-card {
            border-top: 1px solid #edf2f7;
            padding: 14px 16px;
        }

        .ki-show-line-card:first-child,
        .ki-show-payment-card:first-child {
            border-top: 0;
        }

        .ki-show-line-card-head,
        .ki-show-payment-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .ki-show-line-card-grid,
        .ki-show-payment-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .ki-show-card-label {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .ki-show-card-value {
            margin-top: 4px;
            color: var(--ki-show-ink);
            font-size: 13px;
            font-weight: 800;
        }

        .ki-show-summary {
            padding: 18px;
        }

        .ki-show-summary-card {
            border: 1px solid rgba(245, 158, 11, 0.18);
            border-radius: 20px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(255, 251, 235, 0.96)),
                radial-gradient(circle at 100% 0%, rgba(245, 158, 11, 0.1), transparent 40%);
            padding: 16px;
            margin-bottom: 14px;
        }

        .ki-show-summary-card h3 {
            margin: 0;
            color: var(--ki-show-ink);
            font-size: 15px;
            font-weight: 950;
        }

        .ki-show-summary-list {
            margin-top: 12px;
            display: grid;
            gap: 10px;
        }

        .ki-show-summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: var(--ki-show-muted);
            font-size: 13px;
            font-weight: 700;
        }

        .ki-show-summary-row strong,
        .ki-show-summary-row span:last-child {
            color: var(--ki-show-ink);
        }

        .ki-show-summary-row--total {
            border-top: 1px solid rgba(148, 163, 184, 0.24);
            padding-top: 10px;
        }

        .ki-show-summary-row--total span:last-child {
            color: #b45309;
            font-weight: 950;
        }

        .ki-show-summary-row--due span:first-child,
        .ki-show-summary-row--due span:last-child {
            color: #be123c;
            font-weight: 900;
        }

        .ki-show-summary-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: #ffffff;
            padding: 0 12px;
            color: #0f766e;
            font-size: 12px;
            font-weight: 900;
            text-decoration: none;
        }

        .ki-show-summary-note {
            margin-top: 10px;
            color: var(--ki-show-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .ki-show-actions {
            display: grid;
            gap: 10px;
        }

        .ki-show-payments-panel,
        .ki-show-settlement-panel {
            overflow: visible;
        }

        .ki-show-empty {
            padding: 28px 16px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 700;
        }

        .ki-show-payment-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 11px;
            font-weight: 900;
            text-transform: capitalize;
        }

        .ki-show-payment-tag--paid {
            background: #dcfce7;
            color: #166534;
        }

        .ki-show-payment-tag--partial {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .ki-show-payment-tag--unpaid {
            background: #fef3c7;
            color: #b45309;
        }

        .ki-show-settlement-wrap {
            padding: 16px;
        }

        .ki-show-settlement-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .ki-show-settlement-head h3 {
            margin: 0;
            color: var(--ki-show-ink);
            font-size: 15px;
            font-weight: 950;
        }

        .ki-show-settlement-copy {
            margin: 4px 0 0;
            color: var(--ki-show-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .ki-show-due-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            border-radius: 999px;
            background: #fff1f2;
            color: #be123c;
            padding: 6px 11px;
            font-size: 11px;
            font-weight: 950;
            white-space: nowrap;
        }

        .ki-show-page .ki-payment-splits {
            overflow: visible;
        }

        .ki-show-page .ki-payment-splits .ui-filter-select-host,
        .ki-show-page .ki-payment-splits .ui-filter-select {
            width: 100%;
            min-width: 0;
        }

        .ki-show-page .ki-payment-splits .ui-filter-select-trigger {
            min-height: 38px;
            border-radius: 10px;
        }

        .ki-show-page .ki-payment-splits .ki-split-mode,
        .ki-show-page .ki-payment-splits .ki-split-account {
            min-width: 170px;
        }

        .ki-show-payment-row {
            overflow: visible;
        }

        .ki-show-payment-row input[type="number"],
        .ki-show-payment-row input[type="text"],
        .ki-show-payment-row input[type="date"] {
            width: 100%;
            min-height: 38px;
        }

        .ki-show-payment-remove {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 999px;
            color: #e11d48;
            font-size: 20px;
            font-weight: 900;
        }

        .ki-show-payment-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ki-show-payment-add {
            color: #0f766e;
            font-size: 12px;
            font-weight: 900;
            text-decoration: none;
        }

        .ki-show-payment-total {
            margin-left: auto;
            color: var(--ki-show-muted);
            font-size: 12px;
            font-weight: 700;
        }

        @media (max-width: 1180px) {
            .ki-show-layout {
                grid-template-columns: 1fr;
            }

            .ki-show-kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .ki-show-stack {
                gap: 14px;
            }

            .ki-show-alert,
            .ki-show-panel {
                border-radius: 18px;
            }

            .ki-show-main,
            .ki-show-summary,
            .ki-show-settlement-wrap {
                padding: 14px;
            }

            .ki-show-main-head,
            .ki-show-settlement-head {
                flex-direction: column;
                align-items: stretch;
            }

            .ki-show-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 12px;
            }

            .ki-show-kpi {
                border-radius: 14px;
                padding: 11px;
            }

            .ki-show-kpi strong,
            .ki-show-kpi a {
                font-size: 14px;
            }

            .ki-show-lines-table,
            .ki-show-payments-table {
                display: none;
            }

            .ki-show-lines-mobile,
            .ki-show-payments-mobile {
                display: block;
            }

            .ki-show-line-card-grid,
            .ki-show-payment-card-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .ki-show-page .ki-payment-row > div {
                width: 100%;
            }

            .ki-show-page .ki-payment-splits .ki-split-mode,
            .ki-show-page .ki-payment-splits .ki-split-account {
                min-width: 0;
                width: 100%;
            }

            .ki-show-payment-total {
                margin-left: 0;
                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .ki-show-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $lineCount = $invoice->lines->count();
        $modeLabel = str_replace('_', ' ', $invoice->mode);
        $jobOrderNumber = $invoice->jobOrder?->job_order_number;
        $paymentStatus = $invoice->payment_status ?? ($invoice->amount_due > 0 ? 'unpaid' : 'paid');
        $statusPillClass = match ($paymentStatus) {
            'paid' => 'ki-show-pill--status-paid',
            'partial' => 'ki-show-pill--status-partial',
            default => 'ki-show-pill--status-unpaid',
        };
        $paymentTagClass = match ($paymentStatus) {
            'paid' => 'ki-show-payment-tag--paid',
            'partial' => 'ki-show-payment-tag--partial',
            default => 'ki-show-payment-tag--unpaid',
        };
    @endphp

    <x-page-header :title="'Invoice ' . $invoice->karigar_invoice_number" :subtitle="$invoice->karigar?->name . ' · ' . $invoice->karigar_invoice_date->format('d M Y')">
        <x-slot:actions>
            <a href="{{ route('karigar-invoices.print', $invoice) }}" target="_blank" class="btn btn-secondary btn-sm">Print</a>
            <a href="{{ route('karigar-invoices.edit', $invoice) }}" class="btn btn-secondary btn-sm">Edit</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ki-show-page">
        <x-app-alerts class="mb-4" />

        <div class="ki-show-stack">
            @if(! empty($invoice->discrepancy_flags))
                <div class="ki-show-alert">
                    <p class="ki-show-alert-title">Discrepancies flagged</p>
                    <div class="ki-show-flag-list">
                        @foreach($invoice->discrepancy_flags as $flag)
                            <span class="ki-show-flag">{{ str_replace('_', ' ', $flag) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="ki-show-layout">
                <section class="ki-show-panel ki-show-main">
                    <div class="ki-show-main-head">
                        <div>
                            <p class="ki-show-eyebrow">Invoice Overview</p>
                            <h2 class="ki-show-title">{{ $invoice->karigar_invoice_number }}</h2>
                            <p class="ki-show-subtitle">{{ $invoice->karigar?->name ?? 'Karigar not set' }} · {{ $invoice->karigar_invoice_date->format('d M Y') }}</p>
                        </div>
                        <span class="ki-show-pill {{ $statusPillClass }}">{{ $paymentStatus }}</span>
                    </div>

                    <div class="ki-show-kpi-grid">
                        <div class="ki-show-kpi">
                            <span>Mode</span>
                            <strong>{{ $modeLabel }}</strong>
                        </div>
                        <div class="ki-show-kpi">
                            <span>Pieces</span>
                            <strong class="ki-show-mono">{{ $invoice->total_pieces }}</strong>
                        </div>
                        <div class="ki-show-kpi">
                            <span>Net Weight</span>
                            <strong class="ki-show-mono">{{ number_format($invoice->total_net_weight, 3) }}g</strong>
                        </div>
                        <div class="ki-show-kpi">
                            <span>Subtotal</span>
                            <strong class="ki-show-mono">₹{{ number_format($invoice->total_before_tax, 2) }}</strong>
                        </div>
                        <div class="ki-show-kpi">
                            <span>Job Order</span>
                            @if($invoice->jobOrder)
                                <a href="{{ route('job-orders.show', $invoice->jobOrder) }}" class="ki-show-mono">{{ $jobOrderNumber }}</a>
                            @else
                                <strong>Standalone</strong>
                            @endif
                        </div>
                    </div>

                    <div class="ki-show-lines-shell">
                        <div class="ki-show-section-head">
                            <div>
                                <h3>Line Items</h3>
                                <p class="ki-show-section-copy">Charges, metal value, and totals for each invoice line.</p>
                            </div>
                            <span class="ki-show-count">{{ $lineCount }} {{ \Illuminate\Support\Str::plural('line', $lineCount) }}</span>
                        </div>

                        <div class="ki-show-table-wrap">
                            <table class="ki-show-lines-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>HSN</th>
                                        <th class="ki-show-text-right">Pcs</th>
                                        <th class="ki-show-text-right">Net Wt</th>
                                        <th class="ki-show-text-right">Rate / g</th>
                                        <th class="ki-show-text-right">Metal</th>
                                        @if($invoice->isJobWorkMode())
                                            <th class="ki-show-text-right">Making</th>
                                            <th class="ki-show-text-right">Wastage</th>
                                        @endif
                                        <th class="ki-show-text-right">Extra</th>
                                        <th class="ki-show-text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoice->lines as $line)
                                        <tr>
                                            <td>
                                                <div class="ki-show-line-title">{{ $line->description }}</div>
                                            </td>
                                            <td class="ki-show-line-meta">{{ $line->hsn_code }}</td>
                                            <td class="ki-show-text-right ki-show-mono">{{ $line->pieces }}</td>
                                            <td class="ki-show-text-right ki-show-mono">{{ number_format($line->net_weight, 3) }}g</td>
                                            <td class="ki-show-text-right ki-show-mono">₹{{ number_format($line->rate_per_gram, 2) }}</td>
                                            <td class="ki-show-text-right ki-show-mono">₹{{ number_format($line->metal_amount, 2) }}</td>
                                            @if($invoice->isJobWorkMode())
                                                <td class="ki-show-text-right ki-show-mono">₹{{ number_format($line->making_charge, 2) }}</td>
                                                <td class="ki-show-text-right ki-show-mono">₹{{ number_format($line->wastage_charge, 2) }}</td>
                                            @endif
                                            <td class="ki-show-text-right ki-show-mono">₹{{ number_format($line->extra_amount, 2) }}</td>
                                            <td class="ki-show-text-right ki-show-mono"><strong>₹{{ number_format($line->line_total, 2) }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="ki-show-lines-mobile">
                            @foreach($invoice->lines as $line)
                                <article class="ki-show-line-card">
                                    <div class="ki-show-line-card-head">
                                        <div>
                                            <div class="ki-show-line-title">{{ $line->description }}</div>
                                            <div class="ki-show-line-meta">{{ $line->hsn_code }}</div>
                                        </div>
                                        <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->line_total, 2) }}</div>
                                    </div>

                                    <div class="ki-show-line-card-grid">
                                        <div>
                                            <div class="ki-show-card-label">Pieces</div>
                                            <div class="ki-show-card-value ki-show-mono">{{ $line->pieces }}</div>
                                        </div>
                                        <div>
                                            <div class="ki-show-card-label">Net Wt</div>
                                            <div class="ki-show-card-value ki-show-mono">{{ number_format($line->net_weight, 3) }}g</div>
                                        </div>
                                        <div>
                                            <div class="ki-show-card-label">Rate / g</div>
                                            <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->rate_per_gram, 2) }}</div>
                                        </div>
                                        <div>
                                            <div class="ki-show-card-label">Metal</div>
                                            <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->metal_amount, 2) }}</div>
                                        </div>
                                        @if($invoice->isJobWorkMode())
                                            <div>
                                                <div class="ki-show-card-label">Making</div>
                                                <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->making_charge, 2) }}</div>
                                            </div>
                                            <div>
                                                <div class="ki-show-card-label">Wastage</div>
                                                <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->wastage_charge, 2) }}</div>
                                            </div>
                                        @endif
                                        <div>
                                            <div class="ki-show-card-label">Extra</div>
                                            <div class="ki-show-card-value ki-show-mono">₹{{ number_format($line->extra_amount, 2) }}</div>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>

                <aside class="ki-show-panel ki-show-summary">
                    <div class="ki-show-summary-card">
                        <h3>Tax Summary</h3>
                        <div class="ki-show-summary-list">
                            <div class="ki-show-summary-row">
                                <span>Subtotal</span>
                                <span class="ki-show-mono">₹{{ number_format($invoice->total_before_tax, 2) }}</span>
                            </div>
                            @if($invoice->cgst_amount > 0)
                                <div class="ki-show-summary-row">
                                    <span>CGST @ {{ $invoice->cgst_rate }}%</span>
                                    <span class="ki-show-mono">₹{{ number_format($invoice->cgst_amount, 2) }}</span>
                                </div>
                            @endif
                            @if($invoice->sgst_amount > 0)
                                <div class="ki-show-summary-row">
                                    <span>SGST @ {{ $invoice->sgst_rate }}%</span>
                                    <span class="ki-show-mono">₹{{ number_format($invoice->sgst_amount, 2) }}</span>
                                </div>
                            @endif
                            @if($invoice->igst_amount > 0)
                                <div class="ki-show-summary-row">
                                    <span>IGST @ {{ $invoice->igst_rate }}%</span>
                                    <span class="ki-show-mono">₹{{ number_format($invoice->igst_amount, 2) }}</span>
                                </div>
                            @endif
                            <div class="ki-show-summary-row ki-show-summary-row--total">
                                <span>Grand Total</span>
                                <span class="ki-show-mono">₹{{ number_format($invoice->total_after_tax, 2) }}</span>
                            </div>
                            <div class="ki-show-summary-row">
                                <span>Paid</span>
                                <span class="ki-show-mono">₹{{ number_format($invoice->amount_paid, 2) }}</span>
                            </div>
                            <div class="ki-show-summary-row ki-show-summary-row--due">
                                <span>Due</span>
                                <span class="ki-show-mono">₹{{ number_format($invoice->amount_due, 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="ki-show-actions">
                        @if($invoice->invoice_file_path)
                            <a href="{{ asset('storage/' . $invoice->invoice_file_path) }}" target="_blank" class="ki-show-summary-link">View original PDF / image</a>
                        @endif
                        <p class="ki-show-summary-note">Use this panel to quickly verify outstanding value before recording payment splits below.</p>
                    </div>
                </aside>
            </div>

            <section class="ki-show-panel ki-show-payments-panel">
                <div class="ki-show-section-head">
                    <div>
                        <h3>Payments</h3>
                        <p class="ki-show-section-copy">History of received payments and settlement progress for this invoice.</p>
                    </div>
                    <span class="ki-show-payment-tag {{ $paymentTagClass }}">{{ $paymentStatus }}</span>
                </div>

                @if($invoice->payments->isEmpty())
                    <div class="ki-show-empty">No payments yet.</div>
                @else
                    <div class="ki-show-table-wrap">
                        <table class="ki-show-payments-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Mode</th>
                                    <th>Account</th>
                                    <th>Reference</th>
                                    <th class="ki-show-text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoice->payments as $pay)
                                    <tr>
                                        <td>{{ $pay->paid_on->format('d M Y') }}</td>
                                        <td class="ki-show-mono">{{ strtoupper($pay->mode) }}</td>
                                        <td>{{ $pay->paymentMethod?->name ?? '—' }}</td>
                                        <td class="ki-show-line-meta">{{ $pay->reference ?: '—' }}</td>
                                        <td class="ki-show-text-right ki-show-mono">₹{{ number_format($pay->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="ki-show-payments-mobile">
                        @foreach($invoice->payments as $pay)
                            <article class="ki-show-payment-card">
                                <div class="ki-show-payment-card-head">
                                    <div>
                                        <div class="ki-show-line-title">{{ strtoupper($pay->mode) }}</div>
                                        <div class="ki-show-line-meta">{{ $pay->paid_on->format('d M Y') }}</div>
                                    </div>
                                    <div class="ki-show-card-value ki-show-mono">₹{{ number_format($pay->amount, 2) }}</div>
                                </div>

                                <div class="ki-show-payment-card-grid">
                                    <div>
                                        <div class="ki-show-card-label">Account</div>
                                        <div class="ki-show-card-value">{{ $pay->paymentMethod?->name ?? '—' }}</div>
                                    </div>
                                    <div>
                                        <div class="ki-show-card-label">Reference</div>
                                        <div class="ki-show-card-value">{{ $pay->reference ?: '—' }}</div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            @if($invoice->amount_due > 0)
                <section class="ki-show-panel ki-show-settlement-panel">
                    <div class="ki-show-settlement-wrap"
                         x-data="{
                            splits: [{ amount: '{{ number_format($invoice->amount_due, 2, '.', '') }}', mode: 'cash', payment_method_id: '', reference: '', paid_on: '{{ now()->toDateString() }}' }],
                            get splitTotal() { return this.splits.reduce((s,p) => s + (parseFloat(p.amount)||0), 0); },
                            init() { this.refreshSplitSelects(); },
                            refreshSplitSelects() { this.$nextTick(() => window.initEnhancedFilterSelects?.()); },
                            addSplit() {
                                this.splits.push({ amount: '', mode: 'cash', payment_method_id: '', reference: '', paid_on: '{{ now()->toDateString() }}' });
                                this.refreshSplitSelects();
                            },
                            removeSplit(i) {
                                this.splits.splice(i, 1);
                                this.refreshSplitSelects();
                            }
                         }">
                        <div class="ki-show-settlement-head">
                            <div>
                                <h3>Record Payment</h3>
                                <p class="ki-show-settlement-copy">Split the payment across multiple modes if needed, without changing the underlying payment flow.</p>
                            </div>
                            <span class="ki-show-due-badge">Due ₹{{ number_format($invoice->amount_due, 2) }}</span>
                        </div>

                        <form method="POST" action="{{ route('karigar-invoices.pay', $invoice) }}">
                            @csrf
                            <div class="space-y-2 mb-3 ki-payment-splits" data-enhance-selects>
                                <template x-for="(split, i) in splits" :key="i">
                                    <div class="ki-show-payment-row flex flex-wrap items-end gap-2 bg-gray-50 rounded-2xl px-3 py-3">
                                        <div>
                                            <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Amount</div>
                                            <input type="number" step="0.01" min="0.01"
                                                   :name="'payments[' + i + '][amount]'" required
                                                   x-model="split.amount"
                                                   class="rounded-md border-gray-300 text-sm" style="width:120px;">
                                        </div>
                                        <div class="ki-split-mode">
                                            <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Mode</div>
                                            <select :name="'payments[' + i + '][mode]'" required x-model="split.mode" class="rounded-md border-gray-300 text-sm">
                                                <option value="cash">Cash</option>
                                                <option value="upi">UPI</option>
                                                <option value="bank">Bank</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="ki-split-account">
                                            <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Account</div>
                                            <select :name="'payments[' + i + '][payment_method_id]'" x-model="split.payment_method_id" class="rounded-md border-gray-300 text-sm">
                                                <option value="">—</option>
                                                @foreach($paymentMethods as $pm)
                                                    <option value="{{ $pm->id }}">{{ $pm->name }} ({{ $pm->type }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Reference</div>
                                            <input type="text" :name="'payments[' + i + '][reference]'" x-model="split.reference"
                                                   placeholder="UTR / cheque #" class="rounded-md border-gray-300 text-sm" style="width:150px;">
                                        </div>
                                        <div>
                                            <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Date</div>
                                            <input type="date" :name="'payments[' + i + '][paid_on]'" required x-model="split.paid_on" class="rounded-md border-gray-300 text-sm">
                                        </div>
                                        <button type="button" @click="removeSplit(i)" x-show="splits.length > 1" class="ki-show-payment-remove">×</button>
                                    </div>
                                </template>
                            </div>

                            <div class="ki-show-payment-actions">
                                <button type="submit" class="btn btn-success btn-sm">Record Payment</button>
                                <button type="button" @click="addSplit" class="ki-show-payment-add">+ Add another mode</button>
                                <span class="ki-show-payment-total">
                                    Total:
                                    <span class="ki-show-mono font-semibold" x-text="'₹' + splitTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 })"></span>
                                    /
                                    Due:
                                    <span class="ki-show-mono font-semibold text-rose-600">₹{{ number_format($invoice->amount_due, 2) }}</span>
                                </span>
                            </div>
                        </form>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
