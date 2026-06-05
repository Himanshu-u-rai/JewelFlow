{{-- Sales / Invoice Register — interactive screen (Phase 1 pilot).
     Consumes the same canonical dataset as the exports; totals match the PDF. --}}
<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">{{ $definition->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $view['meta']->periodLabel }} · {{ $view['meta']->profileLabel }}</p>
        </div>
        <x-slot:actions>
            <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}"
               class="inline-flex items-center rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                Export
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        {{-- Scope toolbar --}}
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Period</label>
                <select name="date_preset" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->value }}" @selected(request('date_preset', 'this_month') === $preset->value)>
                            {{ \Illuminate\Support\Str::headline($preset->value) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Profile</label>
                <select name="profile" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($definition->profiles as $p)
                        <option value="{{ $p->value }}" @selected($profile->value === $p->value)>{{ \Illuminate\Support\Str::headline($p->value) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canExportSensitive && $definition->hasSensitiveColumns())
                <label class="flex items-center gap-2 text-sm text-slate-700 pb-2">
                    <input type="checkbox" name="include_sensitive" value="1" class="rounded border-slate-300" @checked(request()->boolean('include_sensitive'))>
                    Sensitive columns
                </label>
            @endif
            <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Apply</button>
        </form>

        @foreach ($view['sections'] as $section)
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">
                    {{ $section['title'] }} <span class="text-slate-400 font-normal">· {{ $section['rowCount'] }} invoice(s)</span>
                </div>
                @if ($section['rowCount'] === 0)
                    <div class="px-5 py-10 text-center text-sm text-slate-400">No invoices for this scope.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    @foreach ($section['columns'] as $col)
                                        <th class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-600 {{ $col['numeric'] ? 'text-right' : 'text-left' }}">{{ $col['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($section['rows'] as $row)
                                    <tr class="hover:bg-slate-50">
                                        @foreach ($row as $cell)
                                            <td class="px-4 py-2.5 text-slate-800 {{ $cell['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">{{ $cell['display'] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($section['hasTotals'])
                                <tfoot class="bg-slate-50 font-semibold">
                                    <tr>
                                        @foreach ($section['columns'] as $col)
                                            <td class="px-4 py-2.5 text-slate-900 {{ $col['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">
                                                {{ $section['totals'][$col['key']] ?? ($loop->first ? 'Total' : '') }}
                                            </td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-app-layout>
