<x-app-layout>
    <x-page-header title="Karigar Invoices" subtitle="Tax invoices received from karigars">
        <x-slot:actions>
            <a href="{{ route('karigar-invoices.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Invoice
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="GET" class="bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Payment Status</label>
                <select name="payment_status" class="rounded-md border-gray-300 text-sm" style="height:34px;">
                    <option value="">All</option>
                    @foreach(['unpaid','partial','paid'] as $s)
                        <option value="{{ $s }}" {{ $filterStatus === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Karigar</label>
                <select name="karigar_id" class="rounded-md border-gray-300 text-sm" style="height:34px;">
                    <option value="">All</option>
                    @foreach($karigars as $k)
                        <option value="{{ $k->id }}" {{ (string) $filterKarigar === (string) $k->id ? 'selected' : '' }}>{{ $k->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:34px;">Filter</button>
        </form>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            @if($invoices->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <p class="text-sm">No karigar invoices match.</p>
                </div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3 text-left font-semibold">Invoice #</th>
                            <th class="px-4 py-3 text-left font-semibold">Karigar</th>
                            <th class="px-4 py-3 text-left font-semibold">Mode</th>
                            <th class="px-4 py-3 text-left font-semibold">Date</th>
                            <th class="px-4 py-3 text-left font-semibold">Job Order</th>
                            <th class="px-4 py-3 text-right font-semibold">Net Wt</th>
                            <th class="px-4 py-3 text-right font-semibold">Total</th>
                            <th class="px-4 py-3 text-center font-semibold">Payment</th>
                            <th class="px-4 py-3 text-left font-semibold">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($invoices as $inv)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('karigar-invoices.show', $inv) }}'"  >
                                <td class="px-4 py-3"><a href="{{ route('karigar-invoices.show', $inv) }}" class="text-teal-700 font-mono hover:underline">{{ $inv->karigar_invoice_number }}</a></td>
                                <td class="px-4 py-3 text-gray-700">{{ $inv->karigar?->name }}</td>
                                <td class="px-4 py-3 text-xs uppercase font-semibold text-gray-600">{{ str_replace('_', ' ', $inv->mode) }}</td>
                                <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                <td class="px-4 py-3">
                                    @if($inv->jobOrder)
                                        <a href="{{ route('job-orders.show', $inv->jobOrder) }}" class="text-xs text-teal-700 font-mono hover:underline">{{ $inv->jobOrder->job_order_number }}</a>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono">{{ number_format($inv->total_net_weight, 3) }}g</td>
                                <td class="px-4 py-3 text-right font-mono font-semibold">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                <td class="px-4 py-3 text-center"><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span></td>
                                <td class="px-4 py-3">
                                    @foreach($inv->discrepancy_flags ?? [] as $flag)
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800 mr-1">{{ str_replace('_', ' ', $flag) }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="p-4">{{ $invoices->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
