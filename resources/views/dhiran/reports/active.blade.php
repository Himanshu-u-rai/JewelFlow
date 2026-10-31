<x-dhiran-layout title="Active Loans Report">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Active Loans Report</h1>
            <p class="text-sm text-gray-500 mt-1">All currently active pledge loans</p>
        </div>
        <div class="page-actions">
            <span class="header-badge">{{ $loans->count() }} Active Loans</span>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 flex items-center justify-center rounded-lg">
                        <x-icon name="file" size="18" class="text-blue-600" />
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
                        <x-icon name="dollar-sign" size="18" class="text-amber-600" />
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
                        <x-icon name="trending-up" size="18" class="text-red-600" />
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
</x-dhiran-layout>
