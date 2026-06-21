<x-dhiran-layout title="Pledged Item">
    @php
        $borrower = $loan?->customer;
        $statusColors = [
            'pledged' => 'bg-amber-100 text-amber-800',
            'released' => 'bg-emerald-100 text-emerald-800',
            'forfeited' => 'bg-red-100 text-red-800',
        ];
        $loanStatusColors = [
            'pending_evidence' => 'bg-amber-100 text-amber-800',
            'active' => 'bg-emerald-100 text-emerald-800',
            'closed' => 'bg-slate-100 text-slate-600',
            'renewed' => 'bg-sky-100 text-sky-800',
            'forfeited' => 'bg-red-100 text-red-800',
        ];
    @endphp

    {{-- 1. Header --}}
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">{{ $item->description }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                @if($loan)Loan {{ $loan->loan_number }}@endif
                @if($borrower) · {{ $borrower->name }}@endif
                @if($item->created_at) · Pledged {{ $item->created_at->format('d M Y') }}@endif
            </p>
            <span class="inline-flex items-center mt-2 px-2 py-0.5 rounded-full text-xs font-semibold {{ $statusColors[$item->status] ?? 'bg-slate-100 text-slate-600' }}">
                {{ ucfirst($item->status ?? 'pledged') }}
            </span>
        </div>
        <div class="page-actions">
            @if($loan)
                <a href="{{ route('dhiran.show', $loan) }}" class="btn btn-dark btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back to Loan
                </a>
                <a href="{{ route('dhiran.show', $loan) }}#receipt" class="hidden"></a>
            @endif
            @if($borrower)
                <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="btn btn-secondary btn-sm">Back to Borrower</a>
            @endif
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">

        {{-- Status banners --}}
        @if($item->status === 'released')
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 mb-6 text-sm text-emerald-800">
                This pledged item has been released{{ $item->released_at ? ' on ' . $item->released_at->format('d M Y') : '' }}.
            </div>
        @elseif($item->status === 'forfeited')
            <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-3 mb-6 text-sm text-red-800">
                This pledged item has been forfeited{{ $item->forfeited_at ? ' on ' . $item->forfeited_at->format('d M Y') : '' }}.
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- 2. Item details --}}
            <div class="lg:col-span-2">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
                    <h2 class="text-base font-semibold text-slate-900 mb-4">Item details</h2>
                    <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Description</dt><dd class="text-slate-800 font-medium mt-0.5">{{ $item->description }}</dd></div>
                        @if($item->category)<div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Category</dt><dd class="text-slate-800 mt-0.5">{{ $item->category }}</dd></div>@endif
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Metal</dt><dd class="text-slate-800 mt-0.5 capitalize">{{ $item->metal_type ?? 'gold' }}</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Quantity</dt><dd class="text-slate-800 mt-0.5">{{ $item->quantity ?? 1 }}</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Purity</dt><dd class="text-slate-800 mt-0.5">{{ $item->purity }}K</dd></div>
                        @if($item->huid)<div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">HUID</dt><dd class="text-slate-800 font-mono mt-0.5">{{ $item->huid }}</dd></div>@endif
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Gross weight</dt><dd class="text-slate-800 mt-0.5">{{ number_format($item->gross_weight, 3) }} g</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Stone weight</dt><dd class="text-slate-800 mt-0.5">{{ number_format($item->stone_weight, 3) }} g</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Net metal weight</dt><dd class="text-slate-800 mt-0.5">{{ number_format($item->net_metal_weight, 3) }} g</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Fine weight</dt><dd class="text-slate-800 mt-0.5">{{ number_format($item->fine_weight, 3) }} g</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Rate at pledge</dt><dd class="text-slate-800 mt-0.5">₹{{ number_format($item->rate_per_gram_at_pledge, 2) }}/g</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Market value</dt><dd class="text-slate-800 mt-0.5">₹{{ number_format($item->market_value, 2) }}</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Loan value</dt><dd class="text-slate-800 font-semibold mt-0.5">₹{{ number_format($item->loan_value, 2) }}</dd></div>
                        <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Status</dt><dd class="text-slate-800 mt-0.5 capitalize">{{ $item->status ?? 'pledged' }}</dd></div>
                        @if($item->released_at)<div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Released at</dt><dd class="text-slate-800 mt-0.5">{{ $item->released_at->format('d M Y') }}</dd></div>@endif
                        @if($item->forfeited_at)<div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Forfeited at</dt><dd class="text-slate-800 mt-0.5">{{ $item->forfeited_at->format('d M Y') }}</dd></div>@endif
                        @if($item->release_condition_note)<div class="col-span-2 md:col-span-3"><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Release note</dt><dd class="text-slate-700 mt-0.5">{{ $item->release_condition_note }}</dd></div>@endif
                    </dl>
                </div>

                {{-- 3. Evidence / photos --}}
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
                    <h2 class="text-base font-semibold text-slate-900 mb-1">Evidence &amp; documents</h2>
                    <p class="text-xs text-slate-500 mb-4">Item photos, valuation proof, and loan documents. Files are private to your shop.</p>
                    @if($attachments->isEmpty())
                        <p class="text-sm text-slate-400">No item evidence uploaded yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach($attachments as $att)
                                <li class="flex items-center justify-between py-2.5">
                                    <div class="min-w-0">
                                        <span class="text-sm text-slate-800">{{ ucwords(str_replace('_', ' ', $att->document_type)) }}</span>
                                        <span class="text-xs text-slate-400 ml-2">{{ $att->original_name }}</span>
                                        @if($att->owner_type === \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)
                                            <span class="text-[10px] text-slate-400 ml-1">(loan document)</span>
                                        @endif
                                    </div>
                                    <a href="{{ route('dhiran.attachments.show', $att) }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800 whitespace-nowrap" target="_blank" rel="noopener">View</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @can('dhiran.create')
                        <form method="POST" action="{{ route('dhiran.attachments.store') }}" enctype="multipart/form-data" data-turbo-frame="_top" class="mt-5 pt-5 border-t border-slate-100 flex flex-wrap items-end gap-3">
                            @csrf
                            <input type="hidden" name="owner_type" value="dhiran_loan_item">
                            <input type="hidden" name="owner_id" value="{{ $item->id }}">
                            <div>
                                <label class="block text-[11px] uppercase tracking-[0.16em] text-slate-400 mb-1">Document type</label>
                                <select name="document_type" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                    <option value="item_photo">Item photo</option>
                                    <option value="valuation_proof">Valuation proof</option>
                                    <option value="loan_document">Loan document</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] uppercase tracking-[0.16em] text-slate-400 mb-1">File <span class="text-slate-300 normal-case tracking-normal">(JPG/PNG/PDF, max 8 MB)</span></label>
                                <input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf" class="text-sm">
                            </div>
                            <button type="submit" class="btn btn-dark btn-sm">Upload</button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- Right column: borrower + loan cards + history --}}
            <div class="space-y-6">
                {{-- 4. Linked borrower --}}
                @if($borrower)
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h3 class="text-[11px] uppercase tracking-[0.16em] text-slate-400 mb-2">Borrower</h3>
                    <p class="text-sm font-semibold text-slate-800">{{ $borrower->name }}</p>
                    <p class="text-sm text-slate-500">{{ $borrower->mobile ?? '—' }}</p>
                    <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $borrower->customer_code }}</p>
                    <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="inline-flex items-center mt-3 text-sm font-semibold text-amber-700 hover:text-amber-800">View borrower profile →</a>
                </div>
                @endif

                {{-- 5. Linked loan --}}
                @if($loan)
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h3 class="text-[11px] uppercase tracking-[0.16em] text-slate-400 mb-2">Loan</h3>
                    <p class="text-sm font-mono font-semibold text-slate-800">{{ $loan->loan_number }}</p>
                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium {{ $loanStatusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                        {{ $loan->status === 'pending_evidence' ? 'Awaiting Evidence' : ucfirst($loan->status) }}
                    </span>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Principal</dt><dd class="text-slate-800">₹{{ number_format($loan->principal_amount, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Outstanding</dt><dd class="text-slate-800 font-medium">₹{{ number_format($loan->totalOutstanding(), 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Maturity</dt><dd class="text-slate-800">{{ $loan->maturity_date?->format('d M Y') ?? '—' }}</dd></div>
                    </dl>
                    <a href="{{ route('dhiran.show', $loan) }}" class="inline-flex items-center mt-3 text-sm font-semibold text-amber-700 hover:text-amber-800">View loan →</a>
                </div>
                @endif

                {{-- 6. Item history --}}
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
                    <h3 class="text-[11px] uppercase tracking-[0.16em] text-slate-400 mb-3">History</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-amber-500"></span>
                            <div><p class="text-sm text-slate-800">Pledged</p><p class="text-xs text-slate-400">{{ $item->created_at?->format('d M Y, g:i A') }}</p></div>
                        </li>
                        @if($item->released_at)
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-emerald-500"></span>
                            <div><p class="text-sm text-slate-800">Released</p><p class="text-xs text-slate-400">{{ $item->released_at->format('d M Y, g:i A') }}</p></div>
                        </li>
                        @endif
                        @if($item->forfeited_at)
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-red-500"></span>
                            <div><p class="text-sm text-slate-800">Forfeited</p><p class="text-xs text-slate-400">{{ $item->forfeited_at->format('d M Y, g:i A') }}</p></div>
                        </li>
                        @endif
                        @if($loan && $loan->status === 'closed')
                        <li class="flex items-start gap-3">
                            <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full bg-slate-400"></span>
                            <div><p class="text-sm text-slate-800">Loan closed</p></div>
                        </li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-dhiran-layout>
