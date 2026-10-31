<x-app-layout>
    <x-page-header class="pnl-page-header">
        <div>
            <h1 class="page-title">Profit & Loss Report</h1>
            <p class="text-sm text-gray-500 mt-1">A clear breakdown of sales, costs, and gross profit</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="/report/pnl" class="flex flex-wrap gap-3 items-end pnl-header-filter">
                <input type="date" name="date" value="{{ $date }}"
                       class="border-gray-300 rounded-md shadow-sm focus:border-amber-500 focus:ring-amber-500 text-sm pnl-header-date" style="height: 40px;">
                @if(request()->filled('date'))
                    <a href="{{ route('report.pnl') }}" class="btn btn-secondary btn-sm"><x-icon name="x" size="14" class="mr-1" />Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="calendar" size="14" class="mr-1" />View Date</button>
                @endif
            </form>
            <span class="header-badge">{{ $periodLabel }}</span>
        </div>
    </x-page-header>

    <div class="content-inner pnl-report-page">
        @php
            $profitMargin = $sales > 0 ? ($profit / $sales) * 100 : null;
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 pnl-kpi-grid">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 pnl-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                        <x-icon name="circle-dollar-sign" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Sales</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($sales, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 pnl-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                        <x-icon name="package" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Cost of Goods</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($goldValue, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 pnl-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="{{ $profit >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }} rounded-lg p-2">
                        <x-icon name="chart-column" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Gross Profit</p>
                        <p class="text-xl font-semibold {{ $profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">₹{{ number_format($profit, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 pnl-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-lg p-2">
                        <x-icon name="trending-up" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Profit Margin</p>
                        <p class="text-xl font-semibold text-gray-900">
                            {{ $profitMargin !== null ? number_format($profitMargin, 2) . '%' : '—' }}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">{{ $profitMargin !== null ? 'Of total sales' : 'No sales recorded' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Revenue Breakdown -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden pnl-panel">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <x-icon name="circle-dollar-sign" class="w-5 h-5 mr-2 text-amber-600" />
                        Revenue Breakdown
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">Income sources for selected date</p>
                </div>

                <div class="p-6 space-y-4">
                    <!-- Total Sales -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 border border-gray-200 rounded-lg pnl-stat-row">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 rounded-lg p-2">
                                <x-icon name="shopping-bag" class="w-5 h-5 text-amber-700" />
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-600">Total Sales</div>
                                <div class="text-xs text-gray-500">Overall revenue</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-900">₹{{ number_format($sales, 2) }}</div>
                            <div class="text-xs text-gray-500">100%</div>
                        </div>
                    </div>

                    <!-- Making Charges -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 border border-gray-200 rounded-lg pnl-stat-row">
                        <div class="flex items-center gap-3">
                            <div class="bg-slate-100 rounded-lg p-2">
                                <x-icon name="smile" class="w-5 h-5 text-slate-700" />
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-600">Making Charges</div>
                                <div class="text-xs text-gray-500">Craftsmanship fees</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-900">₹{{ number_format($making, 2) }}</div>
                            @if($sales > 0)
                                <div class="text-xs text-gray-500">{{ number_format(($making / $sales) * 100, 1) }}%</div>
                            @endif
                        </div>
                    </div>

                    <!-- Stone Charges -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 border border-gray-200 rounded-lg pnl-stat-row">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 rounded-lg p-2">
                                <x-icon name="sparkles" class="w-5 h-5 text-amber-700" />
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-600">Stone Charges</div>
                                <div class="text-xs text-gray-500">Gemstone value</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-900">₹{{ number_format($stones, 2) }}</div>
                            @if($sales > 0)
                                <div class="text-xs text-gray-500">{{ number_format(($stones / $sales) * 100, 1) }}%</div>
                            @endif
                        </div>
                    </div>

                    <!-- Wastage Recovered -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200 pnl-stat-row">
                        <div class="flex items-center gap-3">
                            <div class="bg-blue-100 rounded-full p-2">
                                <x-icon name="refresh-ccw" class="w-5 h-5 text-blue-700" />
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-600">Wastage Recovered</div>
                                <div class="text-xs text-gray-500">Gold wastage charges</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-900">₹{{ number_format($wastageRecovered, 2) }}</div>
                            @if($sales > 0)
                                <div class="text-xs text-gray-500">{{ number_format(($wastageRecovered / $sales) * 100, 1) }}%</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cost Breakdown -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden pnl-panel">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <x-icon name="credit-card" class="w-5 h-5 mr-2 text-amber-600" />
                        Cost Analysis
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">Expenses and material costs</p>
                </div>

                <div class="p-6 space-y-4">
                    <!-- Gold Cost -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 border border-gray-200 rounded-lg pnl-stat-row">
                        <div class="flex items-center gap-3">
                            <div class="bg-amber-100 rounded-lg p-2">
                                <x-icon name="package" class="w-5 h-5 text-amber-700" />
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-600">Cost of Goods (COGS)</div>
                                <div class="text-xs text-gray-500">Recorded cost of items sold</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-gray-900">₹{{ number_format($goldValue, 2) }}</div>
                            @if($sales > 0)
                                <div class="text-xs text-gray-500">{{ number_format(($goldValue / $sales) * 100, 1) }}%</div>
                            @endif
                        </div>
                    </div>

                    <!-- Gross Profit = Revenue − COGS -->
                    <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-700 mb-3">Gross Profit Calculation</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Net Sales (excl. GST, after returns)</span>
                                <span class="font-medium text-gray-900">₹{{ number_format($sales, 2) }}</span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-600">− Cost of Goods Sold</span>
                                <span class="font-medium text-rose-600">−₹{{ number_format($goldValue, 2) }}</span>
                            </div>
                            <div class="flex justify-between pt-2 text-base font-bold">
                                <span class="{{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">= Gross Profit</span>
                                <span class="{{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">₹{{ number_format($profit, 2) }}</span>
                            </div>
                        </div>
                        @if(($costUnknownLines ?? 0) > 0)
                            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
                                {{ $costUnknownLines }} of {{ $soldLineCount }} sold items have no recorded cost and are excluded from COGS — actual profit may be lower.
                            </p>
                        @endif
                    </div>

                    <!-- Performance Indicator -->
                    <div class="mt-4 p-4 rounded-lg {{ $profit > 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200' }} border">
                        <div class="flex items-center gap-2">
                            @if($profit > 0)
                                <x-icon name="badge-check" class="w-5 h-5 text-emerald-600" />
                                <span class="text-sm font-medium text-emerald-700">Profitable Day</span>
                            @else
                                <x-icon name="circle-alert" class="w-5 h-5 text-rose-600" />
                                <span class="text-sm font-medium text-rose-700">No Profit Recorded</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Metrics Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden pnl-table-card">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Detailed Metrics</h2>
                        <p class="text-sm text-gray-500 mt-1">Complete P&L breakdown</p>
                    </div>
                    <button onclick="window.print()" class="btn btn-secondary btn-sm">
                        <x-icon name="printer" class="w-4 h-4 mr-2" />
                        Print Report
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto pnl-table-shell">
                <table class="w-full pnl-data-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Metric</th>
                            <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">Amount</th>
                            <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">% of Sales</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">Total Sales</td>
                            <td class="px-6 py-4 text-sm text-right font-bold text-amber-700 whitespace-nowrap">₹{{ number_format($sales, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-600 whitespace-nowrap">100.0%</td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-700 pl-12">Cost of Goods Sold</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-900 whitespace-nowrap">₹{{ number_format($goldValue, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-600 whitespace-nowrap">
                                @if($sales > 0){{ number_format(($goldValue / $sales) * 100, 1) }}%@else-@endif
                            </td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-700 pl-12">Making Charges</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-900 whitespace-nowrap">₹{{ number_format($making, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-600 whitespace-nowrap">
                                @if($sales > 0){{ number_format(($making / $sales) * 100, 1) }}%@else-@endif
                            </td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-700 pl-12">Stone Charges</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-900 whitespace-nowrap">₹{{ number_format($stones, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-600 whitespace-nowrap">
                                @if($sales > 0){{ number_format(($stones / $sales) * 100, 1) }}%@else-@endif
                            </td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-700 pl-12">Wastage Recovered</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-900 whitespace-nowrap">₹{{ number_format($wastageRecovered, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right text-gray-600 whitespace-nowrap">
                                @if($sales > 0){{ number_format(($wastageRecovered / $sales) * 100, 1) }}%@else-@endif
                            </td>
                        </tr>
                        <tr class="{{ $profit >= 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200' }} border-t-2 font-bold">
                            <td class="px-6 py-4 text-sm {{ $profit >= 0 ? 'text-emerald-900' : 'text-rose-900' }}">Gross Profit</td>
                            <td class="px-6 py-4 text-sm text-right {{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }} text-lg whitespace-nowrap">₹{{ number_format($profit, 2) }}</td>
                            <td class="px-6 py-4 text-sm text-right {{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }} whitespace-nowrap">
                                @if($sales > 0){{ number_format(($profit / $sales) * 100, 1) }}%@else-@endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="mt-6 bg-amber-50 border border-amber-200 rounded-xl p-5 pnl-quick-links">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <x-icon name="info" class="h-5 w-5 text-amber-500" />
                </div>
                <div class="ml-3 flex-1">
                    <p class="text-sm text-amber-700 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span>View other reports:</span>
                        <a href="/report/daily" class="font-medium underline hover:text-amber-800">Daily Report</a>
                        <a href="/report/gold" class="font-medium underline hover:text-amber-800">Gold Report</a>
                        <a href="/report/cash" class="font-medium underline hover:text-amber-800">Cash Report</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <style>
        .pnl-report-page .pnl-kpi-card,
        .pnl-report-page .pnl-panel,
        .pnl-report-page .pnl-table-card,
        .pnl-report-page .pnl-stat-row {
            min-width: 0;
        }

        .pnl-report-page .pnl-kpi-card,
        .pnl-report-page .pnl-panel,
        .pnl-report-page .pnl-table-card {
            transition: border-color 160ms ease;
        }

        .pnl-report-page .pnl-kpi-card:hover,
        .pnl-report-page .pnl-panel:hover,
        .pnl-report-page .pnl-table-card:hover {
            border-color: #cbd5e1;
        }

        .pnl-report-page .pnl-table-shell {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .pnl-report-page .pnl-data-table {
            min-width: 700px;
        }

        @media (max-width: 768px) {
            .pnl-page-header .pnl-header-filter {
                width: 100%;
            }

            .pnl-page-header .pnl-header-date {
                min-width: 9.5rem;
            }

            .pnl-report-page .pnl-kpi-card,
            .pnl-report-page .pnl-panel > div,
            .pnl-report-page .pnl-table-card > div {
                padding-left: 0.95rem;
                padding-right: 0.95rem;
            }

            .pnl-report-page .pnl-stat-row {
                gap: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .pnl-report-page .pnl-stat-row {
                flex-wrap: wrap;
                align-items: flex-start;
            }

            .pnl-report-page .pnl-stat-row > div:last-child {
                width: 100%;
                text-align: left;
            }
        }

        @media print {
            .content-header,
            .sidebar,
            button,
            form {
                display: none !important;
            }
            
            .content-inner {
                padding: 0 !important;
            }
        }
    </style>
</x-app-layout>
