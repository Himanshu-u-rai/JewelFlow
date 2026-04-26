<x-app-layout>
    <x-page-header :title="'Job Order ' . $jobOrder->job_order_number" :subtitle="'Karigar: ' . ($jobOrder->karigar?->name ?? '—') . ' · DC: ' . $jobOrder->challan_number">
        <x-slot:actions>
            <a href="{{ route('job-orders.challan', $jobOrder) }}" target="_blank" class="btn btn-secondary btn-sm">Print Challan</a>
            @if($jobOrder->isOpen())
                <a href="{{ route('job-orders.receive.form', $jobOrder) }}" class="btn btn-success btn-sm">Receive Items</a>
            @endif
            @if($jobOrder->returned_fine_weight > 0 || $jobOrder->status === 'completed')
                <a href="{{ route('job-orders.return-doc', $jobOrder) }}" target="_blank" class="btn btn-secondary btn-sm">Return Doc</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-4">
                <div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold">Issued (fine)</div>
                <div class="text-2xl font-bold text-amber-800 mt-1">{{ number_format($jobOrder->issued_fine_weight, 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                <div class="text-[11px] text-gray-500 mt-1">{{ number_format($jobOrder->issued_gross_weight, 3) }}g gross</div>
            </div>
            <div class="bg-white rounded-xl border border-emerald-200 shadow-sm p-4">
                <div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold">Returned (fine)</div>
                <div class="text-2xl font-bold text-emerald-800 mt-1">{{ number_format($jobOrder->returned_fine_weight, 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                <div class="text-[11px] text-gray-500 mt-1">{{ number_format($jobOrder->returned_gross_weight, 3) }}g gross</div>
            </div>
            <div class="bg-white rounded-xl border border-blue-200 shadow-sm p-4">
                <div class="text-[10px] uppercase tracking-wide text-blue-700 font-semibold">Wastage</div>
                <div class="text-2xl font-bold text-blue-800 mt-1">{{ number_format($jobOrder->actual_wastage_fine, 3) }}<span class="text-xs font-normal ml-1">g</span></div>
                <div class="text-[11px] text-gray-500 mt-1">Allowed: {{ number_format($jobOrder->allowed_wastage_fine, 3) }}g ({{ $jobOrder->allowed_wastage_percent }}%)</div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <div class="text-[10px] uppercase tracking-wide text-slate-700 font-semibold">Status</div>
                <div class="mt-1">
                    <span class="inline-block px-2.5 py-1 rounded-full text-sm font-bold {{ $jobOrder->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jobOrder->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jobOrder->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jobOrder->status) }}</span>
                </div>
                <div class="text-[11px] text-gray-500 mt-2">Issued: {{ $jobOrder->issue_date->format('d M Y') }}</div>
            </div>
        </div>

        @if(!empty($jobOrder->discrepancy_flags))
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 mb-4 flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b42318" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-none mt-0.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div class="flex-1">
                    <p class="text-sm font-bold text-rose-800 mb-1">Discrepancies flagged on this job order</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($jobOrder->discrepancy_flags as $flag)
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-rose-200 text-rose-900">{{ str_replace('_', ' ', $flag) }}</span>
                        @endforeach
                    </div>
                    @if(!$jobOrder->discrepancy_acknowledged && $jobOrder->isOpen())
                        <form method="POST" action="{{ route('job-orders.acknowledge', $jobOrder) }}" class="mt-3" onsubmit="return confirm('Acknowledge discrepancies and mark this job order completed?');">
                            @csrf
                            <button type="submit" class="text-xs px-3 py-1 rounded-lg bg-rose-700 text-white hover:bg-rose-800">Acknowledge & Complete</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-100"><h3 class="text-sm font-semibold text-gray-800">Issuance Lines</h3></div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <th class="px-4 py-2 text-left font-semibold">Lot</th>
                        <th class="px-4 py-2 text-left font-semibold">Purity</th>
                        <th class="px-4 py-2 text-right font-semibold">Gross Wt</th>
                        <th class="px-4 py-2 text-right font-semibold">Fine Wt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($jobOrder->issuances as $iss)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">Lot #{{ $iss->metalLot?->lot_number ?? $iss->metal_lot_id }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ rtrim(rtrim(number_format($iss->purity, 2), '0'), '.') }}K</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($iss->gross_weight, 3) }}g</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($iss->fine_weight, 3) }}g</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Receipts ({{ $jobOrder->receipts->count() }})</h3>
                @if($jobOrder->isOpen())
                    <a href="{{ route('job-orders.receive.form', $jobOrder) }}" class="text-xs text-teal-700 hover:underline">+ Receive items</a>
                @endif
            </div>
            @if($jobOrder->receipts->isEmpty())
                <div class="py-8 text-center text-gray-400 text-sm">No receipts yet.</div>
            @else
                @foreach($jobOrder->receipts as $rcpt)
                    <div class="border-t border-gray-100 first:border-t-0 px-5 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <span class="font-mono text-sm text-gray-800">{{ $rcpt->receipt_number }}</span>
                                <span class="text-xs text-gray-400 ml-2">{{ $rcpt->receipt_date->format('d M Y') }}</span>
                            </div>
                            <div class="text-xs text-gray-600">{{ $rcpt->total_pieces }} pcs · {{ number_format($rcpt->total_net_weight, 3) }}g net · {{ number_format($rcpt->total_fine_weight, 3) }}g fine</div>
                        </div>
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-gray-500">
                                    <th class="text-left font-semibold py-1">Description</th>
                                    <th class="text-left font-semibold py-1">HSN</th>
                                    <th class="text-right font-semibold py-1">Pcs</th>
                                    <th class="text-right font-semibold py-1">Gross</th>
                                    <th class="text-right font-semibold py-1">Stone</th>
                                    <th class="text-right font-semibold py-1">Net</th>
                                    <th class="text-right font-semibold py-1">Purity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rcpt->items as $i)
                                    <tr>
                                        <td class="py-1">{{ $i->description }}</td>
                                        <td class="py-1 text-gray-500">{{ $i->hsn_code }}</td>
                                        <td class="py-1 text-right font-mono">{{ $i->pieces }}</td>
                                        <td class="py-1 text-right font-mono">{{ number_format($i->gross_weight, 3) }}</td>
                                        <td class="py-1 text-right font-mono">{{ number_format($i->stone_weight, 3) }}</td>
                                        <td class="py-1 text-right font-mono">{{ number_format($i->net_weight, 3) }}</td>
                                        <td class="py-1 text-right">{{ rtrim(rtrim(number_format($i->purity, 2), '0'), '.') }}K</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Karigar Invoices ({{ $jobOrder->invoices->count() }})</h3>
                <a href="{{ route('karigar-invoices.create') }}?job_order={{ $jobOrder->id }}" class="text-xs text-teal-700 hover:underline">+ Add Invoice</a>
            </div>
            @if($jobOrder->invoices->isEmpty())
                <div class="py-8 text-center text-gray-400 text-sm">No karigar invoice attached yet.</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2 text-left font-semibold">Invoice #</th>
                            <th class="px-4 py-2 text-left font-semibold">Date</th>
                            <th class="px-4 py-2 text-left font-semibold">Mode</th>
                            <th class="px-4 py-2 text-right font-semibold">Total</th>
                            <th class="px-4 py-2 text-center font-semibold">Payment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($jobOrder->invoices as $inv)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2"><a href="{{ route('karigar-invoices.show', $inv) }}" class="text-teal-700 hover:underline font-mono">{{ $inv->karigar_invoice_number }}</a></td>
                                <td class="px-4 py-2 text-gray-500">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                <td class="px-4 py-2"><span class="text-[10px] uppercase font-semibold text-gray-700">{{ str_replace('_', ' ', $inv->mode) }}</span></td>
                                <td class="px-4 py-2 text-right font-mono">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                <td class="px-4 py-2 text-center"><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $inv->payment_status }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($jobOrder->isOpen())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Other Actions</h3>
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('job-orders.leftover', $jobOrder) }}" class="flex items-end gap-2">
                        @csrf
                        <div>
                            <label class="block text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Record Leftover Bullion (fine g)</label>
                            <input type="number" step="0.001" min="0.001" name="fine_weight" required class="rounded-md border-gray-300 text-sm" style="width:160px;">
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">Credit Vault</button>
                    </form>
                    @if($jobOrder->status === 'issued')
                        <form method="POST" action="{{ route('job-orders.cancel', $jobOrder) }}" onsubmit="return confirm('Cancel job order? This will return all bullion to the source lot.');">
                            @csrf
                            <button type="submit" class="text-xs px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 hover:bg-rose-100">Cancel Job Order</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
