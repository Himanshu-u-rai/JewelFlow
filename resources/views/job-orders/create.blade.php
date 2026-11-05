<x-app-layout>

    @php
        $selectedKarigar = $karigars->firstWhere('id', (int) request('karigar'));
    @endphp

    <x-page-header title="Issue Bullion to Karigar" class="job-create-header">
        <x-slot:actions>
            <a href="{{ route('job-orders.index') }}" class="btn btn-secondary btn-sm job-back" aria-label="Back to Job Orders">
                <x-icon name="arrow-left" size="16" /><span class="job-back-label">Job Orders</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner job-create-shell">

        <form method="POST" action="{{ route('job-orders.store') }}" x-data="jobOrderForm()" @keydown.escape.window="closeDropdowns()" @turbo:before-cache.window="closeDropdowns()"
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

                        <div class="job-field-grid job-schedule-grid">
                            <div class="job-combobox job-schedule-karigar" @click.outside="karigarOpen = false">
                                <span class="job-label" id="job-karigar-label">Karigar *</span>
                                <input type="hidden" name="karigar_id" x-model="karigarId">
                                <button type="button" class="job-combobox-trigger" aria-labelledby="job-karigar-label job-karigar-value" @click="karigarOpen = ! karigarOpen" :aria-expanded="karigarOpen.toString()">
                                    <span id="job-karigar-value" :class="karigarName ? '' : 'job-combobox-placeholder'" x-text="karigarName || 'Select karigar...'">Select karigar...</span>
                                    <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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

                            <label class="job-schedule-wastage">
                                <span class="job-label">Allowed Wastage % *</span>
                                <input type="number" step="0.01" name="allowed_wastage_percent" required min="0" max="25" class="job-control" x-model="allowedWastage">
                            </label>

                            <label class="job-schedule-date">
                                <span class="job-label">Issue Date *</span>
                                <input type="date" name="issue_date" required value="{{ now()->toDateString() }}" class="job-control">
                            </label>

                            <label class="job-schedule-date">
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

                        <div class="job-field-grid job-profile-grid">
                            <div class="job-combobox" @click.outside="metalTypeOpen = false">
                                <span class="job-label" id="job-metal-label">Metal Type *</span>
                                <input type="hidden" name="metal_type" x-model="metalType">
                                <button type="button" class="job-combobox-trigger" aria-labelledby="job-metal-label job-metal-value" @click="metalTypeOpen = ! metalTypeOpen" :aria-expanded="metalTypeOpen.toString()">
                                    <span id="job-metal-value" x-text="metalType === 'silver' ? 'Silver' : 'Gold'">Gold</span>
                                    <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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
                                <textarea name="notes" rows="2" class="job-control"></textarea>
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

                        <div class="job-source-options" role="group" aria-label="Metal source">
                            <button type="button" @click="setSource('vault')"
                                    class="job-source-option" :aria-pressed="(metalSource === 'vault').toString()">
                                <strong>Shop vault gold</strong>
                                <span>Give gold from your own stock (a lot in the vault).</span>
                            </button>
                            <button type="button" @click="setSource('karigar_held')"
                                    class="job-source-option" :aria-pressed="(metalSource === 'karigar_held').toString()">
                                <strong>Karigar's own balance</strong>
                                <span>Karigar uses gold they already hold from earlier work.</span>
                            </button>
                            <button type="button" @click="setSource('customer_advance')"
                                    class="job-source-option" :aria-pressed="(metalSource === 'customer_advance').toString()">
                                <strong>Customer's own gold</strong>
                                <span>Customer gave gold for this job (uses their deposit).</span>
                            </button>
                            <button type="button" @click="setSource('none')"
                                    class="job-source-option" :aria-pressed="(metalSource === 'none').toString()">
                                <strong>Only labour (no metal)</strong>
                                <span>No gold is given. Karigar is paid for work only.</span>
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
                                <x-icon name="plus" size="14" stroke-width="2.4" />
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
                                            <button type="button" class="job-combobox-trigger" :aria-label="'Lot for line ' + (idx + 1) + ': ' + (line.lotName || 'Select lot')" @click="open = !open" :aria-expanded="open.toString()">
                                                <span :class="line.lotName ? '' : 'job-combobox-placeholder'" x-text="line.lotName || 'Select lot...'">Select lot...</span>
                                                <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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
                                            <span class="job-label">Gross (g) · challan</span>
                                            <input type="hidden" :name="'issuances[' + idx + '][gross_weight]'" :disabled="metalSource !== 'vault'" :value="line.gross_weight">
                                            <div class="job-gross-value" x-text="(parseFloat(line.gross_weight) || 0).toFixed(3) + ' g'">0.000 g</div>
                                        </div>

                                        <label>
                                            <span class="job-label">Purity</span>
                                            <input type="number" step="0.01" min="1" :max="metalType === 'silver' ? 1000 : 24" :name="'issuances[' + idx + '][purity]'" :required="metalSource === 'vault'" :disabled="metalSource !== 'vault'" class="job-control" x-model="line.purity" @input="recomputeGross(idx)">
                                        </label>

                                        <button type="button" @click="removeLine(idx)" x-show="lines.length > 1" class="job-remove-line" :aria-label="'Remove lot line ' + (idx + 1)"><x-icon name="trash-2" size="16" /></button>
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
                                <p class="job-copy">Use gold the karigar already holds from earlier work. Tap a balance below, then enter how much of it to use.</p>
                            </div>
                        </div>

                        {{-- Available held balances as tappable chips. --}}
                        <div class="job-held-panel">
                            <template x-if="heldBuckets.length > 0">
                                <div>
                                    <p class="job-held-title">Karigar's gold available</p>
                                    <div class="job-held-chips">
                                        <template x-for="(b, i) in heldBuckets" :key="i">
                                            <button type="button" class="job-held-chip"
                                                    :class="(parseFloat(purity) === b.purity) ? 'job-held-chip--on' : ''"
                                                    @click="pickHeldBucket(b)"
                                                    x-text="b.label"></button>
                                        </template>
                                    </div>
                                    <p class="job-held-remain"
                                       :class="heldRemaining < -0.0005 ? 'job-held-remain--over' : ''"
                                       x-show="parseFloat(heldFine) > 0"
                                       x-cloak>
                                        <template x-if="heldRemaining >= -0.0005">
                                            <span>Using <strong x-text="(parseFloat(heldFine) || 0).toFixed(3)"></strong>g — <strong x-text="heldRemaining.toFixed(3)"></strong>g will remain with the karigar.</span>
                                        </template>
                                        <template x-if="heldRemaining < -0.0005">
                                            <span>⚠ That is more than the karigar holds at this purity (<strong x-text="heldAtPurity.toFixed(3)"></strong>g available).</span>
                                        </template>
                                    </p>
                                </div>
                            </template>
                            <template x-if="karigarId && heldBuckets.length === 0">
                                <p class="job-held-empty">This karigar isn't holding any of your gold right now. Choose <strong>Shop vault gold</strong> instead, or pick another karigar.</p>
                            </template>
                            <template x-if="!karigarId">
                                <p class="job-held-empty">Select a karigar above to see the gold they are holding.</p>
                            </template>
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
                                <span class="job-label" id="job-customer-label">Customer *</span>
                                <input type="hidden" name="sources[0][customer_id]" x-model="customerId" :disabled="metalSource !== 'customer_advance'">
                                <button type="button" class="job-combobox-trigger" aria-labelledby="job-customer-label job-customer-value" @click="customerOpen = ! customerOpen" :aria-expanded="customerOpen.toString()">
                                    <span id="job-customer-value" :class="customerName ? '' : 'job-combobox-placeholder'" x-text="customerName || 'Select customer...'">Select customer...</span>
                                    <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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
                                            @if($c->mobile)<span class="job-combobox-meta">{{ \App\Support\Mobile::forDisplay($c->mobile) }}</span>@endif
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
                                    <span class="job-label" id="job-mode-label">Payment Mode</span>
                                    <input type="hidden" name="advance_mode" x-model="advanceMode">
                                    <button type="button" class="job-combobox-trigger bg-white" aria-labelledby="job-mode-label job-mode-value" @click="advanceModeOpen = ! advanceModeOpen" :aria-expanded="advanceModeOpen.toString()">
                                        <span id="job-mode-value" x-text="advanceModeName">Cash</span>
                                        <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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
                                    <span class="job-label" id="job-account-label">Account</span>
                                    <input type="hidden" name="advance_payment_method_id" x-model="paymentMethodId">
                                    <button type="button" class="job-combobox-trigger bg-white" aria-labelledby="job-account-label job-account-value" @click="paymentMethodOpen = ! paymentMethodOpen" :aria-expanded="paymentMethodOpen.toString()">
                                        <span id="job-account-value" :class="paymentMethodName ? '' : 'job-combobox-placeholder'" x-text="paymentMethodName || 'Select account'">Select account</span>
                                        <x-icon name="chevron-down" size="16" stroke-width="2.25" />
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

                </div>

                <aside class="job-card job-preview jf-scrollbar" tabindex="0" aria-labelledby="job-summary-title">
                    <div class="job-preview-head">
                        <h2 class="job-title" id="job-summary-title">Challan Summary</h2>
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
                            <span class="job-preview-value" x-text="metalSourceLabel">Shop vault</span>
                        </div>
                    </div>
                </aside>
                <div class="job-actions">
                    <button type="submit" class="btn btn-primary job-submit">
                        <x-icon name="square-check-big" size="16" />
                        Issue & Print Challan
                    </button>
                    <a href="{{ route('job-orders.index') }}" class="btn btn-secondary job-cancel">Cancel</a>
                </div>
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
                // Per-karigar reusable held balances, from the server. Shape:
                // { "<karigarId>": [{ metal_type, purity, fine }], ... }
                heldMap: @js($karigarHeld),
                heldBuckets: [],
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
                get metalSourceLabel() {
                    return {
                        vault: 'Shop vault',
                        karigar_held: 'Karigar balance',
                        customer_advance: 'Customer gold',
                        none: 'Labour only',
                    }[this.metalSource] || 'Shop vault';
                },
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
                    this.loadHeldBuckets();
                },
                loadHeldBuckets() {
                    const raw = (this.karigarId && this.heldMap[this.karigarId]) ? this.heldMap[this.karigarId] : [];
                    this.heldBuckets = raw.map((b) => {
                        const purity = parseFloat(b.purity);
                        const fine = parseFloat(b.fine);
                        const unit = b.metal_type === 'silver' ? '‰' : 'K';
                        const p = (purity % 1 === 0) ? purity.toString() : purity.toString();
                        return { purity, fine, metal_type: b.metal_type, label: p + unit + ' · ' + fine.toFixed(3) + 'g' };
                    });
                },
                pickHeldBucket(b) {
                    this.purity = b.purity;
                    this.metalType = b.metal_type;
                },
                // How much the selected karigar holds at the purity currently typed.
                get heldAtPurity() {
                    const p = parseFloat(this.purity);
                    const match = this.heldBuckets.find((b) => Math.abs(b.purity - p) < 0.005);
                    return match ? match.fine : 0;
                },
                get heldRemaining() {
                    return this.heldAtPurity - (parseFloat(this.heldFine) || 0);
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
