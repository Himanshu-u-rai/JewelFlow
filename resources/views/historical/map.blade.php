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
    <div class="page" style="padding:1rem;max-width:1000px;margin:0 auto;">
        <x-app-alerts />

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1 style="margin:0;">Confirm column mapping</h1>
            <a href="{{ route('historical.batches.show', $batch) }}">← Batch</a>
        </div>
        <p style="color:#475569;">{{ $batch->label }} · {{ $batch->source_file_name }}</p>

        @if($error)
            <div style="border:1px solid #fca5a5;background:#fef2f2;color:#b91c1c;padding:.75rem 1rem;border-radius:8px;">
                {{ $error }}
            </div>
        @else
        <form method="POST" action="{{ route('historical.batches.map.save', $batch) }}" style="display:grid;gap:1.25rem;margin-top:1rem;">
            @csrf

            {{-- Profile basics --}}
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Profile</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                    <label>Profile name
                        <input type="text" name="name" required style="width:100%;"
                               value="{{ old('name', $profile?->name ?? $batch->source_system.' mapping') }}">
                    </label>
                    <label>Source system
                        <input type="text" name="source_system" style="width:100%;"
                               value="{{ old('source_system', $profile?->source_system ?? $batch->source_system) }}">
                    </label>
                    <label>Layout
                        <select name="layout_type" required style="width:100%;">
                            @foreach($layouts as $layout)
                                <option value="{{ $layout }}" @selected(old('layout_type', $profile?->layout_type) === $layout)>
                                    {{ ucfirst(str_replace('_', ' ', $layout)) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>Header row
                        <input type="number" name="header_row" min="1" required style="width:100%;"
                               value="{{ old('header_row', $profile?->header_row ?? 1) }}">
                    </label>
                    <label>Date format
                        <select name="date_format" required style="width:100%;">
                            @foreach($formats as $key => $desc)
                                <option value="{{ $key }}" @selected(old('date_format', $profile?->date_format) === $key)>{{ $desc }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Tax mode
                        <select name="tax_mode" required style="width:100%;">
                            @foreach($taxModes as $val => $label)
                                <option value="{{ $val }}" @selected(old('tax_mode', $profile?->tax_mode) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Decimal separator
                        <select name="decimal_separator" required style="width:100%;">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('decimal_separator', $profile?->decimal_separator ?? '.') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Thousands separator
                        <select name="thousands_separator" style="width:100%;">
                            @foreach(HistoricalImportProfile::SEPARATORS as $s)
                                <option value="{{ $s }}" @selected(old('thousands_separator', $profile?->thousands_separator ?? ',') === $s)>{{ $sepLabel[$s] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </fieldset>

            {{-- Sheets. Layout C joins a header sheet to a detail sheet. --}}
            @if(count($sheets) > 0)
                <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                    <legend>Sheets</legend>
                    <p style="color:#64748b;margin-top:0;">Workbook sheets: {{ implode(', ', array_column($sheets, 'name')) }}</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                        <label>Data / header sheet
                            <select name="sheets[header]" style="width:100%;">
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected(old('sheets.header', $profile?->sheetFor('header')) === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Detail sheet (Layout C only)
                            <select name="sheets[detail]" style="width:100%;">
                                <option value="">—</option>
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet['name'] }}" @selected(old('sheets.detail', $profile?->sheetFor('detail')) === $sheet['name'])>{{ $sheet['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
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
                    <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                        <legend>{{ $group }}</legend>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                            @foreach($fields as $field => $g)
                                <label>{{ str_replace('_', ' ', $field) }}
                                    @if(in_array($field, HistoricalFields::REQUIRED, true))<span style="color:#b91c1c;">*</span>@endif
                                    <select name="mapping[{{ $field }}]" style="width:100%;">
                                        <option value="">— unmapped —</option>
                                        @foreach($headers as $header)
                                            <option value="{{ $header }}" @selected($current($field) === $header)>{{ $header }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            @endforeach

            {{-- Layout C join key columns. --}}
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Link column (Layout C)</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                    <label>Header sheet key
                        <select name="mapping[{{ HistoricalFields::JOIN_KEY }}]" style="width:100%;">
                            <option value="">—</option>
                            @foreach($headers as $header)
                                <option value="{{ $header }}" @selected($current(HistoricalFields::JOIN_KEY) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Detail sheet key
                        <select name="mapping[{{ HistoricalFields::DETAIL_JOIN_KEY }}]" style="width:100%;">
                            <option value="">—</option>
                            @foreach($headers as $header)
                                <option value="{{ $header }}" @selected($current(HistoricalFields::DETAIL_JOIN_KEY) === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </fieldset>

            {{-- Unmapped columns must be a decision, never a silent drop (Phase 9). --}}
            @if($suggestion['unmapped'] ?? [])
                <fieldset style="border:1px solid #fcd34d;border-radius:8px;padding:1rem;">
                    <legend>Unrecognized columns</legend>
                    <p style="color:#64748b;margin-top:0;">Map these above, or mark each as ignored or informational — nothing is silently dropped.</p>
                    @foreach($suggestion['unmapped'] as $header)
                        <label style="display:block;margin-bottom:.35rem;">{{ $header }}
                            <select name="column_decisions[{{ $header }}]" style="margin-left:.5rem;">
                                <option value="{{ HistoricalImportProfile::DECISION_IGNORED }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_IGNORED)>Ignore</option>
                                <option value="{{ HistoricalImportProfile::DECISION_INFORMATIONAL }}" @selected(($profile?->column_decisions[$header] ?? '') === HistoricalImportProfile::DECISION_INFORMATIONAL)>Keep (informational)</option>
                            </select>
                        </label>
                    @endforeach
                </fieldset>
            @endif

            {{-- Making / labour defaults + zero-tax confirmation. --}}
            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                <legend>Defaults</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                    <label>Making / labour category
                        <select name="making_defaults[category]" style="width:100%;">
                            <option value="">—</option>
                            @foreach($makingCategories as $cat)
                                <option value="{{ $cat }}" @selected(($profile?->making_defaults['category'] ?? '') === $cat)>{{ str_replace('_', ' ', $cat) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Making / labour basis
                        <select name="making_defaults[basis]" style="width:100%;">
                            <option value="">—</option>
                            @foreach($makingBases as $b)
                                <option value="{{ $b }}" @selected(($profile?->making_defaults['basis'] ?? '') === $b)>{{ str_replace('_', ' ', $b) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label style="display:block;margin-top:.5rem;">
                    <input type="hidden" name="tax_defaults[zero_confirmed]" value="0">
                    <input type="checkbox" name="tax_defaults[zero_confirmed]" value="1" @checked($profile?->tax_defaults['zero_confirmed'] ?? false)>
                    A blank/zero tax column means genuinely no tax (not applicable), not missing data.
                </label>
            </fieldset>

            {{-- Sample rows so the operator sees what they're mapping. --}}
            @if($samples ?? [])
                <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                    <legend>Sample rows</legend>
                    <div style="overflow-x:auto;">
                        <table class="data-table" style="border-collapse:collapse;">
                            <thead><tr>@foreach($headers as $h)<th style="text-align:left;">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody>
                            @foreach($samples as $sample)
                                <tr>@foreach($headers as $h)<td>{{ data_get($sample, $h) }}</td>@endforeach</tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </fieldset>
            @endif

            <div><button class="btn btn-primary" type="submit">Save mapping &amp; normalize</button></div>
        </form>
        @endif
    </div>
</x-app-layout>
