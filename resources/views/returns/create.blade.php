<x-app-layout>
    <x-page-header
        :title="'Process Return — ' . $invoice->invoice_number"
        :subtitle="$invoice->customer ? 'Customer: ' . trim($invoice->customer->first_name . ' ' . $invoice->customer->last_name) : 'No customer linked'">
        <x-slot:actions>
            <a href="{{ route('invoices.show', $invoice) }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Cancel
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-6">

        <x-return-policy-banner />

        @php
            $shopPolicy = $shopPolicy ?? auth()->user()->shop?->preferences;
            $hasDeductions = $shopPolicy && (
                !($shopPolicy->refund_making_charges ?? true) ||
                !($shopPolicy->refund_stone_charges ?? true) ||
                !($shopPolicy->refund_gst ?? true) ||
                ($shopPolicy->wear_loss_pct ?? 0) > 0 ||
                ($shopPolicy->restocking_fee_pct ?? 0) > 0
            );
        @endphp
        @if($hasDeductions)
        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 mb-4 text-sm text-amber-800">
            <span class="font-semibold">Active return policy:</span>
            <ul class="mt-1 ml-4 list-disc space-y-0.5">
                @if(!($shopPolicy->refund_making_charges ?? true))
                    <li>Making charges are not refundable</li>
                @endif
                @if(!($shopPolicy->refund_stone_charges ?? true))
                    <li>Stone charges are not refundable</li>
                @endif
                @if(!($shopPolicy->refund_gst ?? true))
                    <li>GST is not refunded</li>
                @endif
                @if(($shopPolicy->wear_loss_pct ?? 0) > 0)
                    <li>Wear loss deduction: {{ $shopPolicy->wear_loss_pct }}%</li>
                @endif
                @if(($shopPolicy->restocking_fee_pct ?? 0) > 0)
                    <li>Restocking fee: {{ $shopPolicy->restocking_fee_pct }}%</li>
                @endif
            </ul>
            <p class="mt-1 text-amber-700 text-xs">Refund estimates shown below already reflect these deductions.</p>
        </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                Check the items the customer is returning. Pick a condition and disposition for each. The system will issue a credit note for the refund amount and route each piece to the chosen destination.
            </div>
        </div>

        <form method="POST" action="{{ route('returns.store', $invoice) }}" class="space-y-6"
              onsubmit="return confirm('Process this return? A credit note will be issued.');">
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900">Items on this invoice</h2>
                    <label class="text-xs font-semibold text-slate-600 flex items-center gap-2">
                        <input type="checkbox" id="selectAll" class="rounded border-slate-300">
                        Select all returnable
                    </label>
                </div>
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <tr>
                            <th class="px-5 py-3 text-left w-12">Return</th>
                            <th class="px-5 py-3 text-left">Item</th>
                            <th class="px-5 py-3 text-right">Line Total</th>
                            <th class="px-5 py-3 text-left">Condition</th>
                            <th class="px-5 py-3 text-left">What happens to this item</th>
                            <th class="px-5 py-3 text-left">Reason (optional)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($invoice->items as $idx => $line)
                            @php
                                $alreadyReturned = $line->returned_at !== null;
                                $policyResult   = $policyBreakdowns[$line->id] ?? null;
                                $refundEstimate = $policyResult
                                    ? $policyResult->refundTotal
                                    : ((float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off);
                                $hasDeductions  = $policyResult && (
                                    abs($policyResult->refundTotal - ((float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off)) > 0.005
                                );
                            @endphp
                            <tr class="{{ $alreadyReturned ? 'opacity-50 bg-slate-50' : '' }}">
                                <td class="px-5 py-4">
                                    @if($alreadyReturned)
                                        <span class="text-xs text-slate-400 italic">returned</span>
                                    @else
                                        <input type="checkbox" name="lines[{{ $idx }}][selected]" value="1"
                                               class="line-checkbox rounded border-slate-300"
                                               data-line-idx="{{ $idx }}"
                                               {{ old("lines.$idx.selected") ? 'checked' : '' }}>
                                        <input type="hidden" name="lines[{{ $idx }}][invoice_item_id]" value="{{ $line->id }}">
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $line->item?->barcode ?? '—' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $line->item?->design ?? $line->item?->category ?? '—' }}</div>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="text-sm font-semibold text-slate-900">₹{{ number_format((float) $line->line_total, 2) }}</div>
                                    <div class="text-xs {{ $hasDeductions ? 'text-amber-600' : 'text-emerald-700' }} mt-1">
                                        ≈ ₹{{ number_format($refundEstimate, 2) }} refund
                                        @if($hasDeductions)
                                            <span class="ml-1 text-[10px] bg-amber-100 text-amber-700 rounded px-1 py-0.5">policy applied</span>
                                        @endif
                                    </div>
                                    @if(isset($policyBreakdowns[$line->id]))
                                    @php $bd = $policyBreakdowns[$line->id]; @endphp
                                    <details class="text-xs mt-1">
                                        <summary class="cursor-pointer text-indigo-600 hover:text-indigo-800 select-none">Details</summary>
                                        <dl class="mt-1 space-y-0.5 bg-gray-50 rounded p-2 border border-gray-200">
                                            <div class="flex justify-between"><dt class="text-gray-500">Original:</dt><dd>₹{{ number_format($bd->breakdown['original_line_total'] ?? 0, 2) }}</dd></div>
                                            @if(($bd->breakdown['making_retained'] ?? 0) > 0)<div class="flex justify-between text-red-700"><dt>− Making:</dt><dd>₹{{ number_format($bd->breakdown['making_retained'], 2) }}</dd></div>@endif
                                            @if(($bd->breakdown['stone_retained'] ?? 0) > 0)<div class="flex justify-between text-red-700"><dt>− Stone:</dt><dd>₹{{ number_format($bd->breakdown['stone_retained'], 2) }}</dd></div>@endif
                                            @if(($bd->breakdown['gst_charged'] ?? 0) - ($bd->breakdown['gst_refunded'] ?? 0) > 0.005)<div class="flex justify-between text-red-700"><dt>− GST retained:</dt><dd>₹{{ number_format($bd->breakdown['gst_charged'] - $bd->breakdown['gst_refunded'], 2) }}</dd></div>@endif
                                            @if(($bd->breakdown['wear_loss_amount'] ?? 0) > 0)<div class="flex justify-between text-red-700"><dt>− Wear loss:</dt><dd>₹{{ number_format($bd->breakdown['wear_loss_amount'], 2) }}</dd></div>@endif
                                            @if(($bd->breakdown['restocking_fee_amount'] ?? 0) > 0)<div class="flex justify-between text-red-700"><dt>− Restock fee:</dt><dd>₹{{ number_format($bd->breakdown['restocking_fee_amount'], 2) }}</dd></div>@endif
                                            <div class="flex justify-between font-semibold border-t pt-1"><dt>= Refund:</dt><dd class="text-green-700">₹{{ number_format($bd->refundTotal, 2) }}</dd></div>
                                        </dl>
                                    </details>
                                    @endif
                                    @can('returns.approve')
                                    @php
                                        $maxRefundable = (float)$line->line_total + (float)$line->gst_amount
                                                       - (float)$line->allocated_discount + (float)$line->allocated_round_off;
                                    @endphp
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-indigo-600 hover:text-indigo-800 select-none">
                                            Adjust Refund
                                        </summary>
                                        <div class="mt-2 rounded border border-indigo-200 bg-indigo-50 p-3 space-y-2 text-xs">
                                            <p class="text-indigo-700 font-medium">Component adjustments</p>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" name="lines[{{ $idx }}][override_making_charges]" value="1"
                                                       class="rounded text-indigo-600">
                                                <span class="text-gray-700">Refund making charges for this line</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" name="lines[{{ $idx }}][override_stone_charges]" value="1"
                                                       class="rounded text-indigo-600">
                                                <span class="text-gray-700">Refund stone charges for this line</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" name="lines[{{ $idx }}][override_gst]" value="1"
                                                       class="rounded text-indigo-600">
                                                <span class="text-gray-700">Refund GST for this line</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" name="lines[{{ $idx }}][override_waive_restocking]" value="1"
                                                       class="rounded text-indigo-600">
                                                <span class="text-gray-700">Waive restocking fee for this line</span>
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <label class="text-gray-700 shrink-0">Custom wear loss %</label>
                                                <input type="number" name="lines[{{ $idx }}][override_wear_loss_pct]"
                                                       min="0" max="25" step="0.1" placeholder="0–25"
                                                       class="w-20 rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                            </div>

                                            <div class="border-t border-indigo-200 pt-2">
                                                <p class="text-indigo-700 font-medium mb-1">— or enter a manual total —</p>
                                                <div class="flex items-center gap-2">
                                                    <label class="text-gray-700 shrink-0">Manual refund (₹)</label>
                                                    <input type="number" name="lines[{{ $idx }}][override_manual_total]"
                                                           min="0" max="{{ $maxRefundable }}" step="0.01"
                                                           placeholder="0.00"
                                                           class="w-32 rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                                    <span class="text-gray-400">max ₹{{ number_format($maxRefundable, 2) }}</span>
                                                </div>
                                            </div>

                                            <div class="border-t border-indigo-200 pt-2">
                                                <label class="block text-gray-700 font-medium mb-1">
                                                    Override reason <span class="text-red-500">*</span>
                                                    <span class="text-gray-400 font-normal">(required if any adjustment made)</span>
                                                </label>
                                                <textarea name="lines[{{ $idx }}][override_reason]" rows="2" minlength="5" maxlength="500"
                                                          placeholder="e.g. VIP customer — waiving restocking fee as goodwill gesture"
                                                          class="w-full rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                                            </div>
                                        </div>
                                    </details>
                                    @endcan
                                </td>
                                <td class="px-5 py-4">
                                    <select name="lines[{{ $idx }}][condition]"
                                            required
                                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm {{ $alreadyReturned ? 'pointer-events-none' : '' }}"
                                            {{ $alreadyReturned ? 'disabled' : '' }}>
                                        <option value="" disabled {{ old("lines.$idx.condition") ? '' : 'selected' }}>— Select condition —</option>
                                        @foreach($conditions as $val => $label)
                                            <option value="{{ $val }}" @selected(old("lines.$idx.condition") === $val)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-5 py-4">
                                    <select name="lines[{{ $idx }}][disposition]"
                                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm {{ $alreadyReturned ? 'pointer-events-none' : '' }}"
                                            {{ $alreadyReturned ? 'disabled' : '' }}>
                                        @foreach($dispositions as $val => $label)
                                            <option value="{{ $val }}" @selected(old("lines.$idx.disposition") === $val)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-5 py-4">
                                    <input type="text" name="lines[{{ $idx }}][reason]"
                                           value="{{ old("lines.$idx.reason") }}"
                                           maxlength="255"
                                           placeholder="e.g. didn't fit"
                                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm {{ $alreadyReturned ? 'pointer-events-none' : '' }}"
                                           {{ $alreadyReturned ? 'disabled' : '' }}>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Reason for return <span class="text-rose-600">*</span></label>
                    <textarea name="reason" required minlength="5" maxlength="500" rows="2"
                              placeholder="e.g. Customer changed mind, defective hallmark, wrong size"
                              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    @error('reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Refund settlement</label>
                    <div class="space-y-2">
                        @if(in_array($allowedSettlement ?? 'cash_or_credit', ['cash_or_credit', 'cash_only']))
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" name="refund_settlement" value="cash"
                                   @checked(old('refund_settlement', 'cash') === 'cash') class="mt-1 rounded border-slate-300">
                            <span>
                                <span class="font-medium text-slate-900">Cash</span>
                                <span class="block text-xs text-slate-500">Refund the credit note amount in cash now.</span>
                            </span>
                        </label>
                        @endif
                        @if(in_array($allowedSettlement ?? 'cash_or_credit', ['cash_or_credit', 'store_credit_only']))
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" name="refund_settlement" value="store_credit"
                                   @checked(old('refund_settlement', ($allowedSettlement === 'store_credit_only' ? 'store_credit' : '')) === 'store_credit') class="mt-1 rounded border-slate-300"
                                   @if(!$invoice->customer_id) disabled @endif>
                            <span>
                                <span class="font-medium text-slate-900">Store credit</span>
                                <span class="block text-xs text-slate-500">
                                    @if($invoice->customer_id)
                                        Credit the customer's wallet. Apply at a future purchase.
                                    @else
                                        Unavailable — invoice has no customer linked.
                                    @endif
                                </span>
                            </span>
                        </label>
                        @endif
                    </div>
                    @error('refund_settlement')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('approval')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('invoices.show', $invoice) }}"
                   class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Cancel
                </a>
                <button type="submit"
                        class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    Process Return
                </button>
            </div>
        </form>

    </div>

    {{-- Filter-only JS: dropdowns inside lines that aren't checked don't post.
         Server validation ignores any line without invoice_item_id, but to keep
         the payload tidy we only post the rows the user actually checked. --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = Array.from(document.querySelectorAll('.line-checkbox'));

            if (selectAll) {
                selectAll.addEventListener('change', (e) => {
                    checkboxes.forEach(cb => { cb.checked = e.target.checked; });
                });
            }

            // On submit, strip any line group whose checkbox is unchecked so
            // the server's "required" validation on invoice_item_id doesn't fire
            // for non-selected rows.
            const form = document.querySelector('form[action="{{ route('returns.store', $invoice) }}"]');
            if (form) {
                form.addEventListener('submit', () => {
                    checkboxes.forEach(cb => {
                        if (!cb.checked) {
                            const idx = cb.dataset.lineIdx;
                            form.querySelectorAll(`[name^="lines[${idx}]"]`).forEach(el => el.disabled = true);
                        }
                    });
                });
            }
        });
    </script>
</x-app-layout>
