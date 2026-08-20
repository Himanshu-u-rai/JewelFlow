{{-- Shared field markup for the manual entry form and its preview screen.
     Values come from old() so the preview screen (which flashes the
     submitted input before rendering) shows exactly what was typed.
     Fieldset/legend kept for accessibility (screen readers announce the
     group name) — only the visual wrapper changed to match the shared
     card system used across the app. --}}
<div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3" data-historical-manual-layout>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-2" data-historical-form-section data-historical-section="document">
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

<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="tax-making">
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

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4 mt-4" data-historical-supporting-grid>
        <div>
            <label for="making_label">Making / labour charge label</label>
            <input type="text" id="making_label" name="making_label" value="{{ old('making_label') }}" placeholder="MC, VA, Wastage…" class="w-full">
        </div>
        <div>
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

{{-- Optional item lines. Header-only is a valid bill, so lines start empty.
     Each field carries an aria-label since the row layout has no room for a
     visible per-cell label — the same reason a spreadsheet uses a header row
     instead of repeating labels per cell. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-2" data-historical-form-section data-historical-section="items">
    <legend class="sr-only">Item lines (optional)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Item lines <span class="text-slate-400 font-normal text-sm">(optional)</span></h2>
    </div>
    <div class="p-4 sm:p-6">
    <template x-for="(line, i) in lines" :key="i">
        <div class="rounded-lg border border-slate-200 p-3 mt-3">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                <input :name="`lines[${i}][line_item_name]`" placeholder="Item name" aria-label="Item name" class="w-full">
                <input :name="`lines[${i}][line_sku]`" placeholder="SKU" aria-label="SKU" class="w-full">
                <input :name="`lines[${i}][line_hsn]`" placeholder="HSN" aria-label="HSN" class="w-full">
                <input :name="`lines[${i}][line_quantity]`" placeholder="Qty" aria-label="Quantity" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_purity]`" placeholder="Purity" aria-label="Purity" class="w-full">
                <input :name="`lines[${i}][line_gross_weight]`" placeholder="Gross wt" aria-label="Gross weight" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_net_weight]`" placeholder="Net wt" aria-label="Net weight" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_stone_weight]`" placeholder="Stone wt" aria-label="Stone weight" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_metal_value]`" placeholder="Metal value" aria-label="Metal value" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_stone_value]`" placeholder="Stone value" aria-label="Stone value" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_making_label]`" placeholder="Making label" aria-label="Making charge label" class="w-full">
                <input :name="`lines[${i}][line_making_value]`" placeholder="Making value" aria-label="Making charge value" class="w-full">
                <input :name="`lines[${i}][line_rate]`" placeholder="Rate" aria-label="Rate" type="number" step="any" class="w-full">
                <input :name="`lines[${i}][line_total]`" placeholder="Line total" aria-label="Line total" type="number" step="any" class="w-full">
            </div>
            <button type="button" class="btn btn-danger btn-sm mt-2 min-h-[44px]" @click="lines.splice(i,1)">Remove line</button>
        </div>
    </template>
    <button type="button" class="btn btn-sm mt-3 min-h-[44px]" @click="lines.push({})">+ Add line</button>
    </div>
</fieldset>

{{-- Cutover acknowledgement (Phase 4): a date after go-live needs a reason. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="cutover">
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
