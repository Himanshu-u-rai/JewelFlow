<x-dhiran-layout title="Customers">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Customers</h1>
            <p class="text-sm text-gray-500 mt-1">Everyone your shop has saved, with or without a loan</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            @can('dhiran.create')
            <a href="{{ route('dhiran.borrowers.create') }}" class="btn btn-secondary btn-sm">
                <x-icon name="user-plus" size="14" class="mr-1" />
                Add Customer
            </a>
            @endcan
            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        {{-- Where their loans stand --}}
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="Filter customers">
            @foreach(['' => 'All', 'active' => 'Active loan', 'paid' => 'Fully paid', 'none' => 'No loan yet'] as $key => $tabLabel)
                <a href="{{ route('dhiran.borrowers.index', array_filter(['show' => $key, 'search' => request('search')])) }}"
                   @if(($show ?? '') === $key) aria-current="page" @endif
                   class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold {{ ($show ?? '') === $key ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }}">
                    {{ $tabLabel }} <span class="tabular-nums text-slate-400">{{ $tabCounts[$key === '' ? 'all' : $key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        {{-- Search --}}
        <form method="GET" action="{{ route('dhiran.borrowers.index') }}" class="mb-5 flex flex-wrap items-center gap-2">
            @if($show ?? null)<input type="hidden" name="show" value="{{ $show }}">@endif
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, mobile, or code"
                   class="w-full sm:w-80 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
            <button type="submit" class="btn btn-dark btn-sm">Search</button>
            @if(request('search'))
                <a href="{{ route('dhiran.borrowers.index', array_filter(['show' => $show ?? null])) }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
        </form>

        @forelse($borrowers as $borrower)
            @if($loop->first)
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" data-list-frame>
                    <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Customer records">
                        <table class="w-full min-w-[820px] jf-table">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th scope="col" class="dh-sno-th">#</th>
                                    <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Customer</th>
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
                                    <x-dhiran.sno :rows="$borrowers" :n="$loop->iteration" />
                                    <td class="pl-6 pr-4 py-4">
                                        <div class="flex items-start gap-3">
                                            <x-customer-avatar :customer="$borrower" realm="dhiran" />
                                            <div>
                                                <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="font-medium text-slate-800 hover:text-amber-700">{{ $borrower->name }}</a>
                                                <div class="text-xs text-slate-400 font-mono mt-0.5">{{ $borrower->customer_code }}</div>
                                                @if(($borrower->loans_count ?? 0) === 0)
                                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600">No loan yet</span>
                                                @endif
                                                @if(($borrower->pending_evidence_count ?? 0) > 0)
                                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800">{{ $borrower->pending_evidence_count }} awaiting evidence</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap">{{ \App\Support\Mobile::forDisplay($borrower->mobile) ?: '—' }}</td>
                                    <td class="px-4 py-4 text-center text-sm font-medium text-emerald-700">{{ $borrower->active_loans_count }}</td>
                                    <td class="px-4 py-4 text-center text-sm text-slate-500">{{ $borrower->closed_loans_count }}</td>
                                    <td class="px-4 py-4 text-center text-sm text-red-600">{{ $borrower->forfeited_loans_count }}</td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-slate-800 whitespace-nowrap">₹{{ number_format($outstanding[$borrower->id] ?? 0, 2) }}</td>
                                    <td class="px-4 py-4 text-center whitespace-nowrap">
                                        <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="jf-row-action jf-row-action--view" data-tip="Profile"><x-icon name="eye" size="16" /><span class="jf-sr-only">Profile</span></a>
                                        <a href="{{ route('dhiran.create', ['customer_id' => $borrower->id]) }}" class="btn btn-primary btn-sm ml-2 align-middle">New Loan</a>
                                    </td>
                                </tr>
            @if($loop->last)
                            </tbody>
                        </table>
                    </div>

                    {{-- Phones: one card per customer with its actions in reach (the table needed a sideways swipe). --}}
                    <div class="dh-mobile-list p-3">
                        @foreach($borrowers as $card)
                            <article class="dh-mobile-card">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <span class="dh-mobile-sno">#{{ ($borrowers->firstItem() ?? 1) + $loop->index }}</span>
                                        <a href="{{ route('dhiran.borrowers.show', $card) }}" class="dh-mobile-title">{{ $card->name }}</a>
                                        <p class="dh-mobile-meta">{{ \App\Support\Mobile::forDisplay($card->mobile) ?: '—' }} · {{ $card->customer_code }}</p>
                                    </div>
                                    @if(($card->loans_count ?? 0) === 0)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">No loan yet</span>
                                    @elseif(($card->pending_evidence_count ?? 0) > 0)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">{{ $card->pending_evidence_count }} awaiting evidence</span>
                                    @endif
                                </div>
                                <div class="dh-mobile-grid">
                                    <div><div class="dh-mobile-label">Active</div><div class="dh-mobile-value">{{ $card->active_loans_count }}</div></div>
                                    <div><div class="dh-mobile-label">Closed</div><div class="dh-mobile-value">{{ $card->closed_loans_count }}</div></div>
                                    <div><div class="dh-mobile-label">Forfeited</div><div class="dh-mobile-value">{{ $card->forfeited_loans_count }}</div></div>
                                    <div><div class="dh-mobile-label">Outstanding</div><div class="dh-mobile-value">₹{{ number_format($outstanding[$card->id] ?? 0, 2) }}</div></div>
                                </div>
                                <div class="dh-mobile-actions">
                                    <a href="{{ route('dhiran.borrowers.show', $card) }}" class="btn btn-secondary btn-sm">Profile</a>
                                    <a href="{{ route('dhiran.create', ['customer_id' => $card->id]) }}" class="btn btn-primary btn-sm">New Loan</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <x-dhiran.pager :paginator="$borrowers" noun="customers" />
                </div>
            @endif
        @empty
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm px-6 py-16 text-center">
                <p class="text-lg font-semibold text-slate-700 mb-1">
                    {{ request('search') ? 'No customers match your search' : (($show ?? null) ? 'No customers here' : 'No customers yet') }}
                </p>
                <p class="text-sm text-slate-500 mb-5">
                    {{ request('search') ? 'Try a different name, mobile, or code.' : (($show ?? null) ? 'Try another tab.' : 'Add a customer, or create their first pledge loan.') }}
                </p>
                @unless(request('search') || ($show ?? null))
                    <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">Create your first pledge loan</a>
                @endunless
            </div>
        @endforelse
    </div>
</x-dhiran-layout>
