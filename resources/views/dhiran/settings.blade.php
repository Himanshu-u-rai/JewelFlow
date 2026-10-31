<x-dhiran-layout title="Dhiran Settings">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Dhiran Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Configure pledge loan module preferences</p>
        </div>
        <div class="page-actions">
            @if(auth()->user()?->isShopOwner())
                <a href="{{ route('dhiran.product-preferences.index') }}" class="btn btn-secondary btn-sm" data-turbo="false">Product preferences</a>
            @endif
            <a href="{{ route('dhiran.dashboard') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                Back to Dashboard
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-settings-root">

        <form method="POST" action="{{ route('dhiran.settings.update') }}" class="max-w-3xl mx-auto" data-turbo-frame="_top">
            @csrf
            @method('PATCH')

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">

                {{-- Interest Defaults --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="calculator" />
                        Interest Defaults
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="dset-label">Default Monthly Rate (%)</label>
                            <input type="number" step="0.01" name="interest_rate_monthly" class="dset-input"
                                   value="{{ old('interest_rate_monthly', $settings['interest_rate_monthly'] ?? 1.5) }}">
                            @error('interest_rate_monthly') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="dset-label">Default Interest Type</label>
                            <select name="interest_type" class="dset-select">
                                <option value="flat" {{ ($settings['interest_type'] ?? 'flat') === 'flat' ? 'selected' : '' }}>Flat (Simple)</option>
                                <option value="daily" {{ ($settings['interest_type'] ?? '') === 'daily' ? 'selected' : '' }}>Daily</option>
                                <option value="compound" {{ ($settings['interest_type'] ?? '') === 'compound' ? 'selected' : '' }}>Compound</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label">Default Penalty Rate (%/month)</label>
                            <input type="number" step="0.01" name="penalty_rate_monthly" class="dset-input"
                                   value="{{ old('penalty_rate_monthly', $settings['penalty_rate_monthly'] ?? 2.0) }}">
                        </div>
                    </div>
                </div>

                {{-- LTV Settings --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="trending-up" />
                        LTV Settings
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Default LTV Percentage (%)</label>
                            <input type="number" step="0.1" name="ltv_percent" class="dset-input"
                                   value="{{ old('ltv_percent', $settings['ltv_percent'] ?? 75) }}">
                            <p class="dset-hint">Loan value as a percentage of market value of pledged gold</p>
                        </div>
                        <div>
                            <label class="dset-label">Max LTV Percentage (%)</label>
                            <input type="number" step="0.1" name="max_ltv_percent" class="dset-input"
                                   value="{{ old('max_ltv_percent', $settings['max_ltv_percent'] ?? 85) }}">
                        </div>
                    </div>
                </div>

                {{-- Loan Limits --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="circle-dollar-sign" />
                        Loan Limits
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Minimum Loan Amount</label>
                            <input type="number" step="0.01" name="min_loan_amount" class="dset-input"
                                   value="{{ old('min_loan_amount', $settings['min_loan_amount'] ?? 1000) }}">
                        </div>
                        <div>
                            <label class="dset-label">Maximum Loan Amount</label>
                            <input type="number" step="0.01" name="max_loan_amount" class="dset-input"
                                   value="{{ old('max_loan_amount', $settings['max_loan_amount'] ?? 500000) }}">
                        </div>
                    </div>
                </div>

                {{-- Processing Fee --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="camera" />
                        Processing Fee
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Processing Fee (%)</label>
                            <input type="number" step="0.01" name="processing_fee_percent" class="dset-input"
                                   value="{{ old('processing_fee_percent', $settings['processing_fee_percent'] ?? 0) }}">
                            <p class="dset-hint">Percentage of principal charged as processing fee. Set 0 to disable.</p>
                        </div>
                        <div>
                            <label class="dset-label">Min Processing Fee (fixed)</label>
                            <input type="number" step="0.01" name="min_processing_fee" class="dset-input"
                                   value="{{ old('min_processing_fee', $settings['min_processing_fee'] ?? 0) }}">
                        </div>
                    </div>
                </div>

                {{-- Tenure & Periods --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="calendar" />
                        Tenure & Periods
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="dset-label">Default Tenure (months)</label>
                            <input type="number" name="tenure_months" class="dset-input"
                                   value="{{ old('tenure_months', $settings['tenure_months'] ?? 6) }}">
                        </div>
                        <div>
                            <label class="dset-label">Grace Period (days)</label>
                            <input type="number" name="grace_period_days" class="dset-input"
                                   value="{{ old('grace_period_days', $settings['grace_period_days'] ?? 7) }}">
                            <p class="dset-hint">Days after maturity before penalty applies</p>
                        </div>
                        <div>
                            <label class="dset-label">Interest Billing Cycle</label>
                            <select name="billing_cycle" class="dset-select">
                                <option value="monthly" {{ ($settings['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                <option value="quarterly" {{ ($settings['billing_cycle'] ?? '') === 'quarterly' ? 'selected' : '' }}>Quarterly</option>
                                <option value="on_closure" {{ ($settings['billing_cycle'] ?? '') === 'on_closure' ? 'selected' : '' }}>On Closure</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Forfeiture --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="triangle-alert" />
                        Forfeiture
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Forfeiture After (days overdue)</label>
                            <input type="number" name="forfeiture_after_days" class="dset-input"
                                   value="{{ old('forfeiture_after_days', $settings['forfeiture_after_days'] ?? 365) }}">
                            <p class="dset-hint">Days after maturity before automatic forfeiture warning</p>
                        </div>
                        <div>
                            <label class="dset-label">Notice Period (days)</label>
                            <input type="number" name="notice_period_days" class="dset-input"
                                   value="{{ old('notice_period_days', $settings['notice_period_days'] ?? 30) }}">
                        </div>
                    </div>
                </div>

                {{-- Loan Number --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="hash" />
                        Loan Number
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Loan Number Prefix</label>
                            <input type="text" name="loan_number_prefix" class="dset-input"
                                   value="{{ old('loan_number_prefix', $settings['loan_number_prefix'] ?? 'GL-') }}" placeholder="GL-">
                        </div>
                        <div>
                            <label class="dset-label">Next Loan Number</label>
                            <input type="number" name="next_loan_number" class="dset-input"
                                   value="{{ old('next_loan_number', $settings['next_loan_number'] ?? 1) }}">
                        </div>
                    </div>
                </div>

                {{-- KYC --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="id-card" />
                        KYC Requirements
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">Aadhaar Required?</label>
                            <select name="aadhaar_required" class="dset-select">
                                <option value="1" {{ ($settings['aadhaar_required'] ?? false) ? 'selected' : '' }}>Yes</option>
                                <option value="0" {{ !($settings['aadhaar_required'] ?? false) ? 'selected' : '' }}>No</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label">PAN Required?</label>
                            <select name="pan_required" class="dset-select">
                                <option value="1" {{ ($settings['pan_required'] ?? false) ? 'selected' : '' }}>Yes</option>
                                <option value="0" {{ !($settings['pan_required'] ?? false) ? 'selected' : '' }}>No</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Receipts/Certificates --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <x-icon name="printer" />
                        Receipts & Certificates
                    </div>
                    <div>
                        <label class="dset-label">Terms & Conditions (printed on receipt)</label>
                        <textarea name="terms_and_conditions" rows="4" class="dset-input dset-textarea">{{ old('terms_and_conditions', $settings['terms_and_conditions'] ?? '') }}</textarea>
                    </div>
                </div>

                {{-- Notifications --}}
                <div class="dset-section dset-section-last">
                    <div class="dset-section-title">
                        <x-icon name="bell" />
                        Notifications
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label">SMS on Loan Creation</label>
                            <select name="sms_on_creation" class="dset-select">
                                <option value="1" {{ ($settings['sms_on_creation'] ?? false) ? 'selected' : '' }}>Enabled</option>
                                <option value="0" {{ !($settings['sms_on_creation'] ?? false) ? 'selected' : '' }}>Disabled</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label">SMS on Payment</label>
                            <select name="sms_on_payment" class="dset-select">
                                <option value="1" {{ ($settings['sms_on_payment'] ?? false) ? 'selected' : '' }}>Enabled</option>
                                <option value="0" {{ !($settings['sms_on_payment'] ?? false) ? 'selected' : '' }}>Disabled</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label">Overdue Reminder (days before)</label>
                            <input type="number" name="overdue_reminder_days" class="dset-input"
                                   value="{{ old('overdue_reminder_days', $settings['overdue_reminder_days'] ?? 7) }}">
                        </div>
                        <div>
                            <label class="dset-label">Maturity Reminder (days before)</label>
                            <input type="number" name="maturity_reminder_days" class="dset-input"
                                   value="{{ old('maturity_reminder_days', $settings['maturity_reminder_days'] ?? 15) }}">
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="flex justify-end gap-3 pt-6 mt-6 border-t border-slate-200">
                    <a href="{{ route('dhiran.dashboard') }}" class="btn btn-secondary btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-dark btn-sm">
                        <x-icon name="save" size="14" class="mr-1" />
                        Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-dhiran-layout>
