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
    $initialMode = $isEdit ? $invoice->mode : 'purchase';
    $modeLabel = $initialMode === 'job_work' ? 'Job Work (making + wastage only)' : 'Purchase (full metal + making)';
    $selectedKarigarId = (string) ($jobOrder?->karigar_id ?? $receipt?->jobOrder?->karigar_id ?? '');
    $selectedKarigar = $karigars->firstWhere('id', (int) $selectedKarigarId) ?? $jobOrder?->karigar ?? $receipt?->jobOrder?->karigar;
@endphp

<div class="ki-form-workspace" x-data='invoiceForm(@json($prefillLines), @json($initialMode), @json(json_decode($advancesByKarigar ?? "[]")), @json($selectedKarigarId), @json($selectedKarigar?->name ?? ""), @json($modeLabel))'
     @keydown.escape.window="closeDropdowns()">
    <div class="ki-form-grid">
        <div class="ki-form-main">
            <section class="ki-card ki-section" aria-labelledby="ki-details-title">
                <header class="ki-section-head"><h2 id="ki-details-title" class="ki-title">Invoice Details</h2></header>
                <div class="ki-field-grid">
                    @if(!$isEdit)
                        <label class="ki-field-karigar">
                            <span class="ki-label">Karigar *</span>
                            <select name="karigar_id" required class="ki-control" x-model="selectedKarigarId" @change="onKarigarSelect($event.target.value, $event.target.selectedOptions[0].text)">
                                <option value="">Select karigar…</option>
                                @foreach($karigars as $k)
                                    <option value="{{ $k->id }}">{{ $k->name }}</option>
                                @endforeach
                                @if($selectedKarigar && !$karigars->contains('id', (int) $selectedKarigarId))
                                    <option value="{{ $selectedKarigarId }}">{{ $selectedKarigar->name }} (inactive — linked job)</option>
                                @endif
                            </select>
                        </label>
                    @endif
                    <label>
                        <span class="ki-label">Karigar's Invoice # *</span>
                        <input type="text" name="karigar_invoice_number" required value="{{ $invoice->karigar_invoice_number ?? '' }}" class="ki-control">
                    </label>
                    <label class="ki-field-date">
                        <span class="ki-label">Invoice Date *</span>
                        <input type="date" name="karigar_invoice_date" required value="{{ ($invoice->karigar_invoice_date ?? now())->toDateString() }}" class="ki-control">
                    </label>
                    <label class="ki-field-state">
                        <span class="ki-label">State Code</span>
                        <input type="text" name="state_code" maxlength="5" value="{{ $invoice->state_code ?? '24' }}" class="ki-control">
                    </label>
                    <label class="ki-field-mode">
                        <span class="ki-label">Mode *</span>
                        <select name="mode" class="ki-control" x-model="mode" @change="onModeSelect($event.target.value, $event.target.selectedOptions[0].text)">
                            <option value="purchase">Purchase (full metal + making)</option>
                            <option value="job_work">Job Work (making + wastage only)</option>
                        </select>
                    </label>
                    @if(!$isEdit)
                        <label class="ki-field-job">
                            <span class="ki-label">Linked Job Order</span>
                            <input type="hidden" name="job_order_id" value="{{ $jobOrder?->id ?? $receipt?->job_order_id }}">
                            <input type="text" disabled value="{{ $jobOrder?->job_order_number ?? $receipt?->jobOrder?->job_order_number ?? 'None' }}" class="ki-control">
                        </label>
                    @endif
                    <label class="ki-interstate">
                        <input type="checkbox" name="is_interstate" value="1" {{ ($invoice->is_interstate ?? false) ? 'checked' : '' }} x-model="isInterstate" class="rounded border-gray-300">
                        <span>Inter-state invoice. Use IGST instead of CGST + SGST.</span>
                    </label>
                </div>
            </section>

            <section class="ki-card ki-section" aria-labelledby="ki-reference-title">
                <header class="ki-section-head"><h2 id="ki-reference-title" class="ki-title">Tax & Payment Reference</h2></header>
                <div class="ki-field-grid">
                    <label class="ki-field-tax">
                        <span class="ki-label">CGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="cgst_rate" value="{{ $invoice->cgst_rate ?? '1.5' }}" x-model.number="cgstRate" :disabled="isInterstate" class="ki-control">
                    </label>
                    <label class="ki-field-tax">
                        <span class="ki-label">SGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="sgst_rate" value="{{ $invoice->sgst_rate ?? '1.5' }}" x-model.number="sgstRate" :disabled="isInterstate" class="ki-control">
                    </label>
                    <label class="ki-field-tax">
                        <span class="ki-label">IGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="igst_rate" value="{{ $invoice->igst_rate ?? '0' }}" x-model.number="igstRate" :disabled="!isInterstate" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">Jurisdiction</span>
                        <input type="text" name="jurisdiction" value="{{ $invoice->jurisdiction ?? '' }}" placeholder="e.g. Mandvi-Kutch" class="ki-control">
                    </label>
                    <label class="ki-field-wide">
                        <span class="ki-label">Payment Terms / Bank Reference</span>
                        <input type="text" name="payment_terms" value="{{ $invoice->payment_terms ?? '' }}" placeholder="e.g. RTGS, 11/09/25; Bank: 430640" class="ki-control">
                    </label>
                    <label class="ki-field-words">
                        <span class="ki-label">Amount in Words</span>
                        <input type="text" name="amount_in_words" value="{{ $invoice->amount_in_words ?? '' }}" class="ki-control">
                    </label>
                    <label class="ki-field-words">
                        <span class="ki-label">Tax Amount in Words</span>
                        <input type="text" name="tax_amount_in_words" value="{{ $invoice->tax_amount_in_words ?? '' }}" class="ki-control">
                    </label>
                    <label class="ki-field-wide">
                        <span class="ki-label">Upload Original Invoice (PDF/JPG/PNG)</span>
                        <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="ki-control">
                        @if($isEdit && $invoice->invoice_file_path)
                            <span class="ki-hint">Current: <a href="{{ $invoice->attachmentUrl() }}" target="_blank" rel="noopener">view</a>. Uploading replaces it.</span>
                        @endif
                    </label>
                </div>
            </section>

            <section class="ki-card ki-section" aria-labelledby="ki-lines-title">
                <header class="ki-section-head">
                    <h2 id="ki-lines-title" class="ki-title">Line Items</h2>
                    <button type="button" @click="addLine" class="btn btn-secondary btn-sm ki-add-line"><x-icon name="plus" size="16" />Add Line</button>
                </header>
                <div class="ki-lines">
                    <template x-for="(line, idx) in lines" :key="idx">
                        <section class="ki-line-card" data-turbo-temporary :aria-label="'Item line ' + (idx + 1)">
                            <input type="hidden" :name="'lines[' + idx + '][linked_receipt_item_id]'" :value="line.linked_receipt_item_id || ''">
                            <header class="ki-line-head">
                                <h3>Item <span x-text="idx + 1"></span></h3>
                                <dl class="ki-line-total"><dt>Line Total</dt><dd x-text="formatRupees(lineTotal(line))"></dd></dl>
                                <button type="button" x-show="lines.length > 1" @click="removeLine(idx)" class="jf-row-action jf-row-action--danger" data-tip="Remove line" :aria-label="'Remove item line ' + (idx + 1)"><x-icon name="trash-2" size="16" /></button>
                            </header>
                            <div class="ki-line-grid">
                                <label class="ki-line-description">
                                    <span class="ki-label">Description *</span>
                                    <input type="text" :name="'lines[' + idx + '][description]'" required x-model="line.description" class="ki-control" :aria-label="'Description, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-code">
                                    <span class="ki-label">HSN</span>
                                    <input type="text" :name="'lines[' + idx + '][hsn_code]'" x-model="line.hsn_code" class="ki-control" :aria-label="'HSN, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-pieces">
                                    <span class="ki-label">Pieces *</span>
                                    <input type="number" min="1" :name="'lines[' + idx + '][pieces]'" required x-model.number="line.pieces" class="ki-control text-right" :aria-label="'Pieces, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-weight">
                                    <span class="ki-label">Gross (g) *</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][gross_weight]'" required x-model.number="line.gross_weight" @input="recompute(idx)" class="ki-control text-right" :aria-label="'Gross weight in grams, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-weight">
                                    <span class="ki-label">Stone (g)</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][stone_weight]'" x-model.number="line.stone_weight" @input="recompute(idx)" class="ki-control text-right" :aria-label="'Stone weight in grams, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-weight">
                                    <span class="ki-label">Net (g) *</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][net_weight]'" required x-model.number="line.net_weight" @input="recompute(idx)" class="ki-control text-right" :aria-label="'Net weight in grams, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-code">
                                    <span class="ki-label">Purity</span>
                                    <input type="number" step="0.01" min="0" max="1000" :name="'lines[' + idx + '][purity]'" x-model.number="line.purity" class="ki-control text-right" :aria-label="'Purity, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-amount">
                                    <span class="ki-label">Rate/g (₹)</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][rate_per_gram]'" x-model.number="line.rate_per_gram" @input="recompute(idx)" class="ki-control text-right" :aria-label="'Rate per gram, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-amount">
                                    <span class="ki-label">Metal (₹)</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][metal_amount]'" x-model.number="line.metal_amount" @input="recomputeTotal(idx)" class="ki-control text-right" :aria-label="'Metal amount, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-amount" x-show="mode === 'job_work'">
                                    <span class="ki-label">Making (₹)</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][making_charge]'" x-model.number="line.making_charge" @input="recomputeTotal(idx)" class="ki-control text-right" :aria-label="'Making charge, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-amount" x-show="mode === 'job_work'">
                                    <span class="ki-label">Wastage (₹)</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][wastage_charge]'" x-model.number="line.wastage_charge" @input="recomputeTotal(idx)" class="ki-control text-right" :aria-label="'Wastage charge, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-amount">
                                    <span class="ki-label">Extra (₹)</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][extra_amount]'" x-model.number="line.extra_amount" @input="recomputeTotal(idx)" class="ki-control text-right" :aria-label="'Extra amount, item line ' + (idx + 1)">
                                </label>
                                <label class="ki-line-note">
                                    <span class="ki-label">Note</span>
                                    <input type="text" :name="'lines[' + idx + '][note]'" x-model="line.note" class="ki-control" :aria-label="'Note, item line ' + (idx + 1)">
                                </label>
                            </div>
                        </section>
                    </template>
                </div>
            </section>

            @if(!$isEdit)
                <section class="ki-card ki-section" x-show="karigarAdvances.length > 0" x-cloak aria-labelledby="ki-advances-title">
                    <header class="ki-section-head">
                        <div><h2 id="ki-advances-title" class="ki-title">Advances Already Given</h2><p class="ki-hint">Select payments to apply to this invoice.</p></div>
                    </header>
                    <div class="ki-advances">
                        <template x-for="adv in karigarAdvances" :key="adv.id">
                            <label class="ki-advance" data-turbo-temporary>
                                <input type="checkbox" :name="'advance_payment_ids[]'" :value="adv.id" :checked="checkedAdvanceIds.includes(adv.id)" @change="toggleAdvance(adv.id)" class="rounded border-gray-300 text-amber-600">
                                <strong x-text="formatRupees(adv.amount)"></strong>
                                <span x-text="adv.paid_on"></span>
                                <span x-text="adv.mode"></span>
                                <span x-text="adv.reference || ''"></span>
                            </label>
                        </template>
                    </div>
                    <p class="ki-hint">Total selected: <strong x-text="formatRupees(selectedAdvanceTotal)"></strong> · Remaining due: <strong x-text="formatRupees(Math.max(0, grandTotal - selectedAdvanceTotal))"></strong></p>
                </section>
            @endif
        </div>

        <aside class="ki-card ki-summary jf-scrollbar" aria-label="Invoice summary">
            <header class="ki-summary-head"><h2 class="ki-title">Invoice Summary</h2><p class="ki-hint">Live totals before saving.</p></header>
            <div class="ki-summary-body">
                <dl class="ki-summary-row"><dt>Subtotal</dt><dd x-text="formatRupees(subtotal)"></dd></dl>
                <dl class="ki-summary-row"><dt>Tax</dt><dd x-text="formatRupees(tax)"></dd></dl>
                <dl class="ki-summary-row ki-grand-total"><dt>Grand Total</dt><dd x-text="formatRupees(grandTotal)"></dd></dl>
                <dl class="ki-summary-row"><dt>Total Net Wt</dt><dd x-text="totalNet.toFixed(3) + 'g'"></dd></dl>
                <dl class="ki-summary-row"><dt>Lines</dt><dd x-text="lines.length"></dd></dl>
            </div>
        </aside>
    </div>
