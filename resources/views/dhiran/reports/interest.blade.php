<x-dhiran-layout title="Interest Report">
    {{-- $totalInterest / $totalPenalty / $footer come from the controller and cover the whole filtered period. --}}

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Interest Income Report</h1>
            <p class="text-sm text-gray-500 mt-1">Interest and penalty collections breakdown</p>
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
        <x-dhiran.filter-bar :filters="$filters" :action="route('dhiran.reports.interest')" default-period="this_month"
            period-label="Paid in" :fields="['search', 'period']" />
        <turbo-frame id="dh-results" target="_top">

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-100 flex items-center justify-center rounded-lg">
                        <x-icon name="dollar-sign" size="18" class="text-green-600" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Interest Collected</p>
                        <p class="text-xl font-bold text-green-600">{{ number_format($totalInterest, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-amber-100 flex items-center justify-center rounded-lg">
                        <x-icon name="triangle-alert" size="18" class="text-amber-600" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Penalty Collected</p>
                        <p class="text-xl font-bold text-amber-600">{{ number_format($totalPenalty, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm" data-list-frame>
            <div class="overflow-x-auto" data-list-scroll tabindex="0" role="region" aria-label="Interest collection records">
                <table class="w-full border-collapse jf-table dh-stack">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mobile</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Interest</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Penalty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($payments as $payment)
                            <tr class="hover:bg-gray-50">
                                <x-dhiran.sno :rows="$payments" :n="$loop->iteration" />
                                <td data-label="Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $payment->payment_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Loan #" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $payment->loan) }}" class="text-blue-600 hover:underline">{{ $payment->loan?->loan_number ?? '-' }}</a>
                                </td>
                                <td data-label="Customer" class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $payment->loan?->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$payment->loan?->customer?->mobile" class="px-6" />
                                <td data-label="Amount" class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $payment->amount, 2) }}</td>
                                <td data-label="Interest" class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right">{{ number_format((float) $payment->interest_component, 2) }}</td>
                                <td data-label="Penalty" class="px-6 py-4 whitespace-nowrap text-sm text-amber-600 text-right">{{ number_format((float) $payment->penalty_component, 2) }}</td>
                                <td data-label="Principal" class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 text-right">{{ number_format((float) $payment->principal_component, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">No payments found for the selected period</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($payments->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            {{-- Every listed payment in the period, on every page. --}}
                            <td colspan="5" class="px-6 py-3 text-sm text-gray-700">Totals ({{ $payments->total() }} payments)</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($footer['amount'], 2) }}</td>
                            <td class="px-6 py-3 text-sm text-green-700 text-right">{{ number_format($footer['interest'], 2) }}</td>
                            <td class="px-6 py-3 text-sm text-amber-700 text-right">{{ number_format($footer['penalty'], 2) }}</td>
                            <td class="px-6 py-3 text-sm text-blue-700 text-right">{{ number_format($footer['principal'], 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$payments" noun="payments" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
