<x-dhiran-layout title="Borrowers">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Borrowers</h1>
            <p class="text-sm text-gray-500 mt-1">Everyone who has a pledge loan with your shop</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create') }}" class="btn btn-dark btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        {{-- Search --}}
        <form method="GET" action="{{ route('dhiran.borrowers.index') }}" class="mb-5 flex flex-wrap items-center gap-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, mobile, or code"
                   class="w-full sm:w-80 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
            <button type="submit" class="btn btn-dark btn-sm">Search</button>
            @if(request('search'))
                <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
        </form>

        @forelse($borrowers as $borrower)
            @if($loop->first)
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[820px]">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Borrower</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Mobile</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Active</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Closed</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Forfeited</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Outstanding</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
            @endif
                                <tr class="hover:bg-slate-50/70">
                                    <td class="pl-6 pr-4 py-4">
                                        <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="font-medium text-slate-800 hover:text-amber-700">{{ $borrower->name }}</a>
                                        <div class="text-xs text-slate-400 font-mono mt-0.5">{{ $borrower->customer_code }}</div>
                                        @if(($borrower->pending_evidence_count ?? 0) > 0)
                                            <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800">{{ $borrower->pending_evidence_count }} awaiting evidence</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap">{{ $borrower->mobile ?? '—' }}</td>
                                    <td class="px-4 py-4 text-center text-sm font-medium text-emerald-700">{{ $borrower->active_loans_count }}</td>
                                    <td class="px-4 py-4 text-center text-sm text-slate-500">{{ $borrower->closed_loans_count }}</td>
                                    <td class="px-4 py-4 text-center text-sm text-red-600">{{ $borrower->forfeited_loans_count }}</td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-slate-800 whitespace-nowrap">₹{{ number_format($outstanding[$borrower->id] ?? 0, 2) }}</td>
                                    <td class="px-4 py-4 text-center whitespace-nowrap">
                                        <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Profile</a>
                                        <a href="{{ route('dhiran.create', ['customer_id' => $borrower->id]) }}" class="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">New Loan</a>
                                    </td>
                                </tr>
            @if($loop->last)
                            </tbody>
                        </table>
                    </div>
                    @if($borrowers->hasPages())
                        <div class="px-6 py-4 border-t border-slate-200">{{ $borrowers->links() }}</div>
                    @endif
                </div>
            @endif
        @empty
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm px-6 py-16 text-center">
                <p class="text-lg font-semibold text-slate-700 mb-1">
                    {{ request('search') ? 'No borrowers match your search' : 'No borrowers yet' }}
                </p>
                <p class="text-sm text-slate-500 mb-5">
                    {{ request('search') ? 'Try a different name, mobile, or code.' : 'Borrowers appear here once you create their first pledge loan.' }}
                </p>
                @unless(request('search'))
                    <a href="{{ route('dhiran.create') }}" class="btn btn-dark btn-sm">Create your first pledge loan</a>
                @endunless
            </div>
        @endforelse
    </div>
</x-dhiran-layout>
