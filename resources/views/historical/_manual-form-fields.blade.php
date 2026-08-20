{{-- Shared field markup for the manual entry form and its preview screen.
     Values come from old() so the preview screen (which flashes the
     submitted input before rendering) shows exactly what was typed.
     Fieldset/legend kept for accessibility (screen readers announce the
     group name) — only the visual wrapper changed to match the shared
     card system used across the app. --}}
@once
<script>
    // Blank template for one item row — the exact 14 canonical keys
    // StoreManualHistoricalRequest::lines() / HistoricalFields::LINE accept.
    // Kept in one place so manual.blade.php and manual-preview.blade.php
    // never grow two divergent row shapes.
    window.historicalBlankLine = function historicalBlankLine() {
        return {
            line_item_name: '', line_sku: '', line_hsn: '', line_quantity: '',
            line_purity: '', line_gross_weight: '', line_net_weight: '',
            line_stone_weight: '', line_metal_value: '', line_stone_value: '',
            line_making_label: '', line_making_value: '', line_rate: '', line_total: '',
        };
    };

    // Same "any non-empty field counts, zero counts" rule the backend uses
    // in StoreManualHistoricalRequest::lines() — a row this calls blank is
    // exactly the row that request drops before it ever reaches the normalizer.
    window.historicalLineIsBlank = function historicalLineIsBlank(line) {
        return Object.values(line || {}).every((v) => v === null || v === undefined || String(v).trim() === '');
    };

    // Normalizes old('lines', []) — an array on a clean submit, but Laravel
    // hands back an object once any index is missing — into full row objects.
    window.historicalSeedLines = function historicalSeedLines(raw) {
        return Object.values(raw || {}).map((row) => Object.assign(historicalBlankLine(), row || {}));
    };

    // The one invariant the register keeps: at least 4 rows, and the last one
    // always blank, so the operator never has to click Add to keep typing.
    window.historicalPadLines = function historicalPadLines(lines) {
        const next = (lines || []).slice();
        while (next.length < 4) next.push(historicalBlankLine());
        if (!historicalLineIsBlank(next[next.length - 1])) next.push(historicalBlankLine());
        return next;
    };

    window.historicalRemoveLine = function historicalRemoveLine(lines, index) {
        const next = (lines || []).slice();
        next.splice(index, 1);
        return historicalPadLines(next);
    };
</script>
@endonce
<div class="grid grid-cols-1 gap-4 items-start" style="--app-control-bg: #ffffff; --app-control-border: #cbd5e1; --app-control-border-focus: #b45309;" data-historical-manual-layout>
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-historical-identity-row>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="document">
    <legend class="sr-only">Document identity</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Document identity</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 sm:p-6">
        <div>
            <label for="original_document_number">Original invoice number <span class="text-slate-400 font-normal">(optional)</span></label>
            <input type="text" id="original_document_number" name="original_document_number" value="{{ old('original_document_number') }}" class="w-full">
        </div>
        <div>
            <label for="document_series">Series <span class="text-slate-400 font-normal">(optional)</span></label>
            <input type="text" id="document_series" name="document_series" value="{{ old('document_series') }}" class="w-full">
        </div>
        <div>
            <label for="document_date">Document date <span class="text-rose-600">*</span></label>
            <input type="date" id="document_date" name="document_date" value="{{ old('document_date') }}" required aria-required="true" class="w-full">
        </div>
        <div>
            <label for="source_system">Source system</label>
            <input type="text" id="source_system" name="source_system" value="{{ old('source_system', 'Manual') }}" class="w-full">
        </div>
    </div>
</fieldset>

<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="customer">
    <legend class="sr-only">Customer snapshot (never linked automatically)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Customer snapshot</h2>
        <p class="mt-1 text-xs text-slate-500">Never linked automatically.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 sm:p-6">
        <div>
            <label for="customer_name">Name</label>
            <input type="text" id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="w-full">
        </div>
        <div>
            <label for="customer_mobile">Mobile</label>
            <input type="text" id="customer_mobile" name="customer_mobile" value="{{ old('customer_mobile') }}" class="w-full">
        </div>
        <div>
            <label for="customer_gstin">GSTIN</label>
            <input type="text" id="customer_gstin" name="customer_gstin" value="{{ old('customer_gstin') }}" class="w-full">
        </div>
        <div>
            <label for="place_of_supply">Place of supply</label>
            <input type="text" id="place_of_supply" name="place_of_supply" value="{{ old('place_of_supply') }}" class="w-full">
        </div>
        <div class="sm:col-span-2">
            <label for="customer_address">Address</label>
            <input type="text" id="customer_address" name="customer_address" value="{{ old('customer_address') }}" class="w-full">
        </div>
    </div>
