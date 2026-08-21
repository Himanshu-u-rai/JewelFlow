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
            <a href="{{ route('historical.batches.show', $batch) }}"
               class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:border-amber-400 hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
               data-historical-back>
                <svg class="h-4 w-4 shrink-0 text-amber-700" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12.5 5 7.5 10l5 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Back to batch</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-map-page" style="--app-control-bg: #ffffff; --app-control-border: #cbd5e1; --app-control-border-focus: #b45309;">
        <x-app-alerts />

        @include('historical._workflow-steps', ['currentStep' => 'map'])

        <section class="rounded-2xl border border-slate-200 bg-white px-4 py-4 sm:px-6" data-historical-map-intro>
            <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Step 2 of 4</p>
                    <h2 class="mt-1 text-base font-semibold text-slate-900">Match the source columns</h2>
                    <p class="mt-1 text-sm text-slate-500">Confirm the import profile first, then work through each data group. Unmapped columns remain visible for an explicit decision.</p>
                </div>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Nothing publishes here</span>
            </div>
        </section>

        @if($error)
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 text-sm px-4 py-3" role="alert">
                {{ $error }}
            </div>
        @else
        <form method="POST" action="{{ route('historical.batches.map.save', $batch) }}" class="grid gap-4 min-w-0 max-w-full" data-historical-form="mapping">
            @csrf

            {{-- Profile basics --}}
            <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                <legend class="sr-only">Profile</legend>
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                    <h2 class="text-base font-semibold text-slate-900">Import profile</h2>
                    <p class="mt-1 text-sm text-slate-500">Describe how this file stores dates, tax and number formatting.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 sm:p-6 min-w-0 max-w-full">
                    <div class="min-w-0 max-w-full">
                        <label for="map_name" class="min-w-0 max-w-full">Profile name</label>
                        <input type="text" id="map_name" name="name" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg"
                               value="{{ old('name', $profile?->name ?? $batch->source_system.' mapping') }}">
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_source_system" class="min-w-0 max-w-full">Source system</label>
                        <input type="text" id="map_source_system" name="source_system" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg"
                               value="{{ old('source_system', $profile?->source_system ?? $batch->source_system) }}">
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_layout_type" class="min-w-0 max-w-full">Layout
                            <span class="block text-xs font-normal normal-case tracking-normal text-slate-500">Layout A: one sheet, one row per bill. Layout C: a header sheet (one row per bill) joined to a detail sheet (one row per item line).</span>
                        </label>
                        <select id="map_layout_type" name="layout_type" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            @foreach($layouts as $layout)
                                <option value="{{ $layout }}" @selected(old('layout_type', $profile?->layout_type) === $layout)>
                                    {{ ucfirst(str_replace('_', ' ', $layout)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_header_row" class="min-w-0 max-w-full">Header row</label>
                        <input type="number" id="map_header_row" name="header_row" min="1" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg"
                               value="{{ old('header_row', $profile?->header_row ?? 1) }}">
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_date_format" class="min-w-0 max-w-full">Date format</label>
                        <select id="map_date_format" name="date_format" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            @foreach($formats as $key => $desc)
                                <option value="{{ $key }}" @selected(old('date_format', $profile?->date_format) === $key)>{{ $desc }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_tax_mode" class="min-w-0 max-w-full">Tax mode</label>
                        <select id="map_tax_mode" name="tax_mode" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            @foreach($taxModes as $val => $label)
                                <option value="{{ $val }}" @selected(old('tax_mode', $profile?->tax_mode) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_decimal_separator" class="min-w-0 max-w-full">Decimal separator</label>
                        <select id="map_decimal_separator" name="decimal_separator" required aria-required="true" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('decimal_separator', $profile?->decimal_separator ?? '.') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_thousands_separator" class="min-w-0 max-w-full">Thousands separator</label>
                        <select id="map_thousands_separator" name="thousands_separator" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('thousands_separator', $profile?->thousands_separator ?? ',') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </fieldset>

            {{-- Sheets. Layout C joins a header sheet to a detail sheet. --}}
            @if(count($sheets) > 0)
                <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                    <legend class="sr-only">Sheets</legend>
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                        <h2 class="text-base font-semibold text-slate-900">Workbook sheets</h2>
                        <p class="mt-1 text-sm text-slate-500">Choose the sheets containing bill headers and item details.</p>
                    </div>
                    <div class="p-4 sm:p-6 min-w-0 max-w-full">
                    <p class="text-sm text-slate-500 mb-4">Workbook sheets: {{ implode(', ', array_column($sheets, 'name')) }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 min-w-0 max-w-full">
                        <div class="min-w-0 max-w-full">
                            <label for="map_sheet_header" class="min-w-0 max-w-full">Data / header sheet</label>
                            <select id="map_sheet_header" name="sheets[header]" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected($headerSheet === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="min-w-0 max-w-full">
                            <label for="map_sheet_detail" class="min-w-0 max-w-full">Detail sheet <span class="text-slate-400 font-normal">(Layout C only)</span></label>
                            <select id="map_sheet_detail" name="sheets[detail]" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                                <option value="" @selected($detailSheet === null)>—</option>
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected($detailSheet === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
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
                    <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                        <legend class="sr-only">{{ $group }}</legend>
                        <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                            <h2 class="text-base font-semibold text-slate-900">{{ $group }}</h2>
                            <p class="mt-1 text-sm text-slate-500">Select the source column for each JewelFlows field.</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 sm:p-6 min-w-0 max-w-full">
                            @foreach($fields as $field => $g)
                                <div class="min-w-0 max-w-full">
                                    <label for="map_field_{{ $field }}" class="min-w-0 max-w-full">{{ str_replace('_', ' ', $field) }}
                                        @if(in_array($field, HistoricalFields::REQUIRED, true))<span class="text-rose-600">*</span>@endif
                                    </label>
                                    <select id="map_field_{{ $field }}" name="mapping[{{ $field }}]" class="js-mapping-field w-full min-w-0 max-w-full min-h-[44px] rounded-lg" data-sheet-role="{{ $role }}">
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
            <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                <legend class="sr-only">Link column (Layout C only)</legend>
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                    <h2 class="text-base font-semibold text-slate-900">Link column <span class="text-sm font-normal text-slate-400">(Layout C only)</span></h2>
                    <p class="mt-1 text-sm text-slate-500">Match the shared key that connects each bill header to its item lines.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 sm:p-6 min-w-0 max-w-full">
                    <div class="min-w-0 max-w-full">
                        <label for="map_join_header" class="min-w-0 max-w-full">Header sheet key</label>
                        <select id="map_join_header" name="mapping[{{ HistoricalFields::JOIN_KEY }}]" class="js-mapping-field w-full min-w-0 max-w-full min-h-[44px] rounded-lg" data-sheet-role="header">
                            <option value="">—</option>
                            @foreach($headers as $header)
                                <option value="{{ $header }}" @selected($current(HistoricalFields::JOIN_KEY) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_join_detail" class="min-w-0 max-w-full">Detail sheet key</label>
                        <select id="map_join_detail" name="mapping[{{ HistoricalFields::DETAIL_JOIN_KEY }}]" class="js-mapping-field w-full min-w-0 max-w-full min-h-[44px] rounded-lg" data-sheet-role="detail">
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
                <fieldset class="min-w-0 max-w-full rounded-2xl border border-amber-300 bg-white overflow-hidden" data-historical-form-section>
                    <legend class="sr-only">Unrecognized columns</legend>
                    <div class="border-b border-amber-200 bg-amber-50 px-4 py-4 sm:px-6" data-historical-card-header>
                        <h2 class="text-base font-semibold text-amber-900">Unrecognized columns</h2>
                        <p class="mt-1 text-sm text-amber-800">Nothing is silently dropped—choose how each remaining column should be handled.</p>
                    </div>
                    <div class="p-4 sm:p-6 min-w-0 max-w-full">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 min-w-0 max-w-full">
                        @foreach($suggestion['unmapped'] as $header)
                            <div class="min-w-0 max-w-full">
                                <label for="map_decision_{{ $loop->index }}" class="min-w-0 max-w-full">{{ $header }}</label>
                                <select id="map_decision_{{ $loop->index }}" name="column_decisions[{{ $header }}]" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                                    <option value="{{ HistoricalImportProfile::DECISION_IGNORED }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_IGNORED)>Ignore</option>
                                    <option value="{{ HistoricalImportProfile::DECISION_INFORMATIONAL }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_INFORMATIONAL)>Keep (informational)</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                    </div>
                </fieldset>
            @endif

            {{-- Making / labour defaults + zero-tax confirmation. --}}
            <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                <legend class="sr-only">Making / labour charge defaults</legend>
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                    <h2 class="text-base font-semibold text-slate-900">Making / labour defaults</h2>
                    <p class="mt-1 text-sm text-slate-500">Set the interpretation used when the source file does not provide it explicitly.</p>
                </div>
                <div class="p-4 sm:p-6 min-w-0 max-w-full">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 min-w-0 max-w-full">
                    <div class="min-w-0 max-w-full">
                        <label for="map_making_category" class="min-w-0 max-w-full">Category</label>
                        <select id="map_making_category" name="making_defaults[category]" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            <option value="">—</option>
                            @foreach($makingCategories as $cat)
                                <option value="{{ $cat }}" @selected(($profile?->making_defaults['category'] ?? '') === $cat)>{{ str_replace('_', ' ', $cat) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 max-w-full">
                        <label for="map_making_basis" class="min-w-0 max-w-full">Basis</label>
                        <select id="map_making_basis" name="making_defaults[basis]" class="w-full min-w-0 max-w-full min-h-[44px] rounded-lg">
                            <option value="">—</option>
                            @foreach($makingBases as $b)
                                <option value="{{ $b }}" @selected(($profile?->making_defaults['basis'] ?? '') === $b)>{{ str_replace('_', ' ', $b) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 min-h-[44px] mt-2 min-w-0 max-w-full" for="map_zero_confirmed">
                    <input type="hidden" name="tax_defaults[zero_confirmed]" value="0">
                    <input type="checkbox" id="map_zero_confirmed" name="tax_defaults[zero_confirmed]" value="1" @checked($profile?->tax_defaults['zero_confirmed'] ?? false)>
                    <span class="font-normal normal-case tracking-normal text-sm text-slate-700">A blank/zero tax column means genuinely no tax (not applicable), not missing data.</span>
                </label>
                </div>
            </fieldset>

            {{-- Sample rows so the operator sees what they're mapping. --}}
            @if($samples ?? [])
                <fieldset class="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-form-section>
                    <legend class="sr-only">Sample rows</legend>
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6" data-historical-card-header>
                        <h2 class="text-base font-semibold text-slate-900">Source preview</h2>
                        <p class="mt-1 text-sm text-slate-500">A read-only sample from the uploaded file for checking your choices.</p>
                    </div>
                    <div class="p-4 sm:p-6 min-w-0 max-w-full">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left">@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach($samples as $sample)
                                <tr>@foreach($headers as $h)<td>{{ data_get($sample, $h) }}</td>@endforeach</tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    </div>
                </fieldset>
            @endif

            {{-- Reachable, not sticky: this form is long, so the save action is
                 repeated at the very top of the workflow-steps bar's scroll
                 position is not guaranteed — instead it stays a normal
                 in-flow primary button, which every other historical form uses. --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-historical-card-footer>
                <p class="text-xs text-slate-500">Saving validates the mapping and prepares a review batch. It does not publish documents.</p>
                <button class="btn btn-primary min-h-[44px]" type="submit">Save mapping &amp; normalize</button>
            </div>
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
