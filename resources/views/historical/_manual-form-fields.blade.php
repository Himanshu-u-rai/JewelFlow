{{-- Shared field markup for the manual entry form and its preview screen.
     Values come from old() so the preview screen (which flashes the
     submitted input before rendering) shows exactly what was typed.
     Fieldset/legend kept for accessibility (screen readers announce the
     group name) — only the visual wrapper changed to match the shared
     card system used across the app. --}}
<div class="grid grid-cols-1 gap-4 items-start" style="--app-control-bg: #ffffff; --app-control-border: #cbd5e1; --app-control-border-focus: #b45309;" data-historical-manual-layout>
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12" data-historical-identity-row>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-4" data-historical-form-section data-historical-section="document">
    <legend class="sr-only">Document identity</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">Document identity</h2>
            @if($carriedForward ?? null)
                <span class="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-teal-700" data-historical-carried-forward>Carried from previous bill</span>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 gap-4 p-4 sm:p-6">
        <div>
            <label for="original_document_number">Original invoice number <span class="text-slate-400 font-normal">(optional)</span></label>
            <input type="text" id="original_document_number" name="original_document_number" value="{{ old('original_document_number') }}" @if($carriedForward ?? null) autofocus @endif class="w-full max-w-full lg:w-64">
        </div>
        <div>
            <label for="document_series">Series <span class="text-slate-400 font-normal">(optional)</span></label>
            <input type="text" id="document_series" name="document_series" value="{{ old('document_series', $carriedForward['document_series'] ?? null) }}" class="w-full max-w-full lg:w-64">
        </div>
        <div>
            <label for="document_date">Document date <span class="text-rose-600">*</span></label>
            <input type="date" id="document_date" name="document_date" value="{{ old('document_date', $carriedForward['document_date'] ?? null) }}" required aria-required="true" class="w-full max-w-full lg:w-64">
        </div>
        <div>
            <label for="source_system">Source system</label>
            <input type="text" id="source_system" name="source_system" value="{{ old('source_system', $carriedForward['source_system'] ?? 'Manual') }}" class="w-full max-w-full lg:w-64">
        </div>
    </div>
</fieldset>

