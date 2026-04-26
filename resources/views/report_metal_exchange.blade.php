<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">{{ __('Metal Exchange Report') }}</h1>
            <p class="text-sm text-gray-500 mt-1">Old Gold &amp; Old Silver received as payment at POS</p>
        </div>
        <div class="page-actions flex flex-wrap items-end gap-2">
            <form method="GET" action="{{ route('report.metal-exchange') }}" class="flex flex-wrap gap-2 items-end">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 font-medium">From</label>
                    <input type="date" name="from" value="{{ $from }}"
                           class="rounded-md border-gray-300 shadow-sm text-sm" style="height:38px;">
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 font-medium">To</label>
                    <input type="date" name="to" value="{{ $to }}"
                           class="rounded-md border-gray-300 shadow-sm text-sm" style="height:38px;">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm" style="height:38px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Filter
                </button>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner">

        {{-- Summary cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            {{-- Gold summary --}}
            <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-5">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Old Gold Received</div>
                        <div class="text-xs text-gray-400">{{ $goldSummary['count'] }} transaction{{ $goldSummary['count'] == 1 ? '' : 's' }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Gross Weight</div>
                        <div class="text-lg font-bold text-amber-700">{{ number_format($goldSummary['gross'], 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Fine Weight</div>
                        <div class="text-lg font-bold text-amber-700">{{ number_format($goldSummary['fine'], 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Total Value</div>
                        <div class="text-lg font-bold text-amber-700">₹{{ number_format($goldSummary['value'], 0) }}</div>
                    </div>
                </div>
            </div>

            {{-- Silver summary --}}
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Old Silver Received</div>
                        <div class="text-xs text-gray-400">{{ $silverSummary['count'] }} transaction{{ $silverSummary['count'] == 1 ? '' : 's' }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Gross Weight</div>
                        <div class="text-lg font-bold text-slate-700">{{ number_format($silverSummary['gross'], 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Fine Weight</div>
                        <div class="text-lg font-bold text-slate-700">{{ number_format($silverSummary['fine'], 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Total Value</div>
                        <div class="text-lg font-bold text-slate-700">₹{{ number_format($silverSummary['value'], 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Transactions table --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-800">All Transactions</h2>
                <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} — {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</span>
            </div>

            @if($rows->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-3 opacity-40"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p class="text-sm">No metal exchange transactions in this period.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                                <th class="px-4 py-3 text-left font-semibold">Date</th>
                                <th class="px-4 py-3 text-left font-semibold">Invoice</th>
                                <th class="px-4 py-3 text-left font-semibold">Customer</th>
                                <th class="px-4 py-3 text-center font-semibold">Type</th>
                                <th class="px-4 py-3 text-right font-semibold">Gross Wt (g)</th>
                                <th class="px-4 py-3 text-right font-semibold">Purity</th>
                                <th class="px-4 py-3 text-right font-semibold">Test Loss %</th>
                                <th class="px-4 py-3 text-right font-semibold">Fine Wt (g)</th>
                                <th class="px-4 py-3 text-right font-semibold">Rate/g (₹)</th>
                                <th class="px-4 py-3 text-right font-semibold">Value (₹)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($rows as $row)
                                @php
                                    $isGold = $row->mode === 'old_gold';
                                    $customer = $row->invoice?->customer;
                                @endphp
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                        {{ $row->created_at->format('d M Y') }}
                                        <div class="text-xs text-gray-400">{{ $row->created_at->format('h:i A') }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($row->invoice)
                                            <a href="{{ route('invoices.show', $row->invoice) }}"
                                               class="text-teal-700 font-medium hover:underline">
                                                {{ $row->invoice->invoice_number }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ $customer?->name ?? '—' }}
                                        @if($customer?->phone)
                                            <div class="text-xs text-gray-400">{{ $customer->phone }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($isGold)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                Gold
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="12" cy="12" r="10"/></svg>
                                                Silver
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-gray-700">{{ number_format($row->metal_gross_weight, 3) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-600">
                                        {{ $row->metal_purity }}{{ $isGold ? 'K' : '‰' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600">{{ $row->metal_test_loss ?? 0 }}%</td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold {{ $isGold ? 'text-amber-700' : 'text-slate-700' }}">
                                        {{ number_format($row->metal_fine_weight, 3) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-gray-700">{{ number_format($row->metal_rate_per_gram, 2) }}</td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-gray-900">₹{{ number_format($row->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                            <tr class="text-sm font-semibold text-gray-700">
                                <td colspan="4" class="px-4 py-3 text-right text-xs uppercase tracking-wide text-gray-500">Totals</td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($rows->sum('metal_gross_weight'), 3) }} g</td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($rows->sum('metal_fine_weight'), 3) }} g</td>
                                <td class="px-4 py-3"></td>
                                <td class="px-4 py-3 text-right font-mono">₹{{ number_format($rows->sum('amount'), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>

    </div>
</x-app-layout>
