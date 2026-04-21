<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Forfeiture Report</h1>
            <p class="text-sm text-gray-500 mt-1">Forfeited and written-off loans</p>
        </div>
        <div class="page-actions">
            <span class="header-badge">{{ $loans->count() }} Forfeited</span>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print
            </button>
        </div>
    </x-page-header>

    <div class="content-inner">
        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Written Off Amount</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Items Count</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Forfeiture Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notice Sent Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($loans as $loan)
                            @php
                                $writtenOff = $loan->totalOutstanding();
                                $itemsCount = $loan->items->count();
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="text-blue-600 hover:underline">{{ $loan->loan_number }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $loan->customer?->name ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format((float) $loan->principal_amount, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600 text-right">{{ number_format($writtenOff, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">{{ $itemsCount }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $loan->forfeited_at?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($loan->forfeiture_notice_sent_at)
                                        {{ $loan->forfeiture_notice_sent_at->format('d M Y') }}
                                    @else
                                        <span class="text-gray-400">Not sent</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No forfeited loans found</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($loans->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="2" class="px-6 py-3 text-sm text-gray-700">Totals</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($loans->sum('principal_amount'), 2) }}</td>
                            <td class="px-6 py-3 text-sm text-red-700 text-right">{{ number_format($loans->sum(fn($l) => $l->totalOutstanding()), 2) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 text-center">{{ $loans->sum(fn($l) => $l->items->count()) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
