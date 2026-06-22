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
    <x-page-header>
        <div>
            <h1 class="page-title">{{ $definition->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">Export — choose scope, profile, and format.</p>
        </div>
    </x-page-header>

    @php
        $ap = $appliedPreset ?? null;
        $appliedColumns = $ap?->columns ?? [];
        $appliedFilters = $ap?->filters ?? [];
        $appliedDatePreset = $appliedFilters['date_preset'] ?? 'this_month';
    @endphp

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        @if (session('status'))
            <div class="rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Saved presets ──────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-slate-800">Saved presets</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Reusable export settings for your shop.</p>
                </div>
            </div>

            @if ($savedPresets->isEmpty())
                <p class="mt-3 text-sm text-slate-400">No saved presets yet.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($savedPresets as $preset)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800">
                                    {{ $preset->name }}
                                    @if ($ap && $ap->id === $preset->id)
                                        <span class="ml-1 rounded bg-teal-100 px-1.5 py-0.5 text-[11px] font-semibold text-teal-700">Applied</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $preset->profile ? \Illuminate\Support\Str::headline($preset->profile) : 'Default profile' }}
                                    · {{ $preset->format ? strtoupper($preset->format) : 'Any format' }}
                                </p>
                            </div>
                            <div class="flex flex-shrink-0 items-center gap-2">
                                <a href="{{ route('reporting.export.panel', ['report' => $definition->key, 'preset' => $preset->id]) }}"
                                   data-turbo-frame="_top"
                                   class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 hover:bg-teal-100">
                                    Apply
                                </a>
                                @if ($canManagePresets)
                                    <form method="POST"
                                          action="{{ route('reporting.presets.destroy', ['report' => $definition->key, 'preset' => $preset->id]) }}"
                                          data-turbo-frame="_top"
                                          onsubmit="return confirm('Delete preset “{{ $preset->name }}”?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Export form (pre-filled from the applied preset, if any) ─────────── --}}
        <form method="POST" action="{{ route('reporting.export', ['report' => $definition->key]) }}"
              data-turbo-frame="_top" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Period</label>
                <select name="date_preset" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->value }}" @selected($preset->value === $appliedDatePreset)>
                            {{ \Illuminate\Support\Str::headline($preset->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">From (custom)</label>
                    <input type="date" name="date_from" value="{{ $appliedFilters['date_from'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">To (custom)</label>
                    <input type="date" name="date_to" value="{{ $appliedFilters['date_to'] ?? '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Profile</label>
                    <select name="profile" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($definition->profiles as $profile)
                            <option value="{{ $profile->value }}" @selected($ap && $ap->profile === $profile->value)>{{ \Illuminate\Support\Str::headline($profile->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Format</label>
                    <select name="format" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($definition->formats as $format)
                            @if ($format->value !== 'screen')
                                <option value="{{ $format->value }}" @selected($ap && $ap->format === $format->value)>{{ strtoupper($format->value) }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($canExportSensitive && $definition->hasSensitiveColumns())
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="include_sensitive" value="1" @checked(in_array('__sensitive__', $appliedColumns, true)) class="rounded border-slate-300">
                    Include sensitive columns (cost / margin / customer contact)
                </label>
            @endif

            {{-- Carry the applied preset's optional column selection through to the export. --}}
            @foreach ($appliedColumns as $colKey)
                @if ($colKey !== '__sensitive__')
                    <input type="hidden" name="columns[]" value="{{ $colKey }}">
                @endif
            @endforeach

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                    Export
                </button>
            </div>
        </form>

        {{-- Save the current selection as a preset (owner/manager) ───────────── --}}
        @if ($canManagePresets)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-800">Save current as preset</h2>
                <p class="text-xs text-slate-500 mt-0.5">Saves the profile, format, period and columns above for reuse by your shop.</p>
                <form method="POST" action="{{ route('reporting.presets.store', ['report' => $definition->key]) }}"
                      data-turbo-frame="_top" class="mt-3 flex items-end gap-3"
                      x-data
                      @submit="
                        const f = $root.previousElementSibling; // not reliable; we copy from the export form by name below
                      ">
                    @csrf
                    <div class="flex-1">
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Preset name</label>
                        <input type="text" name="name" maxlength="120" required placeholder="e.g. Monthly CA Export"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    {{-- Snapshot the current export-form selection into the preset.
                         These mirror the fields the export form posts; the controller
                         re-validates them against the report definition. --}}
                    <input type="hidden" name="profile" x-ref="profile">
                    <input type="hidden" name="format" x-ref="format">
                    <input type="hidden" name="filters[date_preset]" x-ref="datePreset">
                    <input type="hidden" name="filters[date_from]" x-ref="dateFrom">
                    <input type="hidden" name="filters[date_to]" x-ref="dateTo">
                    <button type="submit"
                            x-on:click="
                                const form = document.querySelector('form[action*=\'/export\']:not([action*=\'export-presets\'])');
                                if (form) {
                                    $refs.profile.value = form.querySelector('[name=profile]')?.value ?? '';
                                    $refs.format.value = form.querySelector('[name=format]')?.value ?? '';
                                    $refs.datePreset.value = form.querySelector('[name=date_preset]')?.value ?? '';
                                    $refs.dateFrom.value = form.querySelector('[name=date_from]')?.value ?? '';
                                    $refs.dateTo.value = form.querySelector('[name=date_to]')?.value ?? '';
                                }
                            "
                            class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900">
                        Save preset
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
