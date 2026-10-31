<x-dhiran-layout title="Cash Book">
    @php
        $fromDate = request('from', now()->startOfMonth()->format('Y-m-d'));
        $toDate = request('to', now()->format('Y-m-d'));
        $totalInflows = $entries->where('type', 'in')->sum('amount');
        $totalOutflows = $entries->where('type', 'out')->sum('amount');
        $net = $totalInflows - $totalOutflows;
    @endphp

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Dhiran Cash Book</h1>
            <p class="text-sm text-gray-500 mt-1">All cash inflows and outflows for pledge (gold & silver) loans</p>
        </div>
        <div class="page-actions">
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        <div class="dh-filter-card">
            <form method="GET" action="{{ route('dhiran.reports.cashbook') }}" class="dh-filter-row">
                <div class="dh-filter-field">
                    <label class="dh-label-upper block mb-2">From</label>
                    <input type="date" name="from" value="{{ $fromDate }}" class="dh-form-control">
                </div>
                <div class="dh-filter-field">
                    <label class="dh-label-upper block mb-2">To</label>
                    <input type="date" name="to" value="{{ $toDate }}" class="dh-form-control">
                </div>
                <div class="dh-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">View</button>
                    @if(request()->hasAny(['from', 'to']))
                        <a href="{{ route('dhiran.reports.cashbook') }}" class="btn btn-secondary btn-sm">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-100 flex items-center justify-center rounded-lg">
                        <x-icon name="trending-up" size="18" class="text-green-600" />
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
                        <x-icon name="trending-down" size="18" class="text-red-600" />
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
                        <x-icon name="chart-no-axes-column" size="18" :class="$net >= 0 ? 'text-blue-600' : 'text-red-600'" />
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
</x-dhiran-layout>
