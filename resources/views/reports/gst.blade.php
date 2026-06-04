<x-app-layout>
    @php
        $safeMonth = (int) ($month ?? now()->month);
        $safeMonth = max(1, min(12, $safeMonth));
        $safeYear = (int) ($year ?? now()->year);
        $reportPeriod = \Carbon\Carbon::create()->month($safeMonth)->format('F') . ' ' . $safeYear;
        $shopName = auth()->user()->shop->name ?? 'JewelFlow';
        $reportDate = now()->format('d M Y');
    @endphp
    <x-page-header class="gst-page-header ops-treatment-header">
        <div>
            <div class="gst-title-row">
                <h1 class="page-title">GST Report</h1>
                <span class="gst-period-badge gst-period-badge-mobile">{{ \Carbon\Carbon::create()->month($safeMonth)->format('F') }} {{ $safeYear }}</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">Monthly GST summary and rate-wise breakdown</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.gst') }}" class="flex flex-wrap gap-2 items-end" data-enhance-selects="true" data-enhance-selects-variant="compact">
                <select name="month" class="gst-select gst-month-select">
                    @for($i = 1; $i <= 12; $i++)
                        <option value="{{ $i }}" {{ $safeMonth === $i ? 'selected' : '' }}>
                            {{ \Carbon\Carbon::create()->month($i)->format('F') }}
                        </option>
                    @endfor
                </select>
                <select name="year" class="gst-select gst-year-select">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                        <option value="{{ $y }}" {{ $safeYear === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                @if(request()->hasAny(['month', 'year']))
                    <a href="{{ route('report.gst') }}" class="gst-btn gst-view-toggle-btn" title="Clear" aria-label="Clear">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <span class="gst-action-label">Clear</span>
                    </a>
                @else
                    <button type="submit" class="gst-btn gst-btn--primary gst-view-toggle-btn" title="View" aria-label="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span class="gst-action-label">View</span>
                    </button>
                @endif
                <button type="button" onclick="window.print()" class="gst-btn gst-print-btn" title="Print" aria-label="Print">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    <span class="gst-action-label">Print</span>
                </button>
            </form>
            <span class="gst-period-badge gst-period-badge-desktop">{{ \Carbon\Carbon::create()->month($safeMonth)->format('F') }} {{ $safeYear }}</span>
        </div>
    </x-page-header>

    <div class="content-inner gst-page jf-skeleton-host is-loading">
        {{-- Print-only header --}}
        <div class="gst-print-head">
            <h2>GST Report</h2>
            <div class="gst-print-head-right">Report Period: {{ $reportPeriod }}</div>
        </div>
        <div class="gst-print-subhead">{{ $shopName }} · Report Date: {{ $reportDate }}</div>
        <div class="gst-print-summary">
            <table>
                <tr>
                    <td>Total Sales</td><td>₹{{ number_format($totalSales, 2) }}</td>
                    <td>Taxable Amount</td><td>₹{{ number_format($taxableAmount, 2) }}</td>
                </tr>
                <tr>
                    <td>GST Collected</td><td>₹{{ number_format($gstCollected, 2) }}</td>
                    <td>Invoices</td><td>{{ $invoiceCount }}</td>
                </tr>
                <tr>
                    <td>GST Reversed (Returns)</td><td>−₹{{ number_format($cnGstReversed ?? 0, 2) }}</td>
                    <td>Net GST Liability</td><td>₹{{ number_format($netGstLiability ?? $gstCollected, 2) }}</td>
                </tr>
            </table>
        </div>

        <div class="gst-flow">
            {{-- KPI snapshot strip --}}
            <div class="gst-screen-summary gst-snapshot">
                <div class="gst-snap">
                    <p class="gst-snap-label">Total Sales</p>
                    <p class="gst-snap-value jf-skel jf-skel-value">₹{{ number_format($totalSales, 2) }}</p>
                </div>
                <div class="gst-snap">
                    <p class="gst-snap-label">Taxable Amount</p>
                    <p class="gst-snap-value jf-skel jf-skel-value">₹{{ number_format($taxableAmount, 2) }}</p>
                </div>
                <div class="gst-snap">
                    <p class="gst-snap-label">GST Collected</p>
                    <p class="gst-snap-value gst-snap-value--accent jf-skel jf-skel-value">₹{{ number_format($gstCollected, 2) }}</p>
                </div>
                <div class="gst-snap">
                    <p class="gst-snap-label">Invoices</p>
                    <p class="gst-snap-value jf-skel jf-skel-value">{{ number_format($invoiceCount) }}</p>
                </div>
            </div>

            <div class="gst-main-grid">
                {{-- GST Breakdown by Rate --}}
                <div class="gst-breakdown-panel gst-panel">
                    <div class="gst-panel-head">
                        <h2 class="gst-panel-title">GST Breakdown by Rate</h2>
                        <p class="gst-panel-copy">Itemized tax collection details</p>
                    </div>
                    <div class="gst-table-wrap">
                        <table class="gst-table">
                            <thead>
                                <tr>
                                    <th>Rate</th>
                                    <th class="text-right">Taxable</th>
                                    <th class="text-right">Discount</th>
                                    <th class="text-right">CGST</th>
                                    <th class="text-right">SGST</th>
                                    <th class="text-right">Total GST</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-center">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($gstBreakdown as $row)
                                    @php
                                        $cgst = round((float) ($row->cgst ?? ($row->gst / 2)), 2);
                                        $sgst = round((float) ($row->sgst ?? ($row->gst - $cgst)), 2);
                                    @endphp
                                    <tr>
                                        <td><span class="gst-rate-pill">{{ number_format($row->gst_rate, 2) }}%</span></td>
                                        <td class="text-right gst-num">₹{{ number_format($row->taxable, 2) }}</td>
                                        <td class="text-right gst-num gst-neg">{{ $row->discount > 0 ? '−₹' . number_format($row->discount, 2) : '-' }}</td>
                                        <td class="text-right gst-num gst-muted">₹{{ number_format($cgst, 2) }}</td>
                                        <td class="text-right gst-num gst-muted">₹{{ number_format($sgst, 2) }}</td>
                                        <td class="text-right gst-num gst-accent gst-strong">₹{{ number_format($row->gst, 2) }}</td>
                                        <td class="text-right gst-num gst-strong">₹{{ number_format($row->total, 2) }}</td>
                                        <td class="text-center gst-muted">{{ $row->count }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8">
                                            <div class="gst-empty">No GST transactions for this period</div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($gstBreakdown->isNotEmpty())
                                @php
                                    $totalCgst = round((float) ($cgstCollected ?? ($gstCollected / 2)), 2);
                                    $totalSgst = round((float) ($sgstCollected ?? ($gstCollected - $totalCgst)), 2);
                                @endphp
                                <tfoot>
                                    <tr>
                                        <td class="gst-foot-label">Total</td>
                                        <td class="text-right gst-num gst-strong">₹{{ number_format($taxableAmount, 2) }}</td>
                                        <td class="text-right gst-num gst-neg">{{ $totalDiscount > 0 ? '−₹' . number_format($totalDiscount, 2) : '-' }}</td>
                                        <td class="text-right gst-num gst-accent">₹{{ number_format($totalCgst, 2) }}</td>
                                        <td class="text-right gst-num gst-accent">₹{{ number_format($totalSgst, 2) }}</td>
                                        <td class="text-right gst-num gst-accent gst-strong">₹{{ number_format($gstCollected, 2) }}</td>
                                        <td class="text-right gst-num gst-strong">₹{{ number_format($totalSales, 2) }}</td>
                                        <td class="text-center gst-strong">{{ $invoiceCount }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>

                {{-- GSTR-1 sidebar --}}
                <div class="gst-side-panel">
                    {{-- B2C Sales --}}
                    <div class="gst-panel">
                        <div class="gst-panel-head">
                            <h3 class="gst-panel-title gst-panel-title--sm">B2C Sales (Small)</h3>
                        </div>
                        <div class="gst-panel-body">
                            <div class="gst-kv-list">
                                <div class="gst-kv"><span>Invoices</span><span class="gst-kv-val">{{ $invoiceCount }}</span></div>
                                <div class="gst-kv"><span>Taxable</span><span class="gst-kv-val">₹{{ number_format($taxableAmount, 2) }}</span></div>
                                @if($totalDiscount > 0)
                                <div class="gst-kv"><span>Discount</span><span class="gst-kv-val gst-neg">−₹{{ number_format($totalDiscount, 2) }}</span></div>
                                @endif
                                <div class="gst-kv"><span>CGST</span><span class="gst-kv-val gst-accent">₹{{ number_format($cgstCollected ?? ($gstCollected / 2), 2) }}</span></div>
                                <div class="gst-kv"><span>SGST</span><span class="gst-kv-val gst-accent">₹{{ number_format($sgstCollected ?? ($gstCollected / 2), 2) }}</span></div>
                                @if(($igstCollected ?? 0) > 0)
                                <div class="gst-kv"><span>IGST</span><span class="gst-kv-val gst-accent">₹{{ number_format($igstCollected, 2) }}</span></div>
                                @endif
                                <div class="gst-kv gst-kv--total"><span>Total Tax</span><span class="gst-kv-val gst-accent gst-strong">₹{{ number_format($gstCollected, 2) }}</span></div>
                            </div>
                        </div>
                    </div>

                    {{-- HSN Summary --}}
                    <div class="gst-panel">
                        <div class="gst-panel-head">
                            <h3 class="gst-panel-title gst-panel-title--sm">HSN Summary</h3>
                        </div>
                        <div class="gst-panel-body">
                            <div class="gst-kv-list">
                                <div class="gst-kv"><span>HSN</span><span class="gst-kv-val gst-mono">7113</span></div>
                                <div class="gst-kv"><span>Description</span><span class="gst-kv-val">Gold Jewellery</span></div>
                                <div class="gst-kv"><span>Invoices</span><span class="gst-kv-val">{{ $invoiceCount }}</span></div>
                                <div class="gst-kv"><span>Value</span><span class="gst-kv-val">₹{{ number_format($totalSales, 2) }}</span></div>
                            </div>
                        </div>
                    </div>

                    {{-- Net Tax Liability --}}
                    <div class="gst-panel gst-liability">
                        <p class="gst-liability-label">Net Tax Liability</p>
                        <p class="gst-liability-value">₹{{ number_format($netGstLiability ?? $gstCollected, 2) }}</p>
                        <div class="gst-kv-list gst-kv-list--compact">
                            <div class="gst-kv"><span>GST collected</span><span class="gst-kv-val">₹{{ number_format($gstCollected, 2) }}</span></div>
                            @if(($cnGstReversed ?? 0) > 0)
                            <div class="gst-kv"><span>Less: GST on returns</span><span class="gst-kv-val gst-neg">−₹{{ number_format($cnGstReversed, 2) }}</span></div>
                            @endif
                            <div class="gst-kv gst-kv--total"><span>Net payable</span><span class="gst-kv-val gst-accent gst-strong">₹{{ number_format($netGstLiability ?? $gstCollected, 2) }}</span></div>
                        </div>
                    </div>

                    {{-- Filing reminder --}}
                    <div class="gst-note">
                        <svg class="gst-note-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <div>
                            <p class="gst-note-title">Filing reminder</p>
                            <ul class="gst-note-list">
                                <li>File GSTR-1 by the 11th</li>
                                <li>Verify all invoices</li>
                                <li>Consult your CA if needed</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Credit Notes (Returns) --}}
            <div class="gst-cn-panel gst-panel">
                <div class="gst-panel-head">
                    <h2 class="gst-panel-title">Credit Notes Issued (Returns)</h2>
                    <p class="gst-panel-copy">GST reversed on settled returns this period, already subtracted from the net liability above</p>
                </div>
                @if(($cnData ?? collect())->isEmpty())
                    <div class="gst-empty gst-empty--pad">No credit notes issued this period.</div>
                @else
                    <div class="gst-table-wrap">
                        <table class="gst-table">
                            <thead>
                                <tr>
                                    <th>CN Number</th>
                                    <th>Date</th>
                                    <th>Original Invoice</th>
                                    <th>Customer</th>
                                    <th class="text-right">Taxable</th>
                                    <th class="text-right">GST Reversed</th>
                                    <th class="text-right">CN Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($cnData as $cn)
                                    <tr>
                                        <td class="gst-mono gst-strong">{{ $cn->credit_note_number }}</td>
                                        <td class="gst-muted">{{ \Carbon\Carbon::parse($cn->issued_at)->format('d M Y') }}</td>
                                        <td class="gst-mono gst-muted">{{ $cn->original_invoice_number ?? '-' }}</td>
                                        <td>{{ $cn->customer_name }}</td>
                                        <td class="text-right gst-num">₹{{ number_format($cn->cn_subtotal, 2) }}</td>
                                        <td class="text-right gst-num gst-neg">−₹{{ number_format($cn->cn_gst, 2) }}</td>
                                        <td class="text-right gst-num gst-strong">₹{{ number_format($cn->cn_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="gst-foot-label">{{ $cnCount ?? 0 }} credit note(s)</td>
                                    <td class="text-right gst-num gst-strong">₹{{ number_format($cnSubtotalReversed ?? 0, 2) }}</td>
                                    <td class="text-right gst-num gst-neg gst-strong">−₹{{ number_format($cnGstReversed ?? 0, 2) }}</td>
                                    <td class="text-right gst-num gst-strong">₹{{ number_format($cnTotalReversed ?? 0, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Quick links --}}
            @php $isRetailer = auth()->user()->shop?->isRetailer(); @endphp
            <div class="gst-quick-links gst-links">
                <span class="gst-links-label">Quick jump</span>
                <div class="gst-links-row">
                    @if($isRetailer)
                        <a href="{{ route('cashbook.index') }}" class="gst-link-pill">Cash Ledger</a>
                        <a href="{{ route('report.closing') }}" class="gst-link-pill">Daily Closing</a>
                    @else
                        <a href="{{ route('report.daily') }}" class="gst-link-pill">Daily</a>
                        <a href="{{ route('report.cash') }}" class="gst-link-pill">Cash</a>
                        <a href="{{ route('report.pnl') }}" class="gst-link-pill">P&amp;L</a>
                        <a href="{{ route('report.gold') }}" class="gst-link-pill">Gold</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <style>
        /* ── GST Report — calm teal/hairline system (matches vault/returns/closing) ── */
        .gst-page {
            --gst-border:        #e7ebf1;
            --gst-border-soft:   #eef1f6;
            --gst-border-strong: #d9dfe8;
            --gst-ink:           #0f172a;
            --gst-ink-2:         #3d4861;
            --gst-muted:         #6a7588;
            --gst-accent:        #0d9488;
            --gst-accent-deep:   #0f766e;
            --gst-neg:           #b42318;
            --gst-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
            --gst-ease:          cubic-bezier(0.23,1,0.32,1);
            max-width: 1360px;
        }

        .gst-print-head, .gst-print-subhead, .gst-print-summary { display: none; }

        .gst-flow { display: flex; flex-direction: column; gap: 20px; }

        @media (prefers-reduced-motion: no-preference) {
            .gst-page .gst-snapshot, .gst-page .gst-main-grid > *, .gst-page .gst-cn-panel {
                animation: gstRise .5s var(--gst-ease) both;
            }
            .gst-page .gst-main-grid > *:nth-child(2) { animation-delay: .05s; }
            @keyframes gstRise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        }

        /* Header period badge + selects + buttons */
        .gst-period-badge {
            display: inline-flex; align-items: center; height: 24px; padding: 0 10px;
            border-radius: 999px; background: var(--gst-accent-soft, rgba(13,148,136,.08));
            border: 1px solid #cfe6e2; color: #0f766e; font-size: 11.5px; font-weight: 650;
        }
        .gst-period-badge-mobile { margin-left: 8px; }
        .gst-period-badge-desktop { margin-left: 8px; }
        .gst-select {
            height: 40px; padding: 0 10px;
            border: 1px solid var(--gst-border-strong); border-radius: 10px;
            background: #f4f6fa; color: var(--gst-ink); font-size: 13px;
            transition: border-color .15s var(--gst-ease), box-shadow .15s var(--gst-ease), background-color .15s var(--gst-ease);
        }
        .gst-month-select { width: 6.4rem; }
        .gst-year-select { width: 5.2rem; }
        .gst-select:focus { border-color: var(--gst-accent-deep); background: #fff; box-shadow: 0 0 0 3px rgba(15,118,110,.12); outline: none; }
        .gst-btn {
            display: inline-flex; align-items: center; gap: 5px;
            height: 40px; padding: 0 13px;
            border: 1px solid var(--gst-border-strong); border-radius: 10px;
            background: #fff; color: var(--gst-ink-2); font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: background-color .15s var(--gst-ease), transform .15s var(--gst-ease);
        }
        .gst-btn:hover { background: #f7f9fc; }
        .gst-btn:active { transform: scale(.98); }
        .gst-btn--primary { border-color: var(--gst-accent-deep); background: var(--gst-accent-deep); color: #fff; }
        .gst-btn--primary:hover { background: #115e56; }

        /* KPI snapshot strip */
        .gst-snapshot {
            display: grid; grid-template-columns: repeat(4, minmax(0,1fr));
            border: 1px solid var(--gst-border); border-radius: 16px;
            background: #fff; box-shadow: var(--gst-shadow); overflow: hidden;
        }
        .gst-snap { padding: 16px 18px; border-right: 1px solid var(--gst-border); }
        .gst-snap:last-child { border-right: 0; }
        .gst-snap-label { margin: 0 0 6px; color: var(--gst-muted); font-size: 12px; font-weight: 500; }
        .gst-snap-value {
            margin: 0; color: var(--gst-ink); font-size: 20px; font-weight: 700;
            line-height: 1.15; letter-spacing: -.01em; font-variant-numeric: tabular-nums;
        }
        .gst-snap-value--accent { color: var(--gst-accent-deep); }

        /* Main grid: breakdown (2) + sidebar (1) */
        .gst-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
        .gst-side-panel { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

        /* Panels */
        .gst-panel {
            border: 1px solid var(--gst-border); border-radius: 16px;
            background: #fff; box-shadow: var(--gst-shadow); overflow: hidden; min-width: 0;
        }
        .gst-panel-head { padding: 18px 20px; border-bottom: 1px solid var(--gst-border-soft); }
        .gst-panel-title { margin: 0; color: var(--gst-ink); font-size: 15px; font-weight: 650; letter-spacing: -.01em; }
        .gst-panel-title--sm { font-size: 13.5px; }
        .gst-panel-copy { margin: 4px 0 0; color: var(--gst-muted); font-size: 12px; line-height: 1.5; }
        .gst-panel-body { padding: 16px 20px; }

        /* Tables */
        .gst-table-wrap { overflow-x: auto; }
        .gst-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .gst-table thead th {
            padding: 11px 16px; text-align: left;
            font-size: 11.5px; font-weight: 600; color: var(--gst-muted);
            background: #fafbfd; border-bottom: 1px solid var(--gst-border); white-space: nowrap;
        }
        .gst-table thead th.text-right { text-align: right; }
        .gst-table thead th.text-center { text-align: center; }
        .gst-table tbody td {
            padding: 12px 16px; vertical-align: middle;
            border-bottom: 1px solid var(--gst-border-soft); color: var(--gst-ink-2); white-space: nowrap;
        }
        .gst-table tbody tr:hover { background: #fafbfd; }
        .gst-table td.text-right { text-align: right; }
        .gst-table td.text-center { text-align: center; }
        .gst-table tfoot td {
            padding: 13px 16px; border-top: 1px solid var(--gst-border);
            background: #fafbfd; font-size: 13px; white-space: nowrap;
        }
        .gst-foot-label { color: var(--gst-ink); font-weight: 700; font-size: 12px; }

        .gst-num { font-variant-numeric: tabular-nums; }
        .gst-strong { color: var(--gst-ink); font-weight: 650; }
        .gst-accent { color: var(--gst-accent-deep); }
        .gst-neg { color: var(--gst-neg); }
        .gst-muted { color: var(--gst-muted); }
        .gst-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }

        .gst-rate-pill {
            display: inline-flex; align-items: center; padding: 3px 9px;
            border-radius: 6px; background: rgba(13,148,136,.08); color: #0f766e;
            font-size: 11.5px; font-weight: 650; font-variant-numeric: tabular-nums;
        }

        /* Sidebar key/value lists */
        .gst-kv-list { display: flex; flex-direction: column; }
        .gst-kv {
            display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
            padding: 9px 0; border-bottom: 1px solid var(--gst-border-soft);
        }
        .gst-kv:first-child { padding-top: 2px; }
        .gst-kv:last-child { border-bottom: 0; padding-bottom: 2px; }
        .gst-kv > span:first-child { color: var(--gst-muted); font-size: 12.5px; }
        .gst-kv-val { color: var(--gst-ink); font-size: 13px; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
        .gst-kv--total { border-top: 1px solid var(--gst-border); border-bottom: 0; margin-top: 4px; padding-top: 11px; }
        .gst-kv-list--compact .gst-kv { padding: 7px 0; font-size: 12px; }

        /* Net liability card */
        .gst-liability { padding: 20px 22px; }
        .gst-liability-label { margin: 0; color: var(--gst-muted); font-size: 12px; font-weight: 500; }
        .gst-liability-value {
            margin: 6px 0 12px; color: var(--gst-accent-deep);
            font-size: 26px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums;
        }

        /* Filing note — full border, no side stripe */
        .gst-note {
            display: flex; gap: 12px; padding: 16px 18px;
            border: 1px solid #f0e2c0; border-radius: 14px; background: #fdfaf1;
        }
        .gst-note-icon { width: 18px; height: 18px; flex-shrink: 0; color: #b45309; margin-top: 1px; }
        .gst-note-title { margin: 0 0 6px; color: #92400e; font-size: 12.5px; font-weight: 650; }
        .gst-note-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .gst-note-list li { color: #9a6a1c; font-size: 12px; line-height: 1.5; position: relative; padding-left: 14px; }
        .gst-note-list li::before { content: ''; position: absolute; left: 2px; top: 8px; width: 4px; height: 4px; border-radius: 999px; background: #c2872f; }

        /* Empty states */
        .gst-empty { padding: 32px 16px; text-align: center; color: var(--gst-muted); font-size: 13px; }
        .gst-empty--pad { padding: 40px 16px; }

        /* Quick links */
        .gst-links {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            padding: 16px 20px; border: 1px solid var(--gst-border); border-radius: 16px;
            background: #fff; box-shadow: var(--gst-shadow);
        }
        .gst-links-label { color: var(--gst-muted); font-size: 12px; font-weight: 600; }
        .gst-links-row { display: flex; flex-wrap: wrap; gap: 8px; }
        .gst-link-pill {
            display: inline-flex; align-items: center; height: 32px; padding: 0 14px;
            border: 1px solid var(--gst-border-strong); border-radius: 999px;
            background: #fff; color: var(--gst-ink-2); font-size: 12.5px; font-weight: 600;
            text-decoration: none; transition: background-color .14s var(--gst-ease), border-color .14s var(--gst-ease), color .14s var(--gst-ease);
        }
        .gst-link-pill:hover { background: rgba(13,148,136,.06); border-color: #a3d6cf; color: var(--gst-accent-deep); }

        /* Responsive */
        @media (max-width: 1024px) {
            .gst-main-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .gst-snapshot { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .gst-snap { border-bottom: 1px solid var(--gst-border); }
            .gst-snap:nth-child(2n) { border-right: 0; }
            .gst-snap:nth-last-child(-n+2) { border-bottom: 0; }
        }
        @media (max-width: 460px) {
            .gst-snapshot { grid-template-columns: 1fr; }
            .gst-snap { border-right: 0; border-bottom: 1px solid var(--gst-border); }
            .gst-snap:last-child { border-bottom: 0; }
        }

        /* Header mobile layout */
        @media (max-width: 768px) {
            .content-header.gst-page-header.ops-treatment-header { flex-wrap: wrap; align-items: center; }
            .content-header.gst-page-header.ops-treatment-header > :nth-child(2) { flex: 1 1 calc(100% - 40px); min-width: 0; }
            .content-header.gst-page-header.ops-treatment-header .page-actions {
                flex: 1 0 100%; width: calc(100% - 40px); max-width: calc(100% - 40px);
                margin-left: 40px; justify-content: flex-start; align-items: center;
            }
            .content-header.gst-page-header.ops-treatment-header .page-actions form { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; width: 100%; }
            .gst-page-header .gst-view-toggle-btn, .gst-page-header .gst-print-btn { width: 38px; padding: 0; justify-content: center; }
            .gst-page-header .gst-action-label { display: none; }
            .gst-period-badge-desktop { display: none; }
        }
        @media (min-width: 769px) {
            .gst-period-badge-mobile { display: none; }
        }

        /* ── Print ── */
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
            .mobile-menu-btn, .sidebar-overlay, .sidebar, .content-header,
            .gst-screen-summary, .gst-side-panel, .gst-quick-links { display: none !important; }
            .workspace, .content-area, .content-body { display: block !important; height: auto !important; overflow: visible !important; background: #fff !important; }
            .content-inner { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; gap: 0 !important; }

            .gst-print-head {
                display: flex !important; justify-content: space-between; align-items: flex-start;
                gap: 8mm; padding-bottom: 4mm; margin-bottom: 2mm; border-bottom: 2px solid #111827;
            }
            .gst-print-head h2 { margin: 0; font-size: 20px; font-weight: 700; color: #111827; }
            .gst-print-head-right { font-size: 11px; font-weight: 600; color: #111827; white-space: nowrap; }
            .gst-print-subhead { display: block !important; margin-bottom: 4mm; font-size: 11px; color: #4b5563; }
            .gst-print-summary { display: block !important; margin-bottom: 6mm; }
            .gst-print-summary table { width: 100%; border-collapse: collapse; font-size: 11px; }
            .gst-print-summary td { border: 1px solid #d1d5db; padding: 2.2mm 2.8mm; }
            .gst-print-summary td:nth-child(odd) { width: 22%; font-weight: 600; color: #374151; background: #f9fafb; }

            .gst-flow { gap: 6mm !important; }
            .gst-main-grid { display: block !important; }
            .gst-panel, .gst-breakdown-panel, .gst-cn-panel {
                border: 1px solid #d1d5db !important; border-radius: 0 !important; box-shadow: none !important;
                break-inside: avoid; page-break-inside: avoid; width: 100% !important; margin-bottom: 6mm;
            }
            .gst-panel-head { border-bottom: 1px solid #d1d5db !important; }
            .gst-table-wrap { overflow: visible !important; }
            .gst-table { table-layout: fixed; }
            .gst-table th, .gst-table td {
                white-space: normal !important; word-break: break-word;
                font-size: 9px !important; line-height: 1.25 !important; padding: 1.6mm 1.4mm !important;
            }
            .gst-accent, .gst-neg { color: #111827 !important; }
            .gst-rate-pill { background: #f3f4f6 !important; color: #111827 !important; }
            button, a { color: #111827; }
        }
    </style>

    @push('scripts')
    <script>
        (() => {
            const printTitle = "GST Report - {{ $reportPeriod }} - {{ $shopName }}";
            document.title = printTitle;
            window.addEventListener('beforeprint', () => { document.title = printTitle; });
        })();
    </script>
    @endpush
</x-app-layout>
