<x-app-layout>
    <style>
        :root {
            --dh-ink: #0f172a;
            --dh-ink-soft: #334155;
            --dh-muted: #64748b;
            --dh-border: #e2e8f0;
            --dh-bg: #f8fafc;
            --dh-accent: #d98b00;
        }
        .dh-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            color: var(--dh-ink-soft); margin-bottom: 6px;
        }
        .dh-label svg { width: 14px; height: 14px; color: var(--dh-muted); flex-shrink: 0; }
        .dh-input, .dh-select, .dh-textarea {
            width: 100%; padding: 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--dh-border); border-radius: 10px;
            background: var(--dh-bg); color: var(--dh-ink);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        .dh-input:focus, .dh-select:focus, .dh-textarea:focus {
            outline: none; border-color: var(--dh-accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(217, 139, 0, 0.12);
        }
        .dh-input::placeholder, .dh-textarea::placeholder { color: #9ca3af; font-weight: 400; }
        .dh-select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 36px; cursor: pointer;
        }
        .dh-textarea { resize: vertical; min-height: 72px; line-height: 1.5; }
        .dh-cost-wrap { position: relative; }
        .dh-cost-wrap .dh-cost-symbol {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            font-size: 13px; font-weight: 600; color: var(--dh-muted);
        }
        .dh-cost-wrap .dh-input { padding-left: 28px; }
        .dh-section-title {
            font-size: 14px; font-weight: 700; color: var(--dh-ink);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px; padding-bottom: 8px;
            border-bottom: 1px solid var(--dh-border);
        }
        .dh-section-title svg { width: 16px; height: 16px; color: var(--dh-accent); }
        .dh-item-row {
            background: #f8fafc;
            border: 1px solid var(--dh-border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
        }
        .dh-item-remove {
            position: absolute; top: 8px; right: 8px;
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #fecaca; border-radius: 8px;
            background: #fef2f2; color: #dc2626; cursor: pointer;
            transition: background 0.15s;
        }
        .dh-item-remove:hover { background: #fee2e2; }
        .dh-item-remove svg { width: 14px; height: 14px; }
        .dh-add-item-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; font-size: 12px; font-weight: 600;
            border: 1.5px dashed var(--dh-border); border-radius: 10px;
            background: transparent; color: var(--dh-accent); cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .dh-add-item-btn:hover { border-color: var(--dh-accent); background: #fffbeb; }
        .dh-add-item-btn svg { width: 14px; height: 14px; }
        .dh-calc-field {
            background: #fffbeb; border-color: #fde68a;
        }
        .dh-summary-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; font-size: 13px;
        }
        .dh-summary-label { color: var(--dh-muted); font-weight: 500; }
        .dh-summary-value { color: var(--dh-ink); font-weight: 700; }

        /* Customer dropdown */
        .dh-dd-wrap { position: relative; z-index: 2; }
        .dh-dd-wrap.dd-open { z-index: 34; }
        .dh-dd-trigger {
            width: 100%; padding: 10px 36px 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--dh-border); border-radius: 10px;
            background: var(--dh-bg); color: var(--dh-ink); cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
            text-align: left; position: relative;
        }
        .dh-dd-trigger:focus { outline: none; border-color: var(--dh-accent); background: #fff; box-shadow: 0 0 0 3px rgba(217,139,0,.12); }
        .dh-dd-trigger.open { border-color: var(--dh-accent); background: #fff; box-shadow: 0 0 0 3px rgba(217,139,0,.12); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .dh-dd-trigger::after {
            content: ''; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: center; transition: transform .15s;
        }
        .dh-dd-trigger.open::after { transform: translateY(-50%) rotate(180deg); }
        .dh-dd-placeholder { color: #9ca3af; font-weight: 400; }
        .dh-dd-panel {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 18;
            background: #fff; border: 1.5px solid var(--dh-accent); border-top: none;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.1); max-height: 220px; overflow: hidden;
        }
        .dh-dd-panel.open { display: block; }
        .dh-dd-search {
            width: 100%; padding: 8px 12px 8px 34px; font-size: 12px; font-weight: 400;
            border: none; border-bottom: 1px solid #f1f5f9; background: #f8fafc;
            color: var(--dh-ink); outline: none;
        }
        .dh-dd-search::placeholder { color: #9ca3af; }
        .dh-dd-search-icon { position: absolute; left: 12px; top: 8px; color: var(--dh-muted); }
        .dh-dd-list { max-height: 170px; overflow-y: auto; }
        .dh-dd-opt {
            padding: 8px 12px; cursor: pointer; transition: background .1s;
            border-bottom: 1px solid #f8fafc;
        }
        .dh-dd-opt:last-child { border-bottom: none; }
        .dh-dd-opt:hover, .dh-dd-opt.active { background: #fffbeb; }
        .dh-dd-opt-name { font-size: 13px; font-weight: 600; color: var(--dh-ink); }
        .dh-dd-opt-sub { font-size: 11px; color: var(--dh-muted); margin-top: 1px; }
        .dh-dd-empty { padding: 14px; text-align: center; color: var(--dh-muted); font-size: 12px; }
    </style>

    <x-page-header>
        <div>
            <h1 class="page-title">New Gold Loan</h1>
            <p class="text-sm text-gray-500 mt-1">Create a new Dhiran / Girvi pledge loan</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Loans
            </a>
        </div>
    </x-page-header>

    <div class="content-inner" x-data="dhiranCreateForm()">
        <x-app-alerts class="mb-6" />

        <form method="POST" action="{{ route('dhiran.store') }}" class="max-w-4xl mx-auto space-y-6" @submit="prepareSubmit">
            @csrf

            {{-- Customer Section --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Customer Details
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="dh-label">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            Customer *
                        </label>
                        <input type="hidden" name="customer_id" x-model="customer_id">
                        <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                            <button type="button" class="dh-dd-trigger" :class="{ open: customerDropdownOpen }" @click="customerDropdownOpen = !customerDropdownOpen">
                                <span x-show="!customer_id" class="dh-dd-placeholder">Select Customer</span>
                                <span x-show="customer_id" x-text="customerDisplayName" style="font-weight:600;color:var(--dh-ink)"></span>
                            </button>
                            <div class="dh-dd-panel" :class="{ open: customerDropdownOpen }">
                                <div style="position:relative">
                                    <span class="dh-dd-search-icon"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                    <input type="text" class="dh-dd-search" placeholder="Search customer..." x-model="customerSearch" @click.stop>
                                </div>
                                <div class="dh-dd-list">
                                    @foreach($customers ?? [] as $c)
                                        <div class="dh-dd-opt"
                                             x-show="'{{ strtolower($c->name) }} {{ strtolower($c->mobile ?? '') }}'.includes(customerSearch.toLowerCase())"
                                             @click="selectCustomer({{ $c->id }}, '{{ addslashes($c->name) }}', '{{ $c->mobile ?? '' }}')">
                                            <div class="dh-dd-opt-name">{{ $c->name }}</div>
                                            <div class="dh-dd-opt-sub">{{ $c->mobile ?? '' }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        @error('customer_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="gold_rate_on_date" class="dh-label">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            Gold Rate on Date (per gram) *
                        </label>
                        <div class="dh-cost-wrap">
                            <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                            <input type="number" step="0.01" name="gold_rate_on_date" id="gold_rate_on_date"
                                   x-model.number="goldRateOnDate"
                                   value="{{ old('gold_rate_on_date') }}"
                                   placeholder="e.g. 7500.00" class="dh-input" required>
                        </div>
                        @error('gold_rate_on_date')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label for="aadhaar" class="dh-label">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                            Aadhaar Number
                        </label>
                        <input type="text" name="aadhaar" id="aadhaar"
                               value="{{ old('aadhaar') }}" placeholder="XXXX XXXX XXXX"
                               class="dh-input" maxlength="14">
                        @error('aadhaar')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="pan" class="dh-label">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                            PAN (optional)
                        </label>
                        <input type="text" name="pan" id="pan"
                               value="{{ old('pan') }}" placeholder="ABCDE1234F"
                               class="dh-input" maxlength="10">
                        @error('pan')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
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
                                       placeholder="e.g. Gold Chain 22K" class="dh-input" required>
                            </div>
                            <div>
                                <label class="dh-label">Gross Weight (g) *</label>
                                <input type="number" step="0.001" :name="'items['+index+'][gross_weight]'" x-model.number="item.gross_weight"
                                       placeholder="0.000" class="dh-input" required @input="recalcItem(index)">
                            </div>
                            <div>
                                <label class="dh-label">Stone Weight (g)</label>
                                <input type="number" step="0.001" :name="'items['+index+'][stone_weight]'" x-model.number="item.stone_weight"
                                       placeholder="0.000" class="dh-input" @input="recalcItem(index)">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                            <div>
                                <label class="dh-label">Purity (K) *</label>
                                <select :name="'items['+index+'][purity]'" x-model.number="item.purity" class="dh-select" required @change="recalcItem(index)">
                                    <option value="">Select</option>
                                    <option value="24">24K</option>
                                    <option value="22">22K</option>
                                    <option value="21">21K</option>
                                    <option value="18">18K</option>
                                    <option value="14">14K</option>
                                </select>
                            </div>
                            <div>
                                <label class="dh-label">Rate/gram at Pledge</label>
                                <div class="dh-cost-wrap">
                                    <span class="dh-cost-symbol">{{ $currencySymbol ?? '₹' }}</span>
                                    <input type="number" step="0.01" :name="'items['+index+'][rate_per_gram_at_pledge]'" x-model.number="item.rate_per_gram_at_pledge"
                                           placeholder="0.00" class="dh-input" @input="recalcItem(index)">
                                </div>
                            </div>
                            <div>
                                <label class="dh-label">HUID (optional)</label>
                                <input type="text" :name="'items['+index+'][huid]'" x-model="item.huid"
                                       placeholder="HUID" class="dh-input" maxlength="12">
                            </div>
                            <div>
                                <label class="dh-label">Net Metal Wt (g)</label>
                                <input type="text" readonly class="dh-input dh-calc-field"
                                       :value="item.net_metal_weight.toFixed(3)">
                                <input type="hidden" :name="'items['+index+'][net_metal_weight]'" :value="item.net_metal_weight">
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="dh-label">Fine Weight (g)</label>
                                <input type="text" readonly class="dh-input dh-calc-field"
                                       :value="item.fine_weight.toFixed(3)">
                                <input type="hidden" :name="'items['+index+'][fine_weight]'" :value="item.fine_weight">
                            </div>
                            <div>
                                <label class="dh-label">Market Value</label>
                                <input type="text" readonly class="dh-input dh-calc-field"
                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                                <input type="hidden" :name="'items['+index+'][market_value]'" :value="item.market_value">
                            </div>
                            <div>
                                <label class="dh-label">Loan Value</label>
                                <input type="text" readonly class="dh-input dh-calc-field"
                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                            </div>
                        </div>
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

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
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
                customer_id: '{{ old('customer_id', '') }}',
                customerDisplayName: '',
                customerDropdownOpen: false,
                customerSearch: '',
                goldRateOnDate: {{ old('gold_rate_on_date', 0) }},
                principal_amount: {{ old('principal_amount', 0) }},
                interest_rate_monthly: {{ old('interest_rate_monthly', $defaults['interest_rate_monthly'] ?? 1.5) }},
                interest_type: '{{ old('interest_type', $defaults['interest_type'] ?? 'flat') }}',
                tenure_months: {{ old('tenure_months', $defaults['tenure_months'] ?? 6) }},
                penalty_rate_monthly: {{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? 2) }},
                processingFeePercent: {{ $defaults['processing_fee_percent'] ?? 0 }},
                ltvPercent: {{ $defaults['ltv_percent'] ?? 75 }},

                items: [{
                    description: '', gross_weight: 0, stone_weight: 0, purity: 22,
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

                selectCustomer(id, name, mobile) {
                    this.customer_id = id;
                    this.customerDisplayName = name + (mobile ? ' (' + mobile + ')' : '');
                    this.customerDropdownOpen = false;
                    this.customerSearch = '';
                },

                addItem() {
                    this.items.push({
                        description: '', gross_weight: 0, stone_weight: 0, purity: 22,
                        rate_per_gram_at_pledge: 0, huid: '',
                        net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0
                    });
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        this.items.splice(index, 1);
                    }
                },

                recalcItem(index) {
                    let item = this.items[index];
                    item.net_metal_weight = Math.max(0, (item.gross_weight || 0) - (item.stone_weight || 0));
                    item.fine_weight = item.net_metal_weight * (item.purity || 0) / 24;
                    let rate = item.rate_per_gram_at_pledge || this.goldRateOnDate || 0;
                    item.market_value = item.fine_weight * rate;
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
</x-app-layout>
