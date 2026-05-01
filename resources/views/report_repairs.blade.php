<x-app-layout>
    @php $isManufacturer = (bool) auth()->user()?->shop?->isManufacturer(); @endphp
    <x-page-header class="repairs-report-header">
        <div>
            <h1 class="page-title">Repairs Report</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $isManufacturer ? 'All repair jobs with gold usage, wastage, status, and collected cash' : 'All repair jobs with status and collected cash' }}</p>
        </div>
        <div class="page-actions">
            <span class="header-badge repairs-report-count">{{ $repairs->total() }} Repairs</span>
        </div>
    </x-page-header>

    <div class="content-inner repairs-report-page">
        {{-- Filters --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4 repairs-report-filters ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('report.repairs') }}" class="repairs-report-filters-form" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="repairs-filter-field">
                    <label class="block text-xs font-medium text-gray-600 mb-1">From Date</label>
                    <input type="date" name="from_date" value="{{ $fromDate }}"
                           class="repairs-filter-control">
                </div>
                <div class="repairs-filter-field">
                    <label class="block text-xs font-medium text-gray-600 mb-1">To Date</label>
                    <input type="date" name="to_date" value="{{ $toDate }}"
                           class="repairs-filter-control">
                </div>
                <div class="repairs-filter-field">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="repairs-filter-control">
                        <option value="">All Statuses</option>
                        @foreach(['received' => 'Received', 'in_repair' => 'In Repair', 'ready' => 'Ready', 'delivered' => 'Delivered'] as $val => $label)
                            <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="repairs-filter-actions">
                    @if($fromDate || $toDate || $status)
                        <a href="{{ route('report.repairs') }}" class="btn btn-secondary btn-sm repairs-filter-clear">Clear</a>
                    @else
                        <button type="submit" class="btn btn-primary btn-sm repairs-filter-apply">Filter</button>
                    @endif
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden repairs-report-table-card">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Repairs Summary</h2>
                <p class="text-sm text-gray-500 mt-1">{{ $isManufacturer ? 'Wastage is calculated as issued minus returned gold. Pending jobs may show zero cash.' : 'Pending jobs may show zero cash.' }}</p>
            </div>

            <div class="overflow-x-auto repairs-report-table-shell">
                <table class="w-full repairs-report-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            @if($isManufacturer)
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gold Issued (g)</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gold Returned (g)</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Wastage (g)</th>
                            @endif
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash (₹)</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($repairs as $r)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $r->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900">
                                    {{ trim(($r->customer->first_name ?? '') . ' ' . ($r->customer->last_name ?? '')) ?: ($r->customer->name ?? 'N/A') }}
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ $r->item_description }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @php
                                        $badgeClass = match($r->status) {
                                            'delivered' => 'badge-success',
                                            default     => 'badge-secondary',
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span>
                                </td>
                                @if($isManufacturer)
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ number_format((float) ($r->gold_issued_fine ?? 0), 3) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ number_format((float) ($r->gold_returned_fine ?? 0), 3) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ number_format((float) ($r->gold_issued_fine ?? 0) - (float) ($r->gold_returned_fine ?? 0), 3) }}</td>
                                @endif
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                    @if($r->status === 'delivered')
                                        {{ number_format((float) ($r->final_cost ?? 0), 2) }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-center">
                                    @if($r->invoice_id)
                                        <a href="{{ route('invoices.show', $r->invoice_id) }}" class="btn btn-primary btn-xs">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View
                                        </a>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isManufacturer ? 9 : 6 }}" class="px-4 py-8 text-center text-sm text-gray-500">
                                    No repair records found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($repairs->isNotEmpty())
                        <tfoot class="bg-gray-50 border-t-2 border-gray-300">
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-sm font-semibold text-gray-700">
                                    Totals ({{ $repairs->total() }} {{ Str::plural('repair', $repairs->total()) }})
                                </td>
                                @if($isManufacturer)
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $totals->total_issued, 3) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $totals->total_returned, 3) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $totals->total_issued - (float) $totals->total_returned, 3) }}</td>
                                @endif
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $totals->total_cash, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            @if($repairs->hasPages())
                <div class="px-4 py-3 border-t border-gray-200">
                    {{ $repairs->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