<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-visible lg:col-span-8"
          x-data="historicalCustomerPicker({
              searchUrl: @js(route('historical.customers.search')),
              customerId: @js(old('customer_id', '')),
              selectedLabel: @js(old('selected_customer_label', '')),
              addOnPublish: @js((string) old('add_customer_on_publish', '1') === '1'),
          })"
          data-historical-customer-picker data-historical-form-section data-historical-section="customer">
    <legend class="sr-only">Customer snapshot (never linked automatically)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Customer snapshot</h2>
        <p class="mt-1 text-xs text-slate-500">Never linked automatically.</p>
    </div>
    <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4" @input="updateStatus()" data-historical-customer-fields>
        <div class="relative sm:col-span-2 lg:col-span-4" @click.outside="close()">
            <label for="historical_customer_search">Find an existing customer <span class="font-normal text-slate-400">(optional)</span></label>
            <input type="search" id="historical_customer_search" x-model="search"
                   @input.debounce.250ms="findCustomers()"
                   @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                   @keydown.enter.prevent="chooseActive()" @keydown.escape.prevent="close()"
                   role="combobox" aria-autocomplete="list" :aria-expanded="open"
                   data-customer-search-input data-customer-search-url="{{ route('historical.customers.search') }}"
                   placeholder="Search name, mobile or GSTIN" autocomplete="off" class="w-full">
            <input type="hidden" name="customer_id" x-model="customerId">
            <input type="hidden" name="selected_customer_label" x-model="selectedLabel">

            <div x-cloak x-show="open" role="listbox"
                 class="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
                <p x-show="loading" class="px-3 py-3 text-sm text-slate-500">Searching…</p>
                <template x-for="(customer, index) in results" :key="customer.id">
                    <button type="button" role="option" @click="select(customer)"
                            :aria-selected="activeIndex === index" :class="activeIndex === index ? 'bg-amber-50' : ''"
                            class="flex min-h-[44px] w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left hover:bg-amber-50">
                        <span><span class="block text-sm font-semibold text-slate-900" x-text="customer.name"></span><span class="block text-xs text-slate-500" x-text="customer.mobile_masked"></span></span>
                        <span x-show="customer.customer_type" class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold uppercase text-slate-600" x-text="customer.customer_type"></span>
                    </button>
                </template>
                <p x-show="!loading && results.length === 0" class="px-3 py-3 text-sm text-slate-500">No active customer matches.</p>
            </div>

            <div class="mt-2 flex min-h-0 flex-wrap items-center gap-2" data-customer-status aria-live="polite">
                <span x-show="status === 'existing'" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Existing customer</span>
                <span x-show="status === 'new'" class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700">New customer</span>
                <span x-show="status === 'snapshot'" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Snapshot only</span>
                <span x-show="status === 'archived'" class="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">Archived — action required</span>
                <span x-show="selectedLabel" x-text="selectedLabel" class="text-xs font-medium text-slate-600"></span>
                <button x-show="customerId" type="button" @click="clearSelection()" class="min-h-[32px] text-xs font-semibold text-rose-700">Clear selection</button>
            </div>
        </div>
        <div>
            <label for="customer_name">Name</label>
            <input type="text" id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="w-full">
        </div>
        <div>
            <label for="customer_mobile">Mobile</label>
            <input type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" id="customer_mobile" name="customer_mobile" value="{{ old('customer_mobile') }}" placeholder="10-digit mobile" class="w-full">
        </div>
        <div>
            <label for="customer_gstin">GSTIN</label>
            <input type="text" id="customer_gstin" name="customer_gstin" value="{{ old('customer_gstin') }}" class="w-full">
        </div>
        <div>
            <label for="place_of_supply">Place of supply</label>
            <input type="text" id="place_of_supply" name="place_of_supply" value="{{ old('place_of_supply') }}" class="w-full">
        </div>
        <div>
            <label for="customer_type">Customer type</label>
            <select id="customer_type" name="customer_type" class="w-full min-h-[44px]">
                <option value="">Select customer type</option>
                <option value="b2c" @selected(old('customer_type') === 'b2c')>B2C</option>
                <option value="b2b" @selected(old('customer_type') === 'b2b')>B2B</option>
            </select>
        </div>
        <div>
            <label for="customer_pan">PAN</label>
            <input type="text" id="customer_pan" name="customer_pan" value="{{ old('customer_pan') }}" class="w-full" maxlength="20">
        </div>
        <div class="sm:col-span-2 lg:col-span-2">
            <label for="customer_address">Address</label>
            <input type="text" id="customer_address" name="customer_address" value="{{ old('customer_address') }}" class="w-full">
        </div>
    </div>
</fieldset>
</div>

