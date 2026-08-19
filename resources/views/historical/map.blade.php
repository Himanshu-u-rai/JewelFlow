@php
    use App\Models\Historical\HistoricalImportProfile;
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Support\Historical\HistoricalFields;

    $mapped  = $suggestion['mapping'] ?? [];   // canonical field => source header
    $current = fn ($field) => old("mapping.$field", $profile?->sourceColumnFor($field) ?? ($mapped[$field] ?? ''));
    $taxModes = [
        HistoricalSalesDocument::TAX_MODE_EXCLUSIVE     => 'Tax added on top (exclusive)',
        HistoricalSalesDocument::TAX_MODE_INCLUSIVE     => 'Tax already inside total (inclusive)',
        HistoricalSalesDocument::TAX_MODE_UNKNOWN       => 'Unknown',
        HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE => 'Not applicable',
    ];
    $sepLabel = ['.' => 'dot (.)', ',' => 'comma (,)', ' ' => 'space', "'" => "apostrophe (')", '' => 'none'];
@endphp
<x-app-layout>
    <x-page-header title="Confirm column mapping" subtitle="{{ $batch->label }} · {{ $batch->source_file_name }}">
        <x-slot:actions>
            <a href="{{ route('historical.batches.show', $batch) }}" class="btn btn-sm">← Batch</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-map-page">
        <x-app-alerts />

        @include('historical._workflow-steps', ['currentStep' => 'map'])

        @if($error)
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-sm px-4 py-3" role="alert">
                {{ $error }}
            </div>
        @else
        <form method="POST" action="{{ route('historical.batches.map.save', $batch) }}" class="grid gap-5">
            @csrf

            {{-- Profile basics --}}
            <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <legend class="text-base font-semibold text-slate-800 px-1">Profile</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <div>
                        <label for="map_name">Profile name</label>
                        <input type="text" id="map_name" name="name" required aria-required="true" class="w-full"
                               value="{{ old('name', $profile?->name ?? $batch->source_system.' mapping') }}">
                    </div>
                    <div>
                        <label for="map_source_system">Source system</label>
                        <input type="text" id="map_source_system" name="source_system" class="w-full"
                               value="{{ old('source_system', $profile?->source_system ?? $batch->source_system) }}">
                    </div>
                    <div>
                        <label for="map_layout_type">Layout
                            <span class="block text-xs font-normal normal-case tracking-normal text-slate-500">Layout A: one sheet, one row per bill. Layout C: a header sheet (one row per bill) joined to a detail sheet (one row per item line).</span>
                        </label>
                        <select id="map_layout_type" name="layout_type" required aria-required="true" class="w-full">
                            @foreach($layouts as $layout)
                                <option value="{{ $layout }}" @selected(old('layout_type', $profile?->layout_type) === $layout)>
                                    {{ ucfirst(str_replace('_', ' ', $layout)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_header_row">Header row</label>
                        <input type="number" id="map_header_row" name="header_row" min="1" required aria-required="true" class="w-full"
                               value="{{ old('header_row', $profile?->header_row ?? 1) }}">
                    </div>
                    <div>
                        <label for="map_date_format">Date format</label>
                        <select id="map_date_format" name="date_format" required aria-required="true" class="w-full">
                            @foreach($formats as $key => $desc)
                                <option value="{{ $key }}" @selected(old('date_format', $profile?->date_format) === $key)>{{ $desc }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_tax_mode">Tax mode</label>
                        <select id="map_tax_mode" name="tax_mode" required aria-required="true" class="w-full">
                            @foreach($taxModes as $val => $label)
                                <option value="{{ $val }}" @selected(old('tax_mode', $profile?->tax_mode) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_decimal_separator">Decimal separator</label>
                        <select id="map_decimal_separator" name="decimal_separator" required aria-required="true" class="w-full">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('decimal_separator', $profile?->decimal_separator ?? '.') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_thousands_separator">Thousands separator</label>
                        <select id="map_thousands_separator" name="thousands_separator" class="w-full">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('thousands_separator', $profile?->thousands_separator ?? ',') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </fieldset>

            {{-- Sheets. Layout C joins a header sheet to a detail sheet. --}}
            @if(count($sheets) > 0)
                <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                    <legend class="text-base font-semibold text-slate-800 px-1">Sheets</legend>
                    <p class="text-sm text-slate-500 mt-1 mb-2">Workbook sheets: {{ implode(', ', array_column($sheets, 'name')) }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="map_sheet_header">Data / header sheet</label>
                            <select id="map_sheet_header" name="sheets[header]" class="w-full">
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected($headerSheet === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="map_sheet_detail">Detail sheet <span class="text-slate-400 font-normal">(Layout C only)</span></label>
                            <select id="map_sheet_detail" name="sheets[detail]" class="w-full">
                                <option value="" @selected($detailSheet === null)>—</option>
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected($detailSheet === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </fieldset>
            @endif

            {{-- Field mapping, grouped. Suggestions are prefilled but confirmed here. --}}
            @foreach(['Document identity','Customer snapshot','Amounts','Making / labour','Line'] as $group)
                @php
                    $fields = collect(HistoricalFields::HEADER + HistoricalFields::LINE)
                        ->filter(fn ($g) => $g === $group);
                @endphp
                @if($fields->isNotEmpty())
                    @php
                        // Line fields live on the detail sheet (Layout C) or the
                        // one sheet a single-sheet file has (Layout A/B, where
                        // $detailHeaders already falls back to $headers).
                        $role    = $group === 'Line' ? 'detail' : 'header';
                        $options = $group === 'Line' ? $detailHeaders : $headers;
                    @endphp
                    <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                        <legend class="text-base font-semibold text-slate-800 px-1">{{ $group }}</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                            @foreach($fields as $field => $g)
                                <div>
                                    <label for="map_field_{{ $field }}">{{ str_replace('_', ' ', $field) }}
                                        @if(in_array($field, HistoricalFields::REQUIRED, true))<span class="text-rose-600">*</span>@endif
                                    </label>
                                    <select id="map_field_{{ $field }}" name="mapping[{{ $field }}]" class="js-mapping-field w-full" data-sheet-role="{{ $role }}">
                                        <option value="">— unmapped —</option>
                                        @foreach($options as $header)
                                            <option value="{{ $header }}" @selected($current($field) === $header)>{{ $header }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            @endforeach

            {{-- Layout C join key columns. --}}
            <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <legend class="text-base font-semibold text-slate-800 px-1">Link column <span class="text-slate-400 font-normal text-sm">(Layout C only)</span></legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    <div>
                        <label for="map_join_header">Header sheet key</label>
                        <select id="map_join_header" name="mapping[{{ HistoricalFields::JOIN_KEY }}]" class="js-mapping-field w-full" data-sheet-role="header">
                            <option value="">—</option>
                            @foreach($headers as $header)
                                <option value="{{ $header }}" @selected($current(HistoricalFields::JOIN_KEY) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_join_detail">Detail sheet key</label>
                        <select id="map_join_detail" name="mapping[{{ HistoricalFields::DETAIL_JOIN_KEY }}]" class="js-mapping-field w-full" data-sheet-role="detail">
                            <option value="">—</option>
                            @foreach($detailHeaders as $header)
                                <option value="{{ $header }}" @selected($current(HistoricalFields::DETAIL_JOIN_KEY) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </fieldset>

            {{-- Unmapped columns must be a decision, never a silent drop (Phase 9). --}}
            @if($suggestion['unmapped'] ?? [])
                <fieldset class="rounded-2xl border border-amber-300 bg-white p-4 sm:p-6">
                    <legend class="text-base font-semibold text-slate-800 px-1">Unrecognized columns</legend>
                    <p class="text-sm text-slate-500 mt-1 mb-2">Map these above, or mark each as ignored or informational — nothing is silently dropped.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($suggestion['unmapped'] as $header)
                            <div>
                                <label for="map_decision_{{ $loop->index }}">{{ $header }}</label>
                                <select id="map_decision_{{ $loop->index }}" name="column_decisions[{{ $header }}]" class="w-full">
                                    <option value="{{ HistoricalImportProfile::DECISION_IGNORED }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_IGNORED)>Ignore</option>
                                    <option value="{{ HistoricalImportProfile::DECISION_INFORMATIONAL }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_INFORMATIONAL)>Keep (informational)</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            {{-- Making / labour defaults + zero-tax confirmation. --}}
            <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <legend class="text-base font-semibold text-slate-800 px-1">Making / labour charge defaults</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                    <div>
                        <label for="map_making_category">Category</label>
                        <select id="map_making_category" name="making_defaults[category]" class="w-full">
                            <option value="">—</option>
                            @foreach($makingCategories as $cat)
                                <option value="{{ $cat }}" @selected(($profile?->making_defaults['category'] ?? '') === $cat)>{{ str_replace('_', ' ', $cat) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="map_making_basis">Basis</label>
                        <select id="map_making_basis" name="making_defaults[basis]" class="w-full">
                            <option value="">—</option>
                            @foreach($makingBases as $b)
                                <option value="{{ $b }}" @selected(($profile?->making_defaults['basis'] ?? '') === $b)>{{ str_replace('_', ' ', $b) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 min-h-[44px] mt-2" for="map_zero_confirmed">
                    <input type="hidden" name="tax_defaults[zero_confirmed]" value="0">
                    <input type="checkbox" id="map_zero_confirmed" name="tax_defaults[zero_confirmed]" value="1" @checked($profile?->tax_defaults['zero_confirmed'] ?? false)>
                    <span class="font-normal normal-case tracking-normal text-sm text-slate-700">A blank/zero tax column means genuinely no tax (not applicable), not missing data.</span>
                </label>
            </fieldset>

            {{-- Sample rows so the operator sees what they're mapping. --}}
            @if($samples ?? [])
                <fieldset class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                    <legend class="text-base font-semibold text-slate-800 px-1">Sample rows</legend>
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left">@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach($samples as $sample)
                                <tr>@foreach($headers as $h)<td>{{ data_get($sample, $h) }}</td>@endforeach</tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </fieldset>
            @endif

            {{-- Reachable, not sticky: this form is long, so the save action is
                 repeated at the very top of the workflow-steps bar's scroll
                 position is not guaranteed — instead it stays a normal
                 in-flow primary button, which every other historical form uses. --}}
            <div><button class="btn btn-primary" type="submit">Save mapping &amp; normalize</button></div>
        </form>

        <script>
        (function () {
            // Headers for every sheet in the workbook, keyed by sheet name.
            // Lets the "header sheet" / "detail sheet" pickers swap every
            // dependent mapping dropdown's options instantly, no round trip.
            var headersBySheet = @json($headersBySheet ?? []);

            function refresh(role, sheetName) {
                var headers = headersBySheet[sheetName] || [];
                document.querySelectorAll('.js-mapping-field[data-sheet-role="' + role + '"]').forEach(function (select) {
                    var previous = select.value;
                    // Keep the select's own first option ("— unmapped —" or
                    // "—") and rebuild everything after it.
                    while (select.options.length > 1) {
                        select.remove(1);
                    }
                    headers.forEach(function (header) {
                        var option = document.createElement('option');
                        option.value = header;
                        option.textContent = header; // never innerHTML: header text is untrusted workbook data
                        select.appendChild(option);
                    });
                    if (headers.indexOf(previous) !== -1) {
                        select.value = previous;
                    }
                });
            }

            var headerSheetSelect = document.querySelector('select[name="sheets[header]"]');
            var detailSheetSelect = document.querySelector('select[name="sheets[detail]"]');

            if (headerSheetSelect) {
                headerSheetSelect.addEventListener('change', function () {
                    refresh('header', this.value);
                });
            }
            if (detailSheetSelect) {
                detailSheetSelect.addEventListener('change', function () {
                    refresh('detail', this.value);
                });
            }
        })();
        </script>
        @endif
    </div>
</x-app-layout>
