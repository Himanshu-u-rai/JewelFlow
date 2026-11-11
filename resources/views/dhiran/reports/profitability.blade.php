<x-dhiran-layout title="Profitability Report">
    @php
        // $totals covers every loan given in the period, on every page.
        $totalDisbursed = $totals['principal'];
        $totalInterestEarned = $totals['interest'];
        $totalPenalties = $totals['penalty'];
        $totalProcessingFees = $totals['fees'];
        $netProfit = $totalInterestEarned + $totalPenalties + $totalProcessingFees;
    @endphp

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Profitability Report</h1>
            <p class="text-sm text-gray-500 mt-1">Revenue and profitability analysis across all loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        {{-- Loans given in this period (the controller always took a range; the page had no way to set it). --}}
        <x-dhiran.filter-bar :filters="$filters" :action="route('dhiran.reports.profitability')" default-period="this_month"
            period-label="Loans given in" :fields="['search', 'period', 'status', 'metal', 'amount']" />

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Disbursed</p>
                    <p class="text-xl font-bold text-gray-900 mt-1" data-report-total="principal">{{ number_format($totalDisbursed, 2) }}</p>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Interest Earned</p>
                    <p class="text-xl font-bold text-green-600 mt-1">{{ number_format($totalInterestEarned, 2) }}</p>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Penalties</p>
                    <p class="text-xl font-bold text-amber-600 mt-1">{{ number_format($totalPenalties, 2) }}</p>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Processing Fees</p>
                    <p class="text-xl font-bold text-blue-600 mt-1">{{ number_format($totalProcessingFees, 2) }}</p>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 {{ $netProfit >= 0 ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }}">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Net Profit</p>
                    <p class="text-xl font-bold mt-1 {{ $netProfit >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ number_format($netProfit, 2) }}</p>
                </div>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm" data-list-frame>
            <div class="overflow-x-auto" data-list-scroll tabindex="0" role="region" aria-label="Loan profitability records">
                <table class="w-full border-collapse jf-table dh-stack">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mobile</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Interest Collected</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Penalty Collected</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Processing Fee</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Profit/Loss</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($loans as $loan)
                            @php
                                $loanProfit = (float) $loan->total_interest_collected
                                    + (float) $loan->total_penalty_collected
                                    + (float) $loan->processing_fee;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="text-blue-600 hover:underline">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $loan->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" class="px-6" />
                                <td data-label="Principal" class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Interest Collected" class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right">{{ number_format((float) $loan->total_interest_collected, 2) }}</td>
                                <td data-label="Penalty Collected" class="px-6 py-4 whitespace-nowrap text-sm text-amber-600 text-right">{{ number_format((float) $loan->total_penalty_collected, 2) }}</td>
                                <td data-label="Processing Fee" class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 text-right">{{ number_format((float) $loan->processing_fee, 2) }}</td>
                                <td data-label="Status" class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($loan->status === 'active')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                    @elseif($loan->status === 'closed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Closed</span>
                                    @elseif($loan->status === 'forfeited')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Forfeited</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ ucfirst($loan->status) }}</span>
                                    @endif
                                </td>
                                <td data-label="Profit/Loss" class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-right {{ $loanProfit >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ number_format($loanProfit, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">No loans found</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($loans->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="4" class="px-6 py-3 text-sm text-gray-700">Totals ({{ $totals['count'] }} loans)</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($totalDisbursed, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-green-700 text-right">{{ number_format($totalInterestEarned, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-amber-700 text-right">{{ number_format($totalPenalties, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-blue-700 text-right">{{ number_format($totalProcessingFees, 2) }}</td>
                            <td></td>
                            <td class="px-6 py-3 text-sm font-bold text-right {{ $netProfit >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ number_format($netProfit, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
    </div>
</x-dhiran-layout>
