@php
    $isDesktopGrid = $itemGridMode === 'desktop';
    $itemControlDisabled = $isDesktopGrid ? 'isMobile' : '!isMobile';
    $groupClass = $isDesktopGrid
        ? 'py-1.5 first:pt-0 last:pb-0 2xl:rounded-xl 2xl:border 2xl:border-slate-200 2xl:bg-white 2xl:px-3 2xl:py-2'
        : 'rounded-xl border border-slate-200 bg-white p-3';
    $fieldsClass = $isDesktopGrid
        ? 'flex min-w-0 flex-wrap items-start gap-x-2 gap-y-1 [&_label]:text-[11px] [&_label]:leading-tight'
        : 'grid grid-cols-1 gap-3 sm:grid-cols-2';
    $fieldClass = $isDesktopGrid ? 'mt-1 h-8 min-h-[44px] w-full text-sm' : 'mt-1 h-11 min-h-[44px] w-full text-sm';
    $unitClass = 'pointer-events-none absolute bottom-0 right-3 top-1 flex items-center text-[11px] font-medium leading-none text-slate-500';
    $width = static fn (string $desktop): string => $isDesktopGrid ? $desktop : 'w-full';
@endphp

<div class="{{ $isDesktopGrid ? 'divide-y divide-slate-200 2xl:grid 2xl:grid-cols-12 2xl:gap-2 2xl:divide-y-0' : 'space-y-3' }}" data-historical-advanced-layout="{{ $itemGridMode }}">
    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-4' : '' }}" data-historical-advanced-group="identity">
        <div class="{{ $fieldsClass }}">
            <div class="{{ $width('w-[160px]') }}">
                <label :for="`line-${i}-sku-${@js($itemGridMode)}`">SKU</label>
                <input type="text" :id="`line-${i}-sku-${@js($itemGridMode)}`" :name="`lines[${i}][line_sku]`" x-model="line.line_sku" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}">
            </div>
            <div class="{{ $width('w-[110px]') }}">
                <label :for="`line-${i}-hsn-${@js($itemGridMode)}`">HSN</label>
                <input type="text" :id="`line-${i}-hsn-${@js($itemGridMode)}`" :name="`lines[${i}][line_hsn]`" x-model="line.line_hsn" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}">
            </div>
        </div>
    </section>

    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-8' : '' }}" data-historical-advanced-group="weight-valuation">
        <div class="{{ $fieldsClass }}">
            @foreach(['gross' => 'Gross weight', 'net' => 'Net weight', 'stone-weight' => 'Stone weight'] as $key => $label)
                @php $field = $key === 'stone-weight' ? 'line_stone_weight' : "line_{$key}_weight"; @endphp
                <div class="{{ $width('w-[105px]') }}">
                    <label :for="`line-${i}-{{ $key }}-${@js($itemGridMode)}`">{{ $label }}</label>
                    <div class="relative">
                        <input type="number" step="any" :id="`line-${i}-{{ $key }}-${@js($itemGridMode)}`" :name="`lines[${i}][{{ $field }}]`" x-model="line.{{ $field }}" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-7 text-right tabular-nums">
                        <span class="{{ $unitClass }}">g</span>
                    </div>
                </div>
            @endforeach
            <div class="{{ $width('w-[105px]') }}">
                <span class="flex items-center justify-between gap-1"><span class="text-xs font-medium text-slate-600">Fine weight</span><span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-emerald-700">Auto</span></span>
                <div class="mt-1 flex {{ $isDesktopGrid ? 'h-8' : 'h-11' }} items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold tabular-nums text-slate-800"><span x-text="line.line_fine_weight || '—'"></span><span class="text-xs font-medium text-slate-500">g</span></div>
            </div>
            <div class="{{ $width('w-[150px]') }}">
                <span class="flex items-center justify-between gap-1">
                    <label :for="`line-${i}-weight-basis-${@js($itemGridMode)}`">Billable basis</label>
                    <span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_billable_weight_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_billable_weight_mode === 'manual' ? 'Manual' : 'Auto'"></span>
                </span>
                <select :id="`line-${i}-weight-basis-${@js($itemGridMode)}`" :name="`lines[${i}][line_billable_weight_basis]`" x-model="line.line_billable_weight_basis" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}">
                    <option value="">Choose basis</option><option value="gross">Gross weight</option><option value="net">Net weight</option><option value="manual">Manual weight</option>
                </select>
                <button x-show="line.line_billable_weight_mode === 'manual' && ['gross', 'net'].includes(line.line_billable_weight_basis)" type="button" @click="recalculate(line, 'line_billable_weight')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use selected weight</button>
            </div>
            <div class="{{ $width('w-[140px]') }}">
                <span class="flex items-center justify-between gap-1"><label :for="`line-${i}-metal-value-${@js($itemGridMode)}`">Metal value</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_metal_value_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_metal_value_mode === 'manual' ? 'Manual' : 'Auto'"></span></span>
                <div class="relative"><input type="number" step="any" :id="`line-${i}-metal-value-${@js($itemGridMode)}`" :name="`lines[${i}][line_metal_value]`" x-model="line.line_metal_value" data-derived-field="line_metal_value" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div>
                <input type="hidden" :name="`lines[${i}][line_metal_value_mode]`" :value="line.line_metal_value_mode" :disabled="{{ $itemControlDisabled }}">
                <button x-show="line.line_metal_value_mode === 'manual'" type="button" @click="recalculate(line, 'line_metal_value')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button>
            </div>
        </div>
    </section>

    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-7' : '' }}" data-historical-advanced-group="stone-making">
        <div class="{{ $fieldsClass }}">
            <div class="{{ $width('w-[125px]') }}"><label :for="`line-${i}-stone-rate-${@js($itemGridMode)}`">Stone rate</label><div class="relative"><input type="number" step="any" :id="`line-${i}-stone-rate-${@js($itemGridMode)}`" :name="`lines[${i}][line_stone_rate]`" x-model="line.line_stone_rate" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-11 text-right tabular-nums"><span class="{{ $unitClass }}">₹/g</span></div></div>
            <div class="{{ $width('w-[140px]') }}"><span class="flex items-center justify-between gap-1"><label :for="`line-${i}-stone-value-${@js($itemGridMode)}`">Stone value</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_stone_value_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_stone_value_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><div class="relative"><input type="number" step="any" :id="`line-${i}-stone-value-${@js($itemGridMode)}`" :name="`lines[${i}][line_stone_value]`" x-model="line.line_stone_value" data-derived-field="line_stone_value" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div><input type="hidden" :name="`lines[${i}][line_stone_value_mode]`" :value="line.line_stone_value_mode" :disabled="{{ $itemControlDisabled }}"><button x-show="line.line_stone_value_mode === 'manual'" type="button" @click="recalculate(line, 'line_stone_value')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button></div>
            @php
                $makingBasisLabels = ['fixed_line' => 'Fixed amount', 'percent' => 'Percent', 'per_gram' => 'Per gram'];
            @endphp
            <div class="{{ $width('w-[145px]') }}"><label :for="`line-${i}-making-basis-${@js($itemGridMode)}`">Making charges basis</label><select :id="`line-${i}-making-basis-${@js($itemGridMode)}`" :name="`lines[${i}][line_making_basis]`" x-model="line.line_making_basis" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}"><option value="">Choose basis</option>@foreach($makingBases as $basis)<option value="{{ $basis }}">{{ $makingBasisLabels[$basis] ?? str_replace('_', ' ', ucfirst($basis)) }}</option>@endforeach</select></div>
            <div class="{{ $width('w-[120px]') }}"><label :for="`line-${i}-making-value-${@js($itemGridMode)}`">Making charges value</label><div class="relative"><input type="number" step="any" :id="`line-${i}-making-value-${@js($itemGridMode)}`" :name="`lines[${i}][line_making_value]`" x-model="line.line_making_value" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} text-right tabular-nums" :class="{'pr-3': !makingUnit(line.line_making_basis), 'pr-8': ['%', '₹'].includes(makingUnit(line.line_making_basis)), 'pr-11': makingUnit(line.line_making_basis) === '₹/g', 'pr-16': makingUnit(line.line_making_basis) === '₹/piece'}"><span class="{{ $unitClass }}" x-text="makingUnit(line.line_making_basis)"></span></div></div>
            <div class="{{ $width('w-[150px]') }}"><span class="flex items-center justify-between gap-1"><label :for="`line-${i}-making-amount-${@js($itemGridMode)}`">Making charges amount</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_making_amount_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_making_amount_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><div class="relative"><input type="number" step="any" :id="`line-${i}-making-amount-${@js($itemGridMode)}`" :name="`lines[${i}][line_making_amount]`" x-model="line.line_making_amount" data-derived-field="line_making_amount" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div><input type="hidden" :name="`lines[${i}][line_making_amount_mode]`" :value="line.line_making_amount_mode" :disabled="{{ $itemControlDisabled }}"><button x-show="line.line_making_amount_mode === 'manual'" type="button" @click="recalculate(line, 'line_making_amount')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button></div>
        </div>
    </section>

    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-5' : '' }}" data-historical-advanced-group="additional-charges">
        <div class="{{ $fieldsClass }}">
            <div class="{{ $width('w-[145px]') }}"><label :for="`line-${i}-wastage-basis-${@js($itemGridMode)}`">Wastage basis</label><select :id="`line-${i}-wastage-basis-${@js($itemGridMode)}`" :name="`lines[${i}][line_wastage_basis]`" x-model="line.line_wastage_basis" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}"><option value="">Choose basis</option><option value="percent">Percent of metal</option><option value="flat">Flat amount</option></select></div>
            <div class="{{ $width('w-[110px]') }}"><label :for="`line-${i}-wastage-value-${@js($itemGridMode)}`">Wastage value</label><div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-wastage-value-${@js($itemGridMode)}`" :name="`lines[${i}][line_wastage_value]`" x-model="line.line_wastage_value" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-10 text-right tabular-nums"><span class="{{ $unitClass }}" x-text="wastageUnit(line.line_wastage_basis)"></span></div></div>
            <div class="{{ $width('w-[150px]') }}"><span class="flex items-center justify-between gap-1"><label :for="`line-${i}-wastage-amount-${@js($itemGridMode)}`">Wastage amount</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_wastage_amount_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_wastage_amount_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><div class="relative"><input type="number" step="any" :id="`line-${i}-wastage-amount-${@js($itemGridMode)}`" :name="`lines[${i}][line_wastage_amount]`" x-model="line.line_wastage_amount" data-derived-field="line_wastage_amount" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div><input type="hidden" :name="`lines[${i}][line_wastage_amount_mode]`" :value="line.line_wastage_amount_mode" :disabled="{{ $itemControlDisabled }}"><button x-show="line.line_wastage_amount_mode === 'manual'" type="button" @click="recalculate(line, 'line_wastage_amount')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button></div>
            {{-- Batch 5 usability correction: rhodium/hallmark/misc collapsed into one
                 field. hallmark_charge/rhodium_charge stay as hidden inputs (still
                 bound to Alpine, still submitted) rather than being deleted — any
                 value already carried in the page's state (e.g. a re-rendered
                 preview) must survive an unrelated edit untouched, never be
                 silently cleared, and never be redistributed by editing the one
                 visible field. The backend still sums all three exactly once. --}}
            <div class="{{ $width('w-[160px]') }}">
                <label :for="`line-${i}-other-${@js($itemGridMode)}`">Other charges</label>
                <div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-other-${@js($itemGridMode)}`" :name="`lines[${i}][line_other_charge]`" x-model="line.line_other_charge" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div>
                <p class="mt-1 text-[10px] leading-tight text-slate-500">Rhodium, hallmark & other extras combined</p>
            </div>
            <input type="hidden" :name="`lines[${i}][line_hallmark_charge]`" x-model="line.line_hallmark_charge" :disabled="{{ $itemControlDisabled }}">
            <input type="hidden" :name="`lines[${i}][line_rhodium_charge]`" x-model="line.line_rhodium_charge" :disabled="{{ $itemControlDisabled }}">
        </div>
    </section>

    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-8' : '' }}" data-historical-advanced-group="discount-tax">
        <div class="{{ $fieldsClass }}">
            <div class="{{ $width('w-[140px]') }}"><label :for="`line-${i}-discount-type-${@js($itemGridMode)}`">Discount type</label><select :id="`line-${i}-discount-type-${@js($itemGridMode)}`" :name="`lines[${i}][line_discount_type]`" x-model="line.line_discount_type" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}"><option value="">No discount</option><option value="fixed">Fixed amount</option><option value="percent">Percent</option></select></div>
            <div class="{{ $width('w-[110px]') }}"><label :for="`line-${i}-discount-value-${@js($itemGridMode)}`">Discount value</label><div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-discount-value-${@js($itemGridMode)}`" :name="`lines[${i}][line_discount_value]`" x-model="line.line_discount_value" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-10 text-right tabular-nums"><span class="{{ $unitClass }}" x-text="discountUnit(line.line_discount_type)"></span></div></div>
            <div class="{{ $width('w-[150px]') }}"><span class="flex items-center justify-between gap-1"><label :for="`line-${i}-discount-amount-${@js($itemGridMode)}`">Discount amount</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_discount_amount_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_discount_amount_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-discount-amount-${@js($itemGridMode)}`" :name="`lines[${i}][line_discount_amount]`" x-model="line.line_discount_amount" data-derived-field="line_discount_amount" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div><input type="hidden" :name="`lines[${i}][line_discount_amount_mode]`" :value="line.line_discount_amount_mode" :disabled="{{ $itemControlDisabled }}"><button x-show="line.line_discount_amount_mode === 'manual'" type="button" @click="recalculate(line, 'line_discount_amount')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button></div>
            <div class="{{ $width('w-[140px]') }}"><label :for="`line-${i}-tax-mode-${@js($itemGridMode)}`">Line tax mode</label><select :id="`line-${i}-tax-mode-${@js($itemGridMode)}`" :name="`lines[${i}][line_tax_mode]`" x-model="line.line_tax_mode" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }}"><option value="no_gst">No GST</option><option value="gst_inclusive">GST inclusive</option><option value="gst_exclusive">GST exclusive</option></select></div>
            <div class="{{ $width('w-[80px]') }}"><label :for="`line-${i}-gst-rate-${@js($itemGridMode)}`">GST</label><div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-gst-rate-${@js($itemGridMode)}`" :name="`lines[${i}][line_gst_rate]`" x-model="line.line_gst_rate" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-7 text-right tabular-nums"><span class="{{ $unitClass }}">%</span></div></div>
            <div class="{{ $width('w-[140px]') }}"><span class="flex items-center justify-between gap-1"><label :for="`line-${i}-taxable-${@js($itemGridMode)}`">Line taxable</label><span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase" :class="line.line_taxable_mode === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700'" x-text="line.line_taxable_mode === 'manual' ? 'Manual' : 'Auto'"></span></span><div class="relative"><input type="number" min="0" step="any" :id="`line-${i}-taxable-${@js($itemGridMode)}`" :name="`lines[${i}][line_taxable]`" x-model="line.line_taxable" data-derived-field="line_taxable" :disabled="{{ $itemControlDisabled }}" class="{{ $fieldClass }} pr-8 text-right tabular-nums"><span class="{{ $unitClass }}">₹</span></div><input type="hidden" :name="`lines[${i}][line_taxable_mode]`" :value="line.line_taxable_mode" :disabled="{{ $itemControlDisabled }}"><button x-show="line.line_taxable_mode === 'manual'" type="button" @click="recalculate(line, 'line_taxable')" class="mt-1 min-h-[32px] text-xs font-semibold text-amber-700">Use automatic value</button></div>
            <div x-show="outlierWarnings(line).length" role="status" aria-live="polite" data-historical-line-outlier-warning class="w-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 sm:col-span-2">
                <p class="font-semibold">Check unusual value</p>
                <template x-for="warning in outlierWarnings(line)" :key="warning"><p class="mt-1" x-text="warning"></p></template>
            </div>
        </div>
    </section>

    <section class="{{ $groupClass }} {{ $isDesktopGrid ? '2xl:col-span-4' : '' }}" data-historical-advanced-group="notes">
        <div class="min-w-0">
            <label :for="`line-${i}-notes-${@js($itemGridMode)}`" class="{{ $isDesktopGrid ? 'sr-only' : '' }}">Line notes</label>
            <textarea rows="{{ $isDesktopGrid ? 1 : 3 }}" :id="`line-${i}-notes-${@js($itemGridMode)}`" :name="`lines[${i}][line_notes]`" x-model="line.line_notes" :disabled="{{ $itemControlDisabled }}" class="w-full {{ $isDesktopGrid ? '!h-12 !min-h-0' : 'mt-1' }} text-sm"></textarea>
        </div>
    </section>
</div>