</fieldset>
</div>

{{-- One register for every item row. The table is intentionally shared by
     create and preview/edit; Alpine remains the sole owner of row state. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden min-w-0 max-w-full" data-historical-form-section data-historical-section="items">
    <legend class="sr-only">Item lines (optional)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Item lines <span class="text-slate-400 font-normal text-sm">(optional)</span></h2>
    </div>
    {{-- This exact bubbled listener is Claude's auto-expansion contract. --}}
    <div class="p-4 sm:p-6" @input.debounce.400ms="lines = historicalPadLines(lines)">
        <p class="text-sm text-slate-600">Start entering items below. A new blank row appears automatically; completely blank rows are ignored.</p>
        <p class="mt-1 text-xs text-slate-500 md:hidden">Swipe sideways to view all item columns</p>

        <div class="mt-4 max-w-full overflow-x-auto rounded-xl border border-slate-200" data-historical-item-table-scroll>
            <table class="min-w-[1100px] w-full text-sm" data-historical-item-table>
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">#</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Item name</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">SKU</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">HSN</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Qty</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Purity</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Gross wt</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Net wt</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Stone wt</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Metal value</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Stone value</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Making label</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Making value</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Rate</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Line total</th>
                        <th scope="col" class="border-b border-slate-200 px-3 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600 whitespace-nowrap">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(line, i) in lines" :key="i">
                        <tr class="hover:bg-slate-50" data-historical-item-row>
                            <th scope="row" x-text="i + 1" class="border-b border-slate-200 px-3 py-2 text-center text-sm font-semibold tabular-nums text-slate-500 whitespace-nowrap"></th>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_item_name]`" x-model="line.line_item_name" placeholder="Item name" x-bind:aria-label="`Item ${i + 1} — Item name`" class="w-40 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_sku]`" x-model="line.line_sku" placeholder="SKU" x-bind:aria-label="`Item ${i + 1} — SKU`" class="w-28 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_hsn]`" x-model="line.line_hsn" placeholder="HSN" x-bind:aria-label="`Item ${i + 1} — HSN`" class="w-24 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_quantity]`" x-model="line.line_quantity" placeholder="Qty" x-bind:aria-label="`Item ${i + 1} — Quantity`" type="number" step="any" class="w-20 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_purity]`" x-model="line.line_purity" placeholder="Purity" x-bind:aria-label="`Item ${i + 1} — Purity`" class="w-24 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_gross_weight]`" x-model="line.line_gross_weight" placeholder="Gross wt" x-bind:aria-label="`Item ${i + 1} — Gross weight`" type="number" step="any" class="w-24 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_net_weight]`" x-model="line.line_net_weight" placeholder="Net wt" x-bind:aria-label="`Item ${i + 1} — Net weight`" type="number" step="any" class="w-24 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_stone_weight]`" x-model="line.line_stone_weight" placeholder="Stone wt" x-bind:aria-label="`Item ${i + 1} — Stone weight`" type="number" step="any" class="w-24 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_metal_value]`" x-model="line.line_metal_value" placeholder="Metal value" x-bind:aria-label="`Item ${i + 1} — Metal value`" type="number" step="any" class="w-28 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_stone_value]`" x-model="line.line_stone_value" placeholder="Stone value" x-bind:aria-label="`Item ${i + 1} — Stone value`" type="number" step="any" class="w-28 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_making_label]`" x-model="line.line_making_label" placeholder="Making label" x-bind:aria-label="`Item ${i + 1} — Making charge label`" class="w-32 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_making_value]`" x-model="line.line_making_value" placeholder="Making value" x-bind:aria-label="`Item ${i + 1} — Making charge value`" class="w-32 min-h-[44px] text-sm"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_rate]`" x-model="line.line_rate" placeholder="Rate" x-bind:aria-label="`Item ${i + 1} — Rate`" type="number" step="any" class="w-24 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2"><input :name="`lines[${i}][line_total]`" x-model="line.line_total" placeholder="Line total" x-bind:aria-label="`Item ${i + 1} — Line total`" type="number" step="any" class="w-28 min-h-[44px] text-sm text-right tabular-nums"></td>
                            <td class="border-b border-slate-200 p-2">
                                <button type="button" class="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50" @click="lines = historicalRemoveLine(lines, i)" x-bind:aria-label="`Remove item ${i + 1}`">Remove</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <button type="button" class="btn btn-sm mt-3 min-h-[44px]" @click="lines.push({})">Add another row</button>
    </div>
