<x-dhiran-layout title="Customer Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">{{ $customer->name }} &mdash; Pledge Loans</h1>
            <p class="text-sm text-gray-500 mt-1">{{ \App\Support\Mobile::forDisplay($customer->mobile) ?: '' }} {{ $customer->email ? '/ ' . $customer->email : '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create', ['customer_id' => $customer->id]) }}" class="btn btn-dark btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                All Loans
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">

        {{-- Summary Stats --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <x-icon name="file-text" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Loans</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats['total_loans'] ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <x-icon name="badge-check" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Active Loans</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats['active_loans'] ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <x-icon name="circle-dollar-sign" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Borrowed</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ $currencySymbol ?? '₹' }}{{ number_format($stats['total_borrowed'] ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-violet-100 text-violet-700 rounded-xl p-2.5">
                        <x-icon name="calculator" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Interest Paid</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ $currencySymbol ?? '₹' }}{{ number_format($stats['total_interest_paid'] ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Loans Table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Outstanding</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Rate</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loans ?? [] as $loan)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">{{ $loan->loan_date->format('d M Y') }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-center text-slate-600">{{ $loan->interest_rate_monthly }}%/mo</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    @php
                                        $statusColors = [
                                            'active' => 'bg-emerald-100 text-emerald-800',
                                            'overdue' => 'bg-rose-100 text-rose-800',
                                            'closed' => 'bg-slate-100 text-slate-600',
                                            'renewed' => 'bg-sky-100 text-sky-800',
                                            'forfeited' => 'bg-red-100 text-red-800',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ ucfirst($loan->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-sm hover:bg-slate-50" title="View">
                                        <x-icon name="eye" size="16" />
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No loans for this customer</p>
                                    <p class="text-sm">Create a new pledge loan to get started</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(isset($loans) && $loans->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $loans->links() }}
                </div>
            @endif
        </div>
    </div>
</x-dhiran-layout>
