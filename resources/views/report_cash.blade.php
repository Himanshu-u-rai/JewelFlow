<x-app-layout>
    <x-page-header class="report-cash-header">
        <div>
            <h1 class="page-title">Cash Flow Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Daily inflow and outflow summary</p>
        </div>
        <div class="page-actions flex flex-wrap items-end gap-2 report-cash-header-actions">
            <form method="GET" action="{{ route('report.cash') }}" class="flex flex-wrap gap-2 items-end report-cash-header-form">
                <input
                    type="date"
                    name="date"
                    value="{{ $date ?? now()->toDateString() }}"
                    class="rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-sm report-cash-header-date"
                    style="height: 40px;"
                >
                @if(request()->filled('date'))
                    <a href="{{ route('report.cash') }}" class="btn btn-secondary btn-sm report-cash-header-btn"><x-icon name="x" size="14" class="mr-1" />Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm report-cash-header-btn"><x-icon name="calendar" size="14" class="mr-1" />View Date</button>
                @endif
            </form>
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm report-cash-header-btn report-cash-header-cashbook-btn"><x-icon name="book-open" size="14" class="mr-1" />Cashbook</a>
            <span class="header-badge report-cash-header-badge">{{ \Carbon\Carbon::parse($date ?? now()->toDateString())->format('d M Y') }}</span>
        </div>
    </x-page-header>

    <div class="content-inner report-cash-page">
        @php
            $cashIn = $rows->where('type', 'in')->first()->total ?? 0;
            $cashOut = $rows->where('type', 'out')->first()->total ?? 0;
            $netCash = $cashIn - $cashOut;
            $maxAmount = max($cashIn, $cashOut, 1);
            $inPercent = ($cashIn / $maxAmount) * 100;
            $outPercent = ($cashOut / $maxAmount) * 100;
            $ratio = $cashOut > 0 ? $cashIn / $cashOut : null;
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 report-cash-kpi-grid">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 report-cash-kpi-card report-cash-kpi-card--in">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-lg p-2 report-cash-kpi-icon">
                        <x-icon name="arrow-left" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Cash In</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($cashIn, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 report-cash-kpi-card report-cash-kpi-card--out">
                <div class="flex items-center gap-3">
                    <div class="bg-rose-100 text-rose-700 rounded-lg p-2 report-cash-kpi-icon">
                        <x-icon name="arrow-right" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Cash Out</p>
                        <p class="text-xl font-semibold text-gray-900">₹{{ number_format($cashOut, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 report-cash-kpi-card report-cash-kpi-card--net">
                <div class="flex items-center gap-3">
                    <div class="{{ $netCash >= 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }} rounded-lg p-2 report-cash-kpi-icon">
                        <x-icon name="chart-column" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Net Cash</p>
                        <p class="text-xl font-semibold {{ $netCash >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $netCash >= 0 ? '+' : '' }}₹{{ number_format($netCash, 2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 report-cash-kpi-card report-cash-kpi-card--ratio">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2 report-cash-kpi-icon">
                        <x-icon name="credit-card" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">In/Out Ratio</p>
                        <p class="text-xl font-semibold text-gray-900">
                            {{ $ratio !== null ? number_format($ratio, 2) . 'x' : '—' }}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">{{ $ratio !== null ? 'Cash in vs cash out' : 'No cash out recorded' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 report-cash-surface-card">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Cash Flow Comparison</h2>
                    <p class="text-sm text-gray-500 mt-1">Inflow vs outflow for the selected date</p>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium text-gray-700">Cash In</span>
                            <span class="font-semibold text-emerald-600">₹{{ number_format($cashIn, 2) }}</span>
                        </div>
                        <div class="h-8 bg-gray-100 rounded-lg overflow-hidden">
                            <div class="h-full rounded-lg bg-emerald-500" style="width: {{ $inPercent }}%;"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium text-gray-700">Cash Out</span>
                            <span class="font-semibold text-rose-600">₹{{ number_format($cashOut, 2) }}</span>
                        </div>
                        <div class="h-8 bg-gray-100 rounded-lg overflow-hidden">
                            <div class="h-full rounded-lg bg-rose-500" style="width: {{ $outPercent }}%;"></div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-200">
                        <div class="flex justify-between items-center">
                            <span class="font-semibold text-gray-900">Net Result</span>
                            <span class="text-2xl font-bold {{ $netCash >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ $netCash >= 0 ? '+' : '' }}₹{{ number_format($netCash, 2) }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            @if($netCash > 0)
                                Positive cash position with higher inflow.
                            @elseif($netCash < 0)
                                Outflow exceeds inflow. Review expenses.
                            @else
                                Cash inflow equals outflow. Balanced position.
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 report-cash-surface-card">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Daily Insight</h2>
                    <p class="text-sm text-gray-500 mt-1">Key figures for the day</p>
                </div>
                <div class="p-6 space-y-4 text-sm">
                    <div class="flex justify-between text-gray-600">
                        <span>Cash In</span>
                        <span class="font-semibold text-gray-900">₹{{ number_format($cashIn, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span>Cash Out</span>
                        <span class="font-semibold text-gray-900">₹{{ number_format($cashOut, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span>Net Cash</span>
                        <span class="font-semibold {{ $netCash >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $netCash >= 0 ? '+' : '' }}₹{{ number_format($netCash, 2) }}
                        </span>
                    </div>
                    <div class="rounded-lg border {{ $netCash >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }} p-4">
                        <div class="flex items-center gap-2">
                            @if($netCash >= 0)
                                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span class="text-sm font-medium text-emerald-700">Healthy cash inflow today.</span>
                            @else
                                <x-icon name="circle-alert" class="w-5 h-5 text-rose-600" />
                                <span class="text-sm font-medium text-rose-700">More cash out than in today.</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mt-2">Use the ledger for transaction-level details.</p>
                    </div>
                </div>
            </div>
        </div>

        @if(!empty($modeBreakdown) && count($modeBreakdown) > 0)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 report-cash-surface-card">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Payment Mode Breakdown</h2>
                <p class="text-sm text-gray-500 mt-1">Collections by payment method</p>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach($modeBreakdown as $mode => $modeTotal)
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 report-cash-mode-card">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ ucfirst(str_replace('_', ' ', $mode)) }}</div>
                        <div class="text-xl font-semibold text-gray-900 mt-1">₹{{ number_format($modeTotal, 2) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <button onclick="window.print()" class="btn btn-secondary btn-sm"><x-icon name="printer" size="14" class="mr-1" />Print Report</button>
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm"><x-icon name="book-open" size="14" class="mr-1" />View Cash Ledger</a>
        </div>

        <div class="mt-6 bg-amber-50 border-l-4 border-amber-300 p-4 rounded-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <x-icon name="info" class="h-5 w-5 text-amber-500" />
                </div>
                <div class="ml-3 flex-1">
                    <p class="text-sm text-amber-700">
                        Need transaction details? Open the
                        <a href="{{ route('cashbook.index') }}" class="font-medium underline hover:text-amber-800">Cash Ledger</a>
                        to view each entry.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <style>
        @media print {
            .content-header,
            .sidebar,
            button,
            a {
                display: none !important;
            }

            .content-inner {
                padding: 0 !important;
            }
        }
    </style>
</x-app-layout>