{{-- Dense desktop register plus touch-friendly cards. Only the visible layout
     enables its controls, so both surfaces share one Alpine row model. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden min-w-0 max-w-full" data-historical-form-section data-historical-section="items">
    <legend class="sr-only">Item lines (optional)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Item lines <span class="text-slate-400 font-normal text-sm">(optional)</span></h2>
    </div>
    <div class="p-4 sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-slate-700">Enter the original bill one line at a time.</p>
                <p class="mt-1 text-xs text-slate-500">A fresh row appears automatically. Open Details for weights, charges, discounts and tax.</p>
            </div>
            <button type="button" class="btn btn-sm min-h-[44px] shrink-0" @click="addLine()">Add item</button>
        </div>

        <div class="mt-4 hidden max-w-full overflow-hidden rounded-xl border border-slate-300 bg-white xl:block" data-historical-item-grid-desktop>
            <table class="w-full table-fixed text-sm" data-historical-item-table>
                <colgroup>
                    <col class="w-[3%]"><col class="w-[19%]"><col class="w-[11%]"><col class="w-[9%]"><col class="w-[7%]"><col class="w-[11%]"><col class="w-[13%]"><col class="w-[14%]"><col class="w-[13%]">
                </colgroup>
                <thead class="sticky top-0 z-10 bg-slate-100">
                    <tr class="text-xs font-semibold text-slate-600">
                        <th scope="col" class="border-b border-r border-slate-300 px-1 py-2 text-center">#</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-left">Item</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-left">Metal</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-left">Purity</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-right">Qty</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-right">Billable wt (g)</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-right">Historical rate (₹/g)</th>
                        <th scope="col" class="border-b border-r border-slate-300 px-2 py-2 text-right">Line total (₹)</th>
                        <th scope="col" class="border-b border-slate-300 px-2 py-2 text-center">Actions</th>
                    </tr>
                </thead>
                <template x-for="(line, i) in lines" :key="`desktop-${i}`">
                    <tbody @input.debounce.250ms="lineChanged(i, $event)" @change="lineChanged(i, $event)" class="group">
                        <tr data-historical-item-core-row class="bg-white transition-colors focus-within:bg-amber-50/40 hover:bg-slate-50">
                            <th scope="row" x-text="i + 1" class="border-b border-r border-slate-300 px-1 py-2 text-center text-xs font-semibold tabular-nums text-slate-500"></th>
                            <td class="border-b border-r border-slate-300 p-0"><input type="text" :name="`lines[${i}][line_item_name]`" x-model="line.line_item_name" :disabled="isMobile" :aria-label="`Item ${i + 1} name`" placeholder="Description" class="h-11 w-full rounded-none border border-slate-300 bg-white px-2 text-sm shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500"></td>
                            <td class="border-b border-r border-slate-300 p-0">
                                <select x-model="line.line_metal_choice" @change="metalChanged(line)" :disabled="isMobile" :aria-label="`Item ${i + 1} metal`" class="h-11 min-h-[44px] w-full rounded-none border border-slate-300 bg-white px-1 text-xs shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                    <option value="">Select metal</option>
                                    @foreach($enabledMetals as $metal)<option value="{{ $metal }}">{{ ucfirst($metal) }}</option>@endforeach
                                    <option value="__custom">Other…</option>
                                </select>
                                <input type="text" x-show="line.line_metal_choice === '__custom'" x-model="line.line_custom_metal" @input="customMetalChanged(line)" :disabled="isMobile" aria-label="Custom metal" placeholder="Metal" class="h-9 w-full rounded-none border border-slate-300 bg-white px-2 text-xs shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                <input type="hidden" :name="`lines[${i}][line_metal_type]`" x-model="line.line_metal_type" :disabled="isMobile">
                            </td>
                            <td class="border-b border-r border-slate-300 p-0">
                                <select x-model="line.line_purity_choice" @change="purityChanged(line)" :disabled="isMobile" :aria-label="`Item ${i + 1} purity`" class="h-11 min-h-[44px] w-full rounded-none border border-slate-300 bg-white px-1 text-xs shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                    <option value="">Select purity</option>
                                    <template x-for="profile in purityOptions(line)" :key="`${profile.metal}-${profile.value}`"><option :value="String(profile.value)" x-text="profile.label"></option></template>
                                    <option value="__custom">Custom…</option>
                                </select>
                                <input x-show="line.line_purity_choice === '__custom'" type="number" step="any" x-model.number="line.line_purity_value" @input="customPurityChanged(line)" :disabled="isMobile" aria-label="Custom purity" placeholder="Purity" class="h-9 w-full rounded-none border border-slate-300 bg-white px-2 text-right text-xs shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                <input type="hidden" :name="`lines[${i}][line_purity]`" x-model="line.line_purity" :disabled="isMobile"><input type="hidden" :name="`lines[${i}][line_purity_value]`" x-model="line.line_purity_value" :disabled="isMobile">
                            </td>
                            <td class="border-b border-r border-slate-300 p-0"><input type="number" step="any" :name="`lines[${i}][line_quantity]`" x-model="line.line_quantity" :disabled="isMobile" :aria-label="`Item ${i + 1} quantity`" placeholder="Qty" class="h-11 w-full rounded-none border border-slate-300 bg-white px-2 text-right text-sm tabular-nums shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500"></td>
                            <td class="border-b border-r border-slate-300 p-0">
                                <input type="number" step="any" :name="`lines[${i}][line_billable_weight]`" x-model="line.line_billable_weight" data-derived-field="line_billable_weight" :disabled="isMobile" :aria-label="`Item ${i + 1} billable weight`" placeholder="Weight" class="h-11 w-full rounded-none border border-slate-300 bg-white px-2 text-right text-sm tabular-nums shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                <input type="hidden" :name="`lines[${i}][line_billable_weight_mode]`" :value="line.line_billable_weight_mode" :disabled="isMobile">
                            </td>
                            <td class="border-b border-r border-slate-300 p-0"><input type="number" step="any" :name="`lines[${i}][line_rate]`" x-model="line.line_rate" :disabled="isMobile" :aria-label="`Item ${i + 1} historical rate per gram`" placeholder="Rate" class="h-11 w-full rounded-none border border-slate-300 bg-white px-2 text-right text-sm tabular-nums shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500"></td>
                            <td class="border-b border-r border-slate-300 p-0">
                                <div class="flex items-center">
                                    <input type="number" step="any" :name="`lines[${i}][line_total]`" x-model="line.line_total" data-derived-field="line_total" :disabled="isMobile" :aria-label="`Item ${i + 1} line total`" placeholder="Total" class="h-11 min-w-0 flex-1 rounded-none border border-slate-300 bg-white px-2 text-right text-sm font-semibold tabular-nums shadow-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                                    <span class="mr-1 rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_total_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_total_mode === 'manual' ? 'M' : 'A'"></span>
                                </div>
                                <input type="hidden" :name="`lines[${i}][line_total_mode]`" :value="line.line_total_mode" :disabled="isMobile">
                            </td>
                            <td class="border-b border-slate-300 px-1 py-0">
                                <div class="flex items-center justify-center">
                                    <button type="button" @click="line.advanced = !line.advanced" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-amber-200 bg-amber-50 text-amber-700 shadow-sm hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1" :aria-expanded="line.advanced" :aria-label="`Toggle details for item ${i + 1}`"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                                    <button type="button" @click="duplicateLine(i)" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-sky-700 shadow-sm hover:bg-sky-100 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-1" :aria-label="`Duplicate item ${i + 1}`"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="7" y="7" width="9" height="9" rx="1.5"/><path d="M13 7V5.5A1.5 1.5 0 0 0 11.5 4h-7A1.5 1.5 0 0 0 3 5.5v7A1.5 1.5 0 0 0 4.5 14H7"/></svg></button>
                                    <button type="button" @click="removeLine(i)" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-rose-200 bg-rose-50 text-rose-700 shadow-sm hover:bg-rose-100 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-1" :aria-label="`Remove item ${i + 1}`"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 6h12M8 3h4l1 3H7l1-3Zm-2 3 .7 10h6.6L14 6M8.5 9v4M11.5 9v4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                                </div>
                                <input type="hidden" :name="`lines[${i}][line_calculation_enabled]`" value="1" :disabled="isMobile">
                            </td>
                        </tr>
                        <tr x-cloak x-show="line.advanced" data-historical-item-advanced-row class="bg-slate-50">
                            <td colspan="9" class="border-b border-slate-300 p-3">
                                <div class="mb-1 flex items-center justify-between gap-3"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Advanced details</p><button x-show="line.line_total_mode === 'manual'" type="button" @click="recalculate(line, 'line_total')" class="min-h-[32px] text-xs font-semibold text-amber-700">Recalculate line total</button></div>
                                @include('historical._manual-item-advanced-fields', ['itemGridMode' => 'desktop'])
                                <p x-show="line.line_missing" x-text="line.line_missing" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"></p>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>

        <div class="mt-4 grid gap-3 xl:hidden" data-historical-item-grid-mobile>
            <template x-for="(line, i) in lines" :key="`mobile-${i}`">
                <article class="overflow-hidden rounded-xl border border-slate-300 bg-white" data-historical-item-card @input.debounce.250ms="lineChanged(i, $event)" @change="lineChanged(i, $event)">
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-center gap-2"><span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md border border-slate-200 bg-white px-2 text-xs font-semibold tabular-nums text-slate-600" x-text="i + 1"></span><span class="text-sm font-semibold text-slate-800" x-text="line.line_item_name || 'New item'"></span></div>
                        <div class="flex items-center">
                            <button type="button" @click="duplicateLine(i)" class="inline-flex h-11 min-w-11 items-center justify-center px-2 text-xs font-semibold text-slate-600">Duplicate</button>
                            <button type="button" @click="removeLine(i)" class="inline-flex h-11 min-w-11 items-center justify-center px-2 text-xs font-semibold text-rose-600">Remove</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-3 sm:grid-cols-4">
                        <div class="col-span-2 sm:col-span-2"><label :for="`line-${i}-item-mobile`">Item</label><input type="text" :id="`line-${i}-item-mobile`" :name="`lines[${i}][line_item_name]`" x-model="line.line_item_name" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px]"></div>
                        <div><label :for="`line-${i}-metal-mobile`">Metal</label><select :id="`line-${i}-metal-mobile`" x-model="line.line_metal_choice" @change="metalChanged(line)" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px]"><option value="">Select metal</option>@foreach($enabledMetals as $metal)<option value="{{ $metal }}">{{ ucfirst($metal) }}</option>@endforeach<option value="__custom">Other…</option></select><input type="text" x-show="line.line_metal_choice === '__custom'" x-model="line.line_custom_metal" @input="customMetalChanged(line)" :disabled="!isMobile" aria-label="Custom metal" placeholder="Metal" class="mt-1 h-11 w-full min-h-[44px]"><input type="hidden" :name="`lines[${i}][line_metal_type]`" x-model="line.line_metal_type" :disabled="!isMobile"></div>
                        <div><label :for="`line-${i}-purity-mobile`">Purity</label><select :id="`line-${i}-purity-mobile`" x-model="line.line_purity_choice" @change="purityChanged(line)" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px]"><option value="">Select purity</option><template x-for="profile in purityOptions(line)" :key="`${profile.metal}-${profile.value}`"><option :value="String(profile.value)" x-text="profile.label"></option></template><option value="__custom">Custom purity</option></select><input x-show="line.line_purity_choice === '__custom'" type="number" step="any" x-model.number="line.line_purity_value" @input="customPurityChanged(line)" :disabled="!isMobile" aria-label="Custom purity" class="mt-1 h-11 w-full min-h-[44px]"><input type="hidden" :name="`lines[${i}][line_purity]`" x-model="line.line_purity" :disabled="!isMobile"><input type="hidden" :name="`lines[${i}][line_purity_value]`" x-model="line.line_purity_value" :disabled="!isMobile"></div>
                        <div><label :for="`line-${i}-qty-mobile`">Quantity</label><input type="number" step="any" :id="`line-${i}-qty-mobile`" :name="`lines[${i}][line_quantity]`" x-model="line.line_quantity" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px] text-right tabular-nums"></div>
                        <div><label :for="`line-${i}-billable-mobile`">Billable weight (g)</label><input type="number" step="any" :id="`line-${i}-billable-mobile`" :name="`lines[${i}][line_billable_weight]`" x-model="line.line_billable_weight" data-derived-field="line_billable_weight" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px] text-right tabular-nums"><input type="hidden" :name="`lines[${i}][line_billable_weight_mode]`" :value="line.line_billable_weight_mode" :disabled="!isMobile"></div>
                        <div><label :for="`line-${i}-rate-mobile`">Historical rate (₹/g)</label><input type="number" step="any" :id="`line-${i}-rate-mobile`" :name="`lines[${i}][line_rate]`" x-model="line.line_rate" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px] text-right tabular-nums"></div>
                        <div><span class="flex items-center justify-between gap-2"><label :for="`line-${i}-total-mobile`">Line total (₹)</label><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="line.line_total_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_total_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><input type="number" step="any" :id="`line-${i}-total-mobile`" :name="`lines[${i}][line_total]`" x-model="line.line_total" data-derived-field="line_total" :disabled="!isMobile" class="mt-1 h-11 w-full min-h-[44px] text-right font-semibold tabular-nums"><input type="hidden" :name="`lines[${i}][line_total_mode]`" :value="line.line_total_mode" :disabled="!isMobile"></div>
                    </div>
                    <input type="hidden" :name="`lines[${i}][line_calculation_enabled]`" value="1" :disabled="!isMobile">
                    <button type="button" @click="line.advanced = !line.advanced" class="flex min-h-[44px] w-full items-center justify-between border-t border-slate-200 px-3 text-sm font-semibold text-slate-700" :aria-expanded="line.advanced"><span>Advanced details</span><span x-text="line.advanced ? 'Hide' : 'Show'" class="text-xs text-amber-700"></span></button>
                    <div x-cloak x-show="line.advanced" class="border-t border-slate-200 bg-slate-50 p-3">
                        @include('historical._manual-item-advanced-fields', ['itemGridMode' => 'mobile'])
                        <button x-show="line.line_total_mode === 'manual'" type="button" @click="recalculate(line, 'line_total')" class="mt-3 min-h-[44px] text-sm font-semibold text-amber-700">Use automatic line total</button>
                        <p x-show="line.line_missing" x-text="line.line_missing" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"></p>
                    </div>
                </article>
            </template>
        </div>

        <button type="button" class="btn btn-sm mt-3 min-h-[44px]" @click="addLine()">Add item</button>
    </div>
