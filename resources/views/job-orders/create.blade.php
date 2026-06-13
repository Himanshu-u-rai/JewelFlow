<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .job-create-shell {
            max-width: 1280px;
        }

        .job-create-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 18px;
            align-items: start;
        }

        .job-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
        }

        .job-section {
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .job-section:last-child {
            border-bottom: 0;
        }

        .job-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .job-title {
            margin: 0;
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
        }

        .job-copy {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }

        .job-label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
        }

        .job-control {
            width: 100%;
            min-height: 44px;
            border-radius: 13px;
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
        }

        .job-control:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .job-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .job-field-full {
            grid-column: 1 / -1;
        }

        .job-combobox {
            position: relative;
        }

        .job-combobox-trigger {
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

        .job-combobox-trigger:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .job-combobox-placeholder {
            color: #64748b;
        }

        .job-combobox-menu {
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

        .job-combobox-menu-up {
            top: auto;
            bottom: calc(100% + 8px);
        }

        .job-combobox-list {
            max-height: 230px;
            overflow-y: auto;
            padding: 6px;
        }

        .job-combobox-option {
            display: block;
            width: 100%;
            border-radius: 10px;
            padding: 10px 11px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            text-align: left;
        }

        .job-combobox-option:hover,
        .job-combobox-option-selected {
            background: #f0fdfa;
            color: #0f766e;
        }

        .job-combobox-meta {
            display: block;
            margin-top: 2px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
        }

        .job-line-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #f8fafc;
            padding: 12px;
        }

        .job-line-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) repeat(3, minmax(110px, 150px)) 34px;
            gap: 10px;
            align-items: end;
        }

        .job-lot-avail {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
            padding: 7px 10px;
            border-radius: 10px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            font-size: 11px;
            font-weight: 800;
            color: #166534;
            flex-wrap: wrap;
        }

        .job-lot-avail-warn {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #9a3412;
        }

        .job-lot-avail-over {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #be123c;
        }

        .job-lot-avail span + span::before {
            content: '→';
            margin-right: 10px;
            opacity: 0.5;
        }

        .job-gross-display {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .job-gross-value {
            display: flex;
            align-items: center;
            min-height: 44px;
            border-radius: 13px;
            border: 1px dashed #cbd5e1;
            background: #f1f5f9;
            padding: 10px 12px;
            color: #475569;
            font-size: 14px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .job-add-line {
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

        .job-remove-line {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid #fecdd3;
            background: #fff1f2;
            color: #be123c;
            font-size: 18px;
            font-weight: 900;
        }

        .job-preview {
            position: sticky;
            top: 92px;
        }

        .job-preview-head {
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .job-preview-body {
            padding: 16px;
        }

        .job-preview-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid #edf2f7;
            padding: 11px 0;
        }

        .job-preview-row:first-child {
            padding-top: 0;
        }

        .job-preview-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .job-preview-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
        }

        .job-preview-value {
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 15px;
            font-weight: 900;
            text-align: right;
        }

        .job-advance-panel {
            border: 1px solid #bfdbfe;
            border-radius: 16px;
            background: #eff6ff;
            padding: 14px;
        }

        .job-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .job-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
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

        .job-cancel {
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
            .job-create-grid {
                grid-template-columns: 1fr;
            }

            .job-preview {
                position: static;
                order: -1;
            }
        }

        @media (max-width: 760px) {
            .job-create-shell {
                padding-inline: 10px;
            }

            .job-create-grid {
                gap: 12px;
            }

            .job-preview {
                order: 0;
            }

            .job-card {
                border-radius: 16px;
            }

            .job-section,
            .job-preview-head,
            .job-preview-body {
                padding: 14px;
            }

            .job-section-head {
                flex-direction: column;
                gap: 6px;
                margin-bottom: 12px;
            }

            .job-copy {
                display: none;
            }

            .job-field-grid {
                gap: 12px;
            }

            .job-control {
                min-height: 40px;
                border-radius: 11px;
            }

            .job-combobox-trigger {
                min-height: 40px;
                border-radius: 11px;
            }

            .job-combobox-menu {
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

            .job-combobox-menu-up {
                top: auto;
                bottom: 16px;
            }

            .job-combobox-list {
                max-height: 55vh;
                padding: 8px;
            }

            .job-combobox-option {
                border: 1px solid #e2e8f0;
                margin-bottom: 7px;
                background: #ffffff;
            }

            .job-combobox-option:last-child {
                margin-bottom: 0;
            }

            .job-combobox-option:hover,
            .job-combobox-option-selected {
                border-color: #0f766e;
                background: #f0fdfa;
            }

            .job-label {
                margin-bottom: 5px;
                font-size: 11px;
            }

            .job-preview-body {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .job-preview-row {
                display: block;
                border: 1px solid #edf2f7;
                border-radius: 12px;
                padding: 9px;
            }

            .job-preview-row:first-child,
            .job-preview-row:last-child {
                padding: 9px;
            }

            .job-preview-value {
                display: block;
                margin-top: 4px;
                text-align: left;
                font-size: 13px;
            }

            .job-add-line {
                width: 100%;
            }

            .job-line-grid {
                grid-template-columns: 1fr;
            }

            .job-remove-line {
                width: 100%;
                border-radius: 12px;
            }

            .job-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .job-submit,
            .job-cancel {
                width: 100%;
            }
        }

        @media (max-width: 380px) {
            .job-field-grid,
            .job-preview-body {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $selectedKarigar = $karigars->firstWhere('id', (int) request('karigar'));
    @endphp

    <x-page-header title="Issue Bullion to Karigar" />

    <div class="content-inner job-create-shell">

        <form method="POST" action="{{ route('job-orders.store') }}" x-data="jobOrderForm()" @keydown.escape.window="closeDropdowns()"
              @if($selectedKarigar)
                  x-init="onKarigarSelect('{{ $selectedKarigar->id }}', @js($selectedKarigar->name . ($selectedKarigar->gst_number ? ' - ' . $selectedKarigar->gst_number : '')), {{ (float) ($selectedKarigar->default_wastage_percent ?? 2) }})"
              @endif>
            @csrf

            <div class="job-create-grid">
                <div class="job-card">
                    <section class="job-section">
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Karigar & Schedule</h2>
                                <p class="job-copy">Select who receives bullion and define expected return terms.</p>
                            </div>
                        </div>

                        <div class="job-field-grid">
                            <div class="job-field-full job-combobox" @click.outside="karigarOpen = false">
                                <span class="job-label">Karigar *</span>
                                <input type="hidden" name="karigar_id" x-model="karigarId">
                                <button type="button" class="job-combobox-trigger" @click="karigarOpen = ! karigarOpen" :aria-expanded="karigarOpen.toString()">
                                    <span :class="karigarName ? '' : 'job-combobox-placeholder'" x-text="karigarName || 'Select karigar...'">Select karigar...</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="job-combobox-menu" x-show="karigarOpen" x-transition.origin.top x-cloak>
                                    <div class="job-combobox-list">
                                        <button type="button" class="job-combobox-option" @click="onKarigarSelect('', 'Select karigar...', 2)">
                                            Select karigar...
                                        </button>
                                    @foreach($karigars as $k)
                                        @php
                                            $karigarLabel = $k->name . ($k->gst_number ? ' - ' . $k->gst_number : '');
                                        @endphp
                                        <button type="button"
                                                class="job-combobox-option"
                                                :class="karigarId === '{{ $k->id }}' ? 'job-combobox-option-selected' : ''"
                                                @click="onKarigarSelect('{{ $k->id }}', @js($karigarLabel), {{ (float) ($k->default_wastage_percent ?? 2) }})">
                                            {{ $k->name }}
                                            @if($k->gst_number)
                                                <span class="job-combobox-meta">{{ $k->gst_number }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                    </div>
                                </div>
                            </div>

                            <label>
                                <span class="job-label">Allowed Wastage % *</span>
                                <input type="number" step="0.01" name="allowed_wastage_percent" required min="0" max="25" class="job-control" x-model="allowedWastage">
                            </label>

                            <label>
                                <span class="job-label">Issue Date *</span>
                                <input type="date" name="issue_date" required value="{{ now()->toDateString() }}" class="job-control">
                            </label>

                            <label>
                                <span class="job-label">Expected Return Date</span>
                                <input type="date" name="expected_return_date" class="job-control">
                            </label>
                        </div>
                    </section>

                    <section class="job-section">
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Metal Profile</h2>
                                <p class="job-copy">These values guide purity limits and fine-weight calculation.</p>
                            </div>
                        </div>

                        <div class="job-field-grid">
                            <div class="job-combobox" @click.outside="metalTypeOpen = false">
                                <span class="job-label">Metal Type *</span>
                                <input type="hidden" name="metal_type" x-model="metalType">
                                <button type="button" class="job-combobox-trigger" @click="metalTypeOpen = ! metalTypeOpen" :aria-expanded="metalTypeOpen.toString()">
                                    <span x-text="metalType === 'silver' ? 'Silver' : 'Gold'">Gold</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="job-combobox-menu" x-show="metalTypeOpen" x-transition.origin.top x-cloak>
                                    <div class="job-combobox-list">
                                        <button type="button" class="job-combobox-option" :class="metalType === 'gold' ? 'job-combobox-option-selected' : ''" @click="onMetalTypeSelect('gold')">Gold</button>
                                        <button type="button" class="job-combobox-option" :class="metalType === 'silver' ? 'job-combobox-option-selected' : ''" @click="onMetalTypeSelect('silver')">Silver</button>
                                    </div>
                                </div>
                            </div>

                            <label>
                                <span class="job-label">Purity *</span>
                                <input type="number" step="0.01" name="purity" required min="1" :max="metalType === 'silver' ? 1000 : 24" class="job-control" x-model="purity">
                            </label>

                            <label class="job-field-full">
                                <span class="job-label">Notes</span>
                                <textarea name="notes" rows="3" class="job-control min-h-[96px]"></textarea>
                            </label>
                        </div>
                    </section>

                    <input type="hidden" name="metal_source" :value="metalSource">

                    <section class="job-section">
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Where does the metal come from?</h2>
                                <p class="job-copy">Choose how this job is supplied with gold. Pick "Only labour" if you are not giving any metal.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <button type="button" @click="setSource('vault')"
                                    class="text-left rounded-xl border-2 p-4 transition"
                                    :class="metalSource === 'vault' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 hover:border-gray-300 bg-white'">
                                <div class="text-sm font-semibold text-gray-800">Shop vault gold</div>
                                <div class="text-xs text-gray-500 mt-0.5">Give gold from your own stock (a lot in the vault).</div>
                            </button>
                            <button type="button" @click="setSource('karigar_held')"
                                    class="text-left rounded-xl border-2 p-4 transition"
                                    :class="metalSource === 'karigar_held' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 hover:border-gray-300 bg-white'">
                                <div class="text-sm font-semibold text-gray-800">Karigar's own balance</div>
                                <div class="text-xs text-gray-500 mt-0.5">Karigar uses gold they already hold from earlier work.</div>
                            </button>
                            <button type="button" @click="setSource('customer_advance')"
                                    class="text-left rounded-xl border-2 p-4 transition"
                                    :class="metalSource === 'customer_advance' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 hover:border-gray-300 bg-white'">
                                <div class="text-sm font-semibold text-gray-800">Customer's own gold</div>
                                <div class="text-xs text-gray-500 mt-0.5">Customer gave gold for this job (uses their deposit).</div>
                            </button>
                            <button type="button" @click="setSource('none')"
                                    class="text-left rounded-xl border-2 p-4 transition"
                                    :class="metalSource === 'none' ? 'border-amber-500 bg-amber-50' : 'border-gray-200 hover:border-gray-300 bg-white'">
                                <div class="text-sm font-semibold text-gray-800">Only labour (no metal)</div>
                                <div class="text-xs text-gray-500 mt-0.5">No gold is given. Karigar is paid for work only.</div>
                            </button>
                        </div>
                    </section>

                    <section class="job-section" x-show="metalSource === 'vault'" x-cloak>
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Bullion Issued</h2>
                                <p class="job-copy">Enter the fine weight to issue per lot. Gross weight is derived for the delivery challan.</p>
                            </div>
                            <button type="button" @click="addLine" class="job-add-line">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                Add another lot
                            </button>
                        </div>

                        <div class="space-y-3">
                            <template x-for="(line, idx) in lines" :key="idx">
                                <div class="job-line-card">
                                    <div class="job-line-grid">
                                        <div class="job-combobox" x-data="{ get open() { return lines[idx].lotOpen; }, set open(v) { lines[idx].lotOpen = v; } }" @click.outside="open = false">
                                            <span class="job-label">Lot</span>
                                            <input type="hidden" :name="'issuances[' + idx + '][metal_lot_id]'" :disabled="metalSource !== 'vault'" x-model="line.metal_lot_id">
                                            <button type="button" class="job-combobox-trigger" @click="open = !open" :aria-expanded="open.toString()">
                                                <span :class="line.lotName ? '' : 'job-combobox-placeholder'" x-text="line.lotName || 'Select lot...'">Select lot...</span>
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                            </button>
                                            <div class="job-combobox-menu" x-show="open" x-transition.origin.top x-cloak>
                                                <div class="job-combobox-list">
                                                    <button type="button" class="job-combobox-option" @click="onLotSelect(idx, '', 'Select lot...', 0, 0)">
                                                        Select lot...
                                                    </button>
                                                @foreach($lots as $lot)
                                                    @php
                                                        $lotLabel = 'Lot #' . $lot->lot_number . ' (' . $lot->source . ') - ' . rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') . ($lot->metal_type === 'silver' ? '‰' : 'K');
                                                    @endphp
                                                    <button type="button"
                                                            class="job-combobox-option"
                                                            :class="line.metal_lot_id === '{{ $lot->id }}' ? 'job-combobox-option-selected' : ''"
                                                            @click='onLotSelect(idx, @json((string) $lot->id), @json($lotLabel), {{ (float) $lot->purity }}, {{ (float) $lot->fine_weight_remaining }})'>
                                                        Lot #{{ $lot->lot_number }}
                                                        <span class="job-combobox-meta">{{ $lot->source }} · {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}{{ $lot->metal_type === 'silver' ? '‰' : 'K' }} · {{ number_format($lot->fine_weight_remaining, 3) }}g available fine</span>
                                                    </button>
                                                @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        <label>
                                            <span class="job-label">Fine Weight (g) *</span>
                                            <input type="number" step="0.001" min="0.001" :name="'issuances[' + idx + '][fine_weight]'" :required="metalSource === 'vault'" :disabled="metalSource !== 'vault'" class="job-control font-semibold" x-model="line.fine_weight" @input="recomputeGross(idx)">
                                        </label>

                                        <div class="job-gross-display">
                                            <span class="job-label">Gross (g) <span style="font-weight:500;color:#94a3b8">challan</span></span>
                                            <input type="hidden" :name="'issuances[' + idx + '][gross_weight]'" :disabled="metalSource !== 'vault'" :value="line.gross_weight">
                                            <div class="job-gross-value" x-text="(parseFloat(line.gross_weight) || 0).toFixed(3) + ' g'">0.000 g</div>
                                        </div>

                                        <label>
                                            <span class="job-label">Purity</span>
                                            <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" :name="'issuances[' + idx + '][purity]'" :required="metalSource === 'vault'" :disabled="metalSource !== 'vault'" class="job-control" x-model="line.purity" @input="recomputeGross(idx)">
                                        </label>

                                        <button type="button" @click="removeLine(idx)" x-show="lines.length > 1" class="job-remove-line" aria-label="Remove lot line">×</button>
                                    </div>

                                    <div x-show="line.metal_lot_id"
                                         :class="'job-lot-avail' + (parseFloat(line.fine_weight) > line.lotAvailable ? ' job-lot-avail-over' : (parseFloat(line.fine_weight) > line.lotAvailable * 0.9 ? ' job-lot-avail-warn' : ''))"
                                         x-cloak>
                                        <span>Available: <strong x-text="line.lotAvailable.toFixed(3) + 'g'"></strong></span>
                                        <span>Issuing: <strong x-text="(parseFloat(line.fine_weight) || 0).toFixed(3) + 'g'"></strong></span>
                                        <span>Remaining: <strong x-text="Math.max(0, line.lotAvailable - (parseFloat(line.fine_weight) || 0)).toFixed(3) + 'g'"></strong></span>
                                        <span x-show="parseFloat(line.fine_weight) > line.lotAvailable" style="color:#be123c">⚠ Exceeds available fine weight</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section class="job-section" x-show="metalSource === 'karigar_held'" x-cloak>
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Karigar's held gold</h2>
                                <p class="job-copy">Enter how much fine gold from the karigar's own balance is used for this job. It is taken from what they already hold.</p>
                            </div>
                        </div>
                        <div class="job-field-grid">
                            <input type="hidden" name="sources[0][source_type]" value="karigar_held" :disabled="metalSource !== 'karigar_held'">
                            <label>
                                <span class="job-label">Fine Weight Used (g) *</span>
                                <input type="number" step="0.001" min="0.001" name="sources[0][fine_weight]"
                                       :required="metalSource === 'karigar_held'" :disabled="metalSource !== 'karigar_held'"
                                       class="job-control font-semibold" x-model="heldFine">
                            </label>
                            <label>
                                <span class="job-label">Purity *</span>
                                <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" name="sources[0][purity]"
                                       :required="metalSource === 'karigar_held'" :disabled="metalSource !== 'karigar_held'"
                                       class="job-control" x-model="purity">
                            </label>
                        </div>
                    </section>

                    <section class="job-section" x-show="metalSource === 'customer_advance'" x-cloak>
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Customer's gold</h2>
                                <p class="job-copy">Choose the customer and enter how much of their deposited fine gold is used for this job.</p>
                            </div>
                        </div>
                        <div class="job-field-grid">
                            <input type="hidden" name="sources[0][source_type]" value="customer_advance" :disabled="metalSource !== 'customer_advance'">
                            <div class="job-combobox" @click.outside="customerOpen = false">
                                <span class="job-label">Customer *</span>
                                <input type="hidden" name="sources[0][customer_id]" x-model="customerId" :disabled="metalSource !== 'customer_advance'">
                                <button type="button" class="job-combobox-trigger" @click="customerOpen = ! customerOpen" :aria-expanded="customerOpen.toString()">
                                    <span :class="customerName ? '' : 'job-combobox-placeholder'" x-text="customerName || 'Select customer...'">Select customer...</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="job-combobox-menu" x-show="customerOpen" x-transition.origin.top x-cloak>
                                    <div class="job-combobox-list">
                                        <button type="button" class="job-combobox-option" @click="onCustomerSelect('', 'Select customer...')">
                                            Select customer...
                                        </button>
                                    @foreach($customers as $c)
                                        @php
                                            $custLabel = trim($c->first_name . ' ' . $c->last_name) . ($c->mobile ? ' · ' . $c->mobile : '');
                                        @endphp
                                        <button type="button"
                                                class="job-combobox-option"
                                                :class="customerId === '{{ $c->id }}' ? 'job-combobox-option-selected' : ''"
                                                @click="onCustomerSelect('{{ $c->id }}', @js($custLabel))">
                                            {{ trim($c->first_name . ' ' . $c->last_name) ?: 'Customer #' . $c->id }}
                                            @if($c->mobile)<span class="job-combobox-meta">{{ $c->mobile }}</span>@endif
                                        </button>
                                    @endforeach
                                    </div>
                                </div>
                            </div>
                            <label>
                                <span class="job-label">Fine Weight Used (g) *</span>
                                <input type="number" step="0.001" min="0.001" name="sources[0][fine_weight]"
                                       :required="metalSource === 'customer_advance'" :disabled="metalSource !== 'customer_advance'"
                                       class="job-control font-semibold" x-model="custFine">
                            </label>
                            <label>
                                <span class="job-label">Purity *</span>
                                <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" name="sources[0][purity]"
                                       :required="metalSource === 'customer_advance'" :disabled="metalSource !== 'customer_advance'"
                                       class="job-control" x-model="purity">
                            </label>
                        </div>
                    </section>

                    <section class="job-section" x-show="metalSource === 'none'" x-cloak>
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                            This job has no metal. The karigar will be paid for labour only. No gold leaves the vault.
                        </div>
                    </section>

                    <section class="job-section">
                        <div class="job-section-head">
                            <div>
                                <h2 class="job-title">Advance to Karigar</h2>
                                <p class="job-copy">Leave amount blank if no advance is given.</p>
                            </div>
                        </div>

                        <div class="job-advance-panel">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label>
                                    <span class="job-label">Advance Amount</span>
                                    <input type="number" step="0.01" min="0" name="advance_amount" placeholder="0.00" class="job-control bg-white">
                                </label>

                                <div class="job-combobox" @click.outside="advanceModeOpen = false">
                                    <span class="job-label">Payment Mode</span>
                                    <input type="hidden" name="advance_mode" x-model="advanceMode">
                                    <button type="button" class="job-combobox-trigger bg-white" @click="advanceModeOpen = ! advanceModeOpen" :aria-expanded="advanceModeOpen.toString()">
                                        <span x-text="advanceModeName">Cash</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div class="job-combobox-menu job-combobox-menu-up" x-show="advanceModeOpen" x-transition.origin.bottom x-cloak>
                                        <div class="job-combobox-list">
                                            <button type="button" class="job-combobox-option" :class="advanceMode === 'cash' ? 'job-combobox-option-selected' : ''" @click="onAdvanceModeSelect('cash', 'Cash')">Cash</button>
                                            <button type="button" class="job-combobox-option" :class="advanceMode === 'upi' ? 'job-combobox-option-selected' : ''" @click="onAdvanceModeSelect('upi', 'UPI')">UPI</button>
                                            <button type="button" class="job-combobox-option" :class="advanceMode === 'bank' ? 'job-combobox-option-selected' : ''" @click="onAdvanceModeSelect('bank', 'Bank Transfer')">Bank Transfer</button>
                                            <button type="button" class="job-combobox-option" :class="advanceMode === 'cheque' ? 'job-combobox-option-selected' : ''" @click="onAdvanceModeSelect('cheque', 'Cheque')">Cheque</button>
                                            <button type="button" class="job-combobox-option" :class="advanceMode === 'other' ? 'job-combobox-option-selected' : ''" @click="onAdvanceModeSelect('other', 'Other')">Other</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="job-combobox" @click.outside="paymentMethodOpen = false">
                                    <span class="job-label">Account</span>
                                    <input type="hidden" name="advance_payment_method_id" x-model="paymentMethodId">
                                    <button type="button" class="job-combobox-trigger bg-white" @click="paymentMethodOpen = ! paymentMethodOpen" :aria-expanded="paymentMethodOpen.toString()">
                                        <span :class="paymentMethodName ? '' : 'job-combobox-placeholder'" x-text="paymentMethodName || 'Select account'">Select account</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div class="job-combobox-menu job-combobox-menu-up" x-show="paymentMethodOpen" x-transition.origin.bottom x-cloak>
                                        <div class="job-combobox-list">
                                            <button type="button" class="job-combobox-option" @click="onPaymentMethodSelect('', '')">
                                                Select account
                                            </button>
                                        @foreach($paymentMethods as $pm)
                                            @php $paymentLabel = $pm->name . ' (' . $pm->type . ')'; @endphp
                                            <button type="button"
                                                    class="job-combobox-option"
                                                    :class="paymentMethodId === '{{ $pm->id }}' ? 'job-combobox-option-selected' : ''"
                                                    @click="onPaymentMethodSelect('{{ $pm->id }}', @js($paymentLabel))">
                                                {{ $pm->name }}
                                                <span class="job-combobox-meta">{{ $pm->type }}</span>
                                            </button>
                                        @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="job-section">
                        <div class="job-actions">
                            <button type="submit" class="job-submit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12c.552 0 1-.448 1-1V5c0-.552-.448-1-1-1H3c-.552 0-1 .448-1 1v6c0 .552.448 1 1 1"/><path d="M3 12v7c0 .552.448 1 1 1h16c.552 0 1-.448 1-1v-7"/></svg>
                                Issue & Print Challan
                            </button>
                            <a href="{{ route('job-orders.index') }}" class="job-cancel">Cancel</a>
                        </div>
                    </section>
                </div>

                <aside class="job-card job-preview">
                    <div class="job-preview-head">
                        <h2 class="job-title">Challan Summary</h2>
                        <p class="job-copy">Live totals before issuing bullion.</p>
                    </div>

                    <div class="job-preview-body">
                        <div class="job-preview-row">
                            <span class="job-preview-label">Total Gross</span>
                            <span class="job-preview-value" x-text="totalGross.toFixed(3) + 'g'">0.000g</span>
                        </div>
                        <div class="job-preview-row">
                            <span class="job-preview-label">Total Fine</span>
                            <span class="job-preview-value text-amber-700" x-text="totalFine.toFixed(3) + 'g'">0.000g</span>
                        </div>
                        <div class="job-preview-row">
                            <span class="job-preview-label">Allowed Wastage</span>
                            <span class="job-preview-value" x-text="(parseFloat(allowedWastage) || 0).toFixed(2) + '%'">2.00%</span>
                        </div>
                        <div class="job-preview-row">
                            <span class="job-preview-label">Expected Return</span>
                            <span class="job-preview-value text-emerald-700" x-text="expectedReturn.toFixed(3) + 'g'">0.000g</span>
                        </div>
                        <div class="job-preview-row" x-show="metalSource === 'vault'" x-cloak>
                            <span class="job-preview-label">Issuance Lines</span>
                            <span class="job-preview-value" x-text="lines.length">1</span>
                        </div>
                        <div class="job-preview-row">
                            <span class="job-preview-label">Metal Source</span>
                            <span class="job-preview-value" x-text="{
                                vault: 'Shop vault',
                                karigar_held: \"Karigar's balance\",
                                customer_advance: \"Customer's gold\",
                                none: 'Labour only',
                            }[metalSource]">Shop vault</span>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>

    <script>
        function jobOrderForm() {
            return {
                karigarId: '',
                karigarOpen: false,
                karigarName: '',
                metalTypeOpen: false,
                metalType: 'gold',
                purity: 22,
                advanceModeOpen: false,
                advanceMode: 'cash',
                advanceModeName: 'Cash',
                paymentMethodOpen: false,
                paymentMethodId: '',
                paymentMethodName: '',
                allowedWastage: 2,
                metalSource: 'vault',
                customerOpen: false,
                customerId: '',
                customerName: '',
                heldFine: '',
                custFine: '',
                lines: [{ metal_lot_id: '', lotName: '', lotOpen: false, gross_weight: '', fine_weight: '', purity: 22, lotAvailable: 0 }],
                get totalGross() {
                    if (this.metalSource !== 'vault') { return 0; }
                    return this.lines.reduce((s, l) => s + (parseFloat(l.gross_weight) || 0), 0);
                },
                get totalFine() {
                    if (this.metalSource === 'none') { return 0; }
                    if (this.metalSource === 'karigar_held') { return parseFloat(this.heldFine) || 0; }
                    if (this.metalSource === 'customer_advance') { return parseFloat(this.custFine) || 0; }
                    return this.lines.reduce((s, l) => s + (parseFloat(l.fine_weight) || 0), 0);
                },
                get expectedReturn() { return this.totalFine * (1 - (parseFloat(this.allowedWastage) || 0) / 100); },
                addLine() { this.lines.push({ metal_lot_id: '', lotName: '', lotOpen: false, gross_weight: '', fine_weight: '', purity: this.purity, lotAvailable: 0 }); },
                removeLine(i) { this.lines.splice(i, 1); },
                closeDropdowns() {
                    this.karigarOpen = false;
                    this.metalTypeOpen = false;
                    this.advanceModeOpen = false;
                    this.paymentMethodOpen = false;
                    this.customerOpen = false;
                    this.lines.forEach((line) => line.lotOpen = false);
                },
                onKarigarSelect(id, label, wastage) {
                    this.karigarId = id;
                    this.karigarName = id ? label : '';
                    this.allowedWastage = parseFloat(wastage) || 2;
                    this.karigarOpen = false;
                },
                setSource(source) {
                    this.metalSource = source;
                },
                onCustomerSelect(id, label) {
                    this.customerId = id;
                    this.customerName = id ? label : '';
                    this.customerOpen = false;
                },
                onMetalTypeSelect(type) {
                    this.metalType = type;
                    this.metalTypeOpen = false;
                    this.lines.forEach((line, idx) => this.recomputeGross(idx));
                },
                onLotSelect(idx, id, label, purity, available) {
                    this.lines[idx].metal_lot_id = id;
                    this.lines[idx].lotName = id ? label : '';
                    this.lines[idx].lotOpen = false;
                    this.lines[idx].lotAvailable = parseFloat(available) || 0;
                    if (id && purity) {
                        this.lines[idx].purity = parseFloat(purity);
                        if (this.lines.length === 1) {
                            this.purity = parseFloat(purity);
                        }
                        this.recomputeGross(idx);
                    }
                },
                onAdvanceModeSelect(mode, label) {
                    this.advanceMode = mode;
                    this.advanceModeName = label;
                    this.advanceModeOpen = false;
                },
                onPaymentMethodSelect(id, label) {
                    this.paymentMethodId = id;
                    this.paymentMethodName = label;
                    this.paymentMethodOpen = false;
                },
                recomputeGross(idx) {
                    const f = parseFloat(this.lines[idx].fine_weight) || 0;
                    const p = parseFloat(this.lines[idx].purity) || 0;
                    if (p === 0) { this.lines[idx].gross_weight = ''; return; }
                    const gross = this.metalType === 'silver' ? f * 1000 / p : f * 24 / p;
                    this.lines[idx].gross_weight = gross.toFixed(3);
                },
            };
        }
    </script>
</x-app-layout>
