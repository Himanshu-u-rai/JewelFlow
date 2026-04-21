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
                    <a href="{{ route('report.cash') }}" class="btn btn-secondary btn-sm report-cash-header-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm report-cash-header-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>View Date</button>
                @endif
            </form>
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm report-cash-header-btn report-cash-header-cashbook-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>Cashbook</a>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"/>
                        </svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
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
                                <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
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
            <button onclick="window.print()" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Report</button>
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>View Cash Ledger</a>
        </div>

        <div class="mt-6 bg-amber-50 border-l-4 border-amber-300 p-4 rounded-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
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
