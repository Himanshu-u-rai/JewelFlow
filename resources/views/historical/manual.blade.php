@php
    use App\Models\Historical\HistoricalSalesDocument;
    $taxModes = [
        '' => '— choose —',
        HistoricalSalesDocument::TAX_MODE_EXCLUSIVE     => 'Tax added on top (exclusive)',
        HistoricalSalesDocument::TAX_MODE_INCLUSIVE     => 'Tax already inside total (inclusive)',
        HistoricalSalesDocument::TAX_MODE_UNKNOWN       => 'Unknown',
        HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE => 'Not applicable',
    ];
    $money = ['taxable_amount'=>'Taxable','tax_total'=>'Tax total','cgst'=>'CGST','sgst'=>'SGST','igst'=>'IGST','cess'=>'Cess','discount'=>'Discount','rounding'=>'Rounding','metal_value'=>'Metal value','stone_value'=>'Stone value','paid_amount'=>'Paid','outstanding_amount'=>'Outstanding'];
@endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:900px;margin:0 auto;">
        <x-app-alerts />

        {{-- Blocking findings from a rejected save. --}}
        @if(session('historical_messages'))
            <div style="border:1px solid #fca5a5;background:#fef2f2;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
                @foreach(session('historical_messages') as $m)
                    <div style="color:#b91c1c;">{{ $m['text'] }}</div>
                @endforeach
            </div>
        @endif

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1 style="margin:0;">Enter a historical bill</h1>
            <a href="{{ route('historical.index') }}">← Historical sales</a>
        </div>
        <p style="color:#b45309;">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        <form method="POST" action="{{ route('historical.manual.store') }}"
              x-data="{ lines: [] }" style="display:grid;gap:1.25rem;">
            @csrf

            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Document identity</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                    <label>Original invoice number (optional)
                        <input type="text" name="original_document_number" value="{{ old('original_document_number') }}" style="width:100%;"></label>
                    <label>Series (optional)
                        <input type="text" name="document_series" value="{{ old('document_series') }}" style="width:100%;"></label>
                    <label>Document date <span style="color:#b91c1c;">*</span>
                        <input type="date" name="document_date" value="{{ old('document_date') }}" required style="width:100%;"></label>
                    <label>Source system
                        <input type="text" name="source_system" value="{{ old('source_system', 'Manual') }}" style="width:100%;"></label>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Customer snapshot (never linked)</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                    <label>Name <input type="text" name="customer_name" value="{{ old('customer_name') }}" style="width:100%;"></label>
                    <label>Mobile <input type="text" name="customer_mobile" value="{{ old('customer_mobile') }}" style="width:100%;"></label>
                    <label>GSTIN <input type="text" name="customer_gstin" value="{{ old('customer_gstin') }}" style="width:100%;"></label>
                    <label>Place of supply <input type="text" name="place_of_supply" value="{{ old('place_of_supply') }}" style="width:100%;"></label>
                    <label style="grid-column:1 / -1;">Address <input type="text" name="customer_address" value="{{ old('customer_address') }}" style="width:100%;"></label>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Amounts (display snapshot — no ledger, no receivable)</legend>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;">
                    @foreach($money as $f => $label)
                        <label>{{ $label }}
                            <input type="number" step="any" name="{{ $f }}" value="{{ old($f) }}" style="width:100%;"></label>
                    @endforeach
                    <label>Grand total <span style="color:#b91c1c;">*</span>
                        <input type="number" step="any" name="grand_total" value="{{ old('grand_total') }}" required style="width:100%;"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem;">
                    <label>Tax mode
                        <select name="tax_mode" style="width:100%;">
                            @foreach($taxModes as $v => $l)<option value="{{ $v }}" @selected(old('tax_mode') === $v)>{{ $l }}</option>@endforeach
                        </select></label>
                    <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.5rem;">
                        <input type="hidden" name="zero_tax_confirmed" value="0">
                        <input type="checkbox" name="zero_tax_confirmed" value="1" @checked(old('zero_tax_confirmed'))>
                        Zero tax is genuine (not applicable), not missing</label>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Making / labour</legend>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;">
                    <label>Label <input type="text" name="making_label" value="{{ old('making_label') }}" placeholder="MC, VA, Wastage…" style="width:100%;"></label>
                    <label>Value <input type="text" name="making_value" value="{{ old('making_value') }}" placeholder="12% or 450/gm" style="width:100%;"></label>
                    <label>Category
                        <select name="making_category" style="width:100%;">
                            <option value="">—</option>
                            @foreach($makingCategories as $c)<option value="{{ $c }}" @selected(old('making_category')===$c)>{{ str_replace('_',' ',$c) }}</option>@endforeach
                        </select></label>
                    <label>Basis
                        <select name="making_basis" style="width:100%;">
                            <option value="">—</option>
                            @foreach($makingBases as $b)<option value="{{ $b }}" @selected(old('making_basis')===$b)>{{ str_replace('_',' ',$b) }}</option>@endforeach
                        </select></label>
                </div>
            </fieldset>

            {{-- Optional item lines. Header-only is a valid bill, so lines start empty. --}}
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Item lines (optional)</legend>
                <template x-for="(line, i) in lines" :key="i">
                    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;margin-bottom:.5rem;">
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;">
                            <input :name="`lines[${i}][line_item_name]`" placeholder="Item name">
                            <input :name="`lines[${i}][line_sku]`" placeholder="SKU">
                            <input :name="`lines[${i}][line_hsn]`" placeholder="HSN">
                            <input :name="`lines[${i}][line_quantity]`" placeholder="Qty" type="number" step="any">
                            <input :name="`lines[${i}][line_purity]`" placeholder="Purity">
                            <input :name="`lines[${i}][line_gross_weight]`" placeholder="Gross wt" type="number" step="any">
                            <input :name="`lines[${i}][line_net_weight]`" placeholder="Net wt" type="number" step="any">
                            <input :name="`lines[${i}][line_stone_weight]`" placeholder="Stone wt" type="number" step="any">
                            <input :name="`lines[${i}][line_metal_value]`" placeholder="Metal value" type="number" step="any">
                            <input :name="`lines[${i}][line_stone_value]`" placeholder="Stone value" type="number" step="any">
                            <input :name="`lines[${i}][line_making_label]`" placeholder="Making label">
                            <input :name="`lines[${i}][line_making_value]`" placeholder="Making value">
                            <input :name="`lines[${i}][line_rate]`" placeholder="Rate" type="number" step="any">
                            <input :name="`lines[${i}][line_total]`" placeholder="Line total" type="number" step="any">
                        </div>
                        <button type="button" class="btn" style="margin-top:.5rem;color:#b91c1c;" @click="lines.splice(i,1)">Remove line</button>
                    </div>
                </template>
                <button type="button" class="btn" @click="lines.push({})">+ Add line</button>
            </fieldset>

            {{-- Cutover acknowledgement (Phase 4): a date after go-live needs a reason. --}}
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Cutover (only if this bill is dated after JewelFlow went live)</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                    <label>Cutover date
                        <input type="date" name="cutover_date" value="{{ old('cutover_date') }}" style="width:100%;"></label>
                    <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.5rem;">
                        <input type="hidden" name="cutover_acknowledged" value="0">
                        <input type="checkbox" name="cutover_acknowledged" value="1" @checked(old('cutover_acknowledged'))>
                        I confirm this historical bill is dated after cutover</label>
                    <label style="grid-column:1 / -1;">Reason
                        <input type="text" name="cutover_reason" value="{{ old('cutover_reason') }}" style="width:100%;"></label>
                </div>
            </fieldset>

            <div><button class="btn btn-primary" type="submit">Save as draft</button></div>
        </form>
    </div>
</x-app-layout>
