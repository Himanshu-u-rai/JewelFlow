{{--
    Export panel (frozen §6.1) — pre-filled, scope-editable. Phase 0 ships a
    structural form; the CA-facing visual polish lands in Phase 1 (Sales Register)
    via the design skills. Sensitive toggles are only rendered with permission
    (the server-side gate in ExportRequest is the real enforcement).

    Saved presets (frozen §8; GAP 1): a preset only PRE-FILLS this form via
    ?preset=. The export POST below always re-validates + re-gates, so a preset
    can never bypass a permission. Managing the shop-wide set (save/delete) is
    owner/manager only ($canManagePresets).
--}}
<x-app-layout>
    @php
        $ap = $appliedPreset ?? null;
        $appliedColumns = $ap?->columns ?? [];
        $appliedFilters = $ap?->filters ?? [];
        $appliedDatePreset = $appliedFilters['date_preset'] ?? 'this_month';
        $screenRoute = $definition->key === 'cash-flow' && \Illuminate\Support\Facades\Route::has('report.cash')
            ? route('report.cash')
            : route('reporting.report.screen', ['report' => $definition->key]);
    @endphp

    <x-page-header
        class="report-export-header"
        :title="$definition->title . ' Export'"
        subtitle="Choose period, profile, and file format"
    >
        <x-slot:actions>
            <a href="{{ $screenRoute }}" class="report-export-header-action jf-header-action--neutral" aria-label="Back to report">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M15 18 9 12l6-6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>Back to report</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner report-export-page">
        @if (session('status'))
            <div class="report-export-status">
                {{ session('status') }}
            </div>
        @endif

        <div class="report-export-grid">
            <form method="POST" action="{{ route('reporting.export', ['report' => $definition->key]) }}"
                  data-turbo="false" class="report-export-card report-export-form js-report-export-form">
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
                        <label>Period</label>
                        <div class="report-export-select">
                            <select name="date_preset" class="report-export-input">
                                @foreach ($presets as $preset)
                                    <option value="{{ $preset->value }}" @selected($preset->value === $appliedDatePreset)>
                                        {{ \Illuminate\Support\Str::headline($preset->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="report-export-field">
                        <label>From</label>
                        <input type="date" name="date_from" value="{{ $appliedFilters['date_from'] ?? '' }}" class="report-export-input">
                    </div>
                    <div class="report-export-field">
                        <label>To</label>
                        <input type="date" name="date_to" value="{{ $appliedFilters['date_to'] ?? '' }}" class="report-export-input">
                    </div>

                    <div class="report-export-field">
                        <label>Profile</label>
                        <div class="report-export-select">
                            <select name="profile" class="report-export-input">
                                @foreach ($definition->profiles as $profile)
                                    <option value="{{ $profile->value }}" @selected($ap && $ap->profile === $profile->value)>{{ \Illuminate\Support\Str::headline($profile->value) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="report-export-field">
                        <label>Format</label>
                        <div class="report-export-select">
                            <select name="format" class="report-export-input">
                                @foreach ($definition->formats as $format)
                                    @if ($format->value !== 'screen')
                                        <option value="{{ $format->value }}" @selected($ap && $ap->format === $format->value)>{{ strtoupper($format->value) }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                @if ($canExportSensitive && $definition->hasSensitiveColumns())
                    <label class="report-export-toggle">
                        <input type="checkbox" name="include_sensitive" value="1" @checked(in_array('__sensitive__', $appliedColumns, true))>
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
                    <a href="{{ $screenRoute }}" class="report-export-secondary">Cancel</a>
                    <button type="submit" class="report-export-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
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
                                           data-turbo-frame="_top" class="report-export-small-btn report-export-small-btn--apply">
                                            Apply
                                        </a>
                                        @if ($canManagePresets)
                                            <form method="POST"
                                                  action="{{ route('reporting.presets.destroy', ['report' => $definition->key, 'preset' => $preset->id]) }}"
                                                  data-turbo-frame="_top"
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
                              data-turbo-frame="_top" class="report-export-save-form" x-data>
                            @csrf
                            <div class="report-export-field">
                                <label>Preset name</label>
                                <input type="text" name="name" maxlength="120" required placeholder="Monthly CA Export" class="report-export-input">
                            </div>
                            <input type="hidden" name="profile" x-ref="profile">
                            <input type="hidden" name="format" x-ref="format">
                            <input type="hidden" name="filters[date_preset]" x-ref="datePreset">
                            <input type="hidden" name="filters[date_from]" x-ref="dateFrom">
                            <input type="hidden" name="filters[date_to]" x-ref="dateTo">
                            <button type="submit"
                                    x-on:click="
                                        const form = document.querySelector('.js-report-export-form');
                                        if (form) {
                                            $refs.profile.value = form.querySelector('[name=profile]')?.value ?? '';
                                            $refs.format.value = form.querySelector('[name=format]')?.value ?? '';
                                            $refs.datePreset.value = form.querySelector('[name=date_preset]')?.value ?? '';
                                            $refs.dateFrom.value = form.querySelector('[name=date_from]')?.value ?? '';
                                            $refs.dateTo.value = form.querySelector('[name=date_to]')?.value ?? '';
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

    <style>
        .report-export-header,
        .report-export-page {
            --rx-border: #cbd5e1;
            --rx-border-soft: #e2e8f0;
            --rx-muted: #64748b;
            --rx-ink: #111827;
            --rx-ink-2: #475569;
            --rx-gold: #b45309;
            --rx-gold-hover: #92400e;
            --rx-focus: rgba(245, 158, 11, .2);
            --rx-bg: #f6f7f9;
            --rx-card: #ffffff;
            --rx-nested: #f8fafc;
        }
        .report-export-header {
            border-bottom: 1px solid var(--rx-border-soft);
            background: #ffffff;
            box-shadow: none !important;
        }
        .report-export-header .page-actions {
            margin-left: auto;
        }
        .report-export-header-action {
            min-height: 38px;
            border-radius: 10px;
        }
        .report-export-header-action svg {
            width: 16px !important;
            height: 16px !important;
        }
        .report-export-page {
            display: grid;
            gap: 16px;
            color: var(--rx-ink);
        }
        .report-export-status {
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            background: #f0fdf4;
            padding: 12px 14px;
            color: #047857;
            font-size: 13px;
        }
        .report-export-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(300px, 380px);
            gap: 16px;
            align-items: start;
        }
        .report-export-side {
            display: grid;
            gap: 16px;
        }
        .report-export-card {
            border: 1px solid var(--rx-border-soft);
            border-radius: 14px;
            background: var(--rx-card);
            box-shadow: none !important;
            overflow: hidden;
        }
        .report-export-form,
        .report-export-presets,
        .report-export-save {
            padding: 0;
        }
        .report-export-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--rx-border-soft);
            background: #ffffff;
        }
        .report-export-card-head h2 {
            margin: 0;
            color: var(--rx-ink);
            font-size: 17px;
            font-weight: 650;
            line-height: 1.2;
        }
        .report-export-card-head p {
            margin: 4px 0 0;
            color: var(--rx-muted);
            font-size: 13px;
            line-height: 1.35;
        }
        .report-export-badge {
            display: inline-flex;
            min-height: 28px;
            align-items: center;
            padding: 0 10px;
            border: 1px solid #f3dcb6;
            border-radius: 999px;
            background: #fdf6ec;
            color: #8a4b0f;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: .02em;
        }
        .report-export-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding: 18px;
        }
        .report-export-field--wide {
            grid-column: 1 / -1;
        }
        .report-export-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--rx-ink-2);
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.2;
        }
        .report-export-input {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--rx-border) !important;
            border-radius: 10px;
            background: #ffffff !important;
            color: var(--rx-ink) !important;
            padding: 0 12px;
            font-size: 14px;
            box-shadow: none !important;
        }
        .report-export-input:focus {
            border-color: var(--rx-gold) !important;
            outline: none;
            box-shadow: 0 0 0 3px var(--rx-focus) !important;
        }
        .report-export-select {
            position: relative;
        }
        .report-export-select::after {
            content: "";
            position: absolute;
            top: 50%;
            right: 14px;
            width: 8px;
            height: 8px;
            border-right: 2px solid #64748b;
            border-bottom: 2px solid #64748b;
            transform: translateY(-65%) rotate(45deg);
            pointer-events: none;
        }
        .report-export-select .report-export-input {
            padding-right: 38px;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: none !important;
        }
        .report-export-toggle {
            display: flex;
            align-items: center;
            gap: 11px;
            margin: 0 18px 18px;
            padding: 12px;
            border: 1px solid var(--rx-border-soft);
            border-radius: 12px;
            background: var(--rx-nested);
            color: var(--rx-ink-2);
            cursor: pointer;
            user-select: none;
        }
        .report-export-toggle input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        .report-export-toggle-track {
            position: relative;
            display: inline-flex;
            flex: 0 0 40px;
            width: 40px;
            height: 24px;
            align-items: center;
            border: 1px solid var(--rx-border);
            border-radius: 999px;
            background: #e2e8f0;
        }
        .report-export-toggle-thumb {
            position: absolute;
            left: 3px;
            width: 18px;
            height: 18px;
            border: 1px solid var(--rx-border);
            border-radius: 999px;
            background: #ffffff;
            transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .report-export-toggle input:checked + .report-export-toggle-track {
            border-color: var(--rx-gold);
            background: var(--rx-gold);
        }
        .report-export-toggle input:checked + .report-export-toggle-track .report-export-toggle-thumb {
            border-color: #ffffff;
            transform: translateX(16px);
        }
        .report-export-toggle strong,
        .report-export-toggle small {
            display: block;
        }
        .report-export-toggle strong {
            color: var(--rx-ink);
            font-size: 13px;
            font-weight: 650;
        }
        .report-export-toggle small {
            margin-top: 2px;
            color: var(--rx-muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .report-export-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 18px;
            border-top: 1px solid var(--rx-border-soft);
            background: #ffffff;
        }
        .report-export-primary,
        .report-export-secondary,
        .report-export-small-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: none !important;
            cursor: pointer;
        }
        .report-export-primary {
            border: 1px solid var(--rx-gold);
            background: var(--rx-gold);
            color: #ffffff;
            padding: 0 16px;
        }
        .report-export-primary:hover {
            border-color: var(--rx-gold-hover);
            background: var(--rx-gold-hover);
            color: #ffffff;
        }
        .report-export-secondary {
            border: 1px solid var(--rx-border);
            background: #ffffff;
            color: var(--rx-ink);
            padding: 0 14px;
        }
        .report-export-secondary:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            color: var(--rx-ink);
        }
        .report-export-empty {
            padding: 24px 18px;
            color: var(--rx-muted);
            font-size: 13px;
        }
        .report-export-preset-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .report-export-preset-list li {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 13px 18px;
            border-bottom: 1px solid #edf2f7;
        }
        .report-export-preset-list li:last-child {
            border-bottom: 0;
        }
        .report-export-preset-main {
            min-width: 0;
        }
        .report-export-preset-main p {
            margin: 0;
            color: var(--rx-ink);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.25;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .report-export-preset-main p span {
            display: inline-flex;
            margin-left: 6px;
            min-height: 20px;
            align-items: center;
            padding: 0 7px;
            border-radius: 999px;
            background: #fdf6ec;
            color: #8a4b0f;
            font-size: 10.5px;
        }
        .report-export-preset-main small {
            display: block;
            margin-top: 3px;
            color: var(--rx-muted);
            font-size: 12px;
        }
        .report-export-preset-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .report-export-small-btn {
            min-height: 32px;
            padding: 0 10px;
            font-size: 12px;
        }
        .report-export-small-btn--apply {
            border: 1px solid #f3dcb6;
            background: #fdf6ec;
            color: #8a4b0f;
        }
        .report-export-small-btn--delete {
            border: 1px solid #fecdca;
            background: #fff7f7;
            color: #b42318;
        }
        .report-export-save-form {
            display: grid;
            gap: 12px;
            padding: 18px;
        }
        .report-export-save-btn {
            width: 100%;
        }
        @media (max-width: 1024px) {
            .report-export-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 767px) {
            .content-header.report-export-header {
                flex-wrap: nowrap;
                gap: 8px;
            }
            .report-export-header .content-header-nav {
                margin-right: 0;
            }
            .report-export-header .page-title {
                font-size: 17px;
                line-height: 1.15;
            }
            .report-export-header .page-subtitle {
                display: none;
            }
            .report-export-header .page-actions {
                width: auto;
                flex: 0 0 auto;
            }
            .report-export-header-action {
                width: 36px !important;
                min-width: 36px !important;
                height: 36px !important;
                min-height: 36px !important;
                padding: 0 !important;
            }
            .report-export-header-action span {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
            }
            .report-export-page {
                gap: 12px;
            }
            .report-export-card-head {
                padding: 14px;
            }
            .report-export-card-head h2 {
                font-size: 15px;
            }
            .report-export-card-head p {
                font-size: 12px;
            }
            .report-export-fields {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 14px;
            }
            .report-export-toggle {
                margin: 0 14px 14px;
                align-items: flex-start;
            }
            .report-export-actions {
                display: grid;
                grid-template-columns: 1fr;
                padding: 14px;
            }
            .report-export-primary,
            .report-export-secondary {
                width: 100%;
                min-height: 42px;
            }
            .report-export-preset-list li {
                grid-template-columns: 1fr;
                padding: 13px 14px;
            }
            .report-export-preset-actions {
                justify-content: flex-start;
            }
            .report-export-save-form {
                padding: 14px;
            }
        }
    </style>
</x-app-layout>
