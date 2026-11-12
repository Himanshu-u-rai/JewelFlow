<x-dhiran-layout title="Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Pledge Loans</h1>
            <p class="text-sm text-gray-500 mt-1">All gold &amp; silver pledge loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
            <a href="{{ route('dhiran.dashboard') }}" class="btn btn-secondary btn-sm">
                <x-icon name="house" size="14" class="mr-1" />
                Dashboard
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>

        {{-- Filters --}}
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.loans')" period-label="Loan date"
            :fields="['search', 'period', 'status', 'metal', 'amount', 'overdue', 'maturing']" />
        <turbo-frame id="dh-results" target="_top">

        {{-- Loans Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loan records">
                <table class="dh-table min-w-[900px] jf-table dh-stack">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mobile</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Outstanding</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Rate</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Maturity</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loans ?? [] as $loan)
                            <tr class="hover:bg-slate-50/70">
                                <x-dhiran.sno :rows="$loans ?? null" :n="$loop->iteration" />
                                <td data-label="Loan #" class="pl-6 pr-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}
                                </td>
                                <td data-label="Outstanding" class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding, 2) }}
                                </td>
                                <td data-label="Rate" class="px-4 py-4 whitespace-nowrap text-sm text-center text-slate-600">
                                    {{ $loan->interest_rate_monthly }}%/mo
                                </td>
                                <td data-label="Status" class="px-4 py-4 whitespace-nowrap text-center">
                                    {{-- The "upload proof" hint is the badge's tooltip, not a second line under it (one value per cell). --}}
                                    <x-dhiran.status :value="$loan->status" :title="$loan->status === 'pending_evidence' ? 'Upload the required proof to activate this loan' : null" />
                                </td>
                                <td data-label="Maturity" class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">
                                    {{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}
                                </td>
                                <td data-label="Actions" class="px-4 py-4 whitespace-nowrap text-center">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-sm hover:bg-slate-50" title="View Loan">
                                        <x-icon name="eye" size="16" />
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    <x-icon name="dollar-sign" class="w-12 h-12 mx-auto text-gray-300 mb-4" />
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No loans found</p>
                                    <p class="text-sm">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @isset($loans)<x-dhiran.pager :paginator="$loans" noun="loans" />@endisset
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
