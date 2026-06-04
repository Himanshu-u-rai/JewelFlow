<x-app-layout>
    @php
        $safeMonth = (int) ($month ?? now()->month);
        $safeMonth = max(1, min(12, $safeMonth));
        $safeYear = (int) ($year ?? now()->year);
        $reportPeriod = \Carbon\Carbon::create()->month($safeMonth)->format('F') . ' ' . $safeYear;
        $shopName = auth()->user()->shop->name ?? 'JewelFlow';
        $reportDate = now()->format('d M Y');
        $isRetailer = auth()->user()->shop?->isRetailer();
    @endphp
    <x-page-header title="GST Report" subtitle="Monthly GST summary and rate-wise breakdown" />

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
            {{-- Controls toolbar (period filter + print) --}}
            <div class="gst-toolbar">
                <form method="GET" action="{{ route('report.gst') }}" class="gst-toolbar-filter" data-enhance-selects="true" data-enhance-selects-variant="compact">
                    <span class="gst-toolbar-label">Period</span>
                    <select name="month" class="gst-select">
                        @for($i = 1; $i <= 12; $i++)
                            <option value="{{ $i }}" {{ $safeMonth === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>
                        @endfor
                    </select>
                    <select name="year" class="gst-select">
                        @for($y = now()->year; $y >= now()->year - 5; $y--)
                            <option value="{{ $y }}" {{ $safeYear === $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                    @if(request()->hasAny(['month', 'year']))
                        <a href="{{ route('report.gst') }}" class="gst-btn">Clear</a>
                    @else
                        <button type="submit" class="gst-btn gst-btn--primary">View</button>
                    @endif
                </form>
                <div class="gst-toolbar-actions">
                    <button type="button" onclick="window.print()" class="gst-btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                        Print
                    </button>
                </div>
            </div>

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

            {{-- GST Breakdown by Rate (full width) --}}
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

                    {{-- Mobile card list (rate breakdown) --}}
                    <div class="gst-mobile-list">
                        @forelse($gstBreakdown as $row)
                            @php
                                $cgst = round((float) ($row->cgst ?? ($row->gst / 2)), 2);
                                $sgst = round((float) ($row->sgst ?? ($row->gst - $cgst)), 2);
                            @endphp
                            <div class="gst-mcard">
                                <div class="gst-mcard-head">
                                    <span class="gst-rate-pill">{{ number_format($row->gst_rate, 2) }}%</span>
                                    <span class="gst-num gst-strong">₹{{ number_format($row->total, 2) }}</span>
                                </div>
                                <dl class="gst-mcard-grid">
                                    <div><dt>Taxable</dt><dd class="gst-num">₹{{ number_format($row->taxable, 2) }}</dd></div>
                                    <div><dt>Discount</dt><dd class="gst-num gst-neg">{{ $row->discount > 0 ? '−₹' . number_format($row->discount, 2) : '-' }}</dd></div>
                                    <div><dt>CGST</dt><dd class="gst-num">₹{{ number_format($cgst, 2) }}</dd></div>
                                    <div><dt>SGST</dt><dd class="gst-num">₹{{ number_format($sgst, 2) }}</dd></div>
                                    <div><dt>Total GST</dt><dd class="gst-num gst-accent">₹{{ number_format($row->gst, 2) }}</dd></div>
                                    <div><dt>Count</dt><dd>{{ $row->count }}</dd></div>
                                </dl>
                            </div>
                        @empty
                            <div class="gst-empty gst-empty--pad">No GST transactions for this period</div>
                        @endforelse

                        @if($gstBreakdown->isNotEmpty())
                            @php
                                $totalCgst = round((float) ($cgstCollected ?? ($gstCollected / 2)), 2);
                                $totalSgst = round((float) ($sgstCollected ?? ($gstCollected - $totalCgst)), 2);
                            @endphp
                            <div class="gst-mcard gst-mcard--total">
                                <div class="gst-mcard-head">
                                    <span class="gst-foot-label">Total</span>
                                    <span class="gst-num gst-strong">₹{{ number_format($totalSales, 2) }}</span>
                                </div>
                                <dl class="gst-mcard-grid">
                                    <div><dt>Taxable</dt><dd class="gst-num gst-strong">₹{{ number_format($taxableAmount, 2) }}</dd></div>
                                    <div><dt>Discount</dt><dd class="gst-num gst-neg">{{ $totalDiscount > 0 ? '−₹' . number_format($totalDiscount, 2) : '-' }}</dd></div>
                                    <div><dt>CGST</dt><dd class="gst-num gst-accent">₹{{ number_format($totalCgst, 2) }}</dd></div>
                                    <div><dt>SGST</dt><dd class="gst-num gst-accent">₹{{ number_format($totalSgst, 2) }}</dd></div>
                                    <div><dt>Total GST</dt><dd class="gst-num gst-accent gst-strong">₹{{ number_format($gstCollected, 2) }}</dd></div>
                                    <div><dt>Invoices</dt><dd class="gst-strong">{{ $invoiceCount }}</dd></div>
                                </dl>
                            </div>
                        @endif
                    </div>
                </div>

            {{-- GSTR-1 summary — a balanced card row instead of a tall sidebar --}}
            <div class="gst-summary-grid">
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

                    {{-- Filing reminder + quick jump --}}
                    <div class="gst-note">
                        <div class="gst-note-top">
                            <svg class="gst-note-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                            <p class="gst-note-title">Filing reminder</p>
                        </div>
                        <ul class="gst-note-list">
                            <li>File GSTR-1 by the 11th</li>
                            <li>Verify all invoices</li>
                            <li>Consult your CA if needed</li>
                        </ul>
                        <div class="gst-note-links">
                            <span class="gst-note-links-label">Quick jump</span>
                            <div class="gst-note-links-row">
                                @if($isRetailer)
                                    <a href="{{ route('cashbook.index') }}" class="gst-note-link">Cash Ledger</a>
                                    <a href="{{ route('report.closing') }}" class="gst-note-link">Daily Closing</a>
                                @else
                                    <a href="{{ route('report.daily') }}" class="gst-note-link">Daily</a>
                                    <a href="{{ route('report.cash') }}" class="gst-note-link">Cash</a>
                                    <a href="{{ route('report.pnl') }}" class="gst-note-link">P&amp;L</a>
                                    <a href="{{ route('report.gold') }}" class="gst-note-link">Gold</a>
                                @endif
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

                    {{-- Mobile card list (credit notes) --}}
                    <div class="gst-mobile-list gst-mobile-list--cn">
                        @foreach($cnData as $cn)
                            <div class="gst-mcard">
                                <div class="gst-mcard-head">
                                    <span class="gst-mono gst-strong">{{ $cn->credit_note_number }}</span>
                                    <span class="gst-num gst-strong">₹{{ number_format($cn->cn_total, 2) }}</span>
                                </div>
                                <dl class="gst-mcard-grid">
                                    <div><dt>Date</dt><dd>{{ \Carbon\Carbon::parse($cn->issued_at)->format('d M Y') }}</dd></div>
                                    <div><dt>Invoice</dt><dd class="gst-mono">{{ $cn->original_invoice_number ?? '-' }}</dd></div>
                                    <div><dt>Customer</dt><dd>{{ $cn->customer_name }}</dd></div>
                                    <div><dt>Taxable</dt><dd class="gst-num">₹{{ number_format($cn->cn_subtotal, 2) }}</dd></div>
                                    <div><dt>GST Reversed</dt><dd class="gst-num gst-neg">−₹{{ number_format($cn->cn_gst, 2) }}</dd></div>
                                </dl>
                            </div>
                        @endforeach
                        <div class="gst-mcard gst-mcard--total">
                            <div class="gst-mcard-head">
                                <span class="gst-foot-label">{{ $cnCount ?? 0 }} credit note(s)</span>
                                <span class="gst-num gst-strong">₹{{ number_format($cnTotalReversed ?? 0, 2) }}</span>
                            </div>
                            <dl class="gst-mcard-grid">
                                <div><dt>Taxable</dt><dd class="gst-num gst-strong">₹{{ number_format($cnSubtotalReversed ?? 0, 2) }}</dd></div>
                                <div><dt>GST Reversed</dt><dd class="gst-num gst-neg gst-strong">−₹{{ number_format($cnGstReversed ?? 0, 2) }}</dd></div>
                            </dl>
                        </div>
                    </div>
                @endif
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
            .gst-page .gst-snapshot, .gst-page .gst-breakdown-panel,
            .gst-page .gst-summary-grid > *, .gst-page .gst-cn-panel {
                animation: gstRise .5s var(--gst-ease) both;
            }
            .gst-page .gst-summary-grid > *:nth-child(2) { animation-delay: .05s; }
            @keyframes gstRise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        }

        /* Controls toolbar — period filter (left) + actions (right) */
        .gst-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 13px 18px; border: 1px solid var(--gst-border); border-radius: 16px;
            background: #fff; box-shadow: var(--gst-shadow);
        }
        .gst-toolbar-filter { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .gst-toolbar-label { color: var(--gst-muted); font-size: 12px; font-weight: 600; margin-right: 2px; }
        .gst-toolbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .gst-select {
            height: 40px; padding: 0 10px; min-width: 7rem;
            border: 1px solid var(--gst-border-strong); border-radius: 10px;
            background: #f4f6fa; color: var(--gst-ink); font-size: 13px;
            transition: border-color .15s var(--gst-ease), box-shadow .15s var(--gst-ease), background-color .15s var(--gst-ease);
        }
        .gst-select:focus { border-color: var(--gst-accent-deep); background: #fff; box-shadow: 0 0 0 3px rgba(15,118,110,.12); outline: none; }
        .gst-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 40px; padding: 0 15px;
            border: 1px solid var(--gst-border-strong); border-radius: 10px;
            background: #fff; color: var(--gst-ink-2); font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: background-color .15s var(--gst-ease), transform .15s var(--gst-ease);
        }
        .gst-btn:hover { background: #f7f9fc; }
        .gst-btn:active { transform: scale(.98); }
        .gst-btn--primary { border-color: var(--gst-accent-deep); background: var(--gst-accent-deep); color: #fff; }
        .gst-btn--primary:hover { background: #115e56; }

        @media (max-width: 600px) {
            .gst-toolbar { flex-direction: column; align-items: stretch; }
            .gst-toolbar-filter, .gst-toolbar-actions { width: 100%; }
            .gst-toolbar-filter .gst-select { flex: 1; min-width: 0; }
            .gst-toolbar-actions .gst-btn { flex: 1; }
        }

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

        /* GSTR-1 summary cards — a balanced row, not a tall narrow sidebar
           (the breakdown table is short, so a sidebar left a huge void).
           Cards stretch to equal height and distribute their content to fill,
           so the row reads as even regardless of how many rows each holds. */
        .gst-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            align-items: stretch;
        }
        .gst-summary-grid > .gst-panel { display: flex; flex-direction: column; }
        .gst-summary-grid .gst-panel-body { flex: 1; display: flex; flex-direction: column; }
        .gst-summary-grid .gst-panel-body .gst-kv-list { flex: 1; justify-content: space-between; }
        .gst-summary-grid .gst-liability .gst-kv-list--compact { margin-top: auto; }

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

        /* Filing note (+ embedded quick jump) — full border, no side stripe */
        .gst-note {
            display: flex; flex-direction: column; gap: 12px; padding: 16px 18px;
            border: 1px solid #f0e2c0; border-radius: 16px; background: #fdfaf1;
        }
        .gst-note-top { display: flex; align-items: center; gap: 9px; }
        .gst-note-icon { width: 17px; height: 17px; flex-shrink: 0; color: #b45309; }
        .gst-note-title { margin: 0; color: #92400e; font-size: 12.5px; font-weight: 650; }
        .gst-note-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 5px; }
        .gst-note-list li { color: #9a6a1c; font-size: 12px; line-height: 1.5; position: relative; padding-left: 14px; }
        .gst-note-list li::before { content: ''; position: absolute; left: 2px; top: 8px; width: 4px; height: 4px; border-radius: 999px; background: #c2872f; }
        .gst-note-links { margin-top: auto; padding-top: 12px; border-top: 1px solid #f0e2c0; }
        .gst-note-links-label { display: block; margin-bottom: 8px; color: #9a6a1c; font-size: 11px; font-weight: 600; }
        .gst-note-links-row { display: flex; flex-wrap: wrap; gap: 7px; }
        .gst-note-link {
            display: inline-flex; align-items: center; height: 30px; padding: 0 12px;
            border: 1px solid #ead9b0; border-radius: 999px; background: #fffdf7;
            color: #8a5a18; font-size: 12px; font-weight: 600; text-decoration: none;
            transition: background-color .14s var(--gst-ease), border-color .14s var(--gst-ease);
        }
        .gst-note-link:hover { background: #fbf2dd; border-color: #d9bf86; }

        /* Empty states */
        .gst-empty { padding: 32px 16px; text-align: center; color: var(--gst-muted); font-size: 13px; }
        .gst-empty--pad { padding: 40px 16px; }

        /* Mobile card lists for the data tables */
        .gst-mobile-list { display: none; padding: 12px 16px 16px; }
        .gst-mcard {
            border: 1px solid var(--gst-border); border-radius: 12px;
            background: #fafbfd; padding: 13px 14px; margin-bottom: 10px;
        }
        .gst-mcard:last-child { margin-bottom: 0; }
        .gst-mcard--total { background: #f1f5f4; border-color: #cfe6e2; }
        .gst-mcard-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding-bottom: 10px; margin-bottom: 10px; border-bottom: 1px solid var(--gst-border);
        }
        .gst-mcard-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 9px 16px; margin: 0; }
        .gst-mcard-grid dt { color: var(--gst-muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 1px; }
        .gst-mcard-grid dd { margin: 0; color: var(--gst-ink); font-size: 13px; font-weight: 600; word-break: break-word; }

        /* Responsive */
        @media (max-width: 767px) {
            /* Wide financial tables → stacked cards (no sideways scroll) */
            .gst-table-wrap { display: none; }
            .gst-mobile-list { display: block; }
        }
        @media (max-width: 760px) {
            .gst-snapshot { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .gst-snap { border-bottom: 1px solid var(--gst-border); }
            .gst-snap:nth-child(2n) { border-right: 0; }
            .gst-snap:nth-last-child(-n+2) { border-bottom: 0; }
        }
        @media (max-width: 420px) {
            .gst-mcard-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 460px) {
            .gst-snapshot { grid-template-columns: 1fr; }
            .gst-snap { border-right: 0; border-bottom: 1px solid var(--gst-border); }
            .gst-snap:last-child { border-bottom: 0; }
        }

        /* ── Print ── */
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
            .mobile-menu-btn, .sidebar-overlay, .sidebar, .content-header,
            .gst-toolbar, .gst-screen-summary, .gst-summary-grid { display: none !important; }
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
