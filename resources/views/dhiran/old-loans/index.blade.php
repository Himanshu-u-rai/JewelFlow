<x-dhiran-layout title="Old Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Old Loans</h1>
            <p class="text-sm text-gray-500 mt-1">Running loans brought in from your paper book</p>
        </div>
        <div class="page-actions">
            <x-dhiran.filter-panel :groups="$filters" title="Filter old loans" />
            <a href="{{ route('dhiran.old-loans.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                Add old loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-panel :groups="$filters" mode="chips" />

        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Old loans">
                <table class="dh-table min-w-[900px] jf-table dh-stack">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Ticket #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mobile</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan date</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Lent</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Outstanding</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Evidence</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Brought in</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loans as $loan)
                            <tr class="hover:bg-slate-50/70">
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Ticket #" class="pl-6 pr-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Loan date" class="px-4 py-4 whitespace-nowrap text-sm text-slate-600">{{ $loan->loan_date?->format('d M Y') }}</td>
                                <td data-label="Lent" class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">₹{{ \App\Support\IndianNumber::format($loan->principal_amount) }}</td>
                                <td data-label="Outstanding" class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">₹{{ \App\Support\IndianNumber::format($loan->total_outstanding) }}</td>
                                <td data-label="Status" class="px-4 py-4 whitespace-nowrap text-center"><x-dhiran.status :value="$loan->status" /></td>
                                <td data-label="Evidence" class="px-4 py-4 whitespace-nowrap text-center" data-evidence="{{ $loan->evidence_ok ? 'done' : 'missing' }}">
                                    @if($loan->evidence_ok)
                                        <span class="dh-status dh-status--success">Complete</span>
                                    @else
                                        <a href="{{ route('dhiran.show', $loan) }}#evidence" class="dh-status dh-status--warning">Photo / ID missing</a>
                                    @endif
                                </td>
                                <td data-label="Brought in" class="px-4 py-4 whitespace-nowrap text-sm text-slate-600">{{ $loan->created_at?->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    No old loans yet. Use <a href="{{ route('dhiran.old-loans.create') }}" class="font-semibold">Add old loan</a> to bring in loans from your paper book.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="old loans" />
        </div>
    </div>
</x-dhiran-layout>
