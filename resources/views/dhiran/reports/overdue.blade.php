<x-dhiran-layout title="Overdue Report">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Overdue Loans Report</h1>
            <p class="text-sm text-gray-500 mt-1">Loans past maturity date requiring attention</p>
        </div>
        <div class="page-actions">
            <span class="header-badge">{{ $loans->count() }} Overdue</span>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 flex items-center justify-center rounded-lg">
                        <x-icon name="triangle-alert" size="18" class="text-red-600" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Overdue</p>
                        <p class="text-xl font-bold text-gray-900">{{ $loans->count() }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 flex items-center justify-center rounded-lg">
                        <x-icon name="dollar-sign" size="18" class="text-red-600" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Overdue Amount</p>
                        <p class="text-xl font-bold text-red-600">{{ number_format($loans->sum(fn($l) => $l->totalOutstanding()), 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm" data-list-frame>
            <div class="overflow-x-auto" data-list-scroll tabindex="0" role="region" aria-label="Overdue loan records">
                <table class="w-full border-collapse jf-table">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Days Overdue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Maturity Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grace End</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Notice Sent?</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($loans as $loan)
                            @php
                                $daysOverdue = $loan->daysOverdue();
                                $graceEnd = ($loan->maturity_date && $loan->grace_period_days)
                                    ? $loan->maturity_date->copy()->addDays($loan->grace_period_days)
                                    : $loan->maturity_date;
                                $severityClass = '';
                                if ($daysOverdue > 90) {
                                    $severityClass = 'bg-red-50';
                                } elseif ($daysOverdue > 30) {
                                    $severityClass = 'bg-amber-50';
                                } elseif ($daysOverdue > 0) {
                                    $severityClass = 'bg-yellow-50';
                                }
                            @endphp
                            <tr class="hover:bg-gray-50 {{ $severityClass }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="text-blue-600 hover:underline">{{ $loan->loan_number }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $loan->customer?->name ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format((float) $loan->principal_amount, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600 text-right">{{ number_format($loan->totalOutstanding(), 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($daysOverdue > 90)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ $daysOverdue }}d</span>
                                    @elseif($daysOverdue > 30)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ $daysOverdue }}d</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">{{ $daysOverdue }}d</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $loan->maturity_date?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $graceEnd?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($loan->forfeiture_notice_sent_at)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            {{ $loan->forfeiture_notice_sent_at->format('d M Y') }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">No</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="jf-table-empty">No overdue loans found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-dhiran-layout>
