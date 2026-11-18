    @php
        $ap = $appliedPreset ?? null;
        $appliedColumns = $ap?->columns ?? [];
        $appliedFilters = $ap?->filters ?? [];
        // Query string (e.g. the screen's Export link, carrying its active
        // filters) wins over a saved preset, which wins over the bare default —
        // so a user's on-screen selection is never silently lost on export.
        $appliedDatePreset = old('date_preset', request('date_preset', $appliedFilters['date_preset'] ?? 'this_month'));
        $appliedProfile = old('profile', request('profile', $ap->profile ?? null));
        $appliedFormat = old('format', request('format', $ap->format ?? null));
    @endphp

<div class="content-inner report-export-page ir-export">
        @if($errors->any())
            <div class="report-export-status" role="alert"><strong>Please check the export settings.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if (session('status'))
            <div class="report-export-status">
                {{ session('status') }}
            </div>
        @endif

        @if (($readyExports ?? collect())->isNotEmpty())
            <div class="report-export-status report-export-ready" data-testid="ready-exports">
                <strong>Ready to download</strong>
                <ul>
                    @foreach ($readyExports as $note)
                        <li>
                            <a href="{{ $note->data['download_url'] ?? '#' }}" data-turbo="false">{{ \Illuminate\Support\Str::headline($note->data['report_key'] ?? 'Export') }}</a>
                            @if (! empty($note->data['expires_at']))
                                <span>· link expires {{ \Illuminate\Support\Carbon::parse($note->data['expires_at'])->diffForHumans() }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="report-export-grid">
            <form method="POST" action="{{ route('reporting.export', ['report' => $definition->key]) }}"
                  data-report-download data-turbo="false" class="report-export-card report-export-form js-report-export-form"
                  x-data="{ period: {{ \Illuminate\Support\Js::from($appliedDatePreset) }} }">
                @csrf

                <div class="report-export-card-head">
                    <div>
                        <h2>Export setup</h2>
                        <p>{{ $ap ? 'Preset applied: ' . $ap->name : 'Current report scope' }}</p>
                    </div>
                    <span class="report-export-badge">{{ strtoupper($definition->key) }}</span>
                </div>

                <div class="report-export-fields">
                    <div class="report-export-field report-export-field--wide">
                        <label for="export-period">Period</label>
                        <div class="report-export-select">
                            <select id="export-period" name="date_preset" class="report-export-input" x-model="period">
                                @foreach ($presets as $preset)
                                    <option value="{{ $preset->value }}" @selected($preset->value === $appliedDatePreset)>
                                        {{ \Illuminate\Support\Str::headline($preset->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="report-export-field" x-show="period === 'custom'" x-cloak>
                        <label for="export-from">From (Custom period)</label>
                        <input id="export-from" type="date" name="date_from" value="{{ old('date_from', request('date_from', $appliedFilters['date_from'] ?? '')) }}" class="report-export-input" aria-label="From date" data-date-editable>
                    </div>
                    <div class="report-export-field" x-show="period === 'custom'" x-cloak>
                        <label for="export-to">To (Custom period)</label>
                        <input id="export-to" type="date" name="date_to" value="{{ old('date_to', request('date_to', $appliedFilters['date_to'] ?? '')) }}" class="report-export-input" aria-label="To date" data-date-editable>
                    </div>

                    <div class="report-export-field" x-show="period === 'named_fy'" x-cloak>
                        <label for="export-fy">Financial year</label>
                        <input id="export-fy" type="text" name="fy_name" value="{{ old('fy_name', request('fy_name', $appliedFilters['fy_name'] ?? '')) }}" placeholder="e.g. 2025-26" maxlength="16" class="report-export-input" aria-describedby="export-fy-help">
                        <small id="export-fy-help">Required only for Named FY.</small>
                    </div>

                    <div class="report-export-field">
                        <label for="export-profile">Profile</label>
                        <div class="report-export-select">
                            <select id="export-profile" name="profile" class="report-export-input">
                                @foreach ($definition->profiles as $profile)
                                    <option value="{{ $profile->value }}" @selected($appliedProfile === $profile->value)>{{ \Illuminate\Support\Str::headline($profile->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="report-export-field">
                        <label for="export-format">Format</label>
                        <div class="report-export-select">
                            <select id="export-format" name="format" class="report-export-input">
                                @foreach ($definition->formats as $format)
                                    @if ($format->value !== 'screen')
                                        <option value="{{ $format->value }}" @selected($appliedFormat === $format->value)>{{ strtoupper($format->value) }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Same filter controls the screen renders (§A "consistent
                         screen/export filtering") — prefilled from the query
                         string that brought the user here. --}}
                    @foreach (($filterControls ?? []) as $fc)
                        <div class="report-export-field">
                            <label for="export-filter-{{ $fc['key'] }}">{{ $fc['label'] }}</label>
                            @if (($fc['type'] ?? 'select') === 'text')
                                <input id="export-filter-{{ $fc['key'] }}" type="text" name="{{ $fc['key'] }}" value="{{ old($fc['key'], $fc['current']) }}" class="report-export-input" placeholder="Search {{ strtolower($fc['label']) }}…">
                            @else
                                <div class="report-export-select">
                                    <select id="export-filter-{{ $fc['key'] }}" name="{{ $fc['key'] }}" class="report-export-input">
                                        <option value="">All</option>
                                        @foreach ($fc['options'] as $opt)
                                            <option value="{{ $opt['value'] }}" @selected($fc['current'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if (! $definition->classification->isRigid() && $canExportSensitive && $definition->hasSensitiveColumns())
                    <label class="report-export-toggle">
                        <input type="checkbox" name="include_sensitive" value="1" @checked(request()->has('include_sensitive') ? request()->boolean('include_sensitive') : in_array('__sensitive__', $appliedColumns, true))>
                        <span class="report-export-toggle-track" aria-hidden="true">
                            <span class="report-export-toggle-thumb"></span>
                        </span>
                        <span>
                            <strong>Include sensitive columns</strong>
                            <small>Cost, margin, customer contact, or gated fields for this report.</small>
                        </span>
                    </label>
                @endif

                @foreach ($appliedColumns as $colKey)
                    @if ($colKey !== '__sensitive__')
                        <input type="hidden" name="columns[]" value="{{ $colKey }}">
                    @endif
                @endforeach

                <div class="report-export-actions">
                    @if($embedded ?? false)
                        <button type="button" data-export-close class="report-export-secondary">Cancel</button>
                    @else
                        <a href="{{ $screenRoute }}" class="report-export-secondary">Cancel</a>
                    @endif
                    <button type="submit" class="report-export-primary">
                        <x-icon name="arrow-down-to-line" size="16" />
                        Export
                    </button>
                </div>
            </form>

            <aside class="report-export-side">
                <section class="report-export-card report-export-presets">
                    <div class="report-export-card-head">
                        <div>
                            <h2>Saved presets</h2>
                            <p>{{ $savedPresets->count() }} saved</p>
                        </div>
                    </div>

                    @if ($savedPresets->isEmpty())
                        <div class="report-export-empty">No saved presets yet.</div>
                    @else
                        <ul class="report-export-preset-list">
                            @foreach ($savedPresets as $preset)
                                <li>
                                    <div class="report-export-preset-main">
                                        <p>
                                            {{ $preset->name }}
                                            @if ($ap && $ap->id === $preset->id)
                                                <span>Applied</span>
                                            @endif
                                        </p>
                                        <small>
                                            {{ $preset->profile ? \Illuminate\Support\Str::headline($preset->profile) : 'Default profile' }}
                                            &middot; {{ $preset->format ? strtoupper($preset->format) : 'Any format' }}
                                        </small>
                                    </div>
                                    <div class="report-export-preset-actions">
                                        <a href="{{ route('reporting.export.panel', ['report' => $definition->key, 'preset' => $preset->id]) }}"
                                           data-turbo-frame="{{ ($embedded ?? false) ? 'report-export-frame' : '_top' }}" class="report-export-small-btn report-export-small-btn--apply">
                                            Apply
                                        </a>
                                        @if ($canManagePresets)
                                            <form method="POST"
                                                  action="{{ route('reporting.presets.destroy', ['report' => $definition->key, 'preset' => $preset->id]) }}"
                                                  data-turbo-frame="{{ ($embedded ?? false) ? 'report-export-frame' : '_top' }}" data-export-preset
                                                  onsubmit="return confirm('Delete preset {{ $preset->name }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="report-export-small-btn report-export-small-btn--delete">
                                                    Delete
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($canManagePresets)
                    <section class="report-export-card report-export-save">
                        <div class="report-export-card-head">
                            <div>
                                <h2>Save preset</h2>
                                <p>Store this export setup for reuse.</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('reporting.presets.store', ['report' => $definition->key]) }}"
                              data-turbo-frame="{{ ($embedded ?? false) ? 'report-export-frame' : '_top' }}" data-export-preset class="report-export-save-form" x-data>
                            @csrf
                            <div class="report-export-field">
                                <label for="export-preset-name">Preset name</label>
                                <input id="export-preset-name" type="text" name="name" maxlength="120" required placeholder="Monthly CA Export" class="report-export-input">
                            </div>
                            <input type="hidden" name="profile" x-ref="profile">
                            <input type="hidden" name="format" x-ref="format">
                            <input type="hidden" name="filters[date_preset]" x-ref="datePreset">
                            <input type="hidden" name="filters[date_from]" x-ref="dateFrom">
                            <input type="hidden" name="filters[date_to]" x-ref="dateTo">
                            <input type="hidden" name="filters[fy_name]" x-ref="financialYear">
                            <button type="submit"
                                    x-on:click="
                                        const form = document.querySelector('.js-report-export-form');
                                        if (form) {
                                            $refs.profile.value = form.querySelector('[name=profile]')?.value ?? '';
                                            $refs.format.value = form.querySelector('[name=format]')?.value ?? '';
                                            $refs.datePreset.value = form.querySelector('[name=date_preset]')?.value ?? '';
                                            $refs.dateFrom.value = form.querySelector('[name=date_from]')?.value ?? '';
                                            $refs.dateTo.value = form.querySelector('[name=date_to]')?.value ?? '';
                                            $refs.financialYear.value = form.querySelector('[name=fy_name]')?.value ?? '';
                                        }
                                    "
                                    class="report-export-secondary report-export-save-btn">
                                Save preset
                            </button>
                        </form>
                    </section>
                @endif
            </aside>
        </div>
    </div>
