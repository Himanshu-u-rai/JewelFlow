<x-dhiran-layout title="Forfeiture Report">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Forfeiture Report</h1>
            <p class="text-sm text-gray-500 mt-1">Forfeited and written-off loans</p>
        </div>
        <div class="page-actions">
            <span class="header-badge">{{ $loans->count() }} Forfeited</span>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters" :action="route('dhiran.reports.forfeiture')" period-label="Forfeited in"
            :fields="['search', 'period', 'metal', 'amount']" />
        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm" data-list-frame>
            <div class="overflow-x-auto jf-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Forfeited loan records">
                <table class="w-full border-collapse jf-table dh-stack">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th scope="col" class="dh-sno-th">#</th>
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
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="text-blue-600 hover:underline">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $loan->customer?->name ?? '-' }}</td>
                                <td data-label="Principal" class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Written Off Amount" class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600 text-right">{{ number_format($writtenOff, 2) }}</td>
                                <td data-label="Items Count" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">{{ $itemsCount }}</td>
                                <td data-label="Forfeiture Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $loan->forfeited_at?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Notice Sent Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($loan->forfeiture_notice_sent_at)
                                        {{ $loan->forfeiture_notice_sent_at->format('d M Y') }}
                                    @else
                                        <span class="text-gray-400">Not sent</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="jf-table-empty">No forfeited loans found</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($loans->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="3" class="px-6 py-3 text-sm text-gray-700">Page totals</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($loans->sum('principal_amount'), 2) }}</td>
                            <td class="px-6 py-3 text-sm text-red-700 text-right">{{ number_format($loans->sum(fn($l) => $l->totalOutstanding()), 2) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 text-center">{{ $loans->sum(fn($l) => $l->items->count()) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
    </div>
</x-dhiran-layout>
