<x-dhiran-layout title="Borrower Profile">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">{{ $customer->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $customer->mobile ?? '' }}{{ $customer->customer_code ? ' · ' . $customer->customer_code : '' }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create', ['customer_id' => $customer->id]) }}" class="btn btn-dark btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Loan for this borrower
            </a>
            <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Borrowers
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">

        {{-- 1. Basic details --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-base font-semibold text-slate-900 mb-4">Borrower details</h2>
            <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Name</dt><dd class="text-slate-800 font-medium mt-0.5">{{ $customer->name }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Mobile</dt><dd class="text-slate-800 mt-0.5">{{ $customer->mobile ?? '—' }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Customer code</dt><dd class="text-slate-800 font-mono mt-0.5">{{ $customer->customer_code ?? '—' }}</dd></div>
                @if($customer->address)<div class="col-span-2"><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Address</dt><dd class="text-slate-800 mt-0.5">{{ $customer->address }}</dd></div>@endif
                @if($customer->email)<div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Email</dt><dd class="text-slate-800 mt-0.5">{{ $customer->email }}</dd></div>@endif
                <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">PAN</dt><dd class="text-slate-800 mt-0.5">{{ $kycPan ?? $customer->pan ?? '—' }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Aadhaar (masked)</dt><dd class="text-slate-800 mt-0.5 font-mono">{{ $kycAadhaar ?? '—' }}</dd></div>
                @if($customer->notes)<div class="col-span-2 md:col-span-3"><dt class="text-[11px] uppercase tracking-[0.16em] text-slate-400">Notes</dt><dd class="text-slate-700 mt-0.5">{{ $customer->notes }}</dd></div>@endif
            </dl>
        </div>

        {{-- 3. Loan summary --}}
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-6">
            @php
                $cards = [
                    ['Active', $summary['active'], 'text-emerald-700'],
                    ['Awaiting evidence', $summary['pending_evidence'], 'text-amber-700'],
                    ['Closed', $summary['closed'], 'text-slate-600'],
                    ['Renewed', $summary['renewed'], 'text-sky-700'],
                    ['Forfeited', $summary['forfeited'], 'text-red-600'],
                ];
            @endphp
            @foreach($cards as [$label, $val, $color])
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] uppercase tracking-[0.16em] text-slate-400">{{ $label }}</p>
                    <p class="text-2xl font-semibold {{ $color }} mt-1">{{ number_format($val) }}</p>
                </div>
            @endforeach
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-[10px] uppercase tracking-[0.16em] text-slate-400">Outstanding</p>
                <p class="text-xl font-semibold text-slate-900 mt-1">₹{{ number_format($summary['principal_outstanding'] + $summary['interest_outstanding'], 2) }}</p>
            </div>
        </div>

        {{-- 2. KYC / Documents --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-base font-semibold text-slate-900 mb-1">Documents &amp; evidence</h2>
            <p class="text-xs text-slate-500 mb-4">ID proof, photos, and loan documents for this borrower. Files are private to your shop.</p>
            @if($attachments->isEmpty())
                <p class="text-sm text-slate-400">No documents uploaded yet.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($attachments as $att)
                        <li class="flex items-center justify-between py-2.5">
                            <div class="min-w-0">
                                <span class="text-sm text-slate-800">{{ ucwords(str_replace('_', ' ', $att->document_type)) }}</span>
                                <span class="text-xs text-slate-400 ml-2">{{ $att->original_name }}</span>
                            </div>
                            <a href="{{ route('dhiran.attachments.show', $att) }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800 whitespace-nowrap" target="_blank" rel="noopener">View</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- 4. Loans table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-100"><h2 class="text-base font-semibold text-slate-900">Loans</h2></div>
            @if($loans->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-base font-semibold text-slate-700 mb-1">No loans yet</p>
                    <p class="text-sm text-slate-500 mb-4">This borrower has no pledge loans with your shop.</p>
                    <a href="{{ route('dhiran.create', ['customer_id' => $customer->id]) }}" class="btn btn-dark btn-sm">Create loan for this borrower</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px]">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Loan #</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Principal</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Outstanding</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Created</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Maturity</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php
                                $statusColors = [
                                    'pending_evidence' => 'bg-amber-100 text-amber-800',
                                    'active' => 'bg-emerald-100 text-emerald-800',
                                    'closed' => 'bg-slate-100 text-slate-600',
                                    'renewed' => 'bg-sky-100 text-sky-800',
                                    'forfeited' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            @foreach($loans as $loan)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="pl-6 pr-4 py-4"><a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a></td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                                            {{ $loan->status === 'pending_evidence' ? 'Awaiting Evidence' : ucfirst($loan->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm text-slate-700">₹{{ number_format($loan->principal_amount, 2) }}</td>
                                    <td class="px-4 py-4 text-right text-sm font-medium text-slate-800">₹{{ number_format($loan->totalOutstanding(), 2) }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-500">{{ $loan->loan_date?->format('d M Y') }}</td>
                                    <td class="px-4 py-4 text-sm text-slate-500">{{ $loan->maturity_date?->format('d M Y') }}</td>
                                    <td class="px-4 py-4 text-center"><a href="{{ route('dhiran.show', $loan) }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800">View</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- 5. Payments history --}}
        @if($payments->isNotEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-100"><h2 class="text-base font-semibold text-slate-900">Recent payments</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Loan</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Type</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Interest</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Penalty</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($payments as $p)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-3 text-sm text-slate-500 whitespace-nowrap">{{ optional($p->payment_date)->format('d M Y') ?? $p->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-sm font-mono text-slate-600">
                                    @if($p->loan)<a href="{{ route('dhiran.show', $p->loan) }}" class="hover:text-amber-700">{{ $p->loan->loan_number }}</a>@else — @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600">{{ ucwords(str_replace('_', ' ', $p->type)) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600">₹{{ number_format($p->principal_component, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600">₹{{ number_format($p->interest_component, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600">₹{{ number_format($p->penalty_component, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right font-medium text-slate-800">₹{{ number_format($p->amount, 2) }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($p->loan)<a href="{{ route('dhiran.payment-receipt', [$p->loan, $p]) }}" class="text-sm font-semibold text-amber-700 hover:text-amber-800">Receipt</a>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- 6. Pledged items history --}}
        @php $allItems = $loans->flatMap(fn ($l) => $l->items->map(fn ($i) => [$i, $l])); @endphp
        @if($allItems->isNotEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-100"><h2 class="text-base font-semibold text-slate-900">Pledged items</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Item</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Metal</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Purity</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Gross / Net / Fine</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Loan</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Photo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($allItems as [$item, $loan])
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-3 text-sm">
                                    <a href="{{ route('dhiran.items.show', $item) }}" class="text-slate-800 hover:text-amber-700">{{ $item->description }}</a>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 capitalize">{{ $item->metal_type ?? 'gold' }}</td>
                                <td class="px-4 py-3 text-sm text-center text-slate-600">{{ rtrim(rtrim((string) $item->purity, "0"), ".") }}{{ ($item->metal_type ?? 'gold') === 'silver' ? '' : 'K' }}</td>
                                <td class="px-4 py-3 text-sm text-right text-slate-600 whitespace-nowrap">{{ number_format($item->gross_weight, 3) }} / {{ number_format($item->net_metal_weight, 3) }} / {{ number_format($item->fine_weight, 3) }}g</td>
                                <td class="px-4 py-3 text-center text-sm text-slate-600 capitalize">{{ $item->status ?? 'pledged' }}</td>
                                <td class="px-4 py-3 text-sm font-mono"><a href="{{ route('dhiran.show', $loan) }}" class="text-slate-600 hover:text-amber-700">{{ $loan->loan_number }}</a></td>
                                <td class="px-4 py-3 text-center">
                                    @if(in_array($item->id, $itemsWithPhoto))
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-700">Photo</span>
                                    @else
                                        <span class="text-xs text-slate-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-dhiran-layout>
