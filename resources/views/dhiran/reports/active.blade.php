<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Active Loans Report</h1>
            <p class="text-sm text-gray-500 mt-1">All currently active gold loans</p>
        </div>
        <div class="page-actions">
            <span class="header-badge">{{ $loans->count() }} Active Loans</span>
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
                    <div class="w-10 h-10 bg-blue-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Active</p>
                        <p class="text-xl font-bold text-gray-900">{{ $loans->count() }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-amber-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Principal</p>
                        <p class="text-xl font-bold text-gray-900">{{ number_format($loans->sum('principal_amount'), 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-red-100 flex items-center justify-center rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-600"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Total Outstanding</p>
                        <p class="text-xl font-bold text-gray-900">{{ number_format($loans->sum(fn($l) => $l->totalOutstanding()), 2) }}</p>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan Date</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Principal</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Interest Rate</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Maturity Date</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Days to Maturity</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($loans as $loan)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="text-blue-600 hover:underline">{{ $loan->loan_number }}</a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $loan->customer?->name ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $loan->loan_date?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">{{ number_format((float) $loan->principal_amount, 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-right {{ $loan->totalOutstanding() > 0 ? 'text-red-600' : 'text-green-600' }}">{{ number_format($loan->totalOutstanding(), 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">{{ number_format((float) $loan->interest_rate_monthly, 2) }}%/mo</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $loan->maturity_date?->format('d M Y') ?? '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($loan->isOverdue())
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Overdue</span>
                                    @elseif($loan->daysTillMaturity() <= 30)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ $loan->daysTillMaturity() }}d</span>
                                    @else
                                        <span class="text-gray-500">{{ $loan->daysTillMaturity() }}d</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-sm text-gray-500">No active loans found</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
