<style>
    .pricing-settings {
        --pricing-gap: 14px;
    }

    .pricing-settings .pricing-grid {
        display: grid;
        gap: var(--pricing-gap);
        margin-bottom: 16px;
    }

    .pricing-settings .pricing-grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pricing-settings .pricing-grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .pricing-settings .pricing-grid-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
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
    }

    .pricing-settings .pricing-toolbar-shell {
        overflow-x: auto;
        padding-bottom: 2px;
    }

    .pricing-settings .pricing-toolbar {
        display: grid;
        grid-template-columns: 150px 150px 130px 170px 140px minmax(28px, 1fr) 96px 84px;
        gap: 12px;
        align-items: center;
        min-width: 980px;
    }

    .pricing-settings .pricing-toolbar-spacer {
        min-width: 28px;
    }

    .pricing-settings .pricing-toolbar-button {
        height: 40px;
        width: 100%;
        justify-content: center;
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
    }

    @media (max-width: 700px) {
        .pricing-settings .pricing-grid-2,
        .pricing-settings .pricing-grid-3,
        .pricing-settings .pricing-grid-4,
        .pricing-settings .pricing-profile-form {
            grid-template-columns: 1fr;
        }

        .pricing-settings .pricing-field-span-2,
        .pricing-settings .pricing-profile-form > .pricing-profile-submit {
            grid-column: span 1;
        }

        .pricing-settings .pricing-profile-form > .pricing-profile-submit {
            text-align: left;
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
    $historySortUrl = function (string $column) use ($historySortBy, $historySortDir): string {
        $query = request()->query();
        $query['tab'] = 'pricing';
        $query['history_sort_by'] = $column;
        $query['history_sort_dir'] = ($historySortBy === $column && $historySortDir === 'asc') ? 'desc' : 'asc';
        unset($query['pricing_history_page']);

        return route('settings.edit', $query);
    };
@endphp

<div class="section-label">{{ __('Business Day') }}</div>
<div class="bg-gray-50 border border-gray-200 p-4 text-sm rounded-xl">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <div class="text-gray-500 text-xs uppercase tracking-wide">{{ __('Pricing Date') }}</div>
            <div class="font-semibold text-gray-900 mt-1">{{ $pricingData['business_date'] ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase tracking-wide">{{ __('Timezone') }}</div>
            <div class="font-semibold text-gray-900 mt-1">{{ $pricingData['timezone'] ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase tracking-wide">{{ __('Today\'s Rates') }}</div>
            <div class="font-semibold mt-1 {{ ($pricingData['rates_ready'] ?? false) ? 'text-green-700' : 'text-amber-700' }}">
                {{ ($pricingData['rates_ready'] ?? false) ? __('Saved') : __('Missing') }}
            </div>
        </div>
    </div>
</div>

<div class="section-divider"></div>
<div class="section-label">{{ __('Pricing Timezone') }}</div>
<form method="POST" action="{{ route('settings.pricing.update-timezone') }}">
    @csrf
    @method('PATCH')
    <div class="pricing-grid pricing-grid-2">
        <div class="pricing-field pricing-field-span-2">
            <label class="field-label">{{ __('Timezone') }}</label>
            <select name="pricing_timezone" class="field-input" required>
                @foreach($pricingTimezones as $timezone)
                    <option value="{{ $timezone }}" {{ old('pricing_timezone', $pricingData['timezone'] ?? config('app.timezone', 'UTC')) === $timezone ? 'selected' : '' }}>
                        {{ $timezone }}
                    </option>
                @endforeach
            </select>
            <span class="field-hint">{{ __('Retailer daily pricing resets at local midnight in this timezone.') }}</span>
        </div>
    </div>
    <div class="form-footer">
        <button type="submit" class="btn-primary">{{ __('Save Timezone') }}</button>
    </div>
</form>

<div class="section-divider"></div>
<div class="section-label">{{ __('Today\'s Base Rates') }}</div>
<form method="POST" action="{{ route('settings.pricing.save-rates') }}">
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
            <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-700">
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

<div class="section-divider"></div>
<div class="section-label">{{ __('Purity Profiles') }}</div>
<form method="POST" action="{{ route('settings.pricing.profiles.store') }}">
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

@foreach(($pricingData['profiles'] ?? collect())->sortKeys() as $metalType => $profiles)
    <div class="settings-section-top">
        <h3 class="settings-section-title">{{ ucfirst($metalType) }} {{ __('Profiles') }}</h3>
        <p class="settings-section-subtitle">{{ __('Active and inactive purity definitions used by retailer pricing.') }}</p>
    </div>

    <div class="bg-white shadow-sm border border-gray-200 overflow-hidden rounded-xl">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Code') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Label') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Purity') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Active') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('Save') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($profiles as $profile)
                        <tr>
                            <td colspan="5" class="p-0">
                                <form method="POST" action="{{ route('settings.pricing.profiles.update', $profile) }}" class="pricing-profile-form p-4">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="metal_type" value="{{ $profile->metal_type }}">
                                    <div class="pricing-field mb-0">
                                        <input type="text" name="code" value="{{ old('code', $profile->code) }}" class="field-input" maxlength="30">
                                    </div>
                                    <div class="pricing-field mb-0">
                                        <input type="text" name="label" value="{{ old('label', $profile->label) }}" class="field-input" maxlength="60">
                                    </div>
                                    <div class="pricing-field mb-0">
                                        <input type="number" step="0.001" min="0.001" max="1000" name="purity_value" value="{{ old('purity_value', (float) $profile->purity_value) }}" class="field-input" required>
                                    </div>
                                    <div class="pricing-field mb-0">
                                        <input type="hidden" name="is_active" value="0">
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" name="is_active" value="1" {{ $profile->is_active ? 'checked' : '' }}>
                                            {{ $profile->is_active ? __('Active') : __('Inactive') }}
                                        </label>
                                    </div>
                                    <div class="pricing-profile-submit text-right">
                                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('Update') }}</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div class="section-divider"></div>
<div class="section-label">{{ __('Today\'s Resolved Rates') }}</div>
@if($todayRate)
    <div class="bg-white shadow-sm border border-gray-200 overflow-hidden rounded-xl">
        <div class="overflow-x-auto">
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
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $row['is_override'] ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700' }}">
                                    {{ $row['is_override'] ? __('Active Override') : __('Base Derived') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.pricing.overrides.store', $profile) }}" class="flex items-center justify-end gap-2">
                                    @csrf
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        name="rate_per_gram"
                                        value="{{ old('rate_per_gram', $currentRate ? (float) $currentRate->rate_per_gram : null) }}"
                                        class="field-input max-w-[180px]"
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
    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
        {{ __('Save today\'s base rates first to see resolved per-purity rates and same-day overrides.') }}
    </div>
@endif

<div class="section-divider"></div>
<div class="section-label">{{ __('Legacy Item Review') }}</div>
@if(($pricingData['legacy_items'] ?? collect())->isEmpty())
    <div class="bg-green-50 border border-green-200 text-green-900 rounded-xl p-4 text-sm">
        {{ __('No legacy items need pricing review right now.') }}
    </div>
@else
    <div class="bg-white shadow-sm border border-gray-200 overflow-hidden rounded-xl">
        <div class="overflow-x-auto">
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
                                <form method="POST" action="{{ route('settings.pricing.legacy.resolve', $legacyItem) }}" class="flex items-center gap-2">
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
    <div class="mb-3 rounded-xl border border-gray-200 bg-gray-50/70 p-3">
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
    <div class="bg-white shadow-sm border border-gray-200 overflow-hidden rounded-xl">
        <div class="overflow-x-auto">
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
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $historyRow->is_override ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700' }}">
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
    <div class="bg-gray-50 border border-gray-200 text-gray-700 rounded-xl p-4 text-sm">
        {{ __('No pricing history records matched your filters yet.') }}
    </div>
@endif
</div>