</div>

<script>
    function invoiceForm(initialLines, initialMode, allAdvances, selectedKarigarId, selectedKarigarName, initialModeName) {
        return {
            mode: initialMode,
            modeName: initialModeName,
            modeOpen: false,
            karigarOpen: false,
            isInterstate: {{ ($invoice->is_interstate ?? false) ? 'true' : 'false' }},
            cgstRate: {{ (float) ($invoice->cgst_rate ?? 1.5) }},
            sgstRate: {{ (float) ($invoice->sgst_rate ?? 1.5) }},
            igstRate: {{ (float) ($invoice->igst_rate ?? 0) }},
            lines: initialLines,
            selectedKarigarId: selectedKarigarId || '',
            selectedKarigarName: selectedKarigarName || '',
            allAdvances: allAdvances || {},
            checkedAdvanceIds: [],
            closeDropdowns() {
                this.modeOpen = false;
                this.karigarOpen = false;
            },
            onModeSelect(value, label) {
                this.mode = value;
                this.modeName = label;
                this.modeOpen = false;
            },
            onKarigarSelect(value, label) {
                this.selectedKarigarId = value;
                this.selectedKarigarName = value ? label : '';
                this.checkedAdvanceIds = [];
                this.karigarOpen = false;
            },
            toggleAdvance(id) {
                const idx = this.checkedAdvanceIds.indexOf(id);
                if (idx === -1) this.checkedAdvanceIds.push(id);
                else this.checkedAdvanceIds.splice(idx, 1);
            },
            get karigarAdvances() {
                return this.allAdvances[this.selectedKarigarId] || [];
            },
            get selectedAdvanceTotal() {
                return this.karigarAdvances
                    .filter(a => this.checkedAdvanceIds.includes(a.id))
                    .reduce((s, a) => s + a.amount, 0);
            },
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
            recomputeTotal() {},
        };
    }
</script>
