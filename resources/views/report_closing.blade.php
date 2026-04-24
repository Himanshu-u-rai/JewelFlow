<x-app-layout>
    <x-page-header class="closing-page-header" title="Daily Closing" :subtitle="'Summary for ' . $date">
        <x-slot:actions>
            <form method="GET" action="{{ route('report.closing') }}" class="inline-flex items-center gap-2 closing-header-filter">
                <input type="date" name="date" value="{{ $date }}" class="border border-slate-200 rounded-md px-3 py-2 text-sm bg-white shadow-sm focus:border-amber-500 focus:ring-amber-500 closing-header-date">
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

    <div class="content-inner closing-report-page">
        @php
            $reportDatePretty = \Carbon\Carbon::parse($date)->format('l, d M Y');
            $shopName = auth()->user()->shop->name ?? 'JewelFlow';
        @endphp

        <div class="closing-print-head">
            <h2>Daily Closing Report</h2>
            <span class="closing-print-date">Report Date: {{ $reportDatePretty }}</span>
        </div>

        @php
            $netGold = $goldIn - $goldOut - $wastage;
            $totalCash = $sales + $repairs;
            $isRetailer = auth()->user()->shop?->isRetailer();
        @endphp

        <div class="closing-spotlight">
            <div class="closing-spotlight-main">
                <p class="closing-spotlight-kicker">Operational Snapshot</p>
                <h2 class="closing-spotlight-title">{{ $isRetailer ? 'Retailer Daily Closing' : 'Manufacturer Daily Closing' }}</h2>
                <p class="closing-spotlight-subtitle">{{ $reportDatePretty }} · {{ $shopName }}</p>
            </div>
            <div class="closing-spotlight-metrics">
                <span class="closing-edition-pill {{ $isRetailer ? 'closing-edition-pill--retailer' : 'closing-edition-pill--manufacturer' }}">
                    {{ $isRetailer ? 'Retailer Service' : 'Manufacturer Service' }}
                </span>
                <span class="closing-spotlight-chip">Collections: ₹{{ number_format($totalCash, 2) }}</span>
                @if(!$isRetailer)
                    <span class="closing-spotlight-chip {{ $netGold >= 0 ? 'is-positive' : 'is-negative' }}">
                        Net Gold: {{ number_format($netGold, 6) }} g
                    </span>
                @endif
            </div>
        </div>

        <div class="closing-report-grid grid grid-cols-1 {{ $isRetailer ? '' : 'lg:grid-cols-2' }} gap-6">
            @if(!$isRetailer)
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden closing-panel-card">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Gold Summary</h2>
                    <p class="text-sm text-gray-500 mt-1">Fine gold movements for the day (grams)</p>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--gold-in" data-kpi-symbol="IN">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Gold In</div>
                            <div class="text-xl font-semibold text-gray-900">{{ number_format($goldIn, 6) }} g</div>
                            <div class="text-xs text-gray-500 mt-2">Buyback, advance, repair return</div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--gold-out" data-kpi-symbol="OUT">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Gold Out</div>
                            <div class="text-xl font-semibold text-gray-900">{{ number_format($goldOut, 6) }} g</div>
                            <div class="text-xs text-gray-500 mt-2">Sale, manufacture, repair issue</div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--wastage" data-kpi-symbol="WS">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Wastage</div>
                            <div class="text-xl font-semibold text-gray-900">{{ number_format($wastage, 6) }} g</div>
                            <div class="text-xs text-gray-500 mt-2">Recorded wastage</div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--net-gold" data-kpi-symbol="NET">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Net Movement</div>
                            <div class="text-xl font-semibold {{ $netGold >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($netGold, 6) }} g</div>
                            <div class="text-xs text-gray-500 mt-2">In − Out − Wastage</div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden closing-panel-card">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Cash Summary</h2>
                    <p class="text-sm text-gray-500 mt-1">Sales, repairs, and GST collected (GST included in sales total)</p>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--sales" data-kpi-symbol="S">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Sales (incl. GST)</div>
                            <div class="text-xl font-semibold text-gray-900">₹{{ number_format($sales, 2) }}</div>
                            <div class="text-xs text-gray-500 mt-2">{{ $invoiceCount }} {{ Str::plural('invoice', $invoiceCount) }}</div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--repairs" data-kpi-symbol="R">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Repairs</div>
                            <div class="text-xl font-semibold text-gray-900">₹{{ number_format($repairs, 2) }}</div>
                            <div class="text-xs text-gray-500 mt-2">Repair transactions</div>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--gst" data-kpi-symbol="GST">
                            <div class="text-xs uppercase tracking-wide text-gray-500">GST Collected</div>
                            <div class="text-xl font-semibold text-gray-900">₹{{ number_format($gst, 2) }}</div>
                            <div class="text-xs text-gray-500 mt-2">Already included in sales</div>
                        </div>

                        @if($discount > 0)
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--discount" data-kpi-symbol="D">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Discount Given</div>
                            <div class="text-xl font-semibold text-rose-600">−₹{{ number_format($discount, 2) }}</div>
                            <div class="text-xs text-gray-500 mt-2">Already deducted from sales</div>
                        </div>
                        @endif

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 closing-kpi-card closing-kpi-card--collections" data-kpi-symbol="TC">
                            <div class="text-xs uppercase tracking-wide text-gray-500">Total Collections</div>
                            <div class="text-xl font-semibold text-gray-900">₹{{ number_format($totalCash, 2) }}</div>
                            <div class="text-xs text-gray-500 mt-2">Sales + Repairs</div>
                        </div>
                    </div>

                    @if(!empty($paymentBreakdown) && count($paymentBreakdown) > 0)
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">Payment Mode Breakdown</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 closing-mode-grid">
                            @foreach($paymentBreakdown as $mode => $modeTotal)
                            <div class="bg-white border border-gray-200 rounded-lg p-3 closing-mode-card">
                                <div class="text-xs uppercase tracking-wide text-gray-500">{{ ucfirst(str_replace('_', ' ', $mode)) }}</div>
                                <div class="text-lg font-semibold text-gray-900">₹{{ number_format($modeTotal, 2) }}</div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>
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

            <div class="closing-retail-insights mt-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-4 closing-insight-card">
                        <h3 class="text-sm font-semibold text-gray-900">Sales vs Repairs Mix</h3>
                        <p class="text-xs text-gray-500 mt-1">Today's collection composition</p>
                        <div class="mt-4 h-3 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full flex">
                                <div class="bg-slate-900" style="width: {{ $salesShare }}%"></div>
                                <div class="bg-emerald-500" style="width: {{ $repairsShare }}%"></div>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide text-gray-500">Sales</p>
                                <p class="font-semibold text-slate-900">₹{{ number_format($sales, 2) }}</p>
                                <p class="text-xs text-gray-500">{{ number_format($salesShare, 1) }}%</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide text-gray-500">Repairs</p>
                                <p class="font-semibold text-emerald-700">₹{{ number_format($repairs, 2) }}</p>
                                <p class="text-xs text-gray-500">{{ number_format($repairsShare, 1) }}%</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-4 closing-insight-card">
                        <h3 class="text-sm font-semibold text-gray-900">Average Bill Value</h3>
                        <p class="text-xs text-gray-500 mt-1">Sales divided by finalized invoices</p>
                        <div class="mt-4">
                            <p class="text-3xl font-bold text-slate-900">₹{{ number_format($avgBillValue, 2) }}</p>
                            <p class="text-xs text-gray-500 mt-2">
                                Based on {{ number_format($invoiceCount) }} {{ Str::plural('invoice', $invoiceCount) }}
                            </p>
                        </div>
                    </div>

                    <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-4 closing-insight-card">
                        <h3 class="text-sm font-semibold text-gray-900">Discount Impact</h3>
                        <p class="text-xs text-gray-500 mt-1">How much margin was given away</p>
                        <div class="mt-4 space-y-2">
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs uppercase tracking-wide text-gray-500">Discount</span>
                                <span class="text-lg font-semibold text-rose-600">₹{{ number_format($discount, 2) }}</span>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs uppercase tracking-wide text-gray-500">Discount Rate</span>
                                <span class="text-sm font-semibold text-slate-900">{{ number_format($discountRate, 2) }}%</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-rose-500" style="width: {{ min($discountRate, 100) }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500">Pre-discount base: ₹{{ number_format($preDiscountSales, 2) }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-4 closing-insight-card">
                        <h3 class="text-sm font-semibold text-gray-900">Payment Mode Share</h3>
                        <p class="text-xs text-gray-500 mt-1">Distribution of today's collected payments</p>
                        <div class="mt-4 space-y-3">
                            @forelse($paymentRows as $mode => $modeTotal)
                                @php
                                    $modePct = $paymentTotal > 0 ? round(((float) $modeTotal / $paymentTotal) * 100, 1) : 0;
                                    $modeBar = $modeTotal > 0 ? max($modePct, 2) : 0;
                                @endphp
                                <div>
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="font-medium text-slate-700 uppercase tracking-wide">{{ ucfirst(str_replace('_', ' ', $mode)) }}</span>
                                        <span class="text-slate-600">₹{{ number_format((float) $modeTotal, 2) }} · {{ number_format($modePct, 1) }}%</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full bg-slate-900" style="width: {{ min($modeBar, 100) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">No payment mode data for this date.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="bg-white shadow-sm border border-gray-200 rounded-xl p-4 closing-insight-card">
                        <h3 class="text-sm font-semibold text-gray-900">Collections Trend (Last 7 Days)</h3>
                        <p class="text-xs text-gray-500 mt-1">Sales + repairs ending on selected date</p>
                        <div class="mt-4 overflow-x-auto closing-trend-shell">
                            <div class="h-44 min-w-[340px] flex items-end gap-2 closing-trend-bars">
                                @foreach($trendRows as $point)
                                    @php
                                        $barHeight = $trendMax > 0 ? (($point['total'] / $trendMax) * 100) : 0;
                                        $barHeight = max($barHeight, 6);
                                        $isSelectedPoint = $point['date'] === $date;
                                    @endphp
                                    <div class="flex-1 min-w-[38px] h-full flex flex-col items-center justify-end gap-2">
                                        <div class="w-full max-w-[30px] rounded-t-md {{ $isSelectedPoint ? 'bg-amber-500' : 'bg-slate-900/85' }}" style="height: {{ min($barHeight, 100) }}%"></div>
                                        <div class="text-[10px] uppercase tracking-wide {{ $isSelectedPoint ? 'text-amber-700 font-semibold' : 'text-slate-500' }}">{{ $point['day'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-md bg-slate-50 border border-slate-200 px-2 py-1.5">
                                <p class="uppercase tracking-wide text-slate-500">Today</p>
                                <p class="font-semibold text-slate-900">₹{{ number_format($todayTrendTotal, 2) }}</p>
                            </div>
                            <div class="rounded-md bg-slate-50 border border-slate-200 px-2 py-1.5">
                                <p class="uppercase tracking-wide text-slate-500">Best Day</p>
                                <p class="font-semibold text-slate-900">₹{{ number_format((float) $trendMax, 2) }}</p>
                            </div>
                            <div class="rounded-md bg-slate-50 border border-slate-200 px-2 py-1.5">
                                <p class="uppercase tracking-wide text-slate-500">7-Day Avg</p>
                                <p class="font-semibold text-slate-900">₹{{ number_format($trendAverage, 2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <style>
        .closing-report-page {
            --closing-charcoal: #1f2937;
            --closing-charcoal-soft: #243244;
            --closing-charcoal-border: #314156;
            --closing-charcoal-ink: #f8fafc;
            --closing-charcoal-muted: #b8c7db;
            --closing-palette-ink-deep: #0d1f23;
            --closing-palette-ink: #132e35;
            --closing-palette-mid: #2d4a53;
            --closing-palette-muted: #69818d;
            --closing-palette-soft: #afb3b7;
            --closing-palette-smoke: #5a636a;
        }

        .closing-print-head {
            display: none;
        }

        .closing-report-page {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .closing-spotlight {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.1rem;
            border: 1px solid var(--closing-charcoal-border);
            border-radius: 1rem;
            background: var(--closing-charcoal);
            box-shadow: 0 14px 24px -18px rgba(2, 6, 23, 0.7);
        }

        .closing-spotlight-main {
            min-width: 0;
        }

        .closing-spotlight-kicker {
            margin: 0;
            font-size: 0.67rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--closing-charcoal-muted);
        }

        .closing-spotlight-title {
            margin: 0.2rem 0 0;
            font-size: 1.05rem;
            line-height: 1.25;
            font-weight: 700;
            color: var(--closing-charcoal-ink);
        }

        .closing-spotlight-subtitle {
            margin: 0.35rem 0 0;
            font-size: 0.8rem;
            color: var(--closing-charcoal-muted);
        }

        .closing-spotlight-metrics {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 0.45rem;
            min-width: 0;
        }

        .closing-edition-pill,
        .closing-spotlight-chip {
            display: inline-flex;
            align-items: center;
            min-height: 1.95rem;
            padding: 0.35rem 0.62rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .closing-edition-pill--retailer {
            background: var(--closing-charcoal-soft);
            color: var(--closing-charcoal-ink);
            border-color: #22c55e;
        }

        .closing-edition-pill--manufacturer {
            background: var(--closing-charcoal-soft);
            color: var(--closing-charcoal-ink);
            border-color: #f59e0b;
        }

        .closing-spotlight-chip {
            background: var(--closing-charcoal-soft);
            color: var(--closing-charcoal-ink);
            border-color: var(--closing-charcoal-border);
        }

        .closing-spotlight-chip.is-positive {
            background: var(--closing-charcoal-soft);
            border-color: #86efac;
            color: #bbf7d0;
        }

        .closing-spotlight-chip.is-negative {
            background: var(--closing-charcoal-soft);
            border-color: #fda4af;
            color: #fecdd3;
        }

        .closing-report-grid {
            margin-top: 0.1rem;
        }

        .closing-report-page .closing-panel-card,
        .closing-report-page .closing-kpi-card,
        .closing-report-page .closing-insight-card,
        .closing-report-page .closing-mode-card {
            min-width: 0;
        }

        .closing-report-page .closing-panel-card {
            border-color: #dbe4ef;
            border-radius: 1rem;
            box-shadow: 0 14px 26px -24px rgba(15, 23, 42, 0.65);
            background: #ffffff;
        }

        .closing-report-page .closing-panel-card > .border-b {
            background: #f8fafc;
        }

        .closing-report-page .closing-kpi-card {
            position: relative;
            overflow: hidden;
            border-color: #dbe4ef;
            border-left-width: 4px;
            border-radius: 0.8rem;
            background: #ffffff;
            padding-left: 3.2rem;
            transition: border-color 160ms ease, transform 160ms ease, box-shadow 160ms ease;
        }

        .closing-report-page .closing-kpi-card::before {
            content: attr(data-kpi-symbol);
            position: absolute;
            left: 0.8rem;
            top: 0.8rem;
            width: 1.8rem;
            height: 1.8rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.07em;
            color: var(--closing-palette-soft);
            background: var(--closing-palette-ink);
            border: 1px solid var(--closing-palette-mid);
        }

        .closing-report-page .closing-kpi-card:hover {
            transform: translateY(-1px);
            border-color: #c0cedf;
            box-shadow: 0 10px 20px -18px rgba(15, 23, 42, 0.85);
        }

        .closing-kpi-card--gold-in,
        .closing-kpi-card--sales,
        .closing-kpi-card--collections {
            border-left-color: var(--closing-palette-mid);
        }

        .closing-kpi-card--gold-in::before,
        .closing-kpi-card--sales::before,
        .closing-kpi-card--collections::before {
            background: var(--closing-palette-ink);
            border-color: var(--closing-palette-mid);
            color: var(--closing-palette-soft);
        }

        .closing-kpi-card--gold-out,
        .closing-kpi-card--discount {
            border-left-color: var(--closing-palette-smoke);
        }

        .closing-kpi-card--gold-out::before,
        .closing-kpi-card--discount::before {
            background: var(--closing-palette-smoke);
            border-color: var(--closing-palette-mid);
            color: #e5e7eb;
        }

        .closing-kpi-card--wastage,
        .closing-kpi-card--gst {
            border-left-color: var(--closing-palette-muted);
        }

        .closing-kpi-card--wastage::before,
        .closing-kpi-card--gst::before {
            background: var(--closing-palette-muted);
            border-color: var(--closing-palette-mid);
            color: #f8fafc;
        }

        .closing-kpi-card--net-gold,
        .closing-kpi-card--repairs {
            border-left-color: var(--closing-palette-ink-deep);
        }

        .closing-kpi-card--net-gold::before,
        .closing-kpi-card--repairs::before {
            background: var(--closing-palette-ink-deep);
            border-color: var(--closing-palette-mid);
            color: var(--closing-palette-soft);
        }

        .closing-kpi-card .text-emerald-600,
        .closing-kpi-card .text-rose-600 {
            color: var(--closing-palette-mid) !important;
        }

        .closing-report-page .closing-insight-card {
            border-color: #dbe4ef;
            box-shadow: 0 12px 22px -22px rgba(15, 23, 42, 0.72);
            background: #ffffff;
            transition: border-color 160ms ease;
        }

        .closing-report-page .closing-insight-card:hover,
        .closing-report-page .closing-mode-card:hover {
            border-color: #c0cedf;
        }

        .closing-report-page .closing-mode-grid {
            align-items: stretch;
        }

        .closing-report-page .closing-mode-card {
            background: #ffffff;
            border-color: #dbe4ef;
        }

        .closing-report-page .closing-trend-shell {
            width: 100%;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 0.15rem;
        }

        .closing-report-page .closing-trend-bars {
            width: 100%;
        }

        @media (max-width: 1024px) {
            .closing-report-page .closing-kpi-card {
                padding: 0.85rem;
                padding-left: 2.9rem;
            }
        }

        @media (max-width: 768px) {
            .closing-page-header {
                display: grid;
                grid-template-columns: 40px minmax(0, 1fr);
                column-gap: 8px;
                row-gap: 8px;
                align-items: center;
            }

            .closing-page-header .content-header-nav {
                grid-column: 1;
                grid-row: 1;
                margin-right: 0;
                padding-top: 0;
            }

            .closing-page-header > :nth-child(2) {
                grid-column: 2;
                grid-row: 1;
                min-width: 0;
                text-align: left;
            }

            .closing-page-header .page-title {
                margin: 0;
                font-size: 1rem;
                line-height: 1.25;
                white-space: normal;
            }

            .closing-page-header .page-subtitle {
                display: block;
                margin-top: 0.1rem;
                font-size: 0.72rem;
                line-height: 1.25;
            }

            .closing-page-header .page-actions {
                grid-column: 1 / -1;
                grid-row: 2;
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                align-items: center;
                gap: 6px;
            }

            .closing-page-header .closing-header-filter {
                width: auto;
                min-width: 0;
            }

            .closing-page-header .closing-header-date {
                min-width: 8.8rem;
            }
        }

        @media (max-width: 640px) {
            .closing-spotlight {
                flex-direction: column;
                padding: 0.85rem;
            }

            .closing-spotlight-title {
                font-size: 0.95rem;
            }

            .closing-spotlight-subtitle {
                font-size: 0.75rem;
            }

            .closing-spotlight-metrics {
                justify-content: flex-start;
            }

            .closing-page-header .closing-header-filter {
                width: auto;
                justify-content: center;
                flex-wrap: nowrap;
                flex: 0 1 auto;
            }

            .closing-page-header .closing-header-date {
                width: 6.9rem;
                min-width: 6.9rem;
                max-width: 6.9rem;
            }

            .closing-page-header .page-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
            }

            .closing-page-header .closing-print-btn {
                order: 2;
            }

            .closing-page-header .closing-pnl-btn {
                order: 3;
                flex-basis: 100%;
                width: max-content;
            }

            .closing-report-grid {
                gap: 1rem;
            }

            .closing-report-page .closing-panel-card > div,
            .closing-report-page .closing-insight-card {
                padding-left: 0.875rem;
                padding-right: 0.875rem;
            }

            .closing-report-page .closing-kpi-card {
                padding-left: 2.65rem;
            }

            .closing-report-page .closing-kpi-card::before {
                left: 0.62rem;
                top: 0.72rem;
                width: 1.58rem;
                height: 1.58rem;
                font-size: 0.5rem;
            }

            .closing-report-page .closing-mode-grid {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        @media print {
            @page {
                size: A4;
                margin: 12mm;
            }

            html,
            body {
                background: #fff !important;
                height: auto !important;
                overflow: visible !important;
            }

            .mobile-menu-btn,
            .sidebar-overlay,
            .sidebar {
                display: none !important;
            }

            .workspace,
            .content-area,
            .content-body {
                display: block !important;
                height: auto !important;
                overflow: visible !important;
                background: #fff !important;
            }

            .content-header {
                display: none !important;
            }

            .content-inner {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                gap: 0 !important;
            }

            .closing-print-head {
                display: flex !important;
                margin: 0 0 8mm 0;
                padding-bottom: 4mm;
                border-bottom: 2px solid #111827;
                justify-content: space-between;
                align-items: flex-start;
                gap: 8mm;
            }

            .closing-print-head h2 {
                margin: 0;
                font-size: 20px;
                font-weight: 700;
                color: #111827;
                letter-spacing: 0;
            }

            .closing-print-date {
                font-size: 11px;
                font-weight: 600;
                color: #111827;
                white-space: nowrap;
            }

            .closing-report-grid {
                gap: 6mm !important;
            }

            .closing-report-grid > * {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .closing-report-grid .bg-white {
                border: 1px solid #d1d5db !important;
                box-shadow: none !important;
            }

            .closing-report-grid .border-b {
                border-bottom: 1px solid #d1d5db !important;
            }

            .closing-report-grid .bg-gray-50 {
                background: #fff !important;
            }

            .closing-spotlight,
            .closing-kpi-card::before {
                display: none !important;
            }

            .closing-kpi-card {
                padding-left: 1rem !important;
            }

            .closing-report-grid .text-emerald-600,
            .closing-report-grid .text-rose-600 {
                color: #111827 !important;
            }

            .closing-retail-insights {
                display: none !important;
            }
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
