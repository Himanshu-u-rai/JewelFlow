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
    $selectedKarigar = $karigars->firstWhere('id', (int) $selectedKarigarId);
@endphp

<style>
    [x-cloak] {
        display: none !important;
    }

    .ki-form-shell {
        max-width: 1280px;
    }

    .ki-form-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 18px;
        align-items: start;
    }

    .ki-card {
        border: 1px solid #dbe3ee;
        border-radius: 20px;
        background: #ffffff;
        box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
    }

    .ki-section {
        padding: 18px;
        border-bottom: 1px solid #e2e8f0;
    }

    .ki-section:last-child {
        border-bottom: 0;
    }

    .ki-section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 16px;
    }

    .ki-title {
        margin: 0;
        color: #0f172a;
        font-size: 15px;
        font-weight: 900;
    }

    .ki-copy {
        margin-top: 3px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.45;
    }

    .ki-field-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .ki-field-full {
        grid-column: 1 / -1;
    }

    .ki-label {
        display: block;
        margin-bottom: 6px;
        color: #334155;
        font-size: 12px;
        font-weight: 800;
    }

    .ki-control {
        width: 100%;
        min-height: 44px;
        border-radius: 13px;
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #0f172a;
        font-size: 14px;
    }

    .ki-control:focus {
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
    }

    .ki-combobox {
        position: relative;
    }

    .ki-combobox-trigger {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
        min-height: 44px;
        border: 1px solid #cbd5e1;
        border-radius: 13px;
        background: #f8fafc;
        padding: 10px 12px;
        color: #0f172a;
        font-size: 14px;
        text-align: left;
    }

    .ki-combobox-trigger:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
    }

    .ki-combobox-placeholder {
        color: #64748b;
    }

    .ki-combobox-menu {
        position: absolute;
        z-index: 40;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        overflow: hidden;
        border: 1px solid #dbe3ee;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 18px 36px rgba(15, 23, 42, .16);
    }

    .ki-combobox-list {
        max-height: 240px;
        overflow-y: auto;
        padding: 6px;
    }

    .ki-combobox-option {
        display: block;
        width: 100%;
        border-radius: 10px;
        padding: 10px 11px;
        color: #0f172a;
        font-size: 14px;
        font-weight: 800;
        text-align: left;
    }

    .ki-combobox-option:hover,
    .ki-combobox-option-selected {
        background: #f0fdfa;
        color: #0f766e;
    }

    .ki-line-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #f8fafc;
        padding: 14px;
    }

    .ki-line-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .ki-add-line {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 34px;
        border: 1px solid #dbe3ee;
        border-radius: 999px;
        background: #f8fafc;
        padding: 7px 12px;
        color: #0f172a;
        font-size: 12px;
        font-weight: 900;
        box-shadow: 0 8px 16px rgba(15, 23, 42, .05);
    }

    .ki-remove-line {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        border-radius: 12px;
        border: 1px solid #fecdd3;
        background: #fff1f2;
        padding: 8px 12px;
        color: #be123c;
        font-size: 12px;
        font-weight: 900;
    }

    .ki-summary {
        position: sticky;
        top: 92px;
    }

    .ki-summary-head,
    .ki-summary-body {
        padding: 16px;
    }

    .ki-summary-head {
        border-bottom: 1px solid #e2e8f0;
    }

    .ki-summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        border-bottom: 1px solid #edf2f7;
        padding: 11px 0;
    }

    .ki-summary-row:first-child {
        padding-top: 0;
    }

    .ki-summary-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .ki-summary-label {
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
    }

    .ki-summary-value {
        color: #0f172a;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-size: 15px;
        font-weight: 900;
        text-align: right;
    }

    .ki-advance-box {
        border: 1px solid #bfdbfe;
        border-radius: 16px;
        background: #eff6ff;
        padding: 14px;
    }

    .ki-form-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 18px;
    }

    .ki-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        border-radius: 13px;
        border: 1px solid #0f766e;
        background: #0f766e;
        padding: 10px 16px;
        color: #ffffff;
        font-size: 14px;
        font-weight: 900;
        box-shadow: 0 12px 24px rgba(15, 118, 110, .18);
    }

    .ki-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        border-radius: 13px;
        border: 1px solid #dbe3ee;
        background: #ffffff;
        padding: 10px 15px;
        color: #475569;
        font-size: 14px;
        font-weight: 800;
    }

    @media (max-width: 1120px) {
        .ki-form-grid {
            grid-template-columns: 1fr;
        }

        .ki-summary {
            position: static;
        }
    }

    @media (max-width: 760px) {
        .ki-form-shell {
            padding-inline: 10px;
        }

        .ki-form-grid {
            gap: 12px;
        }

        .ki-card {
            border-radius: 16px;
        }

        .ki-section,
        .ki-summary-head,
        .ki-summary-body {
            padding: 14px;
        }

        .ki-section-head {
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .ki-copy {
            display: none;
        }

        .ki-field-grid,
        .ki-line-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .ki-control,
        .ki-combobox-trigger {
            min-height: 40px;
            border-radius: 11px;
        }

        .ki-label {
            margin-bottom: 5px;
            font-size: 11px;
        }

        .ki-combobox-menu {
            position: fixed;
            top: auto;
            right: 14px;
            bottom: 16px;
            left: 14px;
            max-height: 55vh;
            border: 1.5px solid #0f766e;
            border-radius: 16px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, .24), 0 0 0 4px rgba(15, 118, 110, .08);
        }

        .ki-combobox-list {
            max-height: 55vh;
            padding: 8px;
        }

        .ki-combobox-option {
            border: 1px solid #e2e8f0;
            margin-bottom: 7px;
            background: #ffffff;
        }

        .ki-combobox-option:last-child {
            margin-bottom: 0;
        }

        .ki-combobox-option:hover,
        .ki-combobox-option-selected {
            border-color: #0f766e;
            background: #f0fdfa;
        }

        .ki-summary-body {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .ki-summary-row {
            display: block;
            border: 1px solid #edf2f7;
            border-radius: 12px;
            padding: 9px;
        }

        .ki-summary-row:first-child,
        .ki-summary-row:last-child {
            padding: 9px;
        }

        .ki-summary-value {
            display: block;
            margin-top: 4px;
            text-align: left;
            font-size: 13px;
        }

        .ki-form-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .ki-submit,
        .ki-cancel,
        .ki-add-line {
            width: 100%;
        }
    }

    @media (max-width: 380px) {
        .ki-field-grid,
        .ki-line-grid,
        .ki-summary-body {
            grid-template-columns: 1fr;
        }
    }
</style>

<div x-data="invoiceForm(@json($prefillLines), @js($initialMode), {!! $advancesByKarigar ?? '{}' !!}, @js($selectedKarigarId), @js($selectedKarigar?->name ?? ''), @js($modeLabel))"
     @keydown.escape.window="closeDropdowns()">
    <div class="ki-form-grid">
        <div class="ki-card">
            <section class="ki-section">
                <div class="ki-section-head">
                    <div>
                        <h2 class="ki-title">Invoice Details</h2>
                        <p class="ki-copy">Capture the karigar invoice identity, tax mode, and reference information.</p>
                    </div>
                </div>

                <div class="ki-field-grid">
                    @if(! $isEdit)
                        <div class="ki-field-full ki-combobox" @click.outside="karigarOpen = false">
                            <span class="ki-label">Karigar *</span>
                            <input type="hidden" name="karigar_id" x-model="selectedKarigarId">
                            <button type="button" class="ki-combobox-trigger" @click="karigarOpen = ! karigarOpen" :aria-expanded="karigarOpen.toString()">
                                <span :class="selectedKarigarName ? '' : 'ki-combobox-placeholder'" x-text="selectedKarigarName || 'Select karigar...'">Select karigar...</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div class="ki-combobox-menu" x-show="karigarOpen" x-transition.origin.top x-cloak>
                                <div class="ki-combobox-list">
                                    <button type="button" class="ki-combobox-option" @click="onKarigarSelect('', 'Select karigar...')">Select karigar...</button>
                                    @foreach($karigars as $k)
                                        <button type="button"
                                                class="ki-combobox-option"
                                                :class="selectedKarigarId === '{{ $k->id }}' ? 'ki-combobox-option-selected' : ''"
                                                @click="onKarigarSelect('{{ $k->id }}', @js($k->name))">{{ $k->name }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <label class="ki-field-full">
                            <span class="ki-label">Linked Job Order</span>
                            <input type="hidden" name="job_order_id" value="{{ $jobOrder?->id ?? $receipt?->job_order_id }}">
                            <input type="text" disabled value="{{ ($jobOrder?->job_order_number) ?? ($receipt?->jobOrder?->job_order_number) ?? 'None' }}" class="ki-control">
                        </label>
                    @endif

                    <div class="ki-combobox" @click.outside="modeOpen = false">
                        <span class="ki-label">Mode *</span>
                        <input type="hidden" name="mode" x-model="mode">
                        <button type="button" class="ki-combobox-trigger" @click="modeOpen = ! modeOpen" :aria-expanded="modeOpen.toString()">
                            <span x-text="modeName">Purchase (full metal + making)</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="ki-combobox-menu" x-show="modeOpen" x-transition.origin.top x-cloak>
                            <div class="ki-combobox-list">
                                <button type="button" class="ki-combobox-option" :class="mode === 'purchase' ? 'ki-combobox-option-selected' : ''" @click="onModeSelect('purchase', 'Purchase (full metal + making)')">Purchase (full metal + making)</button>
                                <button type="button" class="ki-combobox-option" :class="mode === 'job_work' ? 'ki-combobox-option-selected' : ''" @click="onModeSelect('job_work', 'Job Work (making + wastage only)')">Job Work (making + wastage only)</button>
                            </div>
                        </div>
                    </div>

                    <label>
                        <span class="ki-label">Karigar's Invoice # *</span>
                        <input type="text" name="karigar_invoice_number" required value="{{ $invoice->karigar_invoice_number ?? '' }}" class="ki-control font-mono">
                    </label>

                    <label>
                        <span class="ki-label">Invoice Date *</span>
                        <input type="date" name="karigar_invoice_date" required value="{{ ($invoice->karigar_invoice_date ?? now())->toDateString() }}" class="ki-control">
                    </label>

                    <label>
                        <span class="ki-label">State Code</span>
                        <input type="text" name="state_code" maxlength="5" value="{{ $invoice->state_code ?? '24' }}" class="ki-control font-mono">
                    </label>

                    <label class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                        <input type="checkbox" name="is_interstate" value="1" {{ ($invoice->is_interstate ?? false) ? 'checked' : '' }} x-model="isInterstate" class="rounded border-gray-300">
                        <span class="text-xs font-semibold text-gray-700">Inter-state invoice. Use IGST instead of CGST + SGST.</span>
                    </label>
                </div>
            </section>

            <section class="ki-section">
                <div class="ki-section-head">
                    <div>
                        <h2 class="ki-title">Tax & Payment Reference</h2>
                        <p class="ki-copy">Rates update the live summary on the right.</p>
                    </div>
                </div>

                <div class="ki-field-grid">
                    <label>
                        <span class="ki-label">CGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="cgst_rate" value="{{ $invoice->cgst_rate ?? '1.5' }}" x-model.number="cgstRate" :disabled="isInterstate" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">SGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="sgst_rate" value="{{ $invoice->sgst_rate ?? '1.5' }}" x-model.number="sgstRate" :disabled="isInterstate" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">IGST Rate (%)</span>
                        <input type="number" step="0.01" min="0" max="50" name="igst_rate" value="{{ $invoice->igst_rate ?? '0' }}" x-model.number="igstRate" :disabled="!isInterstate" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">Jurisdiction</span>
                        <input type="text" name="jurisdiction" value="{{ $invoice->jurisdiction ?? '' }}" placeholder="e.g. Mandvi-Kutch" class="ki-control">
                    </label>
                    <label class="ki-field-full">
                        <span class="ki-label">Payment Terms / Bank Reference</span>
                        <input type="text" name="payment_terms" value="{{ $invoice->payment_terms ?? '' }}" placeholder="e.g. RTGS, 11/09/25; Bank: 430640" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">Amount in Words</span>
                        <input type="text" name="amount_in_words" value="{{ $invoice->amount_in_words ?? '' }}" class="ki-control">
                    </label>
                    <label>
                        <span class="ki-label">Tax Amount in Words</span>
                        <input type="text" name="tax_amount_in_words" value="{{ $invoice->tax_amount_in_words ?? '' }}" class="ki-control">
                    </label>
                    <label class="ki-field-full">
                        <span class="ki-label">Upload Original Invoice (PDF/JPG/PNG)</span>
                        <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" class="ki-control bg-white p-2">
                        @if($isEdit && $invoice->invoice_file_path)
                            <p class="mt-1 text-[11px] text-gray-500">Current: <a href="{{ asset('storage/' . $invoice->invoice_file_path) }}" target="_blank" class="text-teal-700 underline">view</a>. Uploading replaces it.</p>
                        @endif
                    </label>
                </div>
            </section>

            <section class="ki-section">
                <div class="ki-section-head">
                    <div>
                        <h2 class="ki-title">Line Items</h2>
                        <p class="ki-copy">Responsive item cards replace the old wide table while keeping the same submitted fields.</p>
                    </div>
                    <button type="button" @click="addLine" class="ki-add-line">+ Add line</button>
                </div>

                <div class="space-y-3">
                    <template x-for="(line, idx) in lines" :key="idx">
                        <div class="ki-line-card">
                            <input type="hidden" :name="'lines[' + idx + '][linked_receipt_item_id]'" :value="line.linked_receipt_item_id || ''">
                            <div class="ki-line-grid">
                                <label class="ki-field-full">
                                    <span class="ki-label">Description *</span>
                                    <input type="text" :name="'lines[' + idx + '][description]'" required x-model="line.description" class="ki-control">
                                </label>
                                <label>
                                    <span class="ki-label">HSN</span>
                                    <input type="text" :name="'lines[' + idx + '][hsn_code]'" x-model="line.hsn_code" class="ki-control">
                                </label>
                                <label>
                                    <span class="ki-label">Pieces *</span>
                                    <input type="number" min="1" :name="'lines[' + idx + '][pieces]'" required x-model.number="line.pieces" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Gross *</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][gross_weight]'" required x-model.number="line.gross_weight" @input="recompute(idx)" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Stone</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][stone_weight]'" x-model.number="line.stone_weight" @input="recompute(idx)" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Net *</span>
                                    <input type="number" step="0.001" min="0" :name="'lines[' + idx + '][net_weight]'" required x-model.number="line.net_weight" @input="recompute(idx)" class="ki-control text-right font-semibold">
                                </label>
                                <label>
                                    <span class="ki-label">Purity</span>
                                    <input type="number" step="0.01" min="0" max="1000" :name="'lines[' + idx + '][purity]'" x-model.number="line.purity" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Rate/g</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][rate_per_gram]'" x-model.number="line.rate_per_gram" @input="recompute(idx)" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Metal ₹</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][metal_amount]'" x-model.number="line.metal_amount" @input="recomputeTotal(idx)" class="ki-control text-right">
                                </label>
                                <label x-show="mode === 'job_work'">
                                    <span class="ki-label">Making</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][making_charge]'" x-model.number="line.making_charge" @input="recomputeTotal(idx)" class="ki-control text-right">
                                </label>
                                <label x-show="mode === 'job_work'">
                                    <span class="ki-label">Wastage</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][wastage_charge]'" x-model.number="line.wastage_charge" @input="recomputeTotal(idx)" class="ki-control text-right">
                                </label>
                                <label>
                                    <span class="ki-label">Extra</span>
                                    <input type="number" step="0.01" min="0" :name="'lines[' + idx + '][extra_amount]'" x-model.number="line.extra_amount" @input="recomputeTotal(idx)" class="ki-control text-right">
                                </label>
                                <label class="ki-field-full">
                                    <span class="ki-label">Note</span>
                                    <input type="text" :name="'lines[' + idx + '][note]'" x-model="line.note" class="ki-control">
                                </label>
                                <div class="ki-field-full flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                                    <span class="text-xs font-bold text-amber-700">Line Total</span>
                                    <span class="font-mono font-black text-amber-800" x-text="formatRupees(lineTotal(line))"></span>
                                </div>
                                <button type="button" x-show="lines.length > 1" @click="removeLine(idx)" class="ki-remove-line">Remove line</button>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            @if(! $isEdit)
            <section x-show="karigarAdvances.length > 0" x-cloak class="ki-section">
                <div class="ki-advance-box">
                    <div class="text-xs font-semibold uppercase tracking-wide text-blue-700 mb-2">
                        Advances Already Given
                        <span class="text-blue-500 font-normal normal-case ml-1">select payments to apply to this invoice</span>
                    </div>
                    <div class="space-y-2">
                        <template x-for="adv in karigarAdvances" :key="adv.id">
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm hover:border-blue-400">
                                <input type="checkbox" :name="'advance_payment_ids[]'" :value="adv.id" :checked="checkedAdvanceIds.includes(adv.id)" @change="toggleAdvance(adv.id)" class="rounded border-gray-300 text-blue-600">
                                <span class="font-mono font-semibold text-blue-800" x-text="formatRupees(adv.amount)"></span>
                                <span class="text-xs text-gray-500" x-text="adv.paid_on"></span>
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold uppercase text-gray-600" x-text="adv.mode"></span>
                                <span class="text-xs text-gray-400" x-text="adv.reference || ''"></span>
                            </label>
                        </template>
                    </div>
                    <div class="mt-2 text-xs text-blue-600">
                        Total selected: <span class="font-mono font-semibold" x-text="formatRupees(selectedAdvanceTotal)"></span>
                        &nbsp;·&nbsp; Remaining due: <span class="font-mono font-semibold" x-text="formatRupees(Math.max(0, grandTotal - selectedAdvanceTotal))"></span>
                    </div>
                </div>
            </section>
            @endif
        </div>

        <aside class="ki-card ki-summary">
            <div class="ki-summary-head">
                <h2 class="ki-title">Invoice Summary</h2>
                <p class="ki-copy">Live totals before saving.</p>
            </div>
            <div class="ki-summary-body">
                <div class="ki-summary-row">
                    <span class="ki-summary-label">Subtotal</span>
                    <span class="ki-summary-value" x-text="formatRupees(subtotal)"></span>
                </div>
                <div class="ki-summary-row">
                    <span class="ki-summary-label">Tax</span>
                    <span class="ki-summary-value" x-text="formatRupees(tax)"></span>
                </div>
                <div class="ki-summary-row">
                    <span class="ki-summary-label">Grand Total</span>
                    <span class="ki-summary-value text-amber-700" x-text="formatRupees(grandTotal)"></span>
                </div>
                <div class="ki-summary-row">
                    <span class="ki-summary-label">Total Net Wt</span>
                    <span class="ki-summary-value" x-text="totalNet.toFixed(3) + 'g'"></span>
                </div>
                <div class="ki-summary-row">
                    <span class="ki-summary-label">Lines</span>
                    <span class="ki-summary-value" x-text="lines.length"></span>
                </div>
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
