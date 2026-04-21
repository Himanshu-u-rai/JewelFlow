<x-app-layout>
    @php
        $fromDate = request('from', now()->startOfMonth()->format('Y-m-d'));
        $toDate = request('to', now()->format('Y-m-d'));
        $totalInflows = $entries->where('type', 'in')->sum('amount');
        $totalOutflows = $entries->where('type', 'out')->sum('amount');
        $net = $totalInflows - $totalOutflows;
    @endphp

    <x-page-header>
        <div>
            <h1 class="page-title">Dhiran Cash Book</h1>
            <p class="text-sm text-gray-500 mt-1">All cash inflows and outflows for gold loans</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('dhiran.reports.cashbook') }}" class="flex flex-wrap gap-3 items-end">
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
                    <a href="{{ route('dhiran.reports.cashbook') }}" class="btn btn-secondary btn-sm">Clear</a>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-green-600"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Inflows</p>
                        <p class="text-xl font-bold text-green-600">{{ number_format($totalInflows, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-600"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Outflows</p>
                        <p class="text-xl font-bold text-red-600">{{ number_format($totalOutflows, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 {{ $net >= 0 ? 'bg-blue-100' : 'bg-red-100' }} flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="{{ $net >= 0 ? 'text-blue-600' : 'text-red-600' }}"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Net</p>
                        <p class="text-xl font-bold {{ $net >= 0 ? 'text-blue-600' : 'text-red-600' }}">{{ number_format($net, 2) }}</p>
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
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @php $runningBalance = 0; @endphp
                        @forelse($entries as $entry)
                            @php
                                if ($entry->type === 'in') {
                                    $runningBalance += (float) $entry->amount;
                                } else {
                                    $runningBalance -= (float) $entry->amount;
                                }
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $entry->entry_date?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    @if($entry->loan)
                                        <a href="{{ route('dhiran.show', $entry->loan) }}" class="text-blue-600 hover:underline">{{ $entry->loan->loan_number }}</a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($entry->type === 'in')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">IN</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">OUT</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ ucfirst(str_replace('_', ' ', $entry->source ?? '-')) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-right {{ $entry->type === 'in' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $entry->type === 'in' ? '+' : '-' }}{{ number_format((float) $entry->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ ucfirst(str_replace('_', ' ', $entry->method ?? '-')) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">{{ $entry->description ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-right {{ $runningBalance >= 0 ? 'text-gray-900' : 'text-red-600' }}">{{ number_format($runningBalance, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500">No cash entries found for the selected period</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($entries->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="4" class="px-6 py-3 text-sm text-gray-700">Totals</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">Net: {{ number_format($net, 2) }}</td>
                            <td colspan="2"></td>
                            <td class="px-6 py-3 text-sm font-bold text-right {{ $runningBalance >= 0 ? 'text-gray-900' : 'text-red-600' }}">{{ number_format($runningBalance, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
