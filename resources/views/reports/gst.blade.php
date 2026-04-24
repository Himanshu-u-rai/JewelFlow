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
                <span class="header-badge gst-period-badge gst-period-badge-mobile">{{ \Carbon\Carbon::create()->month($safeMonth)->format('F') }} {{ $safeYear }}</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">Monthly GST summary and rate-wise breakdown</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.gst') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="compact">
                <select name="month" class="gst-month-select rounded-full border-slate-200 bg-white shadow-sm text-sm h-10 pl-2.5 pr-2 w-[5.95rem] sm:w-[6rem] focus:border-amber-500 focus:ring-amber-500">
                    @for($i = 1; $i <= 12; $i++)
                        <option value="{{ $i }}" {{ $safeMonth === $i ? 'selected' : '' }}>
                            {{ \Carbon\Carbon::create()->month($i)->format('F') }}
                        </option>
                    @endfor
                </select>
                <select name="year" class="gst-year-select rounded-full border-slate-200 bg-white shadow-sm text-sm h-10 px-3 w-[5.25rem] focus:border-amber-500 focus:ring-amber-500">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                        <option value="{{ $y }}" {{ $safeYear === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                @if(request()->hasAny(['month', 'year']))
                    <a href="{{ route('report.gst') }}" class="btn btn-secondary btn-sm gst-view-toggle-btn" title="Clear" aria-label="Clear">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <span class="gst-action-label">Clear</span>
                    </a>
                @else
                    <button type="submit" class="btn btn-success btn-sm gst-view-toggle-btn" title="View" aria-label="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span class="gst-action-label">View</span>
                    </button>
                @endif
                <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm gst-print-btn" title="Print" aria-label="Print">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="gst-action-icon"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    <span class="gst-action-label">Print</span>
                </button>
            </form>
            <span class="header-badge gst-period-badge gst-period-badge-desktop">{{ \Carbon\Carbon::create()->month($safeMonth)->format('F') }} {{ $safeYear }}</span>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page jf-skeleton-host is-loading">
        <div class="gst-print-head">
            <h2>GST Report</h2>
            <div class="gst-print-head-right">Report Period: {{ $reportPeriod }}</div>
        </div>

        <div class="gst-print-subhead">{{ $shopName }} · Report Date: {{ $reportDate }}</div>

        <div class="gst-print-summary">
            <table>
                <tr>
                    <td>Total Sales</td>
                    <td>₹{{ number_format($totalSales, 2) }}</td>
                    <td>Taxable Amount</td>
                    <td>₹{{ number_format($taxableAmount, 2) }}</td>
                </tr>
                <tr>
                    <td>GST Collected</td>
                    <td>₹{{ number_format($gstCollected, 2) }}</td>
                    <td>Invoices</td>
                    <td>{{ $invoiceCount }}</td>
                </tr>
            </table>
        </div>

        <!-- Summary Cards -->
        <div class="gst-screen-summary grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-lg p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2a4 4 0 014-4h4M9 17H7a2 2 0 01-2-2V7a2 2 0 012-2h6l6 6v4a2 2 0 01-2 2h-2" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Sales</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">₹{{ number_format($totalSales, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-100 text-blue-700 rounded-lg p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h6M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Taxable Amount</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">₹{{ number_format($taxableAmount, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">GST Collected</p>
                        <p class="text-xl font-semibold text-emerald-600 jf-skel jf-skel-value">₹{{ number_format($gstCollected, 2) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m2 9H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Invoices</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">{{ $invoiceCount }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="gst-main-grid grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main GST Breakdown Table (2 columns) -->
            <div class="gst-breakdown-panel lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">GST Breakdown by Rate</h2>
                    <p class="text-xs text-gray-500 mt-1">Itemized tax collection details</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3.5 text-left text-xs font-medium text-gray-500 uppercase leading-5">Rate</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">Taxable</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">Discount</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">CGST</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">SGST</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">Total GST</th>
                                <th class="px-4 py-3.5 text-right text-xs font-medium text-gray-500 uppercase leading-5">Total</th>
                                <th class="px-4 py-3.5 text-center text-xs font-medium text-gray-500 uppercase leading-5">Count</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($gstBreakdown as $row)
                                @php
                                    // Use subtraction for SGST so CGST + SGST always equals Total GST exactly,
                                    // avoiding the rounding error where round(x/2)*2 ≠ x for odd paise.
                                    $cgst = round((float) $row->gst / 2, 2);
                                    $sgst = round((float) $row->gst - $cgst, 2);
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                            {{ number_format($row->gst_rate, 2) }}%
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm text-gray-900 leading-5">
                                        ₹{{ number_format($row->taxable, 2) }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm text-rose-600 leading-5">
                                        {{ $row->discount > 0 ? '−₹' . number_format($row->discount, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm text-emerald-600 leading-5">
                                        ₹{{ number_format($cgst, 2) }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm text-emerald-600 leading-5">
                                        ₹{{ number_format($sgst, 2) }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm font-medium text-emerald-600 leading-5">
                                        ₹{{ number_format($row->gst, 2) }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right text-sm font-semibold text-gray-900 leading-5">
                                        ₹{{ number_format($row->total, 2) }}
                                    </td>
                                    <td class="px-4 py-3.5 whitespace-nowrap text-center text-sm text-gray-600 leading-5">
                                        {{ $row->count }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                        <div class="text-sm leading-relaxed">No GST transactions for this period</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if($gstBreakdown->isNotEmpty())
                            @php
                                $totalCgst = round($gstCollected / 2, 2);
                                $totalSgst = round($gstCollected - $totalCgst, 2);
                            @endphp
                            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                                <tr class="font-bold">
                                    <td class="px-4 py-3.5 text-xs text-gray-900 uppercase leading-5">Total</td>
                                    <td class="px-4 py-3.5 text-right text-sm leading-5">₹{{ number_format($taxableAmount, 2) }}</td>
                                    <td class="px-4 py-3.5 text-right text-sm text-rose-600 leading-5">{{ $totalDiscount > 0 ? '−₹' . number_format($totalDiscount, 2) : '—' }}</td>
                                    <td class="px-4 py-3.5 text-right text-sm text-emerald-700 leading-5">₹{{ number_format($totalCgst, 2) }}</td>
                                    <td class="px-4 py-3.5 text-right text-sm text-emerald-700 leading-5">₹{{ number_format($totalSgst, 2) }}</td>
                                    <td class="px-4 py-3.5 text-right text-sm text-emerald-700 leading-5">₹{{ number_format($gstCollected, 2) }}</td>
                                    <td class="px-4 py-3.5 text-right text-sm leading-5">₹{{ number_format($totalSales, 2) }}</td>
                                    <td class="px-4 py-3.5 text-center text-sm leading-5">{{ $invoiceCount }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            <!-- GSTR-1 Summary Sidebar (1 column) -->
            <div class="gst-side-panel lg:col-span-1 space-y-4">
                <!-- B2C Sales -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h3 class="font-semibold text-gray-900 text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            B2C Sales (Small)
                        </h3>
                    </div>
                    <div class="p-5 space-y-3 text-sm leading-5">
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Invoices:</span>
                            <span class="font-medium text-right">{{ $invoiceCount }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Taxable:</span>
                            <span class="font-medium text-right">₹{{ number_format($taxableAmount, 2) }}</span>
                        </div>
                        @if($totalDiscount > 0)
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Discount:</span>
                            <span class="font-medium text-rose-600 text-right">−₹{{ number_format($totalDiscount, 2) }}</span>
                        </div>
                        @endif
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">CGST:</span>
                            <span class="font-medium text-emerald-600 text-right">₹{{ number_format($gstCollected / 2, 2) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">SGST:</span>
                            <span class="font-medium text-emerald-600 text-right">₹{{ number_format($gstCollected / 2, 2) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Total Tax:</span>
                            <span class="font-medium text-emerald-600 text-right">₹{{ number_format($gstCollected, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- HSN Summary -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h3 class="font-semibold text-gray-900 text-sm flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            HSN Summary
                        </h3>
                    </div>
                    <div class="p-5 space-y-3 text-sm leading-5">
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">HSN:</span>
                            <span class="font-medium text-right">7113</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Description:</span>
                            <span class="font-medium text-right text-xs leading-relaxed max-w-[60%]">Gold Jewellery</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Invoices:</span>
                            <span class="font-medium text-right">{{ $invoiceCount }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-600">Value:</span>
                            <span class="font-medium text-right">₹{{ number_format($totalSales, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Filing Info -->
                <div class="bg-amber-50 border-l-4 border-amber-400 p-5 rounded-lg text-sm">
                    <div class="flex gap-2">
                        <svg class="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="font-medium text-amber-800 text-xs mb-2">Filing Reminder</p>
                            <ul class="text-xs text-amber-700 space-y-1.5 leading-relaxed">
                                <li>• File GSTR-1 by 11th</li>
                                <li>• Verify all invoices</li>
                                <li>• Consult CA if needed</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">Tax Liability</p>
                    <p class="text-2xl font-bold text-emerald-600">₹{{ number_format($gstCollected, 2) }}</p>
                    <p class="text-xs mt-2 leading-relaxed text-gray-500">
                        @if($taxableAmount > 0)
                            Effective Rate: {{ number_format(($gstCollected / $taxableAmount) * 100, 2) }}%
                        @else
                            No sales this period
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="gst-quick-links mt-6 bg-amber-50 border-l-4 border-amber-400 p-5 rounded-lg">
            @php
                $isRetailer = auth()->user()->shop?->isRetailer();
            @endphp
            @if($isRetailer)
                <div class="flex items-center justify-between gap-3 mb-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-amber-800">Retail Shortcuts</p>
                    <span class="text-[11px] text-amber-700">Quick jump</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('cashbook.index') }}"
                       class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 transition-colors hover:bg-amber-100 hover:border-amber-300">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                            </svg>
                        </span>
                        Cash Ledger
                    </a>

                    <a href="{{ route('report.closing') }}"
                       class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 transition-colors hover:bg-slate-100 hover:border-slate-300">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-slate-700">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 11 12 14 22 4"/>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                            </svg>
                        </span>
                        Daily Closing
                    </a>
                </div>
            @else
                <div class="flex items-center text-sm gap-2">
                    <svg class="h-4 w-4 text-amber-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    <p class="text-amber-700 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ route('report.daily') }}" class="font-medium underline hover:text-amber-800">Daily</a>
                        <a href="{{ route('report.cash') }}" class="font-medium underline hover:text-amber-800">Cash</a>
                        <a href="{{ route('report.pnl') }}" class="font-medium underline hover:text-amber-800">P&amp;L</a>
                        <a href="{{ route('report.gold') }}" class="font-medium underline hover:text-amber-800">Gold</a>
                    </p>
                </div>
            @endif
        </div>
    </div>

    <style>
        .gst-print-head,
        .gst-print-subhead,
        .gst-print-summary {
            display: none;
        }

        .gst-page-header .gst-action-icon {
            margin-right: 4px;
        }

        @media (max-width: 768px) {
            .content-header.gst-page-header.ops-treatment-header {
                flex-wrap: wrap;
                align-items: center;
            }

            .content-header.gst-page-header.ops-treatment-header > :nth-child(2) {
                flex: 1 1 calc(100% - 40px);
                min-width: 0;
            }

            .content-header.gst-page-header.ops-treatment-header .page-actions {
                flex: 1 0 100%;
                width: calc(100% - 40px);
                max-width: calc(100% - 40px);
                margin-left: 40px;
                justify-content: flex-start;
                align-items: center;
            }

            .content-header.gst-page-header.ops-treatment-header .page-actions form {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                width: 100%;
            }

            .gst-page-header .gst-view-toggle-btn,
            .gst-page-header .gst-print-btn {
                width: 34px;
                min-width: 34px;
                min-height: 34px;
                padding: 0;
                justify-content: center;
            }

            .gst-page-header .gst-action-label {
                display: none;
            }

            .gst-page-header .gst-action-icon {
                width: 14px;
                height: 14px;
                margin-right: 0;
            }

        }

        @media (max-width: 480px) {
            .content-header.gst-page-header.ops-treatment-header .page-actions {
                width: calc(100% - 36px);
                max-width: calc(100% - 36px);
                margin-left: 36px;
            }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            html,
            body {
                background: #fff !important;
                height: auto !important;
                overflow: visible !important;
            }

            .mobile-menu-btn,
            .sidebar-overlay,
            .sidebar,
            .content-header,
            .gst-screen-summary,
            .gst-side-panel,
            .gst-quick-links {
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

            .content-inner {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                gap: 0 !important;
            }

            .gst-print-head {
                display: flex !important;
                justify-content: space-between;
                align-items: flex-start;
                gap: 8mm;
                padding-bottom: 4mm;
                margin-bottom: 2mm;
                border-bottom: 2px solid #111827;
            }

            .gst-print-head h2 {
                margin: 0;
                font-size: 20px;
                font-weight: 700;
                color: #111827;
            }

            .gst-print-head-right {
                font-size: 11px;
                font-weight: 600;
                color: #111827;
                white-space: nowrap;
            }

            .gst-print-subhead {
                display: block !important;
                margin-bottom: 4mm;
                font-size: 11px;
                color: #4b5563;
            }

            .gst-print-summary {
                display: block !important;
                margin-bottom: 6mm;
            }

            .gst-print-summary table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }

            .gst-print-summary td {
                border: 1px solid #d1d5db;
                padding: 2.2mm 2.8mm;
            }

            .gst-print-summary td:nth-child(odd) {
                width: 22%;
                font-weight: 600;
                color: #374151;
                background: #f9fafb;
            }

            .gst-main-grid {
                display: block !important;
            }

            .gst-breakdown-panel {
                border: 1px solid #d1d5db !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                break-inside: avoid;
                page-break-inside: avoid;
                width: 100% !important;
            }

            .gst-breakdown-panel .border-b {
                border-bottom: 1px solid #d1d5db !important;
            }

            .gst-breakdown-panel .overflow-x-auto {
                overflow: visible !important;
            }

            .gst-breakdown-panel table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed;
                border-collapse: collapse;
            }

            .gst-breakdown-panel th,
            .gst-breakdown-panel td {
                white-space: normal !important;
                word-break: break-word;
                font-size: 9px !important;
                line-height: 1.25 !important;
                padding: 1.6mm 1.4mm !important;
            }

            .gst-breakdown-panel thead {
                background: #f3f4f6 !important;
            }

            .gst-breakdown-panel .text-emerald-600,
            .gst-breakdown-panel .text-emerald-700,
            .gst-breakdown-panel .text-rose-600,
            .gst-breakdown-panel .text-amber-800 {
                color: #111827 !important;
            }

            .gst-breakdown-panel .bg-amber-100 {
                background: #f3f4f6 !important;
                color: #111827 !important;
            }

            .gst-breakdown-panel tfoot {
                border-top: 1px solid #9ca3af !important;
            }

            button,
            a.btn {
                display: none !important;
            }
        }
    </style>

    @push('scripts')
    <script>
        (() => {
            const printTitle = "GST Report - {{ $reportPeriod }} - {{ $shopName }}";
            document.title = printTitle;
            window.addEventListener('beforeprint', () => {
                document.title = printTitle;
            });
        })();
    </script>
    @endpush
</x-app-layout>
