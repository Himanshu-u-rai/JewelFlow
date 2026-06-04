<x-app-layout>
    <x-page-header class="closing-page-header" title="Daily Closing" :subtitle="'Summary for ' . $date">
        <x-slot:actions>
            <form method="GET" action="{{ route('report.closing') }}" class="inline-flex items-center gap-2 closing-header-filter">
                <input type="date" name="date" value="{{ $date }}" class="clr-date closing-header-date">
                @if(request()->filled('date'))
                    <a href="{{ route('report.closing') }}" class="btn btn-secondary btn-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                        Clear
                    </a>
                @else
                    <button type="submit" class="btn btn-success btn-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                        </svg>
                        Filter
                    </button>
                @endif
            </form>

            <button onclick="window.print()" class="btn btn-secondary btn-sm closing-print-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
                </svg>
                Print
            </button>

            @if(auth()->user()->shop?->isManufacturer())
                <a href="{{ route('report.pnl', ['date' => $date]) }}" class="btn btn-secondary btn-sm closing-pnl-btn">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    View P&amp;L
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner clr-page">
        @php
            $reportDatePretty = \Carbon\Carbon::parse($date)->format('l, d M Y');
            $shopName = auth()->user()->shop->name ?? 'JewelFlow';
            $netGold = $goldIn - $goldOut - $wastage;
            $totalCash = $sales + $repairs;
            $isRetailer = auth()->user()->shop?->isRetailer();
            $snapCols = $isRetailer ? 4 : 5;
        @endphp

        {{-- Print-only header --}}
        <div class="closing-print-head">
            <h2>Daily Closing Report</h2>
            <span class="closing-print-date">Report Date: {{ $reportDatePretty }}</span>
        </div>

        {{-- Snapshot KPI strip (replaces the dark banner) --}}
        <div class="clr-snapshot" data-cols="{{ $snapCols }}">
            <div class="clr-snap">
                <p class="clr-snap-label">Total Collections</p>
                <p class="clr-snap-value clr-snap-value--accent">₹{{ number_format($totalCash, 2) }}</p>
                <p class="clr-snap-sub">Sales + Repairs</p>
            </div>
            <div class="clr-snap">
                <p class="clr-snap-label">Sales</p>
                <p class="clr-snap-value">₹{{ number_format($sales, 2) }}</p>
                <p class="clr-snap-sub">incl. GST</p>
            </div>
            <div class="clr-snap">
                <p class="clr-snap-label">Repairs</p>
                <p class="clr-snap-value">₹{{ number_format($repairs, 2) }}</p>
                <p class="clr-snap-sub">Repair income</p>
            </div>
            <div class="clr-snap">
                <p class="clr-snap-label">Invoices</p>
                <p class="clr-snap-value">{{ number_format($invoiceCount) }}</p>
                <p class="clr-snap-sub">Finalized today</p>
            </div>
            @if(!$isRetailer)
            <div class="clr-snap">
                <p class="clr-snap-label">Net Gold</p>
                <p class="clr-snap-value {{ $netGold >= 0 ? 'clr-snap-value--pos' : 'clr-snap-value--neg' }}">{{ number_format($netGold, 4) }} g</p>
                <p class="clr-snap-sub">In − Out − Wastage</p>
            </div>
            @endif
        </div>

        <div class="closing-report-grid clr-grid {{ $isRetailer ? 'clr-grid--single' : '' }}">
            @if(!$isRetailer)
            {{-- Gold Summary ledger --}}
            <section class="clr-panel closing-panel-card">
                <div class="clr-panel-head">
                    <h2 class="clr-panel-title">Gold Summary</h2>
                    <p class="clr-panel-copy">Fine gold movements for the day (grams)</p>
                </div>
                <div class="clr-panel-body">
                    <div class="clr-ledger">
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Gold In<span class="clr-ledger-sub">Buyback, advance, repair return</span></span>
                            <span class="clr-ledger-value">{{ number_format($goldIn, 4) }} g</span>
                        </div>
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Gold Out<span class="clr-ledger-sub">Sale, manufacture, repair issue</span></span>
                            <span class="clr-ledger-value">{{ number_format($goldOut, 4) }} g</span>
                        </div>
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Wastage<span class="clr-ledger-sub">Recorded wastage</span></span>
                            <span class="clr-ledger-value">{{ number_format($wastage, 4) }} g</span>
                        </div>
                        <div class="clr-ledger-row clr-ledger-row--total">
                            <span class="clr-ledger-label">Net Movement<span class="clr-ledger-sub">In − Out − Wastage</span></span>
                            <span class="clr-ledger-value clr-ledger-value--total {{ $netGold >= 0 ? 'clr-ledger-value--pos' : 'clr-ledger-value--neg' }}">{{ number_format($netGold, 4) }} g</span>
                        </div>
                    </div>
                </div>
            </section>
            @endif

            {{-- Cash Summary ledger --}}
            <section class="clr-panel closing-panel-card">
                <div class="clr-panel-head">
                    <h2 class="clr-panel-title">Cash Summary</h2>
                    <p class="clr-panel-copy">Sales, repairs, and GST collected (GST is included in the sales total)</p>
                </div>
                <div class="clr-panel-body">
                    <div class="clr-ledger">
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Sales (incl. GST)<span class="clr-ledger-sub">{{ $invoiceCount }} {{ Str::plural('invoice', $invoiceCount) }}</span></span>
                            <span class="clr-ledger-value">₹{{ number_format($sales, 2) }}</span>
                        </div>
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Repairs<span class="clr-ledger-sub">Repair transactions</span></span>
                            <span class="clr-ledger-value">₹{{ number_format($repairs, 2) }}</span>
                        </div>
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">GST Collected<span class="clr-ledger-sub">Already included in sales</span></span>
                            <span class="clr-ledger-value">₹{{ number_format($gst, 2) }}</span>
                        </div>
                        @if($discount > 0)
                        <div class="clr-ledger-row">
                            <span class="clr-ledger-label">Discount Given<span class="clr-ledger-sub">Already deducted from sales</span></span>
                            <span class="clr-ledger-value clr-ledger-value--neg">−₹{{ number_format($discount, 2) }}</span>
                        </div>
                        @endif
                        <div class="clr-ledger-row clr-ledger-row--total">
                            <span class="clr-ledger-label">Total Collections<span class="clr-ledger-sub">Sales + Repairs</span></span>
                            <span class="clr-ledger-value clr-ledger-value--total">₹{{ number_format($totalCash, 2) }}</span>
                        </div>
                    </div>

                    @if(!empty($paymentBreakdown) && count($paymentBreakdown) > 0)
                    <div class="clr-mode-block">
                        <h3 class="clr-subhead">Payment Mode Breakdown</h3>
                        <div class="clr-mode-grid">
                            @foreach($paymentBreakdown as $mode => $modeTotal)
                            <div class="clr-mode">
                                <p class="clr-mode-label">{{ ucfirst(str_replace('_', ' ', $mode)) }}</p>
                                <p class="clr-mode-value">₹{{ number_format($modeTotal, 2) }}</p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </section>
        </div>

        @if($isRetailer)
            @php
                $salesShare = $totalCash > 0 ? round(($sales / $totalCash) * 100, 1) : 0;
                $repairsShare = $totalCash > 0 ? round(($repairs / $totalCash) * 100, 1) : 0;
                $avgBillValue = $invoiceCount > 0 ? round($sales / $invoiceCount, 2) : 0;
                $preDiscountSales = $sales + $discount;
                $discountRate = $preDiscountSales > 0 ? round(($discount / $preDiscountSales) * 100, 2) : 0;
                $paymentRows = collect($paymentBreakdown ?? [])->sortDesc();
                $paymentTotal = (float) $paymentRows->sum();
                $trendRows = collect($collectionsTrend ?? []);
                $trendMax = max((float) $trendRows->max('total'), 1);
                $todayTrendTotal = (float) data_get($trendRows->last(), 'total', 0);
                $trendAverage = (float) $trendRows->avg('total');
            @endphp

            <div class="closing-retail-insights clr-insights">
                <div class="clr-insight-row clr-insight-row--3">
                    {{-- Sales vs Repairs mix --}}
                    <section class="clr-card closing-insight-card">
                        <h3 class="clr-card-title">Sales vs Repairs Mix</h3>
                        <p class="clr-card-copy">Today's collection composition</p>
                        <div class="clr-stack-bar">
                            <div class="clr-stack-fill clr-stack-fill--ink" style="width: {{ $salesShare }}%"></div>
                            <div class="clr-stack-fill clr-stack-fill--accent" style="width: {{ $repairsShare }}%"></div>
                        </div>
                        <div class="clr-mix-legend">
                            <div>
                                <p class="clr-mini-label">Sales</p>
                                <p class="clr-mini-value">₹{{ number_format($sales, 2) }}</p>
                                <p class="clr-mini-sub">{{ number_format($salesShare, 1) }}%</p>
                            </div>
                            <div>
                                <p class="clr-mini-label">Repairs</p>
                                <p class="clr-mini-value clr-text-accent">₹{{ number_format($repairs, 2) }}</p>
                                <p class="clr-mini-sub">{{ number_format($repairsShare, 1) }}%</p>
                            </div>
                        </div>
                    </section>

                    {{-- Average Bill Value --}}
                    <section class="clr-card closing-insight-card">
                        <h3 class="clr-card-title">Average Bill Value</h3>
                        <p class="clr-card-copy">Sales divided by finalized invoices</p>
                        <div class="clr-hero-stat">
                            <p class="clr-hero-value">₹{{ number_format($avgBillValue, 2) }}</p>
                            <p class="clr-card-copy">Based on {{ number_format($invoiceCount) }} {{ Str::plural('invoice', $invoiceCount) }}</p>
                        </div>
                    </section>

                    {{-- Discount Impact --}}
                    <section class="clr-card closing-insight-card">
                        <h3 class="clr-card-title">Discount Impact</h3>
                        <p class="clr-card-copy">How much margin was given away</p>
                        <div class="clr-discount-block">
                            <div class="clr-kv">
                                <span class="clr-mini-label">Discount</span>
                                <span class="clr-kv-value clr-text-neg">₹{{ number_format($discount, 2) }}</span>
                            </div>
                            <div class="clr-kv">
                                <span class="clr-mini-label">Discount Rate</span>
                                <span class="clr-kv-value">{{ number_format($discountRate, 2) }}%</span>
                            </div>
                            <div class="clr-track">
                                <div class="clr-track-fill clr-track-fill--neg" style="width: {{ min($discountRate, 100) }}%"></div>
                            </div>
                            <p class="clr-mini-sub">Pre-discount base: ₹{{ number_format($preDiscountSales, 2) }}</p>
                        </div>
                    </section>
                </div>

                <div class="clr-insight-row clr-insight-row--2">
                    {{-- Payment Mode Share --}}
                    <section class="clr-card closing-insight-card">
                        <h3 class="clr-card-title">Payment Mode Share</h3>
                        <p class="clr-card-copy">Distribution of today's collected payments</p>
                        <div class="clr-share-list">
                            @forelse($paymentRows as $mode => $modeTotal)
                                @php
                                    $modePct = $paymentTotal > 0 ? round(((float) $modeTotal / $paymentTotal) * 100, 1) : 0;
                                    $modeBar = $modeTotal > 0 ? max($modePct, 2) : 0;
                                @endphp
                                <div class="clr-share-row">
                                    <div class="clr-share-head">
                                        <span class="clr-share-label">{{ ucfirst(str_replace('_', ' ', $mode)) }}</span>
                                        <span class="clr-share-val">₹{{ number_format((float) $modeTotal, 2) }} · {{ number_format($modePct, 1) }}%</span>
                                    </div>
                                    <div class="clr-track">
                                        <div class="clr-track-fill clr-track-fill--ink" style="width: {{ min($modeBar, 100) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="clr-card-copy">No payment mode data for this date.</p>
                            @endforelse
                        </div>
                    </section>

                    {{-- Collections Trend --}}
                    <section class="clr-card closing-insight-card">
                        <h3 class="clr-card-title">Collections Trend (Last 7 Days)</h3>
                        <p class="clr-card-copy">Sales + repairs ending on the selected date</p>
                        <div class="clr-trend-shell">
                            <div class="clr-trend-bars">
                                @foreach($trendRows as $point)
                                    @php
                                        $barHeight = $trendMax > 0 ? (($point['total'] / $trendMax) * 100) : 0;
                                        $barHeight = max($barHeight, 6);
                                        $isSelectedPoint = $point['date'] === $date;
                                    @endphp
                                    <div class="clr-trend-col">
                                        <div class="clr-trend-bar {{ $isSelectedPoint ? 'clr-trend-bar--active' : '' }}" style="height: {{ min($barHeight, 100) }}%"></div>
                                        <div class="clr-trend-day {{ $isSelectedPoint ? 'clr-trend-day--active' : '' }}">{{ $point['day'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="clr-trend-stats">
                            <div class="clr-trend-stat">
                                <p class="clr-mini-label">Today</p>
                                <p class="clr-mini-value">₹{{ number_format($todayTrendTotal, 2) }}</p>
                            </div>
                            <div class="clr-trend-stat">
                                <p class="clr-mini-label">Best Day</p>
                                <p class="clr-mini-value">₹{{ number_format((float) $trendMax, 2) }}</p>
                            </div>
                            <div class="clr-trend-stat">
                                <p class="clr-mini-label">7-Day Avg</p>
                                <p class="clr-mini-value">₹{{ number_format($trendAverage, 2) }}</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>

    <style>
        /* ── Daily Closing — calm teal/hairline system, one light theme ── */
        .clr-page {
            --clr-border:        #e7ebf1;
            --clr-border-soft:   #eef1f6;
            --clr-border-strong: #d9dfe8;
            --clr-ink:           #0f172a;
            --clr-ink-2:         #3d4861;
            --clr-muted:         #6a7588;
            --clr-accent:        #0d9488;
            --clr-accent-deep:   #0f766e;
            --clr-pos:           #0f766e;
            --clr-neg:           #b42318;
            --clr-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
            --clr-ease:          cubic-bezier(0.23,1,0.32,1);
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-width: 1360px;
        }

        @media (prefers-reduced-motion: no-preference) {
            .clr-page .clr-snapshot,
            .clr-page .clr-grid > *,
            .clr-page .clr-insight-row > * {
                animation: clrRise .5s var(--clr-ease) both;
            }
            .clr-page .clr-grid > *:nth-child(2) { animation-delay: .05s; }
            @keyframes clrRise {
                from { opacity: 0; transform: translateY(8px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        }

        .closing-print-head { display: none; }

        /* Header date input — recessed, teal focus (matches the system) */
        .clr-date {
            height: 40px;
            padding: 0 12px;
            border: 1px solid var(--clr-border-strong);
            border-radius: 10px;
            background: #f4f6fa;
            color: var(--clr-ink);
            font-size: 13px;
            transition: border-color .15s var(--clr-ease), box-shadow .15s var(--clr-ease), background-color .15s var(--clr-ease);
        }
        .clr-date:focus {
            border-color: var(--clr-accent-deep);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(15,118,110,.13);
            outline: none;
        }

        /* ── Snapshot KPI strip ── */
        .clr-snapshot {
            display: grid;
            border: 1px solid var(--clr-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--clr-shadow);
            overflow: hidden;
        }
        .clr-snapshot[data-cols="4"] { grid-template-columns: repeat(4, minmax(0,1fr)); }
        .clr-snapshot[data-cols="5"] { grid-template-columns: repeat(5, minmax(0,1fr)); }

        .clr-snap {
            padding: 16px 18px;
            border-right: 1px solid var(--clr-border);
        }
        .clr-snap:last-child { border-right: 0; }

        .clr-snap-label {
            margin: 0 0 6px;
            color: var(--clr-muted);
            font-size: 12px;
            font-weight: 500;
        }
        .clr-snap-value {
            margin: 0;
            color: var(--clr-ink);
            font-size: 20px;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: -.01em;
            font-variant-numeric: tabular-nums;
        }
        .clr-snap-value--accent { color: var(--clr-accent-deep); }
        .clr-snap-value--pos { color: var(--clr-pos); }
        .clr-snap-value--neg { color: var(--clr-neg); }
        .clr-snap-sub {
            margin: 3px 0 0;
            color: var(--clr-muted);
            font-size: 11.5px;
        }

        /* ── Panels grid ── */
        .clr-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 20px;
        }
        .clr-grid--single { grid-template-columns: 1fr; }

        .clr-panel {
            border: 1px solid var(--clr-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--clr-shadow);
            min-width: 0;
        }
        .clr-panel-head {
            padding: 20px 22px;
            border-bottom: 1px solid var(--clr-border-soft);
        }
        .clr-panel-title {
            margin: 0;
            color: var(--clr-ink);
            font-size: 15px;
            font-weight: 650;
            letter-spacing: -.01em;
        }
        .clr-panel-copy {
            margin: 4px 0 0;
            color: var(--clr-muted);
            font-size: 12.5px;
            line-height: 1.5;
        }
        .clr-panel-body { padding: 8px 22px 20px; }

        /* ── Ledger rows (no side stripes, no badges) ── */
        .clr-ledger-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 18px;
            padding: 13px 0;
            border-bottom: 1px solid var(--clr-border-soft);
        }
        .clr-ledger-row:last-child { border-bottom: 0; padding-bottom: 4px; }
        .clr-ledger-row--total {
            border-bottom: 0;
            border-top: 1px solid var(--clr-border);
            margin-top: 4px;
            padding-top: 15px;
        }
        .clr-ledger-label {
            color: var(--clr-ink-2);
            font-size: 13.5px;
            font-weight: 500;
        }
        .clr-ledger-sub {
            display: block;
            margin-top: 2px;
            color: var(--clr-muted);
            font-size: 11.5px;
            font-weight: 400;
        }
        .clr-ledger-value {
            flex-shrink: 0;
            color: var(--clr-ink);
            font-size: 15px;
            font-weight: 650;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .clr-ledger-value--total { font-size: 17px; font-weight: 700; }
        .clr-ledger-value--pos { color: var(--clr-pos); }
        .clr-ledger-value--neg { color: var(--clr-neg); }

        /* ── Payment mode breakdown ── */
        .clr-mode-block { margin-top: 20px; }
        .clr-subhead {
            margin: 0 0 12px;
            color: var(--clr-ink);
            font-size: 12.5px;
            font-weight: 650;
        }
        .clr-mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
            gap: 10px;
        }
        .clr-mode {
            border: 1px solid var(--clr-border);
            border-radius: 10px;
            background: #fafbfd;
            padding: 11px 12px;
            min-width: 0;
        }
        .clr-mode-label {
            margin: 0 0 4px;
            color: var(--clr-muted);
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .clr-mode-value {
            margin: 0;
            color: var(--clr-ink);
            font-size: 15px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        /* ── Retailer insight cards ── */
        .clr-insights {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .clr-insight-row { display: grid; gap: 20px; }
        .clr-insight-row--3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
        .clr-insight-row--2 { grid-template-columns: repeat(2, minmax(0,1fr)); }

        .clr-card {
            border: 1px solid var(--clr-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--clr-shadow);
            padding: 20px 22px;
            min-width: 0;
        }
        .clr-card-title {
            margin: 0;
            color: var(--clr-ink);
            font-size: 14px;
            font-weight: 650;
            letter-spacing: -.01em;
        }
        .clr-card-copy {
            margin: 4px 0 0;
            color: var(--clr-muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .clr-stack-bar {
            display: flex;
            height: 8px;
            width: 100%;
            margin-top: 16px;
            border-radius: 999px;
            overflow: hidden;
            background: #eceff4;
        }
        .clr-stack-fill { height: 100%; }
        .clr-stack-fill--ink { background: #1f2a44; }
        .clr-stack-fill--accent { background: var(--clr-accent); }

        .clr-mix-legend {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 14px;
            margin-top: 16px;
        }
        .clr-mini-label {
            margin: 0;
            color: var(--clr-muted);
            font-size: 11.5px;
            font-weight: 600;
        }
        .clr-mini-value {
            margin: 4px 0 0;
            color: var(--clr-ink);
            font-size: 15px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }
        .clr-mini-sub {
            margin: 2px 0 0;
            color: var(--clr-muted);
            font-size: 11.5px;
        }
        .clr-text-accent { color: var(--clr-accent-deep) !important; }
        .clr-text-neg { color: var(--clr-neg) !important; }

        .clr-hero-stat { margin-top: 18px; }
        .clr-hero-value {
            margin: 0;
            color: var(--clr-ink);
            font-size: 30px;
            font-weight: 700;
            line-height: 1.05;
            letter-spacing: -.02em;
            font-variant-numeric: tabular-nums;
        }
        .clr-hero-stat .clr-card-copy { margin-top: 8px; }

        .clr-discount-block { margin-top: 16px; }
        .clr-kv {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .clr-kv-value {
            color: var(--clr-ink);
            font-size: 15px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        .clr-track {
            height: 8px;
            width: 100%;
            border-radius: 999px;
            background: #eceff4;
            overflow: hidden;
        }
        .clr-track-fill { height: 100%; border-radius: 999px; }
        .clr-track-fill--ink { background: #1f2a44; }
        .clr-track-fill--neg { background: #e0584a; }
        .clr-discount-block .clr-mini-sub { margin-top: 8px; }

        .clr-share-list {
            margin-top: 16px;
            display: flex;
            flex-direction: column;
            gap: 13px;
        }
        .clr-share-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .clr-share-label {
            color: var(--clr-ink-2);
            font-size: 12.5px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .clr-share-val {
            color: var(--clr-muted);
            font-size: 12px;
            font-variant-numeric: tabular-nums;
        }

        /* ── Trend chart ── */
        .clr-trend-shell {
            margin-top: 16px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .clr-trend-bars {
            height: 168px;
            min-width: 320px;
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        .clr-trend-col {
            flex: 1;
            min-width: 36px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }
        .clr-trend-bar {
            width: 100%;
            max-width: 30px;
            border-radius: 6px 6px 0 0;
            background: #1f2a44;
        }
        .clr-trend-bar--active { background: var(--clr-accent); }
        .clr-trend-day {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--clr-muted);
        }
        .clr-trend-day--active { color: var(--clr-accent-deep); font-weight: 650; }

        .clr-trend-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .clr-trend-stat {
            border: 1px solid var(--clr-border);
            border-radius: 10px;
            background: #fafbfd;
            padding: 9px 11px;
        }
        .clr-trend-stat .clr-mini-value { margin-top: 3px; font-size: 13.5px; }

        @media (prefers-reduced-motion: no-preference) {
            .clr-track-fill, .clr-stack-fill { transition: width .6s var(--clr-ease); }
            .clr-trend-bar { transition: height .6s var(--clr-ease); }
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .clr-insight-row--3 { grid-template-columns: 1fr; }
            .clr-insight-row--2 { grid-template-columns: 1fr; }
            .clr-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            .clr-snapshot { grid-template-columns: repeat(2, minmax(0,1fr)) !important; }
            .clr-snap { border-bottom: 1px solid var(--clr-border); }
            .clr-snap:nth-child(2n) { border-right: 0; }
            .clr-snap:last-child { border-bottom: 0; }
            .clr-snap:nth-last-child(2):nth-child(odd) { border-bottom: 0; }
        }

        @media (max-width: 460px) {
            .clr-snapshot { grid-template-columns: 1fr !important; }
            .clr-snap { border-right: 0; border-bottom: 1px solid var(--clr-border); }
            .clr-snap:last-child { border-bottom: 0; }
            .clr-panel-head, .clr-panel-body, .clr-card { padding-left: 16px; padding-right: 16px; }
        }

        /* ── Mobile header layout (kept from original) ── */
        @media (max-width: 768px) {
            .closing-page-header {
                display: grid;
                grid-template-columns: 40px minmax(0, 1fr);
                column-gap: 8px;
                row-gap: 8px;
                align-items: center;
            }
            .closing-page-header .content-header-nav { grid-column: 1; grid-row: 1; margin-right: 0; padding-top: 0; }
            .closing-page-header > :nth-child(2) { grid-column: 2; grid-row: 1; min-width: 0; text-align: left; }
            .closing-page-header .page-title { margin: 0; font-size: 1rem; line-height: 1.25; white-space: normal; }
            .closing-page-header .page-subtitle { display: block; margin-top: 0.1rem; font-size: 0.72rem; line-height: 1.25; }
            .closing-page-header .page-actions { grid-column: 1 / -1; grid-row: 2; width: 100%; display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 6px; }
            .closing-page-header .closing-header-filter { width: auto; min-width: 0; }
            .closing-page-header .closing-header-date { min-width: 8.8rem; }
        }

        @media (max-width: 640px) {
            .closing-page-header .closing-header-filter { width: auto; justify-content: center; flex-wrap: nowrap; flex: 0 1 auto; }
            .closing-page-header .closing-header-date { width: 6.9rem; min-width: 6.9rem; max-width: 6.9rem; }
            .closing-page-header .page-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
            .closing-page-header .closing-print-btn { order: 2; }
            .closing-page-header .closing-pnl-btn { order: 3; flex-basis: 100%; width: max-content; }
        }

        /* ── Print ── */
        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
            .mobile-menu-btn, .sidebar-overlay, .sidebar { display: none !important; }
            .workspace, .content-area, .content-body { display: block !important; height: auto !important; overflow: visible !important; background: #fff !important; }
            .content-header { display: none !important; }
            .content-inner { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; gap: 0 !important; }

            .closing-print-head {
                display: flex !important;
                margin: 0 0 8mm 0;
                padding-bottom: 4mm;
                border-bottom: 2px solid #111827;
                justify-content: space-between;
                align-items: flex-start;
                gap: 8mm;
            }
            .closing-print-head h2 { margin: 0; font-size: 20px; font-weight: 700; color: #111827; }
            .closing-print-date { font-size: 11px; font-weight: 600; color: #111827; white-space: nowrap; }

            .clr-snapshot, .clr-insights { display: none !important; }

            .clr-grid { gap: 6mm !important; grid-template-columns: 1fr !important; }
            .clr-grid > * { break-inside: avoid; page-break-inside: avoid; }
            .clr-panel { border: 1px solid #d1d5db !important; box-shadow: none !important; border-radius: 0 !important; }
            .clr-panel-head { background: #fff !important; border-bottom: 1px solid #d1d5db !important; }
            .clr-ledger-value--pos, .clr-ledger-value--neg { color: #111827 !important; }
        }
    </style>

    @push('scripts')
    <script>
        (() => {
            const printTitle = "Daily Closing Report - {{ \Carbon\Carbon::parse($date)->format('Y-m-d') }} - {{ $shopName }}";
            document.title = printTitle;
            window.addEventListener('beforeprint', () => {
                document.title = printTitle;
            });
        })();
    </script>
    @endpush
</x-app-layout>