</fieldset>

<div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3" data-historical-financial-row>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-2" data-historical-form-section data-historical-section="amounts">
    <legend class="sr-only">Amount / payment (display snapshot — no ledger, no receivable)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Amount / payment <span class="text-slate-400 font-normal text-sm">(display snapshot — no ledger, no receivable)</span></h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 sm:p-6">
        @foreach($money as $f => $label)
            <div>
                <label for="{{ $f }}">{{ $label }}</label>
                <input type="number" step="any" id="{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" class="w-full">
            </div>
        @endforeach
        <div>
            <label for="grand_total">Grand total <span class="text-rose-600">*</span></label>
            <input type="number" step="any" id="grand_total" name="grand_total" value="{{ old('grand_total') }}" required aria-required="true" class="w-full">
        </div>
    </div>
</fieldset>

<div class="grid grid-cols-1 gap-4 lg:col-span-1" data-historical-supporting-column>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section data-historical-section="tax-making">
    <legend class="sr-only">Tax and making / labour charge</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Tax and making / labour charge</h2>
    </div>
    <div class="p-4 sm:p-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4" data-historical-supporting-grid>
        <div>
            <label for="tax_mode">Tax mode</label>
            <select id="tax_mode" name="tax_mode" class="w-full min-h-[44px]">
                @foreach($taxModes as $v => $l)<option value="{{ $v }}" @selected(old('tax_mode') === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 min-h-[44px]" for="zero_tax_confirmed">
                <input type="hidden" name="zero_tax_confirmed" value="0">
                <input type="checkbox" id="zero_tax_confirmed" name="zero_tax_confirmed" value="1" @checked(old('zero_tax_confirmed'))>
                <span class="font-normal normal-case tracking-normal text-sm text-slate-700">Zero tax is genuine (not applicable), not missing</span>
            </label>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 mt-4" data-historical-supporting-grid>
        <div class="lg:col-span-1">
            <label for="making_label">Making / labour charge label</label>
            <input type="text" id="making_label" name="making_label" value="{{ old('making_label') }}" placeholder="MC, VA, Wastage…" class="w-full">
        </div>
        <div class="lg:col-span-1">
            <label for="making_value">Making / labour charge value</label>
            <input type="text" id="making_value" name="making_value" value="{{ old('making_value') }}" placeholder="12% or 450/gm" class="w-full">
        </div>
        <div>
            <label for="making_category">Category</label>
            <select id="making_category" name="making_category" class="w-full min-h-[44px]">
                <option value="">—</option>
                @foreach($makingCategories as $c)<option value="{{ $c }}" @selected(old('making_category')===$c)>{{ str_replace('_',' ',$c) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="making_basis">Basis</label>
            <select id="making_basis" name="making_basis" class="w-full min-h-[44px]">
                <option value="">—</option>
                @foreach($makingBases as $b)<option value="{{ $b }}" @selected(old('making_basis')===$b)>{{ str_replace('_',' ',$b) }}</option>@endforeach
            </select>
        </div>
    </div>
    </div>
</fieldset>

{{-- Cutover acknowledgement (Phase 4): a date after go-live needs a reason. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section data-historical-section="cutover">
    <legend class="sr-only">Cutover (only if this bill is dated after JewelFlow went live)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Cutover</h2>
        <p class="mt-1 text-xs text-slate-500">Only needed for bills dated after JewelFlow went live.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4 p-4 sm:p-6" data-historical-supporting-grid>
        <div>
            <label for="cutover_date">Cutover date</label>
            <input type="date" id="cutover_date" name="cutover_date" value="{{ old('cutover_date') }}" class="w-full">
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 min-h-[44px]" for="cutover_acknowledged">
                <input type="hidden" name="cutover_acknowledged" value="0">
                <input type="checkbox" id="cutover_acknowledged" name="cutover_acknowledged" value="1" @checked(old('cutover_acknowledged'))>
                <span class="font-normal normal-case tracking-normal text-sm text-slate-700">I confirm this historical bill is dated after cutover</span>
            </label>
        </div>
        <div class="sm:col-span-2 lg:col-span-1">
            <label for="cutover_reason">Reason</label>
            <input type="text" id="cutover_reason" name="cutover_reason" value="{{ old('cutover_reason') }}" class="w-full">
        </div>
    </div>
</fieldset>
</div>
</div>
</div>