</fieldset>

<div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3" data-historical-financial-row>
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="amounts">
    <legend class="sr-only">Amount / payment (display snapshot — no ledger, no receivable)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Amount / payment <span class="text-slate-400 font-normal text-sm">(display snapshot — no ledger, no receivable)</span></h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 sm:p-6">
        @foreach($money as $f => $label)
            <div>
                <span class="flex items-center justify-between gap-2">
                    <label for="{{ $f }}">{{ $label }}</label>
                    @if(in_array($f, $calculatedDocumentFields, true))
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="documentModes.{{ $f }} === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="documentModes.{{ $f }} === 'manual' ? 'Manual' : 'Auto'"></span>
                    @endif
                </span>
                @if(in_array($f, $calculatedDocumentFields, true))
                    <input type="number" step="any" id="{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" x-model="documentTotals.{{ $f }}" @input="markDocumentManual('{{ $f }}')" class="w-full text-right tabular-nums">
                    <input type="hidden" name="{{ $f }}_mode" :value="documentModes.{{ $f }}">
                    <button x-show="documentModes.{{ $f }} === 'manual'" type="button" @click="recalculateDocument('{{ $f }}')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button>
                @else
                    <input type="number" step="any" id="{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" class="w-full text-right tabular-nums">
                @endif
            </div>
        @endforeach
        <div>
            <span class="flex items-center justify-between gap-2">
                <label for="grand_total">Grand total <span class="text-rose-600">*</span></label>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="documentModes.grand_total === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="documentModes.grand_total === 'manual' ? 'Manual' : 'Auto'"></span>
            </span>
            <input type="number" step="any" id="grand_total" name="grand_total" value="{{ old('grand_total') }}" x-model="documentTotals.grand_total" @input="markDocumentManual('grand_total')" required aria-required="true" class="w-full text-right font-semibold tabular-nums">
            <input type="hidden" name="grand_total_mode" :value="documentModes.grand_total">
            <button x-show="documentModes.grand_total === 'manual'" type="button" @click="recalculateDocument('grand_total')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button>
        </div>
    </div>
