<x-app-layout>
    <x-page-header
        :title="'Process Exchange — ' . $invoice->invoice_number"
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

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="flex items-center gap-0 rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="flex-1 flex items-center gap-3 px-5 py-3 bg-blue-50 border-r border-slate-200">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-white text-xs font-bold flex-shrink-0">1</span>
                <span class="text-sm font-medium text-blue-900">Select items to return</span>
            </div>
            <div class="flex-1 flex items-center gap-3 px-5 py-3 bg-blue-50 border-r border-slate-200">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-white text-xs font-bold flex-shrink-0">2</span>
                <span class="text-sm font-medium text-blue-900">Add new items</span>
            </div>
            <div class="flex-1 flex items-center gap-3 px-5 py-3 bg-slate-50">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-400 text-white text-xs font-bold flex-shrink-0">3</span>
                <span class="text-sm font-medium text-slate-600">Review &amp; settle</span>
            </div>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m4 6H8m0 0l4 4m-4-4l4-4"/></svg>
            <div>
                Process the return and new sale in one step. Pick items being returned on the left, scan/type barcodes of new items on the right. System computes the net settlement automatically.
            </div>
        </div>

        <form method="POST" action="{{ route('exchanges.unified.store', $invoice) }}" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- LEFT: items being returned --}}
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200 bg-rose-50/40">
                        <h2 class="text-lg font-semibold text-slate-900">← Returned (from {{ $invoice->invoice_number }})</h2>
                        <p class="text-xs text-slate-500 mt-1">Pick which items the customer is bringing back. Default disposition is restock.</p>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left w-10"></th>
                                <th class="px-4 py-3 text-left">Item</th>
                                <th class="px-4 py-3 text-right">Line Total</th>
                                <th class="px-4 py-3 text-left">What happens to this item</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($invoice->items as $idx => $line)
                                @php $already = $line->returned_at !== null; @endphp
                                <tr class="{{ $already ? 'opacity-50 bg-slate-50' : '' }}">
                                    <td class="px-4 py-3">
                                        @if($already)
                                            <span class="text-xs text-slate-400 italic">returned</span>
                                        @else
                                            <input type="checkbox" name="lines[{{ $idx }}][selected]" value="1"
                                                   class="line-checkbox rounded border-slate-300" data-line-idx="{{ $idx }}"
                                                   {{ old("lines.$idx.selected") ? 'checked' : '' }}>
                                            <input type="hidden" name="lines[{{ $idx }}][invoice_item_id]" value="{{ $line->id }}">
                                            <input type="hidden" name="lines[{{ $idx }}][condition]" value="good_condition">
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-900">{{ $line->item?->barcode ?? '—' }}</div>
                                        <div class="text-xs text-slate-500 mt-1">{{ $line->item?->design ?? $line->item?->category }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900" data-return-total data-line-idx="{{ $idx }}" data-amount="{{ (float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off }}">
                                        ₹{{ number_format((float) $line->line_total + (float) $line->gst_amount - (float) $line->allocated_discount + (float) $line->allocated_round_off, 2) }}
                                        @can('returns.approve')
                                        @php
                                            $maxRefundable = (float)$line->line_total + (float)$line->gst_amount
                                                           - (float)$line->allocated_discount + (float)$line->allocated_round_off;
                                        @endphp
                                        <details class="mt-2 text-left">
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
                                    <td class="px-4 py-3">
                                        <select name="lines[{{ $idx }}][disposition]"
                                                class="w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs {{ $already ? 'pointer-events-none' : '' }}"
                                                {{ $already ? 'disabled' : '' }}>
                                            @foreach($dispositions as $val => $label)
                                                <option value="{{ $val }}" @selected(old("lines.$idx.disposition", 'restocked') === $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- RIGHT: new items being taken --}}
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200 bg-emerald-50/40">
                        <h2 class="text-lg font-semibold text-slate-900">→ New Sale</h2>
                        <p class="text-xs text-slate-500 mt-1">Scan or type the barcode(s) of items the customer is taking. Comma-separated.</p>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-[0.15em] text-slate-500 mb-2">Barcodes</label>
                            <textarea name="new_item_barcodes" id="new_item_barcodes" rows="3" required
                                      placeholder="e.g. JFLOW0123, JFLOW0124"
                                      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-mono">{{ old('new_item_barcodes') }}</textarea>
                            <p class="text-xs text-slate-500 mt-1">Each barcode must refer to an in-stock item with a selling price set.</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500 mb-2">Estimated total</p>
                            <p class="text-2xl font-bold text-emerald-700">
                                <span id="newSaleEstimate">—</span>
                            </p>
                            <p class="text-xs text-slate-500 mt-1">Exact total computed at submit time using each item's current price + GST.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Settings + summary row --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <label class="block text-xs font-semibold uppercase tracking-[0.15em] text-slate-500 mb-2">Valuation Basis</label>

                    <div class="space-y-1">
                        <div class="flex items-start gap-3 py-2">
                            <input type="radio" id="basis_sale_day" name="valuation_basis_source" value="sale_day_rate" class="mt-0.5 h-4 w-4"
                                {{ old('valuation_basis_source', $defaultBasis) === 'sale_day_rate' ? 'checked' : '' }}
                                onchange="toggleOverrideSection(this.value)">
                            <label for="basis_sale_day" class="flex-1 cursor-pointer">
                                <span class="font-medium text-sm">Sale-day rate{{ $defaultBasis === 'sale_day_rate' ? ' (shop default)' : '' }}</span>
                                <span class="block text-xs text-slate-500">Value returned items at the rate they were originally sold.</span>
                            </label>
                        </div>
                        <div class="flex items-start gap-3 py-2">
                            <input type="radio" id="basis_today" name="valuation_basis_source" value="today_rate" class="mt-0.5 h-4 w-4"
                                {{ old('valuation_basis_source', $defaultBasis) === 'today_rate' ? 'checked' : '' }}
                                onchange="toggleOverrideSection(this.value)">
                            <label for="basis_today" class="flex-1 cursor-pointer">
                                <span class="font-medium text-sm">Today's rate{{ $defaultBasis === 'today_rate' ? ' (shop default)' : '' }}</span>
                                <span class="block text-xs text-slate-500">Revalues each returned line at today's gold rate from daily pricing.</span>
                            </label>
                        </div>

                        @can('exchanges.override_rate')
                            @unless($shop->preferences?->exchange_rate_basis_locked ?? false)
                            <div class="flex items-start gap-3 py-2">
                                <input type="radio" id="basis_manual" name="valuation_basis_source" value="manual_override" class="mt-0.5 h-4 w-4"
                                    {{ old('valuation_basis_source') === 'manual_override' ? 'checked' : '' }}
                                    onchange="toggleOverrideSection(this.value)">
                                <label for="basis_manual" class="flex-1 cursor-pointer">
                                    <span class="font-medium text-sm">Manual Rate Override</span>
                                    <span class="block text-xs text-slate-500">Specify a custom gold rate. Requires owner permission and must be within ±20% of today's rate.</span>
                                </label>
                            </div>
                            @endunless
                        @endcan
                    </div>

                    <div id="override-rate-section" class="{{ old('valuation_basis_source') === 'manual_override' ? '' : 'hidden' }} mt-3 pl-2 border-l-2 border-amber-300 pl-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Override Rate (₹/gram, 24k)</label>
                        <input type="number" name="gold_rate_per_gram_override" step="0.01" min="0"
                            value="{{ old('gold_rate_per_gram_override') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm w-48">
                        <label class="block text-sm font-medium text-gray-700 mt-2 mb-1">Reason for override</label>
                        <input type="text" name="override_reason" maxlength="500"
                            value="{{ old('override_reason') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm w-full" placeholder="e.g. Agreed rate from prior negotiation">
                        @error('gold_rate_per_gram_override')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('override_reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-amber-800 mb-2">Owner approval</p>
                    <p class="text-sm text-amber-700">
                        When the selected valuation basis differs from the shop default ({{ str_replace('_', ' ', $defaultBasis) }}), owner-level permission is required. This is verified automatically based on your account role.
                    </p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <label class="block text-xs font-semibold uppercase tracking-[0.15em] text-slate-500 mb-2">Reason <span class="text-rose-600">*</span></label>
                    <textarea name="reason" rows="2" required minlength="5" maxlength="500"
                              placeholder="e.g. Customer upgraded to a bigger chain"
                              class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                </div>
            </div>

            {{-- Net preview card --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Return Refund (est)</p>
                    <p class="text-xl font-semibold text-rose-700 mt-1">−₹<span id="returnEstimate">0.00</span></p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">New Sale (est)</p>
                    <p class="text-xl font-semibold text-emerald-700 mt-1">+₹<span id="newSaleEstimate2">0.00</span></p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Net</p>
                    <p class="text-2xl font-bold mt-1" id="netLabel">—</p>
                    <p class="text-xs text-slate-500 mt-1" id="netDirection"></p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('invoices.show', $invoice) }}"
                   class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Cancel
                </a>
                <button type="submit"
                        class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    Process Exchange
                </button>
            </div>
        </form>
    </div>

    <script>
        function toggleOverrideSection(value) {
            const section = document.getElementById('override-rate-section');
            if (section) {
                section.classList.toggle('hidden', value !== 'manual_override');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Strip unchecked-line groups on submit so server validation only sees selected items.
            const form = document.querySelector('form[action="{{ route('exchanges.unified.store', $invoice) }}"]');
            const checkboxes = Array.from(document.querySelectorAll('.line-checkbox'));
            const returnTotals = Array.from(document.querySelectorAll('[data-return-total]'));
            const barcodes = document.getElementById('new_item_barcodes');
            const returnEst = document.getElementById('returnEstimate');
            const newSaleEst = document.getElementById('newSaleEstimate');
            const newSaleEst2 = document.getElementById('newSaleEstimate2');
            const netLabel = document.getElementById('netLabel');
            const netDir = document.getElementById('netDirection');

            const fmt = (n) => Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const recompute = () => {
                // Return estimate = sum of selected lines' totals
                let returnTotal = 0;
                checkboxes.forEach(cb => {
                    if (cb.checked) {
                        const idx = cb.dataset.lineIdx;
                        const cell = document.querySelector(`[data-return-total][data-line-idx="${idx}"]`);
                        if (cell) returnTotal += parseFloat(cell.dataset.amount || '0');
                    }
                });
                returnEst.textContent = fmt(returnTotal);
                // New sale is only known at submit (we don't fetch prices client-side in MVP)
                const barcodeCount = (barcodes.value || '').split(/[,\s]+/).filter(Boolean).length;
                newSaleEst.textContent = barcodeCount > 0 ? `${barcodeCount} item(s) — total at submit` : '—';
                newSaleEst2.textContent = '— (computed at submit)';
                netLabel.textContent = barcodeCount > 0 ? 'Computed at submit' : '—';
                netDir.textContent = barcodeCount > 0 ? 'Based on items selected; system computes exact net after lookup.' : '';
            };

            checkboxes.forEach(cb => cb.addEventListener('change', recompute));
            barcodes.addEventListener('input', recompute);
            recompute();

            form.addEventListener('submit', () => {
                checkboxes.forEach(cb => {
                    if (!cb.checked) {
                        const idx = cb.dataset.lineIdx;
                        form.querySelectorAll(`[name^="lines[${idx}]"]`).forEach(el => el.disabled = true);
                    }
                });
            });
        });
    </script>
</x-app-layout>
