<x-app-layout>
    <x-page-header :title="'Invoice ' . $invoice->karigar_invoice_number" :subtitle="$invoice->karigar?->name . ' · ' . $invoice->karigar_invoice_date->format('d M Y')">
        <x-slot:actions>
            <a href="{{ route('karigar-invoices.print', $invoice) }}" target="_blank" class="btn btn-secondary btn-sm">Print</a>
            <a href="{{ route('karigar-invoices.edit', $invoice) }}" class="btn btn-secondary btn-sm">Edit</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        @if(! empty($invoice->discrepancy_flags))
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 mb-4">
                <p class="text-sm font-bold text-rose-800 mb-2">Discrepancies flagged</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($invoice->discrepancy_flags as $flag)
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-rose-200 text-rose-900">{{ str_replace('_', ' ', $flag) }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 lg:col-span-3">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 text-sm">
                    <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Mode</div><div class="font-semibold uppercase">{{ str_replace('_', ' ', $invoice->mode) }}</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Pieces</div><div class="font-mono">{{ $invoice->total_pieces }}</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Net Wt</div><div class="font-mono">{{ number_format($invoice->total_net_weight, 3) }}g</div></div>
                    <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Subtotal</div><div class="font-mono">₹{{ number_format($invoice->total_before_tax, 2) }}</div></div>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wide text-gray-500 border-b border-gray-200">
                            <th class="text-left py-2 font-semibold">Description</th>
                            <th class="text-left py-2 font-semibold">HSN</th>
                            <th class="text-right py-2 font-semibold">Pcs</th>
                            <th class="text-right py-2 font-semibold">Net Wt</th>
                            <th class="text-right py-2 font-semibold">Rate/g</th>
                            <th class="text-right py-2 font-semibold">Metal ₹</th>
                            @if($invoice->isJobWorkMode())
                                <th class="text-right py-2 font-semibold">Making</th>
                                <th class="text-right py-2 font-semibold">Wastage</th>
                            @endif
                            <th class="text-right py-2 font-semibold">Extra</th>
                            <th class="text-right py-2 font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($invoice->lines as $line)
                            <tr>
                                <td class="py-2">{{ $line->description }}</td>
                                <td class="py-2 text-gray-500 text-xs">{{ $line->hsn_code }}</td>
                                <td class="py-2 text-right font-mono">{{ $line->pieces }}</td>
                                <td class="py-2 text-right font-mono">{{ number_format($line->net_weight, 3) }}g</td>
                                <td class="py-2 text-right font-mono">₹{{ number_format($line->rate_per_gram, 2) }}</td>
                                <td class="py-2 text-right font-mono">₹{{ number_format($line->metal_amount, 2) }}</td>
                                @if($invoice->isJobWorkMode())
                                    <td class="py-2 text-right font-mono">₹{{ number_format($line->making_charge, 2) }}</td>
                                    <td class="py-2 text-right font-mono">₹{{ number_format($line->wastage_charge, 2) }}</td>
                                @endif
                                <td class="py-2 text-right font-mono">₹{{ number_format($line->extra_amount, 2) }}</td>
                                <td class="py-2 text-right font-mono font-semibold">₹{{ number_format($line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-4">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">Tax Summary</h3>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="font-mono">₹{{ number_format($invoice->total_before_tax, 2) }}</dd></div>
                    @if($invoice->cgst_amount > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">CGST @ {{ $invoice->cgst_rate }}%</dt><dd class="font-mono">₹{{ number_format($invoice->cgst_amount, 2) }}</dd></div>
                    @endif
                    @if($invoice->sgst_amount > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">SGST @ {{ $invoice->sgst_rate }}%</dt><dd class="font-mono">₹{{ number_format($invoice->sgst_amount, 2) }}</dd></div>
                    @endif
                    @if($invoice->igst_amount > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">IGST @ {{ $invoice->igst_rate }}%</dt><dd class="font-mono">₹{{ number_format($invoice->igst_amount, 2) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-gray-200 pt-2"><dt class="font-bold">Grand Total</dt><dd class="font-mono font-bold text-amber-700">₹{{ number_format($invoice->total_after_tax, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Paid</dt><dd class="font-mono">₹{{ number_format($invoice->amount_paid, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="font-bold text-rose-700">Due</dt><dd class="font-mono font-bold text-rose-700">₹{{ number_format($invoice->amount_due, 2) }}</dd></div>
                </dl>
                @if($invoice->invoice_file_path)
                    <a href="{{ asset('storage/' . $invoice->invoice_file_path) }}" target="_blank" class="block mt-3 text-xs text-teal-700 hover:underline">View original PDF/image</a>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Payments</h3>
            </div>
            @if($invoice->payments->isEmpty())
                <div class="py-6 text-center text-gray-400 text-sm">No payments yet.</div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2 text-left font-semibold">Date</th>
                            <th class="px-4 py-2 text-left font-semibold">Mode</th>
                            <th class="px-4 py-2 text-left font-semibold">Account</th>
                            <th class="px-4 py-2 text-left font-semibold">Reference</th>
                            <th class="px-4 py-2 text-right font-semibold">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($invoice->payments as $pay)
                            <tr>
                                <td class="px-4 py-2 text-gray-500">{{ $pay->paid_on->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-xs uppercase font-semibold text-gray-700">{{ $pay->mode }}</td>
                                <td class="px-4 py-2 text-gray-700">{{ $pay->paymentMethod?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-500 text-xs">{{ $pay->reference }}</td>
                                <td class="px-4 py-2 text-right font-mono">₹{{ number_format($pay->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($invoice->amount_due > 0)
                <div class="border-t border-gray-100 p-4"
                     x-data="{
                        splits: [{ amount: '{{ number_format($invoice->amount_due, 2, '.', '') }}', mode: 'cash', payment_method_id: '', reference: '', paid_on: '{{ now()->toDateString() }}' }],
                        get splitTotal() { return this.splits.reduce((s,p) => s + (parseFloat(p.amount)||0), 0); },
                        addSplit() { this.splits.push({ amount: '', mode: 'cash', payment_method_id: '', reference: '', paid_on: '{{ now()->toDateString() }}' }); },
                        removeSplit(i) { this.splits.splice(i, 1); }
                     }">
                    <form method="POST" action="{{ route('karigar-invoices.pay', $invoice) }}">
                        @csrf
                        <div class="space-y-2 mb-3">
                            <template x-for="(split, i) in splits" :key="i">
                                <div class="flex flex-wrap items-end gap-2 bg-gray-50 rounded-lg px-3 py-2">
                                    <div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Amount</div>
                                        <input type="number" step="0.01" min="0.01"
                                               :name="'payments[' + i + '][amount]'" required
                                               x-model="split.amount"
                                               class="rounded-md border-gray-300 text-sm" style="width:120px;">
                                    </div>
                                    <div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Mode</div>
                                        <select :name="'payments[' + i + '][mode]'" required x-model="split.mode" class="rounded-md border-gray-300 text-sm">
                                            <option value="cash">Cash</option>
                                            <option value="upi">UPI</option>
                                            <option value="bank">Bank</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Account</div>
                                        <select :name="'payments[' + i + '][payment_method_id]'" x-model="split.payment_method_id" class="rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            @foreach($paymentMethods as $pm)
                                                <option value="{{ $pm->id }}">{{ $pm->name }} ({{ $pm->type }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Reference</div>
                                        <input type="text" :name="'payments[' + i + '][reference]'" x-model="split.reference"
                                               placeholder="UTR / cheque #" class="rounded-md border-gray-300 text-sm" style="width:150px;">
                                    </div>
                                    <div>
                                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Date</div>
                                        <input type="date" :name="'payments[' + i + '][paid_on]'" required x-model="split.paid_on" class="rounded-md border-gray-300 text-sm">
                                    </div>
                                    <button type="button" @click="removeSplit(i)" x-show="splits.length > 1"
                                            class="text-rose-500 hover:text-rose-700 text-lg font-bold leading-none pb-1">×</button>
                                </div>
                            </template>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn btn-success btn-sm">Record Payment</button>
                            <button type="button" @click="addSplit"
                                    class="text-xs text-teal-700 hover:underline">+ Add another mode</button>
                            <span class="text-xs text-gray-500 ml-auto">
                                Total: <span class="font-mono font-semibold" x-text="'₹' + splitTotal.toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                                @if($invoice->amount_due > 0)
                                    / Due: <span class="font-mono font-semibold text-rose-600">₹{{ number_format($invoice->amount_due, 2) }}</span>
                                @endif
                            </span>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