</fieldset>

<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden lg:col-span-1" data-historical-form-section data-historical-section="tax-making">
    <legend class="sr-only">Tax and making charges</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header aria-hidden="true">
        <h2 class="text-base font-semibold text-slate-900">Tax and making charges</h2>
    </div>
    <div class="p-4 sm:p-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4" data-historical-supporting-grid>
        <div>
            <label for="tax_mode">Tax mode</label>
            <select id="tax_mode" name="tax_mode" class="w-full min-h-[44px]">
                @foreach($taxModes as $v => $l)<option value="{{ $v }}" @selected(old('tax_mode', $carriedForward['tax_mode'] ?? null) === $v)>{{ $l }}</option>@endforeach
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

    {{-- One ordinary bill-level rate + split. Populates taxable amount, CGST/SGST
         or IGST, tax total and grand total (see $calculatedDocumentFields above) —
         every result stays directly editable afterward. Lines with their own
         line-level GST mode (gst_inclusive/gst_exclusive) are treated as
         exceptions and excluded from this bill-level base. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 mt-4" data-historical-supporting-grid>
        <div>
            <label for="bill_gst_rate">Bill GST % (ordinary tax rate)</label>
            <input type="number" step="any" min="0" max="100" id="bill_gst_rate" name="bill_gst_rate" value="{{ old('bill_gst_rate') }}" placeholder="e.g. 3" class="w-full">
        </div>
        <div>
            <label for="tax_split_type">Tax split</label>
            <select id="tax_split_type" name="tax_split_type" class="w-full min-h-[44px]">
                @foreach($taxSplitTypes as $v => $l)<option value="{{ $v }}" @selected(old('tax_split_type', $carriedForward['tax_split_type'] ?? null) === $v)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <p class="text-xs text-slate-500 sm:col-span-2">Applies once to the whole bill (not per line). Leave blank to enter tax amounts by hand instead.</p>
    </div>

    {{-- Optional, reference-only: how the ORIGINAL paper bill recorded its
         making charge value. It is stored as a display snapshot alongside
         the document (making_label_original/making_value_original/
         making_category/making_basis/making_amount — see
         HistoricalDocumentNormalizer::normalize()) and is never added into
         grand_total, so it must never look like a second charge-entry field
         sitting next to the real bill-level tax rate above. The actual,
         calculated making charge is entered once per item, in that item's
         own "Advanced details" (line_making_basis/line_making_value).
         "Making charges" is the standard wording — no free-text label or
         category choice is asked of the user here; both are set internally
         (see HistoricalManualCalculationService/manualNormalizerOptions()).
         Collapsed by default; field ids/names/grid classes are unchanged so
         nothing else that imports/renders/tests these fields needs to change. --}}
    <details class="mt-4 rounded-xl border border-slate-200 bg-slate-50" data-historical-original-making-details>
        <summary class="cursor-pointer select-none px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
            Original bill's making charges <span class="font-normal normal-case tracking-normal">(optional, reference only — does not add to the total)</span>
        </summary>
        <div class="px-3 pb-3 pt-1">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 mt-4" data-historical-supporting-grid>
        <div class="lg:col-span-1">
            <label for="making_value">Making charges value</label>
            <input type="text" id="making_value" name="making_value" value="{{ old('making_value') }}" placeholder="12% or 450/gm" class="w-full">
        </div>
        <div class="lg:col-span-1">
            <label for="making_basis">Making charges basis</label>
            <select id="making_basis" name="making_basis" class="w-full min-h-[44px]">
                <option value="">Select basis</option>
                @foreach($makingBases as $b)<option value="{{ $b }}" @selected(old('making_basis')===$b)>{{ str_replace('_',' ',$b) }}</option>@endforeach
            </select>
        </div>
    </div>
        </div>
    </details>
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

{{-- Payment rows (Batch 3 §7/§8). One row by default; account is either a
     shop-configured ShopPaymentMethod or a custom free-text label — never a
     live ledger write, see HistoricalSalesPayment's class docblock. --}}
<fieldset class="rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section data-historical-section="payments">
    <legend class="sr-only">Payments (display snapshot — no ledger, no receivable)</legend>
    <div class="border-b border-slate-200 px-4 py-4 sm:px-6 flex flex-wrap items-center justify-between gap-3" data-historical-card-header aria-hidden="true">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Payments <span class="text-slate-400 font-normal text-sm">(display snapshot — no ledger, no receivable)</span></h2>
            <p class="mt-1 text-xs text-slate-500">Optional. Add one row per tender received against this bill.</p>
        </div>
        <span data-historical-payment-status
              class="rounded-full px-3 py-1 text-xs font-semibold uppercase"
              :class="{
                  'bg-emerald-50 text-emerald-700': paymentStatusLabel === 'Fully paid',
                  'bg-amber-100 text-amber-800': paymentStatusLabel === 'Partially paid',
                  'bg-slate-100 text-slate-600': paymentStatusLabel === 'Unpaid',
              }"
              x-text="paymentStatusLabel"></span>
    </div>
    <div class="p-4 sm:p-6 grid gap-3">
        <template x-for="(payment, i) in payments" :key="`payment-${i}`">
            <div class="grid grid-cols-1 gap-3 rounded-xl border border-slate-300 bg-white p-3 sm:grid-cols-2 lg:grid-cols-6"
                 data-historical-payment-row
                 @input.debounce.250ms="syncDocumentTotals()" @change="syncDocumentTotals()">
                <div>
                    <label :for="`payment-${i}-mode`">Mode</label>
                    <select :id="`payment-${i}-mode`" :name="`payments[${i}][mode]`" x-model="payment.mode" class="w-full min-h-[44px]">
                        <option value="">Select payment mode</option>
                        @foreach(\App\Models\Historical\HistoricalSalesPayment::VALID_MODES as $mode)
                            <option value="{{ $mode }}">{{ ucfirst(str_replace('_', ' ', $mode)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label :for="`payment-${i}-amount`">Amount</label>
                    <input type="number" step="any" :id="`payment-${i}-amount`" :name="`payments[${i}][amount]`" x-model="payment.amount" placeholder="Amount" class="w-full text-right tabular-nums">
                </div>
                <div>
                    <label :for="`payment-${i}-account`">Account</label>
                    <select :id="`payment-${i}-account`" x-model="payment.account_choice" @change="accountChanged(payment)" class="w-full min-h-[44px]">
                        <option value="">Select account</option>
                        @foreach($shopPaymentMethods as $method)
                            <option value="{{ $method['id'] }}">{{ $method['label'] }}</option>
                        @endforeach
                        <option value="__custom">Custom account…</option>
                    </select>
                    <input type="text" x-show="payment.account_choice === '__custom'" x-model="payment.account_label_snapshot" :name="`payments[${i}][account_label_snapshot]`" placeholder="Account name" class="mt-2 w-full">
                    <input type="hidden" :name="`payments[${i}][shop_payment_method_id]`" x-model="payment.shop_payment_method_id">
                </div>
                <div>
                    <label :for="`payment-${i}-reference`">Reference</label>
                    <input type="text" :id="`payment-${i}-reference`" :name="`payments[${i}][reference]`" x-model="payment.reference" placeholder="UPI ref, cheque no…" class="w-full">
                </div>
                <div>
                    <label :for="`payment-${i}-date`">Date</label>
                    <input type="date" :id="`payment-${i}-date`" :name="`payments[${i}][payment_date]`" x-model="payment.payment_date" class="w-full">
                </div>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label :for="`payment-${i}-note`">Note</label>
                        <input type="text" :id="`payment-${i}-note`" :name="`payments[${i}][note]`" x-model="payment.note" class="w-full">
                    </div>
                    <button type="button" @click="removePayment(i)" class="min-h-[44px] shrink-0 text-xs font-semibold text-rose-700">Remove</button>
                </div>
            </div>
        </template>
        <button type="button" class="btn btn-sm min-h-[44px] w-fit" @click="addPayment()">Add another payment</button>
    </div>
</fieldset>
</div>
