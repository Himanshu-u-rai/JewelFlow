<x-app-layout>
    @php
        $fromDate = request('from', now()->startOfMonth()->format('Y-m-d'));
        $toDate = request('to', now()->format('Y-m-d'));
        $totalInterest = $payments->sum('interest_component');
        $totalPenalty = $payments->sum('penalty_component');
        $totalPrincipal = $payments->sum('principal_component');
    @endphp

    <x-page-header>
        <div>
            <h1 class="page-title">Interest Income Report</h1>
            <p class="text-sm text-gray-500 mt-1">Interest and penalty collections breakdown</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('dhiran.reports.interest') }}" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="text-xs text-gray-500 font-medium block mb-1">From</label>
                    <input type="date" name="from" value="{{ $fromDate }}" class="rounded-lg border-gray-200 bg-white shadow-sm text-sm h-10 px-3 focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div>
                    <label class="text-xs text-gray-500 font-medium block mb-1">To</label>
                    <input type="date" name="to" value="{{ $toDate }}" class="rounded-lg border-gray-200 bg-white shadow-sm text-sm h-10 px-3 focus:border-amber-500 focus:ring-amber-500">
                </div>
                <button type="submit" class="btn btn-success btn-sm">View</button>
                @if(request()->hasAny(['from', 'to']))
                    <a href="{{ route('dhiran.reports.interest') }}" class="btn btn-secondary btn-sm">Clear</a>
                @endif
            </form>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print
            </button>
        </div>
    </x-page-header>

    <div class="content-inner">
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-600"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Penalty Collected</p>
                        <p class="text-xl font-bold text-amber-600">{{ number_format($totalPenalty, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 12h.01"/><path d="M17 12h.01"/><path d="M7 12h.01"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Principal Collected</p>
                        <p class="text-xl font-bold text-blue-600">{{ number_format($totalPrincipal, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Interest</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Penalty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($payments as $payment)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $payment->payment_date?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $payment->loan) }}" class="text-blue-600 hover:underline">{{ $payment->loan?->loan_number ?? '-' }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $payment->loan?->customer?->name ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 text-right">{{ number_format((float) $payment->amount, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right">{{ number_format((float) $payment->interest_component, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-amber-600 text-right">{{ number_format((float) $payment->penalty_component, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 text-right">{{ number_format((float) $payment->principal_component, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No payments found for the selected period</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($payments->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="3" class="px-6 py-3 text-sm text-gray-700">Totals</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($payments->sum('amount'), 2) }}</td>
                            <td class="px-6 py-3 text-sm text-green-700 text-right">{{ number_format($totalInterest, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-amber-700 text-right">{{ number_format($totalPenalty, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-blue-700 text-right">{{ number_format($totalPrincipal, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
