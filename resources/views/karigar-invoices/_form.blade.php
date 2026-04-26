@php
    $isEdit = isset($invoice);
    $prefillLines = [];
    if (! $isEdit) {
        if (isset($receipt) && $receipt) {
            foreach ($receipt->items as $ri) {
                $prefillLines[] = [
                    'description' => $ri->description,
                    'hsn_code' => $ri->hsn_code ?? '7113',
                    'pieces' => $ri->pieces,
                    'gross_weight' => (float) $ri->gross_weight,
                    'stone_weight' => (float) $ri->stone_weight,
                    'net_weight' => (float) $ri->net_weight,
                    'purity' => (float) $ri->purity,
                    'rate_per_gram' => 0,
                    'metal_amount' => 0,
                    'making_charge' => 0,
                    'wastage_charge' => 0,
                    'extra_amount' => 0,
                    'note' => '',
                    'linked_receipt_item_id' => $ri->id,
                ];
            }
        }
        if (empty($prefillLines)) {
            $prefillLines[] = ['description' => '', 'hsn_code' => '7113', 'pieces' => 1, 'gross_weight' => 0, 'stone_weight' => 0, 'net_weight' => 0, 'purity' => 22, 'rate_per_gram' => 0, 'metal_amount' => 0, 'making_charge' => 0, 'wastage_charge' => 0, 'extra_amount' => 0, 'note' => '', 'linked_receipt_item_id' => null];
        }
    } else {
        foreach ($invoice->lines as $l) {
            $prefillLines[] = [
                'description' => $l->description,
                'hsn_code' => $l->hsn_code,
                'pieces' => $l->pieces,
                'gross_weight' => (float) $l->gross_weight,
                'stone_weight' => (float) $l->stone_weight,
                'net_weight' => (float) $l->net_weight,
                'purity' => (float) $l->purity,
                'rate_per_gram' => (float) $l->rate_per_gram,
                'metal_amount' => (float) $l->metal_amount,
                'making_charge' => (float) $l->making_charge,
                'wastage_charge' => (float) $l->wastage_charge,
                'extra_amount' => (float) $l->extra_amount,
                'note' => $l->note,
                'linked_receipt_item_id' => $l->linked_receipt_item_id,
            ];
        }
    }
    $prefillJson = json_encode($prefillLines);
@endphp

