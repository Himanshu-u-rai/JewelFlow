<x-app-layout>
    <style>
        .dhiran-settings-root {
            --dset-ink: #0f172a;
            --dset-ink-soft: #334155;
            --dset-muted: #64748b;
            --dset-border: #e2e8f0;
            --dset-bg: #f8fafc;
            --dset-accent: #d98b00;
        }
        .dset-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            color: var(--dset-ink-soft); margin-bottom: 6px;
        }
        .dset-input, .dset-select {
            width: 100%; padding: 10px 12px; font-size: 13px; font-weight: 500;
            border: 1.5px solid var(--dset-border); border-radius: 10px;
            background: var(--dset-bg); color: var(--dset-ink);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        .dset-input:focus, .dset-select:focus {
            outline: none; border-color: var(--dset-accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(217, 139, 0, 0.12);
        }
        .dset-input::placeholder { color: #9ca3af; font-weight: 400; }
        .dset-select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 36px; cursor: pointer;
        }
        .dset-section {
            margin-bottom: 32px;
        }
        .dset-section-title {
            font-size: 14px; font-weight: 700; color: var(--dset-ink);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px; padding-bottom: 8px;
            border-bottom: 1px solid var(--dset-border);
        }
        .dset-section-title svg { width: 16px; height: 16px; color: var(--dset-accent); }
        .dset-hint {
            font-size: 11px; color: var(--dset-muted); margin-top: 4px;
        }
    </style>

    <x-page-header>
        <div>
            <h1 class="page-title">Dhiran Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Configure gold loan module preferences</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.dashboard') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Dashboard
            </a>
        </div>
    </x-page-header>

    <div class="content-inner dhiran-settings-root">
        <x-app-alerts class="mb-6" />

        <form method="POST" action="{{ route('dhiran.settings.update') }}" class="max-w-3xl mx-auto">
            @csrf
            @method('PATCH')

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">

                {{-- Interest Defaults --}}
                <div class="dset-section">
                    <div class="dset-section-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Receipts & Certificates
                    </div>
                    <div>
                        <label class="dset-label">Terms & Conditions (printed on receipt)</label>
                        <textarea name="terms_and_conditions" rows="4" class="dset-input" style="resize:vertical;min-height:72px;line-height:1.5;">{{ old('terms_and_conditions', $settings['terms_and_conditions'] ?? '') }}</textarea>
                    </div>
                </div>

                {{-- Notifications --}}
                <div class="dset-section" style="margin-bottom:0;">
                    <div class="dset-section-title">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
