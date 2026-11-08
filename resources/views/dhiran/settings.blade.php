<x-dhiran-layout title="Dhiran Settings">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Dhiran Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Defaults for new pledge loans</p>
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

    {{-- Every field here is a DhiranSettings column that loans read (2026-11-08: the old form used names the save did not
         accept, so it never saved, and showed placeholder values). Existing loans keep their own terms. --}}
    @php
        $v = fn (string $key, $fallback = null) => old($key, $settings->{$key} ?? $fallback);
        $num = fn (string $key) => is_numeric($v($key)) ? rtrim(rtrim(number_format((float) $v($key), 2, '.', ''), '0'), '.') : $v($key);
        $err = fn (string $key) => $errors->first($key);
    @endphp

    <div class="content-inner dhiran-settings-root">
        <form method="POST" action="{{ route('dhiran.settings.update') }}" class="max-w-3xl mx-auto" data-turbo-frame="_top">
            @csrf
            @method('PATCH')

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
                @if($errors->any())
                    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
                @endif

                <div class="dset-section">
                    <div class="dset-section-title"><x-icon name="calculator" /> Interest</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="dset-label" for="ds-rate">Monthly interest rate (%)</label>
                            <input id="ds-rate" type="number" step="0.01" min="0" max="10" required name="default_interest_rate_monthly" class="dset-input" value="{{ $num('default_interest_rate_monthly') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-type">Interest type</label>
                            <select id="ds-type" name="default_interest_type" class="dset-select">
                                @foreach(['flat' => 'Flat (Simple)', 'daily' => 'Daily', 'compound' => 'Compound'] as $value => $label)
                                    <option value="{{ $value }}" @selected($v('default_interest_type', 'flat') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-penalty">Penalty rate (%/month)</label>
                            <input id="ds-penalty" type="number" step="0.01" min="0" max="10" required name="default_penalty_rate_monthly" class="dset-input" value="{{ $num('default_penalty_rate_monthly') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-minint">Minimum interest (months)</label>
                            <input id="ds-minint" type="number" min="0" max="12" required name="default_min_interest_months" class="dset-input" value="{{ $v('default_min_interest_months') }}">
                            <p class="dset-hint">Charged even if the loan is closed or renewed sooner.</p>
                        </div>
                    </div>
                </div>

                <div class="dset-section">
                    <div class="dset-section-title"><x-icon name="trending-up" /> Loan value (LTV)</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="dset-label" for="ds-ltv">Loan value (% of market value)</label>
                            <input id="ds-ltv" type="number" step="0.01" min="1" max="100" required name="default_ltv_ratio" class="dset-input" value="{{ $num('default_ltv_ratio') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-hv-threshold">Large loans from (₹)</label>
                            <input id="ds-hv-threshold" type="number" step="0.01" min="0" required name="high_value_threshold" class="dset-input" value="{{ $num('high_value_threshold') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-hv-ltv">Large-loan value (%)</label>
                            <input id="ds-hv-ltv" type="number" step="0.01" min="1" max="100" required name="high_value_ltv_ratio" class="dset-input" value="{{ $num('high_value_ltv_ratio') }}">
                            <p class="dset-hint">Used instead of the normal % for loans of this size or more.</p>
                        </div>
                    </div>
                </div>

                <div class="dset-section">
                    <div class="dset-section-title"><x-icon name="circle-dollar-sign" /> Loan limits and fee</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label" for="ds-min">Minimum loan (₹)</label>
                            <input id="ds-min" type="number" step="0.01" min="0" required name="min_loan_amount" class="dset-input" value="{{ $num('min_loan_amount') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-max">Maximum loan (₹)</label>
                            <input id="ds-max" type="number" step="0.01" min="0" required name="max_loan_amount" class="dset-input" value="{{ $num('max_loan_amount') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-fee-type">Processing fee</label>
                            <select id="ds-fee-type" name="processing_fee_type" class="dset-select">
                                <option value="flat" @selected($v('processing_fee_type', 'flat') === 'flat')>Fixed amount (₹)</option>
                                <option value="percent" @selected($v('processing_fee_type') === 'percent')>% of the loan</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-fee">Fee value</label>
                            <input id="ds-fee" type="number" step="0.01" min="0" required name="processing_fee_value" class="dset-input" value="{{ $num('processing_fee_value') }}">
                            <p class="dset-hint">0 for no fee.</p>
                        </div>
                    </div>
                </div>

                <div class="dset-section">
                    <div class="dset-section-title"><x-icon name="calendar" /> Tenure and forfeiture</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label" for="ds-tenure">Tenure (months)</label>
                            <input id="ds-tenure" type="number" min="1" max="60" required name="default_tenure_months" class="dset-input" value="{{ $v('default_tenure_months') }}">
                        </div>
                        <div>
                            <label class="dset-label" for="ds-lock">Lock-in (months)</label>
                            <input id="ds-lock" type="number" min="0" max="60" required name="default_min_lock_months" class="dset-input" value="{{ $v('default_min_lock_months') }}">
                            <p class="dset-hint">The loan cannot be closed early before this.</p>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-grace">Grace period (days)</label>
                            <input id="ds-grace" type="number" min="0" max="90" required name="grace_period_days" class="dset-input" value="{{ $v('grace_period_days') }}">
                            <p class="dset-hint">Days after maturity before penalty applies and a forfeiture notice can be sent.</p>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-notice">Forfeiture notice period (days)</label>
                            <input id="ds-notice" type="number" min="0" max="365" required name="forfeiture_notice_days" class="dset-input" value="{{ $v('forfeiture_notice_days') }}">
                            <p class="dset-hint">Days the customer gets to pay after the notice, before forfeiture.</p>
                        </div>
                    </div>
                </div>

                <div class="dset-section">
                    <div class="dset-section-title"><x-icon name="printer" /> Loan numbers and printouts</div>
                    <div class="grid grid-cols-1 gap-4">
                        <div class="max-w-xs">
                            <label class="dset-label" for="ds-prefix">Loan number prefix</label>
                            <input id="ds-prefix" type="text" maxlength="10" name="loan_number_prefix" class="dset-input" value="{{ $v('loan_number_prefix') }}" placeholder="DH-">
                            <p class="dset-hint">Numbers continue from the last loan.</p>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-terms">Terms printed on the pledge receipt</label>
                            <textarea id="ds-terms" name="receipt_terms_text" rows="4" maxlength="5000" class="dset-input dset-textarea">{{ $v('receipt_terms_text') }}</textarea>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-closure">Text on the closure certificate</label>
                            <textarea id="ds-closure" name="closure_certificate_text" rows="3" maxlength="5000" class="dset-input dset-textarea">{{ $v('closure_certificate_text') }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="dset-section dset-section-last">
                    <div class="dset-section-title"><x-icon name="bell" /> Reminders</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="dset-label" for="ds-sms">Due-date reminders</label>
                            <select id="ds-sms" name="sms_reminders_enabled" class="dset-select">
                                <option value="1" @selected((bool) $v('sms_reminders_enabled'))>On</option>
                                <option value="0" @selected(! (bool) $v('sms_reminders_enabled'))>Off</option>
                            </select>
                        </div>
                        <div>
                            <label class="dset-label" for="ds-remind">Remind (days before due)</label>
                            <input id="ds-remind" type="number" min="1" max="30" name="reminder_days_before_due" class="dset-input" value="{{ $v('reminder_days_before_due') }}">
                        </div>
                    </div>
                </div>

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