<div x-data="invoiceForm({{ $prefillJson }}, '{{ $isEdit ? $invoice->mode : 'purchase' }}', {{ $advancesByKarigar ?? '{}' }})">

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        @if(! $isEdit)
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Karigar *</label>
                <select name="karigar_id" required class="w-full rounded-md border-gray-300 text-sm"
                        x-model="selectedKarigarId" @change="onKarigarChange">
                    <option value="">Select karigar…</option>
                    @foreach($karigars as $k)
                        <option value="{{ $k->id }}" {{ ($jobOrder?->karigar_id ?? $receipt?->jobOrder?->karigar_id) == $k->id ? 'selected' : '' }}>{{ $k->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Linked Job Order</label>
                <input type="hidden" name="job_order_id" value="{{ $jobOrder?->id ?? $receipt?->job_order_id }}">
                <input type="text" disabled value="{{ ($jobOrder?->job_order_number) ?? ($receipt?->jobOrder?->job_order_number) ?? '— None —' }}" class="w-full rounded-md border-gray-300 text-sm bg-gray-50">
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Mode *</label>
            <select name="mode" required class="w-full rounded-md border-gray-300 text-sm" x-model="mode">
                <option value="purchase">Purchase (full metal + making)</option>
                <option value="job_work">Job Work (making + wastage only)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Karigar's Invoice # *</label>
            <input type="text" name="karigar_invoice_number" required value="{{ $invoice->karigar_invoice_number ?? '' }}" class="w-full rounded-md border-gray-300 text-sm font-mono">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Invoice Date *</label>
            <input type="date" name="karigar_invoice_date" required value="{{ ($invoice->karigar_invoice_date ?? now())->toDateString() }}" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">State Code</label>
            <input type="text" name="state_code" maxlength="5" value="{{ $invoice->state_code ?? '24' }}" class="w-full rounded-md border-gray-300 text-sm font-mono">
        </div>
        <div class="flex items-end">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="is_interstate" value="1" {{ ($invoice->is_interstate ?? false) ? 'checked' : '' }} x-model="isInterstate" class="rounded border-gray-300">
                <span class="text-xs font-semibold text-gray-700">Inter-state (use IGST instead of CGST + SGST)</span>
            </label>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">CGST Rate (%)</label>
            <input type="number" step="0.01" min="0" max="50" name="cgst_rate" value="{{ $invoice->cgst_rate ?? '1.5' }}" :disabled="isInterstate" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">SGST Rate (%)</label>
            <input type="number" step="0.01" min="0" max="50" name="sgst_rate" value="{{ $invoice->sgst_rate ?? '1.5' }}" :disabled="isInterstate" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">IGST Rate (%)</label>
            <input type="number" step="0.01" min="0" max="50" name="igst_rate" value="{{ $invoice->igst_rate ?? '0' }}" :disabled="!isInterstate" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Jurisdiction</label>
            <input type="text" name="jurisdiction" value="{{ $invoice->jurisdiction ?? '' }}" placeholder="e.g. Mandvi-Kutch" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-gray-700 mb-1">Payment Terms / Bank Reference</label>
            <input type="text" name="payment_terms" value="{{ $invoice->payment_terms ?? '' }}" placeholder="e.g. RTGS, 11/09/25; Bank: 430640" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Amount in Words</label>
            <input type="text" name="amount_in_words" value="{{ $invoice->amount_in_words ?? '' }}" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Tax Amount in Words</label>
            <input type="text" name="tax_amount_in_words" value="{{ $invoice->tax_amount_in_words ?? '' }}" class="w-full rounded-md border-gray-300 text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-gray-700 mb-1">Upload Original Invoice (PDF/JPG/PNG)</label>
            <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-md border-gray-300 text-sm">
            @if($isEdit && $invoice->invoice_file_path)
                <p class="text-[11px] text-gray-500 mt-1">Current: <a href="{{ asset('storage/' . $invoice->invoice_file_path) }}" target="_blank" class="text-teal-700 underline">view</a> — uploading replaces it.</p>
            @endif
        </div>
    </div>

    <div class="border-t border-gray-200 pt-4 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-800">Line Items</h3>
            <button type="button" @click="addLine" class="text-xs text-teal-700 hover:underline">+ Add line</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[10px] uppercase tracking-wide text-gray-500">
                        <th class="text-left py-1 font-semibold">Description *</th>
                        <th class="text-left py-1 font-semibold">HSN</th>
                        <th class="text-right py-1 font-semibold">Pcs *</th>
                        <th class="text-right py-1 font-semibold">Gross *</th>
                        <th class="text-right py-1 font-semibold">Stone</th>
                        <th class="text-right py-1 font-semibold">Net *</th>
                        <th class="text-right py-1 font-semibold">Purity</th>
                        <th class="text-right py-1 font-semibold">Rate/g</th>
                        <th class="text-right py-1 font-semibold">Metal ₹</th>
                        <th class="text-right py-1 font-semibold" x-show="mode === 'job_work'">Making</th>
                        <th class="text-right py-1 font-semibold" x-show="mode === 'job_work'">Wastage</th>
                        <th class="text-right py-1 font-semibold">Extra</th>
                        <th class="text-right py-1 font-semibold">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(line, idx) in lines" :key="idx">
                        <tr class="border-t border-gray-100">
                            <input type="hidden" :name="'lines[' + idx + '][linked_receipt_item_id]'" :value="line.linked_receipt_item_id || ''">
                            <td class="py-1 pr-1"><input type="text" :name="'lines[' + idx + '][description]'" required x-model="line.description" class="w-full rounded-md border-gray-300 text-xs" style="min-width:140px;"></td>
                            <td class="py-1 pr-1"><input type="text" :name="'lines[' + idx + '][hsn_code]'" x-model="line.hsn_code" class="rounded-md border-gray-300 text-xs" style="width:60px;"></td>
                            <td class="py-1 pr-1"><input type="number" min="1" :name="'lines[' + idx + '][pieces]'" required x-model.number="line.pieces" class="rounded-md border-gray-300 text-xs text-right" style="width:55px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.001" min="0" :name="'lines[' + idx + '][gross_weight]'" required x-model.number="line.gross_weight" @input="recompute(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:80px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.001" min="0" :name="'lines[' + idx + '][stone_weight]'" x-model.number="line.stone_weight" @input="recompute(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:70px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.001" min="0" :name="'lines[' + idx + '][net_weight]'" required x-model.number="line.net_weight" @input="recompute(idx)" class="rounded-md border-gray-300 text-xs text-right font-semibold" style="width:80px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.01" min="0" max="100" :name="'lines[' + idx + '][purity]'" x-model.number="line.purity" class="rounded-md border-gray-300 text-xs text-right" style="width:60px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.01" min="0" :name="'lines[' + idx + '][rate_per_gram]'" x-model.number="line.rate_per_gram" @input="recompute(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:80px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.01" min="0" :name="'lines[' + idx + '][metal_amount]'" x-model.number="line.metal_amount" @input="recomputeTotal(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:90px;"></td>
                            <td class="py-1 pr-1" x-show="mode === 'job_work'"><input type="number" step="0.01" min="0" :name="'lines[' + idx + '][making_charge]'" x-model.number="line.making_charge" @input="recomputeTotal(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:80px;"></td>
                            <td class="py-1 pr-1" x-show="mode === 'job_work'"><input type="number" step="0.01" min="0" :name="'lines[' + idx + '][wastage_charge]'" x-model.number="line.wastage_charge" @input="recomputeTotal(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:80px;"></td>
                            <td class="py-1 pr-1"><input type="number" step="0.01" min="0" :name="'lines[' + idx + '][extra_amount]'" x-model.number="line.extra_amount" @input="recomputeTotal(idx)" class="rounded-md border-gray-300 text-xs text-right" style="width:75px;"></td>
                            <td class="py-1 pr-1 text-right font-mono font-semibold" x-text="formatRupees(lineTotal(line))"></td>
                            <td class="py-1"><button type="button" x-show="lines.length > 1" @click="removeLine(idx)" class="text-rose-600 text-xs">×</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-gray-50 rounded-lg p-4 mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Subtotal</div><div class="font-mono font-bold" x-text="formatRupees(subtotal)"></div></div>
            <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Tax</div><div class="font-mono font-bold" x-text="formatRupees(tax)"></div></div>
            <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Grand Total</div><div class="font-mono font-bold text-amber-700 text-lg" x-text="formatRupees(grandTotal)"></div></div>
            <div><div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Total Net Wt</div><div class="font-mono font-bold" x-text="totalNet.toFixed(3) + 'g'"></div></div>
        </div>
    </div>

    @if(! $isEdit)
    {{-- Advance / Prior Payments --}}
    <div x-show="karigarAdvances.length > 0" x-cloak class="border border-blue-200 rounded-xl p-4 mb-4 bg-blue-50">
        <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 mb-2">
            Advances Already Given to this Karigar
            <span class="text-blue-500 font-normal normal-case ml-1">(check to apply to this invoice)</span>
        </div>
        <div class="space-y-2">
            <template x-for="adv in karigarAdvances" :key="adv.id">
                <label class="flex items-center gap-3 bg-white border border-blue-200 rounded-lg px-3 py-2 cursor-pointer hover:border-blue-400 text-sm">
                    <input type="checkbox"
                           :name="'advance_payment_ids[]'"
                           :value="adv.id"
                           class="rounded border-gray-300 text-blue-600">
                    <span class="font-mono font-semibold text-blue-800" x-text="formatRupees(adv.amount)"></span>
                    <span class="text-gray-500 text-xs" x-text="adv.paid_on"></span>
                    <span class="text-xs uppercase font-semibold text-gray-600 bg-gray-100 rounded px-1.5 py-0.5" x-text="adv.mode"></span>
                    <span class="text-xs text-gray-400" x-text="adv.reference || ''"></span>
                </label>
            </template>
        </div>
        <div class="mt-2 text-xs text-blue-600">
            Total selected: <span class="font-semibold font-mono" x-text="formatRupees(selectedAdvanceTotal)"></span>
            &nbsp;·&nbsp; Remaining due after advance: <span class="font-semibold font-mono" x-text="formatRupees(Math.max(0, grandTotal - selectedAdvanceTotal))"></span>
        </div>
    </div>
    @endif
</div>

<script>
    function invoiceForm(initialLines, initialMode, allAdvances) {
        return {
            mode: initialMode,
            isInterstate: {{ ($invoice->is_interstate ?? false) ? 'true' : 'false' }},
            cgstRate: {{ (float) ($invoice->cgst_rate ?? 1.5) }},
            sgstRate: {{ (float) ($invoice->sgst_rate ?? 1.5) }},
            igstRate: {{ (float) ($invoice->igst_rate ?? 0) }},
            lines: initialLines,
            selectedKarigarId: '{{ ($jobOrder?->karigar_id ?? $receipt?->jobOrder?->karigar_id ?? '') }}',
            allAdvances: allAdvances || {},
            get karigarAdvances() {
                return this.allAdvances[this.selectedKarigarId] || [];
            },
            get selectedAdvanceTotal() {
                // Sum checked advances — we read the DOM checkboxes
                return this.karigarAdvances
                    .filter(a => document.querySelector(`input[value="${a.id}"][type="checkbox"]`)?.checked)
                    .reduce((s, a) => s + a.amount, 0);
            },
            onKarigarChange(e) { this.selectedKarigarId = e.target.value; },
            get subtotal() { return this.lines.reduce((s, l) => s + this.lineTotal(l), 0); },
            get totalNet() { return this.lines.reduce((s, l) => s + (parseFloat(l.net_weight) || 0), 0); },
            get tax() {
                if (this.isInterstate) return this.subtotal * (this.igstRate || 0) / 100;
                return this.subtotal * (((this.cgstRate || 0) + (this.sgstRate || 0)) / 100);
            },
            get grandTotal() { return this.subtotal + this.tax; },
            lineTotal(line) {
                return (parseFloat(line.metal_amount) || 0)
                    + (parseFloat(line.making_charge) || 0)
                    + (parseFloat(line.wastage_charge) || 0)
                    + (parseFloat(line.extra_amount) || 0);
            },
            formatRupees(n) {
                return '₹' + (n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
            addLine() { this.lines.push({ description: '', hsn_code: '7113', pieces: 1, gross_weight: 0, stone_weight: 0, net_weight: 0, purity: 22, rate_per_gram: 0, metal_amount: 0, making_charge: 0, wastage_charge: 0, extra_amount: 0, note: '', linked_receipt_item_id: null }); },
            removeLine(i) { this.lines.splice(i, 1); },
            recompute(idx) {
                const l = this.lines[idx];
                const g = parseFloat(l.gross_weight) || 0;
                const s = parseFloat(l.stone_weight) || 0;
                if (! l.net_weight || l.net_weight == 0 || (g - s).toFixed(3) != (parseFloat(l.net_weight)).toFixed(3)) {
                    l.net_weight = Math.max(0, g - s);
                }
                const net = parseFloat(l.net_weight) || 0;
                const rate = parseFloat(l.rate_per_gram) || 0;
                if (rate > 0) {
                    l.metal_amount = +(net * rate).toFixed(2);
                }
            },
            recomputeTotal() { /* x-text recomputes via lineTotal */ },
        };
    }
</script>
