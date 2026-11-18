<form method="GET" action="{{ url()->current() }}" class="ir-filters"
      data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
      x-data="{ periodChoice: @js(old('date_preset', $periodPreset)), dirty: false, hasFilters: @js($hasFilters) }"
      @input="dirty = true" @change="dirty = true">
    <div class="ir-field">
        <label for="report-period">Period</label>
        <select id="report-period" name="date_preset" x-model="periodChoice" class="ir-control">
            @foreach($presets as $preset)
                <option value="{{ $preset->value }}" @selected($periodPreset === $preset->value)>{{ Str::headline($preset->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="ir-field" x-show="periodChoice === 'custom'">
        <label for="report-from">From date</label>
        <input id="report-from" type="date" name="date_from" aria-label="From date" data-date-editable class="ir-control"
               value="{{ old('date_from', $period->from->toDateString()) }}" :required="periodChoice === 'custom'">
    </div>
    <div class="ir-field" x-show="periodChoice === 'custom'">
        <label for="report-to">To date</label>
        <input id="report-to" type="date" name="date_to" aria-label="To date" data-date-editable class="ir-control"
               value="{{ old('date_to', $period->to->toDateString()) }}" :required="periodChoice === 'custom'">
    </div>
    <div class="ir-field" x-show="periodChoice === 'named_fy'">
        <label for="report-fy">Financial year</label>
        <input id="report-fy" type="text" name="fy_name" class="ir-control" value="{{ old('fy_name', request('fy_name', $period->fyName)) }}"
               placeholder="e.g. 2025-26" maxlength="16" :disabled="periodChoice !== 'named_fy'" :required="periodChoice === 'named_fy'">
    </div>
    @foreach($filterControls ?? [] as $fc)
        <div class="ir-field">
            <label for="report-filter-{{ $fc['key'] }}">{{ $fc['label'] }}</label>
            @if(($fc['type'] ?? 'select') === 'text')
                <input id="report-filter-{{ $fc['key'] }}" type="search" name="{{ $fc['key'] }}" value="{{ $fc['current'] }}" class="ir-control" placeholder="Search {{ strtolower($fc['label']) }}…">
            @else
                <select id="report-filter-{{ $fc['key'] }}" name="{{ $fc['key'] }}" class="ir-control">
                    <option value="">All</option>
                    @foreach($fc['options'] as $opt)
                        <option value="{{ $opt['value'] }}" @selected($fc['current'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    @endforeach
    @unless($isRigid)
        <div class="ir-field">
            <label for="report-profile">Columns</label>
            <select id="report-profile" name="profile" class="ir-control">
                @foreach($definition->profiles as $p)
                    <option value="{{ $p->value }}" @selected($profile->value === $p->value)>{{ Str::headline($p->value) }}</option>
                @endforeach
            </select>
        </div>
        @if($canExportSensitive && $definition->hasSensitiveColumns())
            <label class="ir-check"><input type="checkbox" name="include_sensitive" value="1" @checked(request()->boolean('include_sensitive'))> Include sensitive columns</label>
        @endif
    @endunless
    <div class="ir-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button>
        <a href="{{ url()->current() }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a>
        @if($hasFilters)<noscript><a href="{{ url()->current() }}" class="btn btn-secondary btn-sm">Clear</a></noscript>@endif
    </div>
</form>
