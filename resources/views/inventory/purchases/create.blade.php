<x-app-layout>
    @php
        $isEdit = isset($purchase);
        $title  = $isEdit ? "Edit Purchase {$purchase->purchase_number}" : 'New Stock Purchase';
    @endphp

    <x-page-header :title="$title" subtitle="Record incoming stock from a supplier">
        <x-slot:actions>
            <a href="{{ $isEdit ? route('inventory.purchases.show', $purchase) : route('inventory.purchases.index') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                Cancel
            </a>
        </x-slot:actions>
    </x-page-header>

    @php
        /* Pass resolved rates to Alpine as JSON */
        $ratesJson    = json_encode($resolvedRates ?? []);
        $existingLines = $isEdit ? $purchase->lines->map(fn($l) => [
            'id'                     => $l->id,
            'line_type'              => $l->line_type,
            'design'                 => $l->design ?? '',
            'category'               => $l->category ?? '',
            'sub_category'           => $l->sub_category ?? '',
            'metal_type'             => $l->metal_type,
            'purity'                 => (string) $l->purity,
            'gross_weight'           => (string) $l->gross_weight,
            'stone_weight'           => (string) $l->stone_weight,
            'net_metal_weight'       => (string) $l->net_metal_weight,
            'huid'                   => $l->huid ?? '',
            'hallmark_date'          => $l->hallmark_date?->format('Y-m-d') ?? '',
            'hsn_code'               => $l->hsn_code ?? '',
            'making_charges'         => (string) $l->making_charges,
            'stone_charges'          => (string) $l->stone_charges,
            'hallmark_charges'       => (string) $l->hallmark_charges,
            'rhodium_charges'        => (string) $l->rhodium_charges,
            'other_charges'          => (string) $l->other_charges,
            'purchase_rate_per_gram' => (string) $l->purchase_rate_per_gram,
            'purchase_line_amount'   => (string) $l->purchase_line_amount,
            'barcode'                => $l->barcode ?? '',
            'notes'                  => $l->notes ?? '',
        ])->values()->toArray() : [];
        $existingLinesJson = json_encode($existingLines);
    @endphp

    <div class="content-inner"
         x-data="purchaseForm({{ $ratesJson }}, {{ $existingLinesJson }})"
         x-init="init()">

        {{-- Restored draft notice --}}
        <div x-show="draftRestored" x-cloak
             class="mb-4 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <span>
                <svg class="inline w-4 h-4 mr-1.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Unsaved draft restored from your last session. Review before saving.
            </span>
            <button type="button" @click="clearDraft(); draftRestored = false;"
                    class="text-xs font-semibold text-amber-700 underline hover:no-underline">
                Clear draft
            </button>
        </div>

        @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
            <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST"
              action="{{ $isEdit ? route('inventory.purchases.update', $purchase) : route('inventory.purchases.store') }}"
              enctype="multipart/form-data"
              @submit="clearDraft()">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- ── Main Column ───────────────────────────────────────── --}}
                <div class="lg:col-span-2 space-y-6">

                    {{-- Invoice Header --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500 mb-4">Invoice Details</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                            {{-- Vendor --}}
                            <div x-data="{ mode: '{{ old('vendor_id', $isEdit ? ($purchase->vendor_id ? 'vendor' : 'other') : 'vendor') }}' }">
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Supplier</label>
                                <div class="flex gap-2 mb-2">
                                    <button type="button" @click="mode='vendor'" :class="mode==='vendor' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition">From Vendors</button>
                                    <button type="button" @click="mode='other'" :class="mode==='other' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition">New Supplier</button>
                                </div>
                                <div x-show="mode==='vendor'">
                                    <select name="vendor_id"
                                            @change="
                                                const opt = $event.target.options[$event.target.selectedIndex];
                                                const gstin = opt.dataset.gstin || '';
                                                const el = document.getElementById('supplier_gstin_input');
                                                if (el) el.value = gstin;
                                                scheduleSave();
                                            "
                                            class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                                        <option value="">— Select Vendor —</option>
                                        @foreach($vendors as $v)
                                            <option value="{{ $v->id }}"
                                                data-gstin="{{ $v->gst_number }}"
                                                {{ old('vendor_id', $isEdit ? $purchase->vendor_id : '') == $v->id ? 'selected' : '' }}>
                                                {{ $v->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div x-show="mode==='other'" class="space-y-2">
                                    <input type="text" name="supplier_name" @input="scheduleSave()" value="{{ old('supplier_name', $isEdit ? $purchase->supplier_name : '') }}" placeholder="Supplier name" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                                    <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-600 font-medium">
                                        <input type="checkbox" name="save_as_vendor" value="1" {{ old('save_as_vendor') ? 'checked' : '' }} class="rounded border-slate-300 text-amber-500 focus:ring-amber-500">
                                        Save this supplier to my Vendors list
                                    </label>
                                </div>
                            </div>

                            {{-- Supplier GSTIN --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Supplier GST Number (GSTIN)</label>
                                <input type="text" name="supplier_gstin" id="supplier_gstin_input" @input="scheduleSave()" value="{{ old('supplier_gstin', $isEdit ? $purchase->supplier_gstin : '') }}" placeholder="22AAAAA0000A1Z5" maxlength="20" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-mono focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Invoice Number --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Invoice Number</label>
                                <input type="text" name="invoice_number" @input="scheduleSave()" value="{{ old('invoice_number', $isEdit ? $purchase->invoice_number : '') }}" placeholder="Vendor's invoice #" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Invoice Date --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Invoice Date</label>
                                <input type="date" name="invoice_date" @change="scheduleSave()" value="{{ old('invoice_date', $isEdit ? $purchase->invoice_date?->format('Y-m-d') : '') }}" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Purchase Date --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Purchase Date <span class="text-red-500">*</span></label>
                                <input type="date" name="purchase_date" @change="scheduleSave()" value="{{ old('purchase_date', $isEdit ? $purchase->purchase_date->format('Y-m-d') : date('Y-m-d')) }}" required class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Invoice Reference Number (IRN) --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Invoice Reference Number (IRN)</label>
                                <input type="text" name="irn_number" @input="scheduleSave()" value="{{ old('irn_number', $isEdit ? $purchase->irn_number : '') }}" placeholder="64-character IRN from GST portal" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-mono focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Acknowledgement Number (ACK) --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Acknowledgement Number (ACK)</label>
                                <input type="text" name="ack_number" @input="scheduleSave()" value="{{ old('ack_number', $isEdit ? $purchase->ack_number : '') }}" placeholder="ACK number from GST portal" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-mono focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            {{-- Invoice Image / PDF --}}
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Invoice PDF / Image</label>
                                <input type="file" name="invoice_image" accept="image/jpeg,image/png,application/pdf" class="w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-amber-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-amber-700 hover:file:bg-amber-100">
                                @if($isEdit && $purchase->invoice_image)
                                    <p class="mt-1 text-xs text-slate-500">Current: <a href="{{ Storage::url($purchase->invoice_image) }}" target="_blank" class="text-amber-600 underline">View</a></p>
                                @endif
                            </div>

                        </div>
                    </div>

                    {{-- Line Items --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Line Items</h3>
                            <button type="button" @click="addLine()" class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Add Item
                            </button>
                        </div>

                        <template x-if="lines.length === 0">
                            <div class="py-8 text-center text-slate-400 border-2 border-dashed border-slate-200 rounded-xl">
                                <p class="text-sm">No items yet. Click "Add Item" to add a line.</p>
                            </div>
                        </template>

                        <div class="space-y-4">
                            <template x-for="(line, idx) in lines" :key="line._key">
                                <div class="relative rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <input type="hidden" :name="`lines[${idx}][id]`" :value="line.id || ''">

                                    {{-- Row header --}}
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-bold text-slate-600 uppercase tracking-wider" x-text="`Item ${idx + 1}`"></span>
                                        <button type="button" @click="removeLine(idx)" class="text-red-400 hover:text-red-600">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">

                                        {{-- Line Type --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Type</label>
                                            <select :name="`lines[${idx}][line_type]`" x-model="line.line_type" @change="scheduleSave()" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500">
                                                <option value="ornament">Ornament</option>
                                                <option value="bullion_for_sale">Bullion (Sale)</option>
                                                <option value="bullion_reserve">Bullion (Reserve)</option>
                                            </select>
                                        </div>

                                        {{-- Metal Type --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Metal</label>
                                            <select :name="`lines[${idx}][metal_type]`" x-model="line.metal_type" @change="prefillRate(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500">
                                                <option value="gold">Gold</option>
                                                <option value="silver">Silver</option>
                                            </select>
                                        </div>

                                        {{-- Purity --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Purity (KT/‰)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][purity]`" x-model="line.purity" @input="prefillRate(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="18 / 22 / 925">
                                        </div>

                                        {{-- Design / Description --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Design / Desc.</label>
                                            <input type="text" :name="`lines[${idx}][design]`" x-model="line.design" @input="scheduleSave()" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="e.g. Bangles">
                                        </div>

                                        {{-- Category --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Category</label>
                                            <select :name="`lines[${idx}][category]`" x-model="line.category" @change="scheduleSave()" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500">
                                                <option value="">— Select —</option>
                                                @foreach($categories as $cat)
                                                    <option value="{{ $cat->name }}">{{ $cat->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        {{-- Gross Weight --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Gross Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][gross_weight]`" x-model="line.gross_weight" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.000">
                                        </div>

                                        {{-- Stone Weight --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Stone Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][stone_weight]`" x-model="line.stone_weight" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.000">
                                        </div>

                                        {{-- Net Weight (editable, auto-fills from gross−stone) --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Net Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][net_metal_weight]`" x-model="line.net_metal_weight" @input="recalcLineFromNet(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.000">
                                        </div>

                                        {{-- Rate per gram --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Rate/g (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][purchase_rate_per_gram]`" x-model="line.purchase_rate_per_gram" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.00">
                                        </div>

                                        {{-- Making Charges --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Making (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][making_charges]`" x-model="line.making_charges" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.00">
                                        </div>

                                        {{-- Stone Charges --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Stone (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][stone_charges]`" x-model="line.stone_charges" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.00">
                                        </div>

                                        {{-- Hallmark Charges --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Hallmark (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][hallmark_charges]`" x-model="line.hallmark_charges" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.00">
                                        </div>

                                        {{-- Other Charges --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Other (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][other_charges]`" x-model="line.other_charges" @input="recalcLine(line)" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="0.00">
                                        </div>

                                        {{-- Line Total --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Line Total (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][purchase_line_amount]`" x-model="line.purchase_line_amount" @input="recalcSummary()" class="w-full rounded-lg border-amber-200 bg-amber-50 px-2 py-2 text-sm font-semibold text-amber-700 focus:border-amber-500 focus:ring-amber-500">
                                        </div>

                                        {{-- HUID (hidden for bullion) --}}
                                        <template x-if="line.line_type === 'ornament'">
                                            <div>
                                                <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Hallmark Unique ID (HUID)</label>
                                                <input type="text" :name="`lines[${idx}][huid]`" x-model="line.huid" @input="scheduleSave()" maxlength="30" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm font-mono focus:border-amber-500 focus:ring-amber-500" placeholder="6-char code">
                                            </div>
                                        </template>

                                        {{-- HSN Code --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">HSN Code (Product Category Code)</label>
                                            <input type="text" :name="`lines[${idx}][hsn_code]`" x-model="line.hsn_code" @input="scheduleSave()" maxlength="20" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm font-mono focus:border-amber-500 focus:ring-amber-500" placeholder="711319">
                                        </div>

                                        {{-- Barcode (optional, auto-generated on confirm if blank) --}}
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Barcode (opt.)</label>
                                            <input type="text" :name="`lines[${idx}][barcode]`" x-model="line.barcode" @input="scheduleSave()" maxlength="100" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm font-mono focus:border-amber-500 focus:ring-amber-500" placeholder="Auto on confirm">
                                        </div>

                                        {{-- Notes --}}
                                        <div class="col-span-2 sm:col-span-3 lg:col-span-4">
                                            <label class="block text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400 mb-1">Line Notes</label>
                                            <input type="text" :name="`lines[${idx}][notes]`" x-model="line.notes" @input="scheduleSave()" maxlength="500" class="w-full rounded-lg border-slate-200 bg-white px-2 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="Optional note">
                                        </div>

                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Invoice Totals --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500 mb-4">Invoice Totals</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Labour Discount (₹)</label>
                                <input type="number" step="0.01" name="labour_discount" x-model="labourDiscount" @input="recalcSummary()" value="{{ old('labour_discount', $isEdit ? $purchase->labour_discount : 0) }}" min="0" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Central GST (CGST) %</label>
                                <input type="number" step="0.01" name="cgst_rate" x-model="cgstRate" @input="recalcSummary()" value="{{ old('cgst_rate', $isEdit ? $purchase->cgst_rate : 0) }}" min="0" max="100" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">State GST (SGST) %</label>
                                <input type="number" step="0.01" name="sgst_rate" x-model="sgstRate" @input="recalcSummary()" value="{{ old('sgst_rate', $isEdit ? $purchase->sgst_rate : 0) }}" min="0" max="100" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Integrated GST (IGST) %</label>
                                <input type="number" step="0.01" name="igst_rate" x-model="igstRate" @input="recalcSummary()" value="{{ old('igst_rate', $isEdit ? $purchase->igst_rate : 0) }}" min="0" max="100" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-1">Tax Collected at Source — TCS (₹)</label>
                                <input type="number" step="0.01" name="tcs_amount" x-model="tcsAmount" @input="recalcSummary()" value="{{ old('tcs_amount', $isEdit ? $purchase->tcs_amount : 0) }}" min="0" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>

                        </div>
                    </div>
                </div>

                {{-- ── Sidebar ───────────────────────────────────────────── --}}
                <div class="lg:col-span-1">
                    <div class="sticky top-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500 mb-4">Summary</h3>

                        <div class="space-y-2 text-sm mb-4">
                            <div class="flex justify-between text-slate-600">
                                <span>Line Items</span>
                                <span class="font-semibold" x-text="lines.length"></span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Total Gross Wt</span>
                                <span class="font-semibold" x-text="totalGrossWeight.toFixed(3) + ' g'"></span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Total Net Wt</span>
                                <span class="font-semibold" x-text="totalNetWeight.toFixed(3) + ' g'"></span>
                            </div>
                            <div class="border-t border-slate-100 pt-2 flex justify-between text-slate-600">
                                <span>Lines Total</span>
                                <span class="font-semibold" x-text="'₹' + linesTotal.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>− Labour Discount</span>
                                <span x-text="'₹' + parseFloat(labourDiscount || 0).toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>Subtotal</span>
                                <span x-text="'₹' + subtotal.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>Central GST (CGST)</span>
                                <span x-text="'₹' + cgstAmount.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>State GST (SGST)</span>
                                <span x-text="'₹' + sgstAmount.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>Integrated GST (IGST)</span>
                                <span x-text="'₹' + igstAmount.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>Tax Collected at Source (TCS)</span>
                                <span x-text="'₹' + parseFloat(tcsAmount || 0).toFixed(2)"></span>
                            </div>
                            <div class="border-t border-slate-200 pt-2 flex justify-between text-base font-bold text-amber-700">
                                <span>Grand Total</span>
                                <span x-text="'₹' + grandTotal.toFixed(2)"></span>
                            </div>
                        </div>

                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition">
                            {{ $isEdit ? 'Update Draft' : 'Save as Draft' }}
                        </button>
                        <p class="mt-2 text-center text-xs text-slate-400">You can confirm later from the purchase page</p>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <script>
    function purchaseForm(resolvedRates, existingLines) {
        const IS_EDIT = {{ $isEdit ? 'true' : 'false' }};
        const DRAFT_KEY = 'jf_purchase_draft_new';

        return {
            resolvedRates,
            lines: [],
            labourDiscount: {{ old('labour_discount', $isEdit ? (float)$purchase->labour_discount : 0) }},
            cgstRate:       {{ old('cgst_rate',        $isEdit ? (float)$purchase->cgst_rate       : 0) }},
            sgstRate:       {{ old('sgst_rate',        $isEdit ? (float)$purchase->sgst_rate       : 0) }},
            igstRate:       {{ old('igst_rate',        $isEdit ? (float)$purchase->igst_rate       : 0) }},
            tcsAmount:      {{ old('tcs_amount',       $isEdit ? (float)$purchase->tcs_amount      : 0) }},
            _keyCounter: 0,
            _saveTimer: null,
            draftRestored: false,

            get linesTotal() {
                return this.lines.reduce((s, l) => s + parseFloat(l.purchase_line_amount || 0), 0);
            },
            get totalGrossWeight() {
                return this.lines.reduce((s, l) => s + parseFloat(l.gross_weight || 0), 0);
            },
            get totalNetWeight() {
                return this.lines.reduce((s, l) => s + parseFloat(l.net_metal_weight || 0), 0);
            },
            get subtotal() {
                return Math.max(0, this.linesTotal - parseFloat(this.labourDiscount || 0));
            },
            get cgstAmount() {
                return Math.round(this.subtotal * parseFloat(this.cgstRate || 0) / 100 * 100) / 100;
            },
            get sgstAmount() {
                return Math.round(this.subtotal * parseFloat(this.sgstRate || 0) / 100 * 100) / 100;
            },
            get igstAmount() {
                return Math.round(this.subtotal * parseFloat(this.igstRate || 0) / 100 * 100) / 100;
            },
            get grandTotal() {
                return Math.round((this.subtotal + this.cgstAmount + this.sgstAmount + this.igstAmount + parseFloat(this.tcsAmount || 0)) * 100) / 100;
            },

            init() {
                if (IS_EDIT) {
                    if (existingLines && existingLines.length > 0) {
                        this.lines = existingLines.map(l => ({ ...l, _key: ++this._keyCounter }));
                    }
                    return;
                }

                const saved = this.loadDraft();
                if (saved) {
                    if (saved.lines && saved.lines.length > 0) {
                        this.lines = saved.lines.map(l => ({ ...l, _key: ++this._keyCounter }));
                    }
                    if (saved.labourDiscount !== undefined) this.labourDiscount = saved.labourDiscount;
                    if (saved.cgstRate      !== undefined) this.cgstRate      = saved.cgstRate;
                    if (saved.sgstRate      !== undefined) this.sgstRate      = saved.sgstRate;
                    if (saved.igstRate      !== undefined) this.igstRate      = saved.igstRate;
                    if (saved.tcsAmount     !== undefined) this.tcsAmount     = saved.tcsAmount;

                    if (saved.inputs) {
                        this.$nextTick(() => {
                            const form = this.$el.querySelector('form') || document.querySelector('form');
                            if (!form) return;
                            const fields = ['vendor_id','supplier_name','supplier_gstin','invoice_number','invoice_date','purchase_date','notes','irn_number','ack_number'];
                            fields.forEach(name => {
                                if (saved.inputs[name] === undefined) return;
                                const el = form.querySelector(`[name="${name}"]`);
                                if (el) el.value = saved.inputs[name];
                            });
                        });
                    }

                    this.draftRestored = true;
                }
            },

            scheduleSave() {
                if (IS_EDIT) return;
                clearTimeout(this._saveTimer);
                this._saveTimer = setTimeout(() => this.saveDraft(), 500);
            },

            saveDraft() {
                if (IS_EDIT) return;
                const form = this.$el.querySelector('form') || document.querySelector('form');
                const inputs = {};
                if (form) {
                    ['vendor_id','supplier_name','supplier_gstin','invoice_number','invoice_date','purchase_date','notes','irn_number','ack_number'].forEach(name => {
                        const el = form.querySelector(`[name="${name}"]`);
                        if (el) inputs[name] = el.value;
                    });
                }
                try {
                    localStorage.setItem(DRAFT_KEY, JSON.stringify({
                        inputs,
                        lines:          this.lines,
                        labourDiscount: this.labourDiscount,
                        cgstRate:       this.cgstRate,
                        sgstRate:       this.sgstRate,
                        igstRate:       this.igstRate,
                        tcsAmount:      this.tcsAmount,
                    }));
                } catch (_) {}
            },

            loadDraft() {
                try {
                    const raw = localStorage.getItem(DRAFT_KEY);
                    return raw ? JSON.parse(raw) : null;
                } catch (_) { return null; }
            },

            clearDraft() {
                localStorage.removeItem(DRAFT_KEY);
                this.draftRestored = false;
            },

            newLine() {
                return {
                    _key: ++this._keyCounter,
                    id: '',
                    line_type: 'ornament',
                    design: '',
                    category: '',
                    sub_category: '',
                    metal_type: 'gold',
                    purity: '',
                    gross_weight: '',
                    stone_weight: '0',
                    net_metal_weight: '0',
                    huid: '',
                    hallmark_date: '',
                    hsn_code: '',
                    making_charges: '0',
                    stone_charges: '0',
                    hallmark_charges: '0',
                    rhodium_charges: '0',
                    other_charges: '0',
                    purchase_rate_per_gram: '',
                    purchase_line_amount: '0',
                    barcode: '',
                    notes: '',
                };
            },

            addLine() {
                const line = this.newLine();
                this.prefillRate(line);
                this.lines.push(line);
                this.scheduleSave();
            },

            removeLine(idx) {
                this.lines.splice(idx, 1);
                this.scheduleSave();
            },

            prefillRate(line) {
                if (!line.metal_type || !line.purity) return;
                const metal   = line.metal_type;
                const purity  = parseFloat(line.purity);
                if (!this.resolvedRates[metal]) return;

                // Find closest purity key
                const keys = Object.keys(this.resolvedRates[metal]);
                let best = null, bestDiff = Infinity;
                for (const k of keys) {
                    const diff = Math.abs(parseFloat(k) - purity);
                    if (diff < bestDiff) { bestDiff = diff; best = k; }
                }
                if (best && bestDiff < 0.5) {
                    const rate = this.resolvedRates[metal][best]?.rate_per_gram;
                    if (rate && !line.purchase_rate_per_gram) {
                        line.purchase_rate_per_gram = rate;
                        this._recalcLineTotal(line);
                    }
                }
            },

            recalcLine(line) {
                const gross = parseFloat(line.gross_weight || 0);
                const stone = parseFloat(line.stone_weight || 0);
                // Auto-fill net from gross−stone only when gross or stone changes
                line.net_metal_weight = Math.max(0, gross - stone).toFixed(3);
                this._recalcLineTotal(line);
            },

            recalcLineFromNet(line) {
                // User typed net directly — just recalc the line total
                this._recalcLineTotal(line);
            },

            _recalcLineTotal(line) {
                const net    = parseFloat(line.net_metal_weight  || 0);
                const rate   = parseFloat(line.purchase_rate_per_gram || 0);
                const making = parseFloat(line.making_charges    || 0);
                const stoneC = parseFloat(line.stone_charges     || 0);
                const hallC  = parseFloat(line.hallmark_charges  || 0);
                const rhodC  = parseFloat(line.rhodium_charges   || 0);
                const otherC = parseFloat(line.other_charges     || 0);

                const lineTotal = Math.round((net * rate + making + stoneC + hallC + rhodC + otherC) * 100) / 100;
                line.purchase_line_amount = lineTotal.toFixed(2);
                this.scheduleSave();
            },

            recalcSummary() {
                this.scheduleSave();
            },
        };
    }
    </script>
</x-app-layout>
