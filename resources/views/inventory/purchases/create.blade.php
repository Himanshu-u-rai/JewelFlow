<x-app-layout>
    <style>
        .purchase-shell {
            max-width: 1500px;
        }

        .purchase-card,
        .purchase-summary,
        .purchase-nav {
            border: 1px solid #dbe3ee;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .purchase-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .purchase-card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0;
            text-transform: none;
        }

        .purchase-card-kicker {
            margin-bottom: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #c25a1a;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .purchase-card-subtitle {
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.45;
            color: #64748b;
        }

        .purchase-field-label {
            display: block;
            margin-bottom: 6px;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            letter-spacing: 0;
            text-transform: none;
        }

        .purchase-input,
        .purchase-select,
        .purchase-textarea {
            width: 100%;
            border-radius: 12px;
            border-color: #dbe3ee;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
        }

        .purchase-input,
        .purchase-select {
            padding: 10px 12px;
        }

        .purchase-textarea {
            padding: 10px 12px;
        }

        .purchase-input:focus,
        .purchase-select:focus,
        .purchase-textarea:focus {
            border-color: #d97706;
            box-shadow: 0 0 0 1px #d97706;
        }

        .purchase-stepper {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }

        .purchase-step-btn {
            min-width: 0;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px;
            text-align: left;
            transition: border-color .15s ease, background .15s ease, color .15s ease;
        }

        .purchase-step-btn.is-active {
            border-color: #d97706;
            background: #fff7ed;
        }

        .purchase-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .purchase-step-btn.is-active .purchase-step-number {
            background: #d97706;
            color: #ffffff;
        }

        .purchase-line-card {
            border: 1px solid #dbe3ee;
            background: #ffffff;
            border-radius: 14px;
        }

        .purchase-line-head {
            border-bottom: 1px solid #eef2f7;
            padding-bottom: 12px;
        }

        .purchase-total-row {
            border-radius: 12px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            padding: 12px;
        }

        .purchase-secondary-panel {
            border-top: 1px solid #eef2f7;
            margin-top: 14px;
            padding-top: 14px;
        }

        .purchase-bottom-spacer {
            display: none;
        }

        @media (max-width: 768px) {
            .purchase-shell {
                padding-inline: 10px;
                padding-bottom: 96px;
            }

            .purchase-card,
            .purchase-summary,
            .purchase-nav {
                border-radius: 14px;
                padding: 14px !important;
            }

            .purchase-card-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
                margin-bottom: 14px;
            }

            .purchase-card-header .purchase-add-btn {
                width: 100%;
                justify-content: center;
            }

            .purchase-stepper {
                display: flex;
                gap: 8px;
                overflow-x: auto;
                padding-bottom: 2px;
                scrollbar-width: none;
            }

            .purchase-stepper::-webkit-scrollbar {
                display: none;
            }

            .purchase-step-btn {
                flex: 0 0 150px;
                padding: 9px;
            }

            .purchase-line-card {
                border-radius: 14px;
                padding: 12px !important;
            }

            .purchase-nav {
                position: fixed;
                right: 10px;
                bottom: 10px;
                left: 10px;
                z-index: 30;
            }

            .purchase-bottom-spacer {
                display: block;
                height: 78px;
            }
        }
    </style>

    @php
        $isEdit = isset($purchase);
        $title  = $isEdit ? "Edit Purchase {$purchase->purchase_number}" : 'New Stock Purchase';
        $oldVendorId = old('vendor_id', $isEdit ? $purchase->vendor_id : '');
        $initialSupplierMode = old('supplier_name') && !$oldVendorId
            ? 'other'
            : (($isEdit && !$purchase->vendor_id && $purchase->supplier_name) ? 'other' : 'vendor');
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

    <div class="content-inner purchase-shell"
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

        <form id="purchase-create-form"
              method="POST"
              action="{{ $isEdit ? route('inventory.purchases.update', $purchase) : route('inventory.purchases.store') }}"
              enctype="multipart/form-data"
              x-ref="purchaseForm">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-5 xl:gap-6" x-ref="purchaseTop">

                {{-- Main Column --}}
                <div class="space-y-5 xl:space-y-6">

                    {{-- Stepper --}}
                    <div class="purchase-card p-4">
                        <div class="purchase-stepper" role="tablist" aria-label="Purchase steps">
                            <template x-for="step in steps" :key="step.id">
                                <button type="button"
                                        @click="goToStep(step.id)"
                                        class="purchase-step-btn"
                                        :class="{ 'is-active': currentStep === step.id }">
                                    <div class="flex items-start gap-3">
                                        <span class="purchase-step-number" x-text="step.id"></span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-bold text-slate-900" x-text="step.label"></span>
                                            <span class="block truncate text-xs text-slate-500" x-text="step.helper"></span>
                                        </span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Step 1: Supplier & Invoice --}}
                    <section x-show="currentStep === 1" x-cloak class="purchase-card p-5" role="tabpanel">
                        <div class="purchase-card-header">
                            <div>
                                <p class="purchase-card-kicker">Step 1</p>
                                <h3 class="purchase-card-title">Supplier & Invoice</h3>
                                <p class="purchase-card-subtitle">Capture the supplier, purchase date, invoice reference, and file.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            {{-- Vendor --}}
                            <div class="xl:col-span-2">
                                <label class="purchase-field-label">Supplier</label>
                                <div class="grid grid-cols-2 gap-2 mb-2 rounded-xl bg-slate-100 p-1">
                                    <button type="button"
                                            @click="supplierMode = 'vendor'; scheduleSave()"
                                            :class="supplierMode === 'vendor' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
                                            class="rounded-lg px-3 py-2 text-xs font-semibold transition">
                                        From Vendors
                                    </button>
                                    <button type="button"
                                            @click="supplierMode = 'other'; scheduleSave()"
                                            :class="supplierMode === 'other' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
                                            class="rounded-lg px-3 py-2 text-xs font-semibold transition">
                                        New Supplier
                                    </button>
                                </div>

                                <div x-show="supplierMode === 'vendor'">
                                    <select name="vendor_id"
                                            @change="
                                                const opt = $event.target.options[$event.target.selectedIndex];
                                                const gstin = opt.dataset.gstin || '';
                                                const el = document.getElementById('supplier_gstin_input');
                                                if (el) el.value = gstin;
                                                scheduleSave();
                                            "
                                            class="purchase-select">
                                        <option value="">Select Vendor</option>
                                        @foreach($vendors as $v)
                                            <option value="{{ $v->id }}"
                                                data-gstin="{{ $v->gst_number }}"
                                                {{ old('vendor_id', $isEdit ? $purchase->vendor_id : '') == $v->id ? 'selected' : '' }}>
                                                {{ $v->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div x-show="supplierMode === 'other'" class="space-y-2">
                                    <input type="text" name="supplier_name" @input="scheduleSave()" value="{{ old('supplier_name', $isEdit ? $purchase->supplier_name : '') }}" placeholder="Supplier name" class="purchase-input">
                                    <label class="flex items-center gap-2 cursor-pointer text-xs text-slate-600 font-medium">
                                        <input type="checkbox" name="save_as_vendor" value="1" @change="scheduleSave()" {{ old('save_as_vendor') ? 'checked' : '' }} class="rounded border-slate-300 text-amber-500 focus:ring-amber-500">
                                        Save this supplier to my Vendors list
                                    </label>
                                </div>
                            </div>

                            <div>
                                <label class="purchase-field-label">Supplier GST Number (GSTIN)</label>
                                <input type="text" name="supplier_gstin" id="supplier_gstin_input" @input="scheduleSave()" value="{{ old('supplier_gstin', $isEdit ? $purchase->supplier_gstin : '') }}" placeholder="22AAAAA0000A1Z5" maxlength="20" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Invoice Number</label>
                                <input type="text" name="invoice_number" @input="scheduleSave()" value="{{ old('invoice_number', $isEdit ? $purchase->invoice_number : '') }}" placeholder="Vendor's invoice #" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Purchase Date <span class="text-red-500">*</span></label>
                                <input type="date" name="purchase_date" @change="scheduleSave()" value="{{ old('purchase_date', $isEdit ? $purchase->purchase_date->format('Y-m-d') : date('Y-m-d')) }}" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Invoice Date</label>
                                <input type="date" name="invoice_date" @change="scheduleSave()" value="{{ old('invoice_date', $isEdit ? $purchase->invoice_date?->format('Y-m-d') : '') }}" class="purchase-input">
                            </div>

                            <div class="md:col-span-2 xl:col-span-1">
                                <label class="purchase-field-label">Invoice PDF / Image</label>
                                <input type="file" name="invoice_image" accept="image/jpeg,image/png,application/pdf" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-amber-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-amber-700 hover:file:bg-amber-100">
                                @if($isEdit && $purchase->invoice_image)
                                    <p class="mt-1 text-xs text-slate-500">Current: <a href="{{ Storage::url($purchase->invoice_image) }}" target="_blank" class="text-amber-600 underline">View</a></p>
                                @endif
                            </div>

                            <div>
                                <label class="purchase-field-label">Invoice Reference Number (IRN)</label>
                                <input type="text" name="irn_number" @input="scheduleSave()" value="{{ old('irn_number', $isEdit ? $purchase->irn_number : '') }}" placeholder="IRN from GST portal" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Acknowledgement Number (ACK)</label>
                                <input type="text" name="ack_number" @input="scheduleSave()" value="{{ old('ack_number', $isEdit ? $purchase->ack_number : '') }}" placeholder="ACK number from GST portal" class="purchase-input">
                            </div>

                            <div class="md:col-span-2 xl:col-span-3">
                                <label class="purchase-field-label">Notes</label>
                                <textarea name="notes" @input="scheduleSave()" rows="2" maxlength="2000"
                                          class="purchase-textarea"
                                          placeholder="Optional purchase notes">{{ old('notes', $isEdit ? $purchase->notes : '') }}</textarea>
                            </div>
                        </div>
                    </section>

                    {{-- Step 2: Stock Items --}}
                    <section x-show="currentStep === 2" x-cloak class="purchase-card p-5" role="tabpanel">
                        <div class="purchase-card-header">
                            <div>
                                <p class="purchase-card-kicker">Step 2</p>
                                <h3 class="purchase-card-title">Stock Items</h3>
                                <p class="purchase-card-subtitle">Enter the essential item values first. Charges and codes stay tucked under details.</p>
                            </div>
                            <button type="button" @click="addLine()" class="purchase-add-btn inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                Add Item
                            </button>
                        </div>

                        <div x-show="lines.length === 0" x-cloak class="rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 py-9 text-center">
                            <p class="text-sm font-semibold text-slate-600">No stock items added yet.</p>
                            <button type="button" @click="addLine()" class="mt-3 inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                                Add first item
                            </button>
                        </div>

                        <div class="space-y-4" x-show="lines.length > 0">
                            <template x-for="(line, idx) in lines" :key="line._key">
                                <div class="purchase-line-card relative p-4">
                                    <input type="hidden" :name="`lines[${idx}][id]`" :value="line.id || ''">

                                    <div class="purchase-line-head mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="inline-flex shrink-0 items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700" x-text="`Item ${idx + 1}`"></span>
                                            <span class="truncate text-sm font-semibold text-slate-900" x-text="line.design || 'New purchase item'"></span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700" x-text="'₹' + parseFloat(line.purchase_line_amount || 0).toFixed(2)"></span>
                                            <button type="button" @click="toggleLineDetails(line)" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50" x-text="line.detailsOpen ? 'Hide details' : 'More details'"></button>
                                            <button type="button" @click="removeLine(idx)" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove item">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
                                        <div>
                                            <label class="purchase-field-label">Type</label>
                                            <select :name="`lines[${idx}][line_type]`" x-model="line.line_type" @change="if (line.line_type !== 'ornament') { line.huid = ''; line.hallmark_date = ''; } scheduleSave()" class="purchase-select">
                                                <option value="ornament">Ornament</option>
                                                <option value="bullion_for_sale">Bullion (Sale)</option>
                                                <option value="bullion_reserve">Bullion (Reserve)</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Metal</label>
                                            <select :name="`lines[${idx}][metal_type]`" x-model="line.metal_type" @change="prefillRate(line)" class="purchase-select">
                                                <option value="gold">Gold</option>
                                                <option value="silver">Silver</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Purity (KT/‰)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][purity]`" x-model="line.purity" @input="prefillRate(line)" class="purchase-input" placeholder="18 / 22 / 925">
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label class="purchase-field-label">Design / Description</label>
                                            <input type="text" :name="`lines[${idx}][design]`" x-model="line.design" @input="scheduleSave()" class="purchase-input" placeholder="e.g. Bangles">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Category</label>
                                            <select :name="`lines[${idx}][category]`" x-model="line.category" @change="scheduleSave()" class="purchase-select">
                                                <option value="">Select category</option>
                                                @foreach($categories as $cat)
                                                    <option value="{{ $cat->name }}">{{ $cat->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Gross Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][gross_weight]`" x-model="line.gross_weight" @input="recalcLine(line)" class="purchase-input" placeholder="0.000">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Stone Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][stone_weight]`" x-model="line.stone_weight" @input="recalcLine(line)" class="purchase-input" placeholder="0.000">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Net Wt (g)</label>
                                            <input type="number" step="0.001" :name="`lines[${idx}][net_metal_weight]`" x-model="line.net_metal_weight" @input="recalcLineFromNet(line)" class="purchase-input" placeholder="0.000">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Rate/g (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][purchase_rate_per_gram]`" x-model="line.purchase_rate_per_gram" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Making (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][making_charges]`" x-model="line.making_charges" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                        </div>

                                        <div>
                                            <label class="purchase-field-label">Line Total (₹)</label>
                                            <input type="number" step="0.01" :name="`lines[${idx}][purchase_line_amount]`" x-model="line.purchase_line_amount" @input="recalcSummary()" class="purchase-input border-amber-200 bg-amber-50 font-semibold text-amber-700">
                                        </div>
                                    </div>

                                    <div x-show="line.detailsOpen" x-cloak class="purchase-secondary-panel">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                                            <div>
                                                <label class="purchase-field-label">Stone Charges (₹)</label>
                                                <input type="number" step="0.01" :name="`lines[${idx}][stone_charges]`" x-model="line.stone_charges" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                            </div>

                                            <div>
                                                <label class="purchase-field-label">Hallmark Charges (₹)</label>
                                                <input type="number" step="0.01" :name="`lines[${idx}][hallmark_charges]`" x-model="line.hallmark_charges" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                            </div>

                                            <div>
                                                <label class="purchase-field-label">Rhodium Charges (₹)</label>
                                                <input type="number" step="0.01" :name="`lines[${idx}][rhodium_charges]`" x-model="line.rhodium_charges" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                            </div>

                                            <div>
                                                <label class="purchase-field-label">Other Charges (₹)</label>
                                                <input type="number" step="0.01" :name="`lines[${idx}][other_charges]`" x-model="line.other_charges" @input="recalcLine(line)" class="purchase-input" placeholder="0.00">
                                            </div>

                                            <div x-show="line.line_type === 'ornament'" x-cloak>
                                                <label class="purchase-field-label">Hallmark Unique ID (HUID)</label>
                                                <input type="text" :name="`lines[${idx}][huid]`" x-model="line.huid" @input="scheduleSave()" maxlength="30" class="purchase-input" placeholder="6-char code">
                                            </div>

                                            <div x-show="line.line_type === 'ornament'" x-cloak>
                                                <label class="purchase-field-label">Hallmark Date</label>
                                                <input type="date" :name="`lines[${idx}][hallmark_date]`" x-model="line.hallmark_date" @change="scheduleSave()" class="purchase-input">
                                            </div>

                                            <div>
                                                <label class="purchase-field-label">HSN Code</label>
                                                <input type="text" :name="`lines[${idx}][hsn_code]`" x-model="line.hsn_code" @input="scheduleSave()" maxlength="20" class="purchase-input" placeholder="711319">
                                            </div>

                                            <div>
                                                <label class="purchase-field-label">Barcode</label>
                                                <input type="text" :name="`lines[${idx}][barcode]`" x-model="line.barcode" @input="scheduleSave()" maxlength="100" class="purchase-input" placeholder="Auto on confirm">
                                            </div>

                                            <div class="sm:col-span-2 xl:col-span-4">
                                                <label class="purchase-field-label">Line Notes</label>
                                                <input type="text" :name="`lines[${idx}][notes]`" x-model="line.notes" @input="scheduleSave()" maxlength="500" class="purchase-input" placeholder="Optional note">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- Step 3: Tax & Charges --}}
                    <section x-show="currentStep === 3" x-cloak class="purchase-card p-5" role="tabpanel">
                        <div class="purchase-card-header">
                            <div>
                                <p class="purchase-card-kicker">Step 3</p>
                                <h3 class="purchase-card-title">Tax & Charges</h3>
                                <p class="purchase-card-subtitle">Apply invoice-level discount, GST, and TCS values.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
                            <div>
                                <label class="purchase-field-label">Labour Discount (₹)</label>
                                <input type="number" step="0.01" name="labour_discount" x-model="labourDiscount" @input="recalcSummary()" value="{{ old('labour_discount', $isEdit ? $purchase->labour_discount : 0) }}" min="0" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Central GST (CGST) %</label>
                                <input type="number" step="0.01" name="cgst_rate" x-model="cgstRate" @input="recalcSummary()" value="{{ old('cgst_rate', $isEdit ? $purchase->cgst_rate : 0) }}" min="0" max="100" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">State GST (SGST) %</label>
                                <input type="number" step="0.01" name="sgst_rate" x-model="sgstRate" @input="recalcSummary()" value="{{ old('sgst_rate', $isEdit ? $purchase->sgst_rate : 0) }}" min="0" max="100" class="purchase-input">
                            </div>

                            <div>
                                <label class="purchase-field-label">Integrated GST (IGST) %</label>
                                <input type="number" step="0.01" name="igst_rate" x-model="igstRate" @input="recalcSummary()" value="{{ old('igst_rate', $isEdit ? $purchase->igst_rate : 0) }}" min="0" max="100" class="purchase-input">
                                <p x-show="hasTaxConflict" x-cloak class="mt-1 text-xs font-semibold text-rose-600">
                                    IGST cannot be used with CGST/SGST.
                                </p>
                            </div>

                            <div>
                                <label class="purchase-field-label">TCS (₹)</label>
                                <input type="number" step="0.01" name="tcs_amount" x-model="tcsAmount" @input="recalcSummary()" value="{{ old('tcs_amount', $isEdit ? $purchase->tcs_amount : 0) }}" min="0" class="purchase-input">
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <span class="block text-xs font-semibold text-slate-500">Lines Total</span>
                                <span class="mt-1 block text-lg font-bold text-slate-900" x-text="'₹' + linesTotal.toFixed(2)"></span>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <span class="block text-xs font-semibold text-slate-500">Tax Total</span>
                                <span class="mt-1 block text-lg font-bold text-slate-900" x-text="'₹' + (cgstAmount + sgstAmount + igstAmount).toFixed(2)"></span>
                            </div>
                            <div class="purchase-total-row">
                                <span class="block text-xs font-semibold text-amber-700">Grand Total</span>
                                <span class="mt-1 block text-xl font-bold text-amber-700" x-text="'₹' + grandTotal.toFixed(2)"></span>
                            </div>
                        </div>
                    </section>

                    {{-- Step 4: Review & Save --}}
                    <section x-show="currentStep === 4" x-cloak class="purchase-card p-5" role="tabpanel">
                        <div class="purchase-card-header">
                            <div>
                                <p class="purchase-card-kicker">Step 4</p>
                                <h3 class="purchase-card-title">Review & Save</h3>
                                <p class="purchase-card-subtitle">Check the purchase totals and save the draft when the entry looks right.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-5">
                            <div class="space-y-4">
                                <div x-show="reviewWarnings.length > 0" x-cloak class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <p class="text-sm font-bold text-amber-900">Review these before saving</p>
                                    <ul class="mt-2 space-y-1 text-sm text-amber-800">
                                        <template x-for="warning in reviewWarnings" :key="warning">
                                            <li x-text="warning"></li>
                                        </template>
                                    </ul>
                                </div>

                                <div x-show="reviewWarnings.length === 0" x-cloak class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                                    The visible checks are clear.
                                </div>

                                <div class="rounded-xl border border-slate-200">
                                    <div class="border-b border-slate-100 px-4 py-3">
                                        <h4 class="text-sm font-bold text-slate-900">Items</h4>
                                    </div>
                                    <div class="divide-y divide-slate-100">
                                        <div x-show="lines.length === 0" class="px-4 py-5 text-sm text-slate-500">No items added.</div>
                                        <template x-for="(line, idx) in lines" :key="line._key">
                                            <div class="grid grid-cols-1 gap-2 px-4 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_140px] sm:items-center">
                                                <div class="min-w-0">
                                                    <p class="truncate font-bold text-slate-900" x-text="line.design || `Item ${idx + 1}`"></p>
                                                    <p class="mt-1 text-xs text-slate-500">
                                                        <span x-text="line.metal_type || 'metal'"></span>
                                                        <span> / </span>
                                                        <span x-text="line.purity || 'purity'"></span>
                                                        <span> / </span>
                                                        <span x-text="parseFloat(line.net_metal_weight || 0).toFixed(3) + ' g net'"></span>
                                                    </p>
                                                </div>
                                                <p class="text-left text-base font-bold text-slate-900 sm:text-right" x-text="'₹' + parseFloat(line.purchase_line_amount || 0).toFixed(2)"></p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="purchase-summary p-4">
                                <h4 class="mb-3 text-sm font-bold text-slate-900">Purchase Summary</h4>
                                <div class="space-y-2 text-sm">
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
                                        <span>Labour Discount</span>
                                        <span x-text="'₹' + parseFloat(labourDiscount || 0).toFixed(2)"></span>
                                    </div>
                                    <div class="flex justify-between text-slate-500 text-xs">
                                        <span>GST</span>
                                        <span x-text="'₹' + (cgstAmount + sgstAmount + igstAmount).toFixed(2)"></span>
                                    </div>
                                    <div class="flex justify-between text-slate-500 text-xs">
                                        <span>TCS</span>
                                        <span x-text="'₹' + parseFloat(tcsAmount || 0).toFixed(2)"></span>
                                    </div>
                                    <div class="purchase-total-row mt-3 flex justify-between text-base font-bold text-amber-700">
                                        <span>Grand Total</span>
                                        <span x-text="'₹' + grandTotal.toFixed(2)"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Bottom Navigation --}}
                    <div class="purchase-nav p-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <button type="button"
                                    x-show="currentStep > 1"
                                    x-cloak
                                    @click="prevStep()"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Back
                            </button>
                            <div x-show="currentStep === 1" class="hidden sm:block"></div>

                            <div class="text-center text-xs font-semibold text-slate-500 sm:text-left">
                                <span x-text="`Step ${currentStep} of ${steps.length}`"></span>
                                <span class="text-slate-300"> / </span>
                                <span x-text="currentStepLabel"></span>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button type="button"
                                        x-show="currentStep < 3"
                                        x-cloak
                                        @click="nextStep()"
                                        class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700">
                                    Save Step & Continue
                                </button>
                                <button type="button"
                                        x-show="currentStep === 3"
                                        x-cloak
                                        @click="goToStep(4)"
                                        class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700">
                                    Review
                                </button>
                                <button type="submit"
                                        x-show="currentStep === 4"
                                        x-cloak
                                        @click="saveDraft()"
                                        class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                                    {{ $isEdit ? 'Update Draft' : 'Save as Draft' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="purchase-bottom-spacer"></div>
                </div>

                {{-- Sidebar --}}
                <aside class="hidden xl:block">
                    <div class="purchase-summary xl:sticky xl:top-6 p-5">
                        <h3 class="mb-4 text-sm font-bold text-slate-900">Progress & Summary</h3>

                        <div class="mb-5 space-y-2">
                            <template x-for="step in steps" :key="`side-${step.id}`">
                                <button type="button"
                                        @click="goToStep(step.id)"
                                        class="flex w-full items-center justify-between rounded-xl border px-3 py-2 text-left text-sm"
                                        :class="currentStep === step.id ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'">
                                    <span class="font-semibold" x-text="step.label"></span>
                                    <span class="text-xs" x-text="currentStep === step.id ? 'Current' : `Step ${step.id}`"></span>
                                </button>
                            </template>
                        </div>

                        <div class="space-y-2 text-sm">
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
                                <span>Labour Discount</span>
                                <span x-text="'₹' + parseFloat(labourDiscount || 0).toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>Subtotal</span>
                                <span x-text="'₹' + subtotal.toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>GST</span>
                                <span x-text="'₹' + (cgstAmount + sgstAmount + igstAmount).toFixed(2)"></span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-xs">
                                <span>TCS</span>
                                <span x-text="'₹' + parseFloat(tcsAmount || 0).toFixed(2)"></span>
                            </div>
                            <div class="purchase-total-row flex justify-between text-base font-bold text-amber-700">
                                <span>Grand Total</span>
                                <span x-text="'₹' + grandTotal.toFixed(2)"></span>
                            </div>
                        </div>
                    </div>
                </aside>

            </div>
        </form>
    </div>

    <script>
    function purchaseForm(resolvedRates, existingLines) {
        const IS_EDIT = {{ $isEdit ? 'true' : 'false' }};
        const DRAFT_KEY = 'jf_purchase_draft_{{ auth()->id() }}';

        return {
            resolvedRates,
            steps: [
                { id: 1, label: 'Supplier & Invoice', helper: 'Supplier details' },
                { id: 2, label: 'Stock Items', helper: 'Items and weights' },
                { id: 3, label: 'Tax & Charges', helper: 'Discount and GST' },
                { id: 4, label: 'Review & Save', helper: 'Final check' },
            ],
            currentStep: 1,
            supplierMode: @json($initialSupplierMode),
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
            get currentStepLabel() {
                return this.steps.find(step => step.id === this.currentStep)?.label || '';
            },
            get hasTaxConflict() {
                return parseFloat(this.igstRate || 0) > 0
                    && (parseFloat(this.cgstRate || 0) > 0 || parseFloat(this.sgstRate || 0) > 0);
            },
            get incompleteLineCount() {
                return this.lines.filter(line => {
                    return !line.line_type
                        || !line.metal_type
                        || !line.purity
                        || !line.category
                        || !line.gross_weight;
                }).length;
            },
            get reviewWarnings() {
                const warnings = [];
                const form = this.purchaseFormEl();
                const purchaseDate = form?.querySelector('[name="purchase_date"]')?.value;

                if (!purchaseDate) warnings.push('Purchase date is required.');
                if (this.lines.length === 0) warnings.push('Add at least one stock item before saving.');
                if (this.incompleteLineCount > 0) {
                    warnings.push(`${this.incompleteLineCount} item${this.incompleteLineCount === 1 ? '' : 's'} need type, metal, purity, category, and gross weight.`);
                }
                if (this.hasTaxConflict) warnings.push('Use either IGST or CGST/SGST, not both.');

                return warnings;
            },

            init() {
                if (IS_EDIT) {
                    if (existingLines && existingLines.length > 0) {
                        this.lines = existingLines.map(l => this.prepareLine(l));
                    }
                    return;
                }

                const saved = this.loadDraft();
                if (saved) {
                    if (saved.lines && saved.lines.length > 0) {
                        this.lines = saved.lines.map(l => this.prepareLine(l));
                    }
                    if (saved.currentStep !== undefined) this.currentStep = this.normalizeStep(saved.currentStep);
                    if (saved.supplierMode !== undefined) this.supplierMode = saved.supplierMode === 'other' ? 'other' : 'vendor';
                    if (saved.labourDiscount !== undefined) this.labourDiscount = saved.labourDiscount;
                    if (saved.cgstRate      !== undefined) this.cgstRate      = saved.cgstRate;
                    if (saved.sgstRate      !== undefined) this.sgstRate      = saved.sgstRate;
                    if (saved.igstRate      !== undefined) this.igstRate      = saved.igstRate;
                    if (saved.tcsAmount     !== undefined) this.tcsAmount     = saved.tcsAmount;

                    if (saved.inputs) {
                        this.$nextTick(() => {
                            const form = this.purchaseFormEl();
                            if (!form) return;
                            const fields = ['vendor_id','supplier_name','supplier_gstin','invoice_number','invoice_date','purchase_date','notes','irn_number','ack_number','save_as_vendor'];
                            fields.forEach(name => {
                                if (saved.inputs[name] === undefined) return;
                                const el = form.querySelector(`[name="${name}"]`);
                                if (!el) return;
                                if (el.type === 'checkbox') {
                                    el.checked = Boolean(saved.inputs[name]);
                                    return;
                                }
                                el.value = saved.inputs[name];
                                // For selects, verify the option actually exists; clear if not.
                                if (el.tagName === 'SELECT' && el.value !== String(saved.inputs[name])) {
                                    el.value = '';
                                }
                            });
                        });
                    }

                    this.draftRestored = true;
                }
            },

            normalizeStep(step) {
                const parsed = parseInt(step, 10);
                if (!Number.isFinite(parsed)) return 1;
                return Math.min(this.steps.length, Math.max(1, parsed));
            },

            goToStep(step) {
                this.currentStep = this.normalizeStep(step);
                this.saveDraft();
                this.$nextTick(() => {
                    if (!this.$refs.purchaseTop) return;
                    this.$refs.purchaseTop.scrollIntoView({ block: 'start', behavior: 'smooth' });
                });
            },

            nextStep() {
                this.goToStep(this.currentStep + 1);
            },

            prevStep() {
                this.goToStep(this.currentStep - 1);
            },

            prepareLine(line = {}) {
                const hasDetails = [
                    'stone_charges',
                    'hallmark_charges',
                    'rhodium_charges',
                    'other_charges',
                    'huid',
                    'hallmark_date',
                    'hsn_code',
                    'barcode',
                    'notes',
                ].some(field => {
                    const value = line[field];
                    return value !== undefined && value !== null && String(value) !== '' && String(value) !== '0' && String(value) !== '0.00';
                });

                return {
                    ...line,
                    detailsOpen: line.detailsOpen ?? hasDetails,
                    _key: ++this._keyCounter,
                };
            },

            purchaseFormEl() {
                return document.getElementById('purchase-create-form');
            },

            scheduleSave() {
                if (IS_EDIT) return;
                clearTimeout(this._saveTimer);
                this._saveTimer = setTimeout(() => this.saveDraft(), 500);
            },

            saveDraft() {
                if (IS_EDIT) return;
                const form = this.purchaseFormEl();
                const inputs = {};
                if (form) {
                    ['vendor_id','supplier_name','supplier_gstin','invoice_number','invoice_date','purchase_date','notes','irn_number','ack_number','save_as_vendor'].forEach(name => {
                        const el = form.querySelector(`[name="${name}"]`);
                        if (!el) return;
                        inputs[name] = el.type === 'checkbox' ? el.checked : el.value;
                    });
                }
                try {
                    localStorage.setItem(DRAFT_KEY, JSON.stringify({
                        inputs,
                        currentStep:    this.currentStep,
                        supplierMode:   this.supplierMode,
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
                    detailsOpen: false,
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

            toggleLineDetails(line) {
                line.detailsOpen = !line.detailsOpen;
                this.scheduleSave();
            },

            prefillRate(line) {
                if (!line.metal_type || !line.purity) {
                    this.scheduleSave();
                    return;
                }
                const metal   = line.metal_type;
                const purity  = parseFloat(line.purity);
                if (!this.resolvedRates[metal]) {
                    this.scheduleSave();
                    return;
                }

                // Find closest purity key
                const keys = Object.keys(this.resolvedRates[metal]);
                let best = null, bestDiff = Infinity;
                for (const k of keys) {
                    const diff = Math.abs(parseFloat(k) - purity);
                    if (diff < bestDiff) { bestDiff = diff; best = k; }
                }
                if (best && bestDiff < 0.5) {
                    const rate = this.resolvedRates[metal][best]?.rate_per_gram;
                    if (rate) {
                        line.purchase_rate_per_gram = rate;
                        this._recalcLineTotal(line);
                    }
                }
                this.scheduleSave();
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
