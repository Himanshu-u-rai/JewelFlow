{{--
    Export panel (frozen §6.1) — pre-filled, scope-editable. Phase 0 ships a
    structural form; the CA-facing visual polish lands in Phase 1 (Sales Register)
    via the design skills. Sensitive toggles are only rendered with permission
    (the server-side gate in ExportRequest is the real enforcement).
--}}
<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">{{ $definition->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">Export — choose scope, profile, and format.</p>
        </div>
    </x-page-header>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @if (session('status'))
            <div class="mb-4 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('reporting.export', ['report' => $definition->key]) }}"
              data-turbo-frame="_top" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Period</label>
                <select name="date_preset" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->value }}" @selected($preset->value === 'this_month')>
                            {{ \Illuminate\Support\Str::headline($preset->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">From (custom)</label>
                    <input type="date" name="date_from" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">To (custom)</label>
                    <input type="date" name="date_to" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Profile</label>
                    <select name="profile" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($definition->profiles as $profile)
                            <option value="{{ $profile->value }}">{{ \Illuminate\Support\Str::headline($profile->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Format</label>
                    <select name="format" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($definition->formats as $format)
                            @if ($format->value !== 'screen')
                                <option value="{{ $format->value }}">{{ strtoupper($format->value) }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($canExportSensitive && $definition->hasSensitiveColumns())
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="include_sensitive" value="1" class="rounded border-slate-300">
                    Include sensitive columns (cost / margin / customer contact)
                </label>
            @endif

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                    Export
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
