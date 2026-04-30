<style>
    .pricing-settings {
        --pricing-gap: 18px;
        --pricing-ink: #11223a;
        --pricing-muted: #5e6c83;
        --pricing-border: rgba(15, 23, 42, 0.1);
        --pricing-border-strong: rgba(15, 23, 42, 0.16);
        --pricing-panel-bg: #ffffff;
        --pricing-panel-tint: #f8fafc;
        display: flex;
        flex-direction: column;
        gap: 18px;
        color: var(--pricing-ink);
    }

    .pricing-settings .pricing-grid {
        display: grid;
        gap: var(--pricing-gap);
        margin-bottom: 16px;
    }

    .pricing-settings .pricing-grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pricing-settings .pricing-timezone-row {
        display: block;
    }

    .pricing-settings .pricing-timezone-controls {
        display: flex;
        flex-direction: column;
        gap: 16px;
        width: 100%;
    }

    .pricing-settings .pricing-grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .pricing-settings .pricing-grid-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .pricing-settings .pricing-desktop-row {
        display: grid;
        gap: 18px;
    }

    .pricing-settings .pricing-desktop-card {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .pricing-settings .pricing-desktop-only {
        display: grid;
    }

    .pricing-settings .pricing-field {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .pricing-settings .pricing-field-span-2 {
        grid-column: span 2;
    }

    .pricing-settings .pricing-profile-form {
        display: grid;
        grid-template-columns: minmax(120px, 0.9fr) minmax(160px, 1.2fr) minmax(120px, 0.9fr) minmax(120px, 0.8fr) auto;
        gap: 12px;
        align-items: end;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid rgba(148, 163, 184, 0.18);
    }

    .pricing-settings .pricing-profile-stack {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .pricing-settings .pricing-profile-edit-card {
        padding: 16px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 18px;
        background: #ffffff;
    }

    .pricing-settings .pricing-profile-edit-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .pricing-settings .pricing-profile-toggle {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 44px;
        padding: 10px 12px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        border-radius: 12px;
        background: #f8fafc;
        color: #334155;
        font-size: 13px;
        font-weight: 600;
    }

    .pricing-settings .pricing-profile-toggle input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }

    .pricing-settings .pricing-profile-card-footer {
        display: flex;
        justify-content: flex-end;
        margin-top: 14px;
    }

    .pricing-settings .pricing-toolbar-shell {
        overflow: visible;
        padding-bottom: 0;
    }

    .pricing-settings .pricing-toolbar {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr)) minmax(24px, 0.35fr) auto auto;
        gap: 12px;
        align-items: center;
        min-width: 0;
    }

    .pricing-settings .pricing-toolbar-spacer {
        min-width: 28px;
    }

    .pricing-settings .pricing-toolbar-button {
        height: 40px;
        width: auto;
        min-width: 96px;
        justify-content: center;
    }

    .pricing-settings .section-divider {
        display: none;
    }

    .pricing-settings .section-label {
        display: inline-flex;
        align-self: flex-start;
        margin: 0;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid rgba(96, 165, 250, 0.25);
        background: rgba(239, 246, 255, 0.92);
        color: #35557a;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }

    .pricing-settings .pricing-status-card,
    .pricing-settings .pricing-panel,
    .pricing-settings .pricing-table-card,
    .pricing-settings .pricing-filter-card,
    .pricing-settings .pricing-alert {
        border: 1px solid var(--pricing-border);
        border-radius: 24px;
        background: var(--pricing-panel-bg);
    }

    .pricing-settings .pricing-status-card {
        padding: 20px;
        background: var(--pricing-panel-tint);
    }

    .pricing-settings .pricing-overview-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }

    .pricing-settings .pricing-overview-item {
        padding: 16px 18px;
        border-radius: 18px;
        border: 1px solid rgba(255, 255, 255, 0.75);
        background: rgba(255, 255, 255, 0.88);
        backdrop-filter: blur(8px);
    }

    .pricing-settings .pricing-overview-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #6b7b90;
    }

    .pricing-settings .pricing-overview-value {
        margin-top: 8px;
        font-size: 1rem;
        font-weight: 700;
        color: #12233d;
        line-height: 1.45;
    }

    .pricing-settings .pricing-overview-value.is-ready {
        color: #0f766e;
    }

    .pricing-settings .pricing-overview-value.is-missing {
        color: #b45309;
    }

    .pricing-settings .pricing-panel,
    .pricing-settings .pricing-filter-card {
        padding: 22px;
    }

    .pricing-settings .pricing-panel .pricing-grid,
    .pricing-settings .pricing-filter-card .pricing-grid {
        margin-bottom: 0;
    }

    .pricing-settings .field-label {
        margin-bottom: 7px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #607089;
    }

    .pricing-settings .field-input,
    .pricing-settings select.field-input {
        width: 100%;
        min-height: 44px;
        padding: 10px 12px;
        font-size: 13px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        border-radius: 12px;
        background: #f8fafc;
        color: #0f172a;
        transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
    }

    .pricing-settings .field-input:focus,
    .pricing-settings select.field-input:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.14);
        background: #ffffff;
    }

    .pricing-settings .field-hint {
        margin-top: 8px;
        font-size: 12px;
        line-height: 1.5;
        color: #6a7890;
    }

    .pricing-settings .form-footer {
        display: flex;
        justify-content: flex-end;
        margin-top: 18px;
        padding: 0;
        border: 0;
        background: transparent;
    }

    .pricing-settings .form-footer .btn-primary,
    .pricing-settings .pricing-toolbar-button {
        min-width: 118px;
    }

    .pricing-settings .pricing-timezone-action {
        display: flex;
        align-items: center;
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
        justify-content: flex-start;
    }

    .pricing-settings .pricing-rates-panel .form-footer {
        display: block;
        margin-top: 28px;
    }

    .pricing-settings .pricing-rates-panel .form-footer .btn-primary {
        display: inline-flex;
        margin-top: 10px;
        margin-left: auto;
    }

    .pricing-settings .btn-primary,
    .pricing-settings .btn.btn-secondary,
    .pricing-settings .btn.btn-secondary.btn-sm {
        border-radius: 12px;
        font-weight: 700;
    }

    .pricing-settings .pricing-snapshot-card {
        min-height: 100%;
        border: 1px dashed rgba(59, 130, 246, 0.25);
        border-radius: 18px;
        padding: 15px 16px;
        background: #f8fafc;
        color: #334155;
    }

    .pricing-settings .pricing-snapshot-card > div + div {
        margin-top: 8px;
    }

    .pricing-settings .pricing-section-top {
        display: flex;
        justify-content: space-between;
        align-items: end;
        gap: 16px;
        margin-top: 4px;
        padding: 2px 4px 0;
        flex-wrap: wrap;
    }

    .pricing-settings .settings-section-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #12233d;
    }

    .pricing-settings .settings-section-subtitle {
        margin-top: 4px;
        max-width: 42rem;
        font-size: 13px;
        line-height: 1.55;
        color: #68788f;
    }

    .pricing-settings .pricing-table-card {
        overflow: hidden;
    }

    .pricing-settings .pricing-table-shell {
        overflow-x: auto;
        overscroll-behavior-x: contain;
    }

    .pricing-settings .pricing-table-card table {
        width: 100%;
        min-width: 760px;
        border-collapse: separate;
        border-spacing: 0;
    }

    .pricing-settings .pricing-table-card thead th {
        background: #f8fafc;
        border-bottom: 1px solid rgba(148, 163, 184, 0.24);
        color: #66768d;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.09em;
        text-transform: uppercase;
    }

    .pricing-settings .pricing-table-card tbody td {
        vertical-align: middle;
        background: rgba(255, 255, 255, 0.98);
    }

    .pricing-settings .pricing-table-card tbody tr:hover td {
        background: #fbfdff;
    }

    .pricing-settings .pricing-state-pill {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .pricing-settings .pricing-state-pill.pricing-state-pill-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .pricing-settings .pricing-state-pill.pricing-state-pill-neutral {
        background: #e9eef5;
        color: #516175;
    }

    .pricing-settings .pricing-action-form {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .pricing-settings .pricing-action-form.pricing-action-form-end {
        justify-content: flex-end;
    }

    .pricing-settings .pricing-action-form .field-input {
        min-height: 42px;
    }

    .pricing-settings .pricing-resolved-rate-form {
        flex-wrap: nowrap;
        justify-content: flex-end;
    }

    .pricing-settings .pricing-resolved-rate-form .field-input {
        width: 160px;
        min-width: 160px;
        max-width: 160px;
        flex: 0 0 160px;
    }

    .pricing-settings .pricing-resolved-rate-form .btn {
        flex: 0 0 auto;
        min-width: 96px;
        width: auto;
        white-space: nowrap;
    }

    .pricing-settings .pricing-resolved-rate-form .btn-sm {
        width: auto;
    }

    .pricing-settings .pricing-alert {
        padding: 18px 20px;
        font-size: 14px;
        line-height: 1.6;
    }

    .pricing-settings .pricing-alert.pricing-alert-warning {
        border-color: rgba(251, 191, 36, 0.34);
        background: #fffbeb;
        color: #8a5a07;
    }

    .pricing-settings .pricing-alert.pricing-alert-success {
        border-color: rgba(34, 197, 94, 0.24);
        background: #f0fdf4;
        color: #166534;
    }

    .pricing-settings .pricing-alert.pricing-alert-neutral {
        border-color: rgba(148, 163, 184, 0.22);
        background: #f8fafc;
        color: #516175;
    }

    .pricing-settings .pricing-filter-card {
        background: #f8fafc;
    }

    .pricing-settings .pricing-table-card .border-t {
        border-color: rgba(148, 163, 184, 0.22);
    }

    .pricing-settings .pricing-table-card .hover\:text-gray-700:hover {
        color: #123a67;
    }

    @media (max-width: 1100px) {
        .pricing-settings .pricing-grid-4 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pricing-settings .pricing-grid-3 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pricing-settings .pricing-profile-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pricing-settings .pricing-profile-form > .pricing-profile-submit {
            grid-column: 1 / -1;
            text-align: right;
        }

        .pricing-settings .pricing-overview-grid,
        .pricing-settings .pricing-grid-3 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pricing-settings .pricing-toolbar {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pricing-settings .pricing-toolbar-spacer {
            display: none;
        }

        .pricing-settings .pricing-toolbar-button {
            width: 100%;
        }
    }

    @media (min-width: 900px) {
        .pricing-settings .pricing-desktop-row {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
        }

        .pricing-settings .pricing-panel-stack .pricing-grid-3,
        .pricing-settings .pricing-panel-stack .pricing-grid-4 {
            grid-template-columns: 1fr;
        }

        .pricing-settings .pricing-panel-stack .form-footer .btn-primary {
            width: auto;
        }

        .pricing-settings .pricing-timezone-controls {
            flex-direction: row;
            align-items: center;
            width: 100%;
            max-width: none;
        }

        .pricing-settings .pricing-timezone-field {
            max-width: none;
        }

        .pricing-settings .pricing-timezone-select {
            flex: 1 1 auto;
            min-width: 0;
        }

        .pricing-settings .pricing-timezone-action {
            flex: 0 0 auto;
            justify-content: flex-start;
            align-self: center;
        }

        .pricing-settings .pricing-timezone-action .btn-primary {
            width: auto;
        }
    }

    @media (max-width: 700px) {
        .pricing-settings {
            gap: 14px;
        }

        .pricing-settings .pricing-panel,
        .pricing-settings .pricing-filter-card,
        .pricing-settings .pricing-status-card {
            padding: 18px;
        }

        .pricing-settings .pricing-grid-2,
        .pricing-settings .pricing-grid-3,
        .pricing-settings .pricing-grid-4,
        .pricing-settings .pricing-profile-form,
        .pricing-settings .pricing-toolbar,
        .pricing-settings .pricing-overview-grid {
            grid-template-columns: 1fr;
        }

        .pricing-settings .pricing-profile-edit-grid {
            grid-template-columns: 1fr;
        }

        .pricing-settings .pricing-field-span-2,
        .pricing-settings .pricing-profile-form > .pricing-profile-submit {
            grid-column: span 1;
        }

        .pricing-settings .pricing-profile-form > .pricing-profile-submit {
            text-align: left;
        }

        .pricing-settings .form-footer {
            justify-content: stretch;
        }

        .pricing-settings .form-footer .btn-primary,
        .pricing-settings .pricing-toolbar-button {
            width: 100%;
        }

        .pricing-settings .pricing-profile-card-footer {
            justify-content: stretch;
        }

        .pricing-settings .pricing-profile-card-footer .btn {
            width: 100%;
            justify-content: center;
        }

        .pricing-settings .pricing-timezone-controls {
            flex-direction: row;
            align-items: center;
            gap: 10px;
        }

        .pricing-settings .pricing-timezone-select {
            flex: 1 1 auto;
            min-width: 0;
        }

        .pricing-settings .pricing-timezone-action {
            flex: 0 0 auto;
            justify-content: flex-start;
        }

        .pricing-settings .pricing-timezone-action .btn-primary {
            width: auto;
            white-space: nowrap;
            padding-inline: 14px;
        }

        .pricing-settings .pricing-timezone-hint {
            display: none;
        }

        .pricing-settings .pricing-table-card table {
            min-width: 680px;
        }
    }
</style>

<div class="pricing-settings">
<div class="settings-header">
    <h2 class="settings-title">{{ __('Pricing') }}</h2>
    <p class="settings-desc">{{ __('Daily retailer rates, purity profiles, overrides, and legacy cleanup') }}</p>
</div>

@php
    $todayRate = $pricingData['today_rate'] ?? null;
    $goldRateValue = old('gold_24k_rate_per_gram', $todayRate ? (float) $todayRate->gold_24k_rate_per_gram : null);
    $silverRatePerKgValue = old('silver_999_rate_per_kg', $todayRate ? round((float) $todayRate->silver_999_rate_per_gram * 1000, 4) : null);
    $historyFilters = $pricingData['history_filters'] ?? [
        'date_from' => null,
        'date_to' => null,
        'metal_type' => '',
        'purity_value' => null,
        'entry_type' => '',
        'sort_by' => 'business_date',
        'sort_dir' => 'desc',
    ];
    $historyRows = $pricingData['history_rows'] ?? null;
    $historyPurityOptions = $pricingData['history_purity_options'] ?? collect();
    $historySortBy = $historyFilters['sort_by'] ?? 'business_date';
    $historySortDir = $historyFilters['sort_dir'] ?? 'desc';
    $historySortIcon = function (string $column) use ($historySortBy, $historySortDir): string {
        if ($historySortBy !== $column) {
            return '↕';
        }

        return $historySortDir === 'asc' ? '▲' : '▼';
    };
    $historySortUrl = function (string $column) use ($historySortBy, $historySortDir, $historyFilters): string {
        $query = [
            'tab'                  => 'pricing',
            'history_date_from'    => $historyFilters['date_from'] ?? null,
            'history_date_to'      => $historyFilters['date_to'] ?? null,
            'history_metal_type'   => $historyFilters['metal_type'] ?? '',
            'history_purity_value' => $historyFilters['purity_value'] ?? null,
            'history_entry_type'   => $historyFilters['entry_type'] ?? '',
            'history_sort_by'      => $column,
            'history_sort_dir'     => ($historySortBy === $column && $historySortDir === 'asc') ? 'desc' : 'asc',
        ];

        return route('settings.edit', array_filter($query, fn ($v) => $v !== null && $v !== ''));
    };
@endphp

<div class="section-label">{{ __('Business Day') }}</div>
<div class="pricing-status-card">
    <div class="pricing-overview-grid">
        <div class="pricing-overview-item">
            <div class="pricing-overview-label">{{ __('Pricing Date') }}</div>
            <div class="pricing-overview-value">{{ $pricingData['business_date'] ?? '—' }}</div>
        </div>
        <div class="pricing-overview-item">
            <div class="pricing-overview-label">{{ __('Timezone') }}</div>
            <div class="pricing-overview-value">{{ $pricingData['timezone'] ?? '—' }}</div>
        </div>
        <div class="pricing-overview-item">
            <div class="pricing-overview-label">{{ __('Today\'s Rates') }}</div>
            <div class="pricing-overview-value {{ ($pricingData['rates_ready'] ?? false) ? 'is-ready' : 'is-missing' }}">
                {{ ($pricingData['rates_ready'] ?? false) ? __('Saved') : __('Missing') }}
            </div>
        </div>
    </div>
</div>

<div class="section-divider"></div>
<div class="section-label">{{ __('Pricing Timezone') }}</div>
<form method="POST" action="{{ route('settings.pricing.update-timezone') }}" class="pricing-panel">
    @csrf
    @method('PATCH')
    <div class="pricing-timezone-row">
        <div class="pricing-field pricing-timezone-field">
            <label class="field-label">{{ __('Timezone') }}</label>
            <div class="pricing-timezone-controls">
                <select name="pricing_timezone" class="field-input pricing-timezone-select" required>
                    @foreach($pricingTimezones as $timezone)
                        <option value="{{ $timezone }}" {{ old('pricing_timezone', $pricingData['timezone'] ?? config('app.timezone', 'UTC')) === $timezone ? 'selected' : '' }}>
                            {{ $timezone }}
                        </option>
                    @endforeach
                </select>
                <div class="pricing-timezone-action">
                    <button type="submit" class="btn-primary">{{ __('Save Timezone') }}</button>
                </div>
            </div>
            <span class="field-hint pricing-timezone-hint">{{ __('Retailer daily pricing resets at local midnight in this timezone.') }}</span>
        </div>
    </div>
</form>

<div class="pricing-desktop-row">
    <div class="pricing-desktop-card">
        <div class="section-divider"></div>
        <div class="section-label">{{ __('Today\'s Base Rates') }}</div>
        <form method="POST" action="{{ route('settings.pricing.save-rates') }}" class="pricing-panel pricing-panel-stack pricing-rates-panel">
            @csrf
            <input type="hidden" name="context" value="settings">
            <div class="pricing-grid pricing-grid-3">
                <div class="pricing-field">
                    <label class="field-label">{{ __('24K Gold Price / Gram') }}</label>
                    <input type="number" step="0.0001" min="0.0001" name="gold_24k_rate_per_gram" value="{{ $goldRateValue }}" class="field-input" required>
                </div>
                <div class="pricing-field">
                    <label class="field-label">{{ __('Silver 999 Price / Kg') }}</label>
                    <input type="number" step="0.0001" min="0.0001" name="silver_999_rate_per_kg" value="{{ $silverRatePerKgValue }}" class="field-input" required>
                    <span class="field-hint">{{ __('Stored internally as per gram after conversion.') }}</span>
                </div>
                <div class="pricing-field">
                    <label class="field-label">{{ __('Current Snapshot') }}</label>
                    <div class="pricing-snapshot-card">
                        @if($todayRate)
                            <div>{{ __('Gold') }}: ₹{{ number_format((float) $todayRate->gold_24k_rate_per_gram, 4) }}/g</div>
                            <div class="mt-1">{{ __('Silver') }}: ₹{{ number_format((float) $todayRate->silver_999_rate_per_gram, 4) }}/g</div>
                        @else
                            <div>{{ __('No daily rates saved yet.') }}</div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="form-footer">
                <button type="submit" class="btn-primary">{{ __('Save Today\'s Rates') }}</button>
            </div>
        </form>
    </div>

    <div class="pricing-desktop-card">
        <div class="section-divider"></div>
        <div class="section-label">{{ __('Purity Profiles') }}</div>
        <form method="POST" action="{{ route('settings.pricing.profiles.store') }}" class="pricing-panel pricing-panel-stack">
            @csrf
            <div class="pricing-grid pricing-grid-4">
                <div class="pricing-field">
                    <label class="field-label">{{ __('Metal') }}</label>
                    <select name="metal_type" class="field-input" required>
                        <option value="gold">{{ __('Gold') }}</option>
                        <option value="silver">{{ __('Silver') }}</option>
                    </select>
                </div>
                <div class="pricing-field">
                    <label class="field-label">{{ __('Purity Value') }}</label>
                    <input type="number" step="0.001" min="0.001" max="1000" name="purity_value" class="field-input" required>
                </div>
                <div class="pricing-field">
                    <label class="field-label">{{ __('Code') }}</label>
                    <input type="text" name="code" class="field-input" maxlength="30" placeholder="{{ __('Optional') }}">
                </div>
                <div class="pricing-field">
                    <label class="field-label">{{ __('Label') }}</label>
                    <input type="text" name="label" class="field-input" maxlength="60" placeholder="{{ __('Optional') }}">
                </div>
            </div>
            <div class="form-footer">
                <button type="submit" class="btn-primary">{{ __('Add Purity Profile') }}</button>
            </div>
        </form>
    </div>
</div>

@php
    $profileGroups = ($pricingData['profiles'] ?? collect())->sortKeys();
@endphp

<div class="pricing-desktop-row pricing-desktop-only">
    @foreach($profileGroups as $metalType => $profiles)
        <div class="pricing-desktop-card">
            <div class="settings-section-top pricing-section-top">
                <h3 class="settings-section-title">{{ ucfirst($metalType) }} {{ __('Profiles') }}</h3>
                <p class="settings-section-subtitle">{{ __('Active and inactive purity definitions used by retailer pricing.') }}</p>
            </div>

            <div class="pricing-panel">
                <div class="pricing-profile-stack">
                    @foreach($profiles as $profile)
                        <form method="POST" action="{{ route('settings.pricing.profiles.update', $profile) }}" class="pricing-profile-edit-card">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="metal_type" value="{{ $profile->metal_type }}">
                            <div class="pricing-profile-edit-grid">
                                <div class="pricing-field">
                                    <label class="field-label">{{ __('Code') }}</label>
                                    <input type="text" name="code" value="{{ old('code', $profile->code) }}" class="field-input" maxlength="30">
                                </div>
                                <div class="pricing-field">
                                    <label class="field-label">{{ __('Label') }}</label>
                                    <input type="text" name="label" value="{{ old('label', $profile->label) }}" class="field-input" maxlength="60">
                                </div>
                                <div class="pricing-field">
                                    <label class="field-label">{{ __('Purity') }}</label>
                                    <input type="number" step="0.001" min="0.001" max="1000" name="purity_value" value="{{ old('purity_value', (float) $profile->purity_value) }}" class="field-input" required>
                                </div>
                                <div class="pricing-field">
                                    <label class="field-label">{{ __('Active') }}</label>
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="pricing-profile-toggle">
                                        <input type="checkbox" name="is_active" value="1" {{ $profile->is_active ? 'checked' : '' }}>
                                        {{ __('Active') }}
                                    </label>
                                </div>
                            </div>
                            <div class="pricing-profile-card-footer">
                                <button type="submit" class="btn btn-secondary btn-sm">{{ __('Update') }}</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="section-divider"></div>
<div class="section-label">{{ __('Today\'s Resolved Rates') }}</div>
@if($todayRate)
    <div class="bg-white border border-gray-200 overflow-hidden rounded-xl pricing-table-card">
        <div class="overflow-x-auto pricing-table-shell">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Metal') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Purity') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Rate / Gram') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Override') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Save') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($pricingData['resolved_rates'] as $row)
                        @php
                            $profile = $row['profile'];
                            $currentRate = $row['current_rate'];
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ ucfirst($profile->metal_type) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $profile->label }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                @if($row['rate_per_gram'] !== null)
                                    ₹{{ number_format((float) $row['rate_per_gram'], 4) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="pricing-state-pill {{ $row['is_override'] ? 'pricing-state-pill-warning' : 'pricing-state-pill-neutral' }}">
                                    {{ $row['is_override'] ? __('Active Override') : __('Base Derived') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.pricing.overrides.store', $profile) }}" class="pricing-action-form pricing-action-form-end pricing-resolved-rate-form">
                                    @csrf
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        name="rate_per_gram"
                                        value="{{ old('rate_per_gram', $currentRate ? (float) $currentRate->rate_per_gram : null) }}"
                                        class="field-input"
                                        required
                                    >
                                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('Override') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div class="pricing-alert pricing-alert-warning">
        {{ __('Save today\'s base rates first to see resolved per-purity rates and same-day overrides.') }}
    </div>
@endif

<div class="section-divider"></div>
<div class="section-label">{{ __('Legacy Item Review') }}</div>
@if(($pricingData['legacy_items'] ?? collect())->isEmpty())
    <div class="pricing-alert pricing-alert-success">
        {{ __('No legacy items need pricing review right now.') }}
    </div>
@else
    <div class="bg-white border border-gray-200 overflow-hidden rounded-xl pricing-table-card">
        <div class="overflow-x-auto pricing-table-shell">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Barcode') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Design') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Category') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Purity') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Assign Metal') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Resolve') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($pricingData['legacy_items'] as $legacyItem)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $legacyItem->barcode }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $legacyItem->design ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $legacyItem->category ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $legacyItem->purity_label ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.pricing.legacy.resolve', $legacyItem) }}" class="pricing-action-form">
                                    @csrf
                                    @method('PATCH')
                                    <select name="metal_type" class="field-input max-w-[180px]" required>
                                        <option value="gold" {{ old('metal_type', $legacyItem->metal_type) === 'gold' ? 'selected' : '' }}>{{ __('Gold') }}</option>
                                        <option value="silver" {{ old('metal_type', $legacyItem->metal_type) === 'silver' ? 'selected' : '' }}>{{ __('Silver') }}</option>
                                    </select>
                                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('Save') }}</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-right text-xs text-gray-500">
                                {{ $legacyItem->pricing_review_notes ?: __('Needs owner review') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="section-divider"></div>
<div class="section-label">{{ __('Price History') }}</div>
<form method="GET" action="{{ route('settings.edit') }}">
    <input type="hidden" name="tab" value="pricing">
    <input type="hidden" name="history_sort_by" value="{{ $historySortBy }}">
    <input type="hidden" name="history_sort_dir" value="{{ $historySortDir }}">
    <div class="mb-3 rounded-xl border border-gray-200 bg-gray-50/70 p-3 pricing-filter-card">
        <div class="pricing-toolbar-shell">
        <div class="pricing-toolbar">
            <div>
                <input type="date" name="history_date_from" value="{{ $historyFilters['date_from'] }}" class="field-input" style="height:40px;" placeholder="{{ __('From') }}" title="{{ __('From Date') }}">
            </div>
            <div>
                <input type="date" name="history_date_to" value="{{ $historyFilters['date_to'] }}" class="field-input" style="height:40px;" placeholder="{{ __('To') }}" title="{{ __('To Date') }}">
            </div>
            <div>
                <select name="history_metal_type" class="field-input" style="height:40px;" title="{{ __('Metal') }}">
                    <option value="">{{ __('All Metals') }}</option>
                    <option value="gold" {{ $historyFilters['metal_type'] === 'gold' ? 'selected' : '' }}>{{ __('Gold') }}</option>
                    <option value="silver" {{ $historyFilters['metal_type'] === 'silver' ? 'selected' : '' }}>{{ __('Silver') }}</option>
                </select>
            </div>
            <div>
                <input type="text" name="history_purity_value" list="pricing-history-purity-options" value="{{ $historyFilters['purity_value'] !== null ? rtrim(rtrim(number_format((float) $historyFilters['purity_value'], 3, '.', ''), '0'), '.') : '' }}" class="field-input" style="height:40px;" placeholder="{{ __('Karat/Purity') }}" title="{{ __('Karat/Purity') }}">
                <datalist id="pricing-history-purity-options">
                    @foreach($historyPurityOptions as $option)
                        <option value="{{ $option['value'] }}">{{ ucfirst($option['metal_type']) }} — {{ $option['label'] }}</option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <select name="history_entry_type" class="field-input" style="height:40px;" title="{{ __('Entry Type') }}">
                    <option value="">{{ __('All Entries') }}</option>
                    <option value="base" {{ $historyFilters['entry_type'] === 'base' ? 'selected' : '' }}>{{ __('Base Derived') }}</option>
                    <option value="override" {{ $historyFilters['entry_type'] === 'override' ? 'selected' : '' }}>{{ __('Override') }}</option>
                </select>
            </div>
            <div class="pricing-toolbar-spacer" aria-hidden="true"></div>
            <div>
                <button type="submit" class="btn-primary pricing-toolbar-button">{{ __('Apply') }}</button>
            </div>
            <div>
                <a href="{{ route('settings.edit', ['tab' => 'pricing']) }}" class="btn btn-secondary pricing-toolbar-button inline-flex items-center">{{ __('Reset') }}</a>
            </div>
        </div>
        </div>
    </div>
</form>

@if($historyRows && $historyRows->count() > 0)
    <div class="bg-white border border-gray-200 overflow-hidden rounded-xl pricing-table-card">
        <div class="overflow-x-auto pricing-table-shell">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('business_date') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Business Date') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('business_date') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('metal_type') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Metal') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('metal_type') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('purity_value') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Karat/Purity') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('purity_value') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('rate_per_gram') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Rate / Gram') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('rate_per_gram') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('entry_type') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Entry Type') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('entry_type') }}</span>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <a href="{{ $historySortUrl('recorded_at') }}" class="inline-flex items-center gap-1 hover:text-gray-700">
                                <span>{{ __('Recorded At') }}</span>
                                <span class="text-[10px]">{{ $historySortIcon('recorded_at') }}</span>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($historyRows as $historyRow)
                        @php
                            $rawPurity = is_numeric($historyRow->purity_value ?? null)
                                ? (float) $historyRow->purity_value
                                : (float) ($historyRow->purity ?? 0);
                            $purityText = rtrim(rtrim(number_format($rawPurity, 3, '.', ''), '0'), '.');
                            $fallbackPurityLabel = $historyRow->metal_type === 'gold' ? $purityText . 'K' : $purityText;
                            $purityLabel = $historyRow->profile_label ?: $fallbackPurityLabel;
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $historyRow->business_date }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ ucfirst($historyRow->metal_type) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $purityLabel }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">₹{{ number_format((float) $historyRow->rate_per_gram, 4) }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span class="pricing-state-pill {{ $historyRow->is_override ? 'pricing-state-pill-warning' : 'pricing-state-pill-neutral' }}">
                                    {{ $historyRow->is_override ? __('Override') : __('Base Derived') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                {{ $historyRow->fetched_at ? \Carbon\Carbon::parse($historyRow->fetched_at)->timezone($pricingData['timezone'] ?? config('app.timezone', 'UTC'))->format('Y-m-d H:i') : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-gray-200">
            {{ $historyRows->links() }}
        </div>
    </div>
@else
    <div class="pricing-alert pricing-alert-neutral">
        {{ __('No pricing history records matched your filters yet.') }}
    </div>
@endif
</div>
