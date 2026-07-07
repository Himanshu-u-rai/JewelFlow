<x-dhiran-layout title="New Loan">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">New Pledge Loan</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new gold &amp; silver pledge loan</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Loans
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner"
         x-data="dhiranCreateForm()"
         x-effect="document.body.classList.toggle('dh-modal-open', newBorrowerOpen)"
         @turbo:before-cache.window="document.body.classList.remove('dh-modal-open')">

        <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
            @csrf

            {{-- Customer Section --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Customer Details
                </div>

                <div class="dh-customer-stack">
                    <div class="dh-customer-search-row" @click.outside="customerDropdownOpen = false">
                        <div class="dh-customer-search-box">
                            <label class="dh-label">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Customer *
                            </label>
                            <input type="hidden" name="customer_id" x-model="customer_id">
                            <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                    <input type="search"
                                           class="dh-customer-search-input"
                                           placeholder="Search borrower by name, mobile, PAN, or ID"
                                           x-model="customerSearch"
                                           @focus="customerDropdownOpen = true"
                                           @input="handleCustomerSearchInput()">
                                </div>
                                <div class="dh-dd-panel dh-customer-results-panel" :class="{ open: customerDropdownOpen }">
                                    <div class="dh-dd-list">
                                        <template x-for="c in filteredCustomers" :key="c.id">
                                            <button type="button" class="dh-dd-opt" @click="selectCustomer(c)">
                                                <span class="dh-dd-opt-name" x-text="c.name"></span>
                                                <span class="dh-dd-opt-sub" x-text="[c.mobile, c.pan, c.address].filter(Boolean).join(' · ') || 'No profile details added'"></span>
                                            </button>
                                        </template>
                                        <div x-show="filteredCustomers.length === 0" class="dh-dd-opt dh-dd-opt-muted">
                                            No borrowers found.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @error('customer_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <span>Add New Borrower</span>
                        </button>
                    </div>

                    <div class="dh-customer-details-layout">
                    <div class="dh-selected-customer-card" x-show="selectedCustomer" x-cloak>
                        <div class="dh-selected-customer-head">
                            <div class="dh-selected-customer-avatar" x-text="selectedCustomerInitials"></div>
                            <div>
                                <p class="dh-selected-customer-name" x-text="selectedCustomer && selectedCustomer.name ? selectedCustomer.name : 'Borrower'"></p>
                                <p class="dh-selected-customer-sub">Selected borrower profile</p>
                            </div>
                        </div>

                        <dl class="dh-selected-customer-grid">
                            <div>
                                <dt>Mobile</dt>
                                <dd x-text="selectedCustomer && selectedCustomer.mobile ? selectedCustomer.mobile : 'Not added'"></dd>
                            </div>
                            <div>
                                <dt>PAN</dt>
                                <dd x-text="selectedCustomer && selectedCustomer.pan ? selectedCustomer.pan : 'Not added'"></dd>
                            </div>
                            <div>
                                <dt>ID number</dt>
                                <dd x-text="selectedCustomer && selectedCustomer.id_number ? selectedCustomer.id_number : 'Not added'"></dd>
                            </div>
                            <div>
                                <dt>State code</dt>
                                <dd x-text="selectedCustomer && selectedCustomer.state_code ? selectedCustomer.state_code : 'Not added'"></dd>
                            </div>
                            <div class="dh-selected-customer-address">
                                <dt>Address</dt>
                                <dd x-text="selectedCustomer && selectedCustomer.address ? selectedCustomer.address : 'Not added'"></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="dh-selected-customer-empty" x-show="!selectedCustomer" x-cloak>
                        <p>Select an existing borrower or add a new borrower to see their profile details here.</p>
                    </div>
                    </div>
                </div>

                <input type="hidden" name="pan" x-model="loanPan">
                <input type="hidden" name="aadhaar" x-model="loanAadhaar">
                @error('pan')
                    <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('aadhaar')
                    <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="dh-borrower-modal"
                 x-show="newBorrowerOpen"
                 x-cloak
                 x-transition.opacity
                 @keydown.escape.window="newBorrowerOpen = false"
                 @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
                 @click.self="newBorrowerOpen = false"
                 @wheel.self.prevent
                 @touchmove.self.prevent
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="dh-borrower-modal-title">
                <div class="dh-borrower-modal-card" @click.stop>
                    <div class="dh-borrower-modal-head">
                        <div>
                            <h2 id="dh-borrower-modal-title">Add New Borrower</h2>
                            <p>Create a borrower profile and select it for this pledge loan.</p>
                        </div>
                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <div class="dh-borrower-modal-body">
                        <div class="dh-borrower-grid">
                            <label class="dh-borrower-modal-field">
                                <span>First name *</span>
                                <input type="text" class="dh-borrower-input" placeholder="First name" x-model="nb.first_name">
                            </label>
                            <label class="dh-borrower-modal-field">
                                <span>Last name</span>
                                <input type="text" class="dh-borrower-input" placeholder="Last name" x-model="nb.last_name">
                            </label>
                            <label class="dh-borrower-modal-field">
                                <span>Mobile number *</span>
                                <input type="tel" class="dh-borrower-input" placeholder="10-digit mobile number" maxlength="10" x-model="nb.mobile">
                            </label>
                            <label class="dh-borrower-modal-field">
                                <span>PAN</span>
                                <input type="text" class="dh-borrower-input" placeholder="ABCDE1234F" maxlength="10" x-model="nb.pan" @input="nb.pan = nb.pan.toUpperCase()">
                            </label>
                            <label class="dh-borrower-modal-field">
                                <span>ID number</span>
                                <input type="text" class="dh-borrower-input" placeholder="ID number" x-model="nb.id_number">
                            </label>
                            <label class="dh-borrower-modal-field">
                                <span>State code</span>
                                <input type="text" class="dh-borrower-input" placeholder="State code" x-model="nb.state_code">
                            </label>
                            <label class="dh-borrower-modal-field dh-borrower-input--wide">
                                <span>Address</span>
                                <textarea class="dh-borrower-input dh-borrower-modal-address" placeholder="Borrower address" rows="3" x-model="nb.address"></textarea>
                            </label>
                        </div>
                        <p class="dh-borrower-error" x-show="nbError" x-text="nbError"></p>
                    </div>

                    <div class="dh-borrower-modal-foot">
                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                        <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                            <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Pledged Items --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    Pledged Items
                </div>

                <template x-for="(item, index) in items" :key="index">
                    <div class="dh-item-row">
                        <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                            <div class="col-span-2">
                                <label class="dh-label">Description *</label>
                                <input type="text" :name="'items['+index+'][description]'" x-model="item.description"
                                       placeholder="e.g. Gold chain, silver anklet" class="dh-input" required>
                            </div>
                            <div x-show="item.metal_type !== 'other'">
                                <label class="dh-label">Gross Weight (g) <span x-show="item.value_mode !== 'appraised'">*</span></label>
                                <input type="number" step="0.001" :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight"
                                       placeholder="0.000" class="dh-input" :required="item.metal_type !== 'other' && item.value_mode !== 'appraised'" @input="recalcItem(index)">
                            </div>
                            <div x-show="item.metal_type !== 'other'">
                                <label class="dh-label">Stone Weight (g)</label>
                                <input type="number" step="0.001" :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight"
                                       placeholder="0.000" class="dh-input" @input="recalcItem(index)">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                            <div>
                                <label class="dh-label">Type *</label>
                                <select :name="'items['+index+'][metal_type]'" x-model="item.metal_type" class="dh-select" required @change="onMetalChange(index)">
                                    <option value="gold">Gold</option>
                                    <option value="silver">Silver</option>
                                    <option value="other">Other (diamond, platinum, watch…)</option>
                                </select>
                            </div>
                            {{-- Valuation: metal-melt (auto from purity × rate) OR appraised
                                 (operator enters value). 'Other' is always appraised. --}}
                            <div x-show="item.metal_type !== 'other'">
                                <label class="dh-label">Valuation</label>
                                <select :name="'items['+index+'][value_mode]'" x-model="item.value_mode" class="dh-select" @change="recalcItem(index)">
                                    <option value="metal">Auto (metal value)</option>
                                    <option value="appraised">Appraised (enter value)</option>
                                </select>
                            </div>

                            {{-- Metal-mode fields (gold/silver, auto valuation) --}}
                            <template x-if="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                                <div>
                                    <label class="dh-label">Purity *</label>
                                    <select :name="'items['+index+'][purity]'" x-model.number="item.purity" class="dh-select" required @change="recalcItem(index)">
                                        <option value="">Select</option>
                                        <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
                                            <option :value="opt.value" x-text="opt.label"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                            <template x-if="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                                <div>
                                    <label class="dh-label">Rate/gram at Pledge</label>
                                    <div class="dh-cost-wrap">
                                        <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                                        <input type="number" step="0.01" :name="'items['+index+'][rate_per_gram_at_pledge]'" x-model.number="item.rate_per_gram_at_pledge"
                                               placeholder="0.00" class="dh-input" @input="recalcItem(index)">
                                    </div>
                                </div>
                            </template>

                            <div>
                                <label class="dh-label">HUID (optional)</label>
                                <input type="text" :name="'items['+index+'][huid]'" x-model="item.huid"
                                       placeholder="HUID" class="dh-input" maxlength="12">
                            </div>
                        </div>

                        {{-- Weights row: only for metal-valued items. --}}
                        <div class="grid grid-cols-3 gap-3 mb-3" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'">
                            <div>
                                <label class="dh-label">Net Metal Wt (g)</label>
                                <input type="text" readonly class="dh-input dh-calc-field" :value="item.net_metal_weight.toFixed(3)">
                            </div>
                            <div>
                                <label class="dh-label">Fine Weight (g)</label>
                                <input type="text" readonly class="dh-input dh-calc-field" :value="item.fine_weight.toFixed(3)">
                            </div>
                            <div></div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            {{-- Market value: read-only (computed) for metal mode, editable for appraised/other. --}}
                            <div>
                                <label class="dh-label" x-text="(item.metal_type === 'other' || item.value_mode === 'appraised') ? 'Appraised Value *' : 'Market Value'"></label>
                                <div class="dh-cost-wrap" x-show="item.metal_type === 'other' || item.value_mode === 'appraised'">
                                    <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                                    <input type="number" step="0.01" min="0" :name="'items['+index+'][market_value]'"
                                           x-model.number="item.market_value" placeholder="0.00" class="dh-input" @input="recalcItem(index)">
                                </div>
                                <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                            </div>
                            <div>
                                <label class="dh-label">Loan Value</label>
                                <input type="text" readonly class="dh-input dh-calc-field"
                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                            </div>
                            <div></div>
                        </div>

                        {{-- Hidden carriers so server always gets net/fine even when the
                             weight row is hidden (0 for appraised/other). --}}
                        <input type="hidden" :name="'items['+index+'][net_metal_weight]'" :value="item.net_metal_weight">
                        <input type="hidden" :name="'items['+index+'][fine_weight]'" :value="item.fine_weight">
                    </div>
                </template>

                <button type="button" class="dh-add-item-btn" @click="addItem()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Add Item
                </button>
            </div>

            {{-- Loan Parameters --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    Loan Parameters
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label for="principal_amount" class="dh-label">Principal Amount *</label>
                        <div class="dh-cost-wrap">
                            <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                            <input type="number" step="0.01" name="principal_amount" id="principal_amount"
                                   x-model.number="principal_amount"
                                   value="{{ old('principal_amount') }}"
                                   placeholder="0.00" class="dh-input" required>
                        </div>
                        @error('principal_amount')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="gold_rate_on_date" class="dh-label">Gold rate today (per gram)</label>
                        <div class="dh-cost-wrap">
                            <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                            <input type="number" step="0.01" name="gold_rate_on_date" id="gold_rate_on_date"
                                   x-model.number="goldRateOnDate"
                                   value="{{ old('gold_rate_on_date') }}"
                                   placeholder="Reference rate" class="dh-input">
                        </div>
                        @error('gold_rate_on_date')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="interest_rate_monthly" class="dh-label">Monthly Interest Rate (%) *</label>
                        <input type="number" step="0.01" name="interest_rate_monthly" id="interest_rate_monthly"
                               x-model.number="interest_rate_monthly"
                               value="{{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? '') }}"
                               placeholder="e.g. 1.5" class="dh-input" required>
                        @error('interest_rate_monthly')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="interest_type" class="dh-label">Interest Type *</label>
                        <select name="interest_type" id="interest_type" x-model="interest_type" class="dh-select" required>
                            <option value="flat">Flat (Simple)</option>
                            <option value="daily">Daily</option>
                            <option value="compound">Compound</option>
                        </select>
                        @error('interest_type')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="tenure_months" class="dh-label">Tenure (Months) *</label>
                        <input type="number" name="tenure_months" id="tenure_months"
                               x-model.number="tenure_months"
                               value="{{ old('tenure_months', $defaults['tenure_months'] ?? 6) }}"
                               placeholder="6" class="dh-input" required min="1">
                        @error('tenure_months')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="penalty_rate_monthly" class="dh-label">Penalty Rate Monthly (%)</label>
                        <input type="number" step="0.01" name="penalty_rate_monthly" id="penalty_rate_monthly"
                               x-model.number="penalty_rate_monthly"
                               value="{{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? '') }}"
                               placeholder="e.g. 2.0" class="dh-input">
                        @error('penalty_rate_monthly')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="dh-label">Processing Fee</label>
                        <input type="text" readonly class="dh-input dh-calc-field"
                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                        <input type="hidden" name="processing_fee" :value="processingFee">
                    </div>
                </div>
            </div>

            {{-- Summary --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Loan Summary
                </div>
                <div class="dh-summary-row">
                    <span class="dh-summary-label">Total Market Value</span>
                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                </div>
                <div class="dh-summary-row">
                    <span class="dh-summary-label">Total Loan Value (from items)</span>
                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                </div>
                <div class="dh-summary-row border-t border-slate-200 pt-2">
                    <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                </div>
                <div class="dh-summary-row">
                    <span class="dh-summary-label">Monthly Interest (approx.)</span>
                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                </div>
                <div class="dh-summary-row">
                    <span class="dh-summary-label">Processing Fee</span>
                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                </div>
            </div>

            {{-- Notes --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <label for="notes" class="dh-label">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Notes (optional)
                </label>
                <textarea name="notes" id="notes" rows="3" placeholder="Any additional notes about this loan..." class="dh-textarea">{{ old('notes') }}</textarea>
            </div>

            {{-- Submit --}}
            <div class="flex justify-end gap-3 pb-6">
                <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Cancel
                </a>
                <button type="submit" class="btn btn-dark btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Create Loan
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('dhiranCreateForm', () => ({
                customer_id: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
                customerDisplayName: '',
                customerDropdownOpen: false,
                customerSearch: '',
                customers: {{ Illuminate\Support\Js::from(($customers ?? collect())->map(fn ($c) => [
                    'id'     => $c->id,
                    'name'   => trim($c->first_name . ' ' . ($c->last_name ?? '')),
                    'mobile' => $c->mobile ?? '',
                    'address' => $c->address ?? '',
                    'pan'    => $c->pan ?? '',
                    'id_number' => $c->id_number ?? '',
                    'state_code' => $c->state_code ?? '',
                ])->values()) }},
                selectedCustomer: null,
                newBorrowerOpen: false,
                nbSaving: false,
                nbError: '',
                nb: { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' },
                loanPan: {{ Illuminate\Support\Js::from(old('pan', '')) }},
                loanAadhaar: {{ Illuminate\Support\Js::from(old('aadhaar', '')) }},
                goldRateOnDate: {{ old('gold_rate_on_date', 0) }},
                principal_amount: {{ old('principal_amount', 0) }},
                interest_rate_monthly: {{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? 1.5) }},
                interest_type: '{{ old('interest_type', $defaults['interest_type'] ?? 'flat') }}',
                tenure_months: {{ old('tenure_months', $defaults['tenure_months'] ?? 6) }},
                penalty_rate_monthly: {{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? 2) }},
                processingFeePercent: {{ $defaults['processing_fee_percent'] ?? 0 }},
                ltvPercent: {{ $defaults['ltv_percent'] ?? 75 }},

                items: [{
                    description: '', metal_type: 'gold', value_mode: 'metal', gross_weight: 0, stone_weight: 0, purity: 22,
                    rate_per_gram_at_pledge: 0, huid: '',
                    net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0
                }],

                get totalMarketValue() {
                    return this.items.reduce((sum, i) => sum + (i.market_value || 0), 0);
                },
                get totalLoanValue() {
                    return this.items.reduce((sum, i) => sum + (i.loan_value || 0), 0);
                },
                get monthlyInterest() {
                    return (this.principal_amount || 0) * (this.interest_rate_monthly || 0) / 100;
                },
                get processingFee() {
                    return (this.principal_amount || 0) * (this.processingFeePercent || 0) / 100;
                },

                init() {
                    // If a borrower was preselected (?customer_id= from the borrower
                    // profile/list), show their name in the picker on load.
                    if (this.customer_id) {
                        const c = this.customers.find(x => String(x.id) === String(this.customer_id));
                        if (c) this.selectCustomer(c, true);
                    }
                },

                get selectedCustomerInitials() {
                    const name = (this.selectedCustomer && this.selectedCustomer.name ? this.selectedCustomer.name : '').trim();
                    if (!name) return 'B';
                    return name.split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
                },

                get filteredCustomers() {
                    const q = (this.customerSearch || '').toLowerCase();
                    if (!q) return this.customers;
                    return this.customers.filter(c =>
                        [c.name, c.mobile, c.address, c.pan, c.id_number].join(' ').toLowerCase().includes(q));
                },

                handleCustomerSearchInput() {
                    this.customerDropdownOpen = true;
                    if (this.selectedCustomer && this.customerSearch.trim() !== (this.selectedCustomer.name || '')) {
                        this.selectedCustomer = null;
                        this.customer_id = '';
                        this.customerDisplayName = '';
                        this.loanPan = '';
                        this.loanAadhaar = '';
                    }
                },

                openNewBorrower() {
                    this.customerDropdownOpen = false;
                    this.newBorrowerOpen = true;
                },

                aadhaarCandidate(customer) {
                    const id = (customer && customer.id_number ? customer.id_number : '').trim();
                    return /^[0-9Xx\- ]{4,20}$/.test(id) ? id : '';
                },

                selectCustomer(customer, keepExistingKyc = false) {
                    this.selectedCustomer = customer;
                    this.customer_id = customer.id;
                    this.customerDisplayName = customer.name + (customer.mobile ? ' (' + customer.mobile + ')' : '');
                    this.customerSearch = customer.name || '';
                    if (!keepExistingKyc || !this.loanPan) this.loanPan = customer.pan || '';
                    if (!keepExistingKyc || !this.loanAadhaar) this.loanAadhaar = this.aadhaarCandidate(customer);
                    this.customerDropdownOpen = false;
                },

                async saveBorrower() {
                    this.nbError = '';
                    if (!this.nb.first_name.trim()) { this.nbError = 'First name is required.'; return; }
                    if (!/^[0-9]{10}$/.test(this.nb.mobile.trim())) { this.nbError = 'Enter a valid 10-digit mobile number.'; return; }
                    this.nbSaving = true;
                    try {
                        const res = await fetch('{{ route('dhiran.customers.store') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify(this.nb),
                        });
                        const out = await res.json().catch(() => ({}));
                        if (!res.ok || !out.ok) {
                            this.nbError = (out.errors ? Object.values(out.errors)[0][0] : out.message) || 'Could not save borrower.';
                            return;
                        }
                        const savedCustomer = {
                            id: out.customer.id,
                            name: out.customer.name,
                            mobile: out.customer.mobile || '',
                            address: out.customer.address || '',
                            pan: out.customer.pan || '',
                            id_number: out.customer.id_number || '',
                            state_code: out.customer.state_code || '',
                        };

                        // Add to the list if new; update it if the mobile matched an existing borrower.
                        const existingIndex = this.customers.findIndex(c => c.id === savedCustomer.id);
                        if (existingIndex === -1) {
                            this.customers.unshift(savedCustomer);
                        } else {
                            this.customers.splice(existingIndex, 1, { ...this.customers[existingIndex], ...savedCustomer });
                        }
                        this.selectCustomer(savedCustomer);
                        this.newBorrowerOpen = false;
                        this.nb = { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' };
                    } catch (e) {
                        this.nbError = 'Network error. Please try again.';
                    } finally {
                        this.nbSaving = false;
                    }
                },

                addItem() {
                    this.items.push({
                        description: '', metal_type: 'gold', value_mode: 'metal', gross_weight: 0, stone_weight: 0, purity: 22,
                        rate_per_gram_at_pledge: 0, huid: '',
                        net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0
                    });
                },

                // Supported purities per metal (MetalRegistry: gold = karat/24,
                // silver = millesimal/1000). Display-list only; the backend is
                // authoritative.
                purityOptions(metal) {
                    if (metal === 'silver') {
                        return [
                            { value: 999, label: '999' },
                            { value: 958, label: '958' },
                            { value: 925, label: '925' },
                            { value: 900, label: '900' },
                            { value: 800, label: '800' },
                        ];
                    }
                    return [
                        { value: 24, label: '24K' },
                        { value: 22, label: '22K' },
                        { value: 20, label: '20K' },
                        { value: 18, label: '18K' },
                        { value: 14, label: '14K' },
                    ];
                },

                // When metal changes, reset a now-invalid purity and recompute.
                onMetalChange(index) {
                    const item = this.items[index];
                    if (item.metal_type === 'other') {
                        // Other = always appraised; clear metal-only fields.
                        item.value_mode = 'appraised';
                        item.purity = '';
                    } else {
                        const valid = this.purityOptions(item.metal_type).some(o => o.value === Number(item.purity));
                        if (!valid) item.purity = '';
                    }
                    this.recalcItem(index);
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        this.items.splice(index, 1);
                    }
                },

                recalcItem(index) {
                    let item = this.items[index];
                    const appraised = item.metal_type === 'other' || item.value_mode === 'appraised';
                    if (appraised) {
                        // Operator-entered market value is authoritative; no metal math.
                        item.net_metal_weight = 0;
                        item.fine_weight = 0;
                        item.market_value = Number(item.market_value) || 0;
                    } else {
                        item.net_metal_weight = Math.max(0, (item.gross_weight || 0) - (item.stone_weight || 0));
                        // Metal-aware fine-weight scale: gold = purity/24 (karat),
                        // silver = purity/1000 (millesimal). Mirrors MetalRegistry.
                        const divisor = item.metal_type === 'silver' ? 1000 : 24;
                        item.fine_weight = item.net_metal_weight * (item.purity || 0) / divisor;
                        let rate = item.rate_per_gram_at_pledge || this.goldRateOnDate || 0;
                        item.market_value = item.fine_weight * rate;
                    }
                    item.loan_value = item.market_value * (this.ltvPercent / 100);
                },

                prepareSubmit() {
                    // Recalculate all items before submit
                    this.items.forEach((_, i) => this.recalcItem(i));
                }
            }));
        });
    </script>
    @endpush
</x-dhiran-layout>
