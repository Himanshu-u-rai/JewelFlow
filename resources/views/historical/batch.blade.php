@php
    use App\Models\Historical\HistoricalImportBatch;
    use App\Services\Historical\HistoricalDuplicateDetector;
    use App\Support\Historical\HistoricalMessages;

    $sev = fn ($s) => match ($s) {
        HistoricalMessages::ERROR   => ['#b91c1c', 'Blocking'],
        HistoricalMessages::WARNING => ['#b45309', 'Warning'],
        default                     => ['#475569', 'Info'],
    };

    // ponytail: duplicate review is built from the current rows page only.
    // Release 1 pages at 100 rows; a batch with duplicates past page 1 is rare
    // and the operator can page to it. Revisit if real files need cross-page review.
    $dupGroups = collect($rows->items())
        ->filter(fn ($r) => collect(json_decode($r->messages ?? '[]', true) ?: [])
            ->contains(fn ($m) => in_array($m['code'] ?? '', [
                HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
            ], true)))
        ->groupBy('grouping_key');
@endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:1100px;margin:0 auto;">
        <x-app-alerts />

        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <div style="display:flex;align-items:center;gap:.5rem;">
                    <span class="badge">{{ ucfirst($batch->status) }}</span>
                    <h1 style="margin:0;">{{ $batch->label }}</h1>
                </div>
                <p style="color:#475569;margin:.25rem 0 0;">
                    {{ $batch->source_system ?? 'Manual' }}
                    @if($batch->source_file_name) · {{ $batch->source_file_name }} @endif
                    @if($batch->profile) · profile “{{ $batch->profile->name }}” @endif
                    @if($batch->layout_type) · {{ $batch->layout_type }} @endif
                    @if($batch->date_format) · dates {{ $batch->date_format }} @endif
                </p>
            </div>
            <a href="{{ route('historical.index') }}">← All historical sales</a>
        </div>

        {{-- Blocking messages carried back from a failed manual save / import. --}}
        @if(session('historical_messages'))
            <div style="border:1px solid #fca5a5;background:#fef2f2;padding:.75rem 1rem;border-radius:8px;margin-top:1rem;">
                @foreach(session('historical_messages') as $m)
                    @php
                        [$severityColor, $severityLabel] = $sev($m['severity'] ?? 'info');
                    @endphp
                    <div style="color:{{ $severityColor }};"><strong>{{ $severityLabel }}:</strong> {{ $m['text'] }}</div>
                @endforeach
            </div>
        @endif

        @if($batch->preview_generated_at === null)
            <p style="color:#64748b;margin-top:1rem;">
                No reconciliation preview yet. Confirm the column mapping to normalize this batch.
            </p>
            @can('historical.import')
                @if($batch->source_file_name)
                    <a class="btn btn-primary" href="{{ route('historical.batches.map', $batch) }}">Map columns</a>
                @endif
            @endcan
        @else
            <h2 style="margin-top:1.5rem;">Reconciliation preview</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;">
                @php
                    $tiles = [
                        'Rows'              => $preview['row_count'] ?? 0,
                        'Documents'         => $preview['document_count'] ?? 0,
                        'Lines'             => $preview['line_count'] ?? 0,
                        'Header-only'       => $preview['header_only_count'] ?? 0,
                        'Customers'         => $preview['customer_count'] ?? 0,
                        'Duplicates'        => $preview['duplicate_count'] ?? 0,
                        'Blocking errors'   => $preview['blocking_count'] ?? 0,
                        'Warnings'          => $preview['warning_count'] ?? 0,
                        'Informational'     => $preview['informational_count'] ?? 0,
                        'Cutover warnings'  => $preview['cutover_warnings'] ?? 0,
                    ];
                @endphp
                @foreach($tiles as $label => $value)
                    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:.6rem .75rem;">
                        <div style="color:#64748b;font-size:.8rem;">{{ $label }}</div>
                        <div style="font-size:1.3rem;font-weight:700;">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <table class="data-table" style="width:100%;border-collapse:collapse;margin-top:1rem;">
                <tbody>
                    <tr><td>Date range</td><td style="text-align:right;">{{ $preview['date_from'] ?? '—' }} → {{ $preview['date_to'] ?? '—' }}</td></tr>
                    <tr><td>Financial years</td><td style="text-align:right;">{{ implode(', ', $preview['financial_years'] ?? []) ?: '—' }}</td></tr>
                    <tr><td>Header total</td><td style="text-align:right;">{{ number_format((float) ($preview['grand_total'] ?? 0), 2) }}</td></tr>
                    <tr><td>Taxable total</td><td style="text-align:right;">{{ number_format((float) ($preview['taxable_total'] ?? 0), 2) }}</td></tr>
                    <tr><td>Tax total (CGST/SGST/IGST/Cess)</td><td style="text-align:right;">
                        {{ number_format((float) ($preview['cgst_total'] ?? 0), 2) }} /
                        {{ number_format((float) ($preview['sgst_total'] ?? 0), 2) }} /
                        {{ number_format((float) ($preview['igst_total'] ?? 0), 2) }} /
                        {{ number_format((float) ($preview['cess_total'] ?? 0), 2) }}
                    </td></tr>
                    <tr><td>Paid / Outstanding</td><td style="text-align:right;">
                        {{ number_format((float) ($preview['paid_total'] ?? 0), 2) }} /
                        {{ number_format((float) ($preview['outstanding_total'] ?? 0), 2) }}
                    </td></tr>
                    <tr><td>Tax completeness</td><td style="text-align:right;">
                        @foreach(($preview['tax_completeness'] ?? []) as $k => $v){{ $k }}: {{ $v }}@if(!$loop->last) · @endif @endforeach
                    </td></tr>
                    <tr><td>Ignored / informational columns</td><td style="text-align:right;">
                        {{ implode(', ', $preview['ignored_columns'] ?? []) ?: '—' }}
                        @if($preview['informational_columns'] ?? []) · info: {{ implode(', ', $preview['informational_columns']) }} @endif
                    </td></tr>
                </tbody>
            </table>

            {{-- Making/labour interpretations shown, never assumed (Phase 11). --}}
            @if($preview['making_mappings'] ?? [])
                <h3>Making / labour interpretations</h3>
                <ul>
                    @foreach($preview['making_mappings'] as $mk)
                        <li>“{{ $mk['label'] ?? '—' }}” → {{ $mk['category'] ?? 'uncategorised' }} ({{ $mk['basis'] ?? 'unknown' }})</li>
                    @endforeach
                </ul>
            @endif

            {{-- Message breakdown by code. --}}
            @if($preview['messages'] ?? [])
                <h3>Findings</h3>
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <tbody>
                    @foreach($preview['messages'] as $code => $m)
                        @php
                            [$severityColor, $severityLabel] = $sev($m['severity']);
                        @endphp
                        <tr>
                            <td style="color:{{ $severityColor }};white-space:nowrap;"><strong>{{ $severityLabel }}</strong> ×{{ $m['count'] ?? 1 }}</td>
                            <td>{{ $m['text'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            {{-- Sample normalized records. --}}
            @if($preview['samples'] ?? [])
                <h3>Sample records</h3>
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead><tr><th style="text-align:left;">Number</th><th>Date</th><th>Customer</th><th>Total</th><th>Tax</th><th>Lines</th></tr></thead>
                    <tbody>
                    @foreach($preview['samples'] as $s)
                        <tr>
                            <td style="text-align:left;">{{ $s['number'] }}</td>
                            <td>{{ $s['date'] ?? '—' }}</td>
                            <td>{{ $s['customer'] ?? '—' }}</td>
                            <td style="text-align:right;">{{ number_format((float) $s['grand_total'], 2) }}</td>
                            <td>{{ $s['tax'] }}</td>
                            <td style="text-align:center;">{{ $s['lines'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        {{-- Duplicate / conflict review (Phase 12). Number collisions never auto-suffix. --}}
        @can('historical.import')
            @if($dupGroups->isNotEmpty())
                <h2 style="margin-top:1.5rem;">Duplicate review</h2>
                @foreach($dupGroups as $key => $groupRows)
                    @php
                        $msg = collect(json_decode($groupRows->first()->messages ?? '[]', true) ?: [])
                            ->first(fn ($m) => in_array($m['code'] ?? '', [
                                HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                                HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
                            ], true));
                    @endphp
                    <div style="border:1px solid #fca5a5;background:#fef2f2;border-radius:8px;padding:.75rem 1rem;margin-bottom:.75rem;">
                        <p style="margin:0 0 .5rem;color:#b91c1c;">{{ $msg['text'] ?? 'Duplicate detected.' }}</p>
                        <form method="POST" action="{{ route('historical.batches.duplicates', $batch) }}"
                              style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:end;">
                            @csrf
                            <input type="hidden" name="grouping_key" value="{{ $key }}">
                            <label>Resolution
                                <select name="action" required style="width:100%;">
                                    @foreach(HistoricalDuplicateDetector::RESOLUTIONS as $r)
                                        <option value="{{ $r }}">{{ ucfirst(str_replace('_', ' ', $r)) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Series (for “new series” only)
                                <input type="text" name="series" placeholder="e.g. B" style="width:100%;">
                            </label>
                            <label style="grid-column:1 / -1;">Reason (recorded)
                                <input type="text" name="reason" style="width:100%;">
                            </label>
                            <div style="grid-column:1 / -1;"><button class="btn" type="submit">Apply &amp; re-normalize</button></div>
                        </form>
                    </div>
                @endforeach
            @endif
        @endcan

        {{-- Staged rows. --}}
        <h2 style="margin-top:1.5rem;">Staged rows</h2>
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <thead><tr><th>Sheet</th><th>Row</th><th>Severity</th><th style="text-align:left;">Findings</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php
                    [$severityColor, $severityLabel] = $sev($row->severity === 'error' ? 'error' : ($row->severity === 'warning' ? 'warning' : 'info'));
                @endphp
                <tr>
                    <td>{{ $row->source_sheet ?? '—' }}</td>
                    <td style="text-align:center;">{{ $row->source_row_number }}</td>
                    <td style="color:{{ $severityColor }};">{{ ucfirst($row->severity ?? 'ok') }}</td>
                    <td style="text-align:left;">
                        @foreach(json_decode($row->messages ?? '[]', true) ?: [] as $m){{ $m['text'] }}@if(!$loop->last)<br>@endif @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="color:#64748b;padding:1rem;">No staged rows.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top:.5rem;">{{ $rows->links() }}</div>

        {{-- Documents produced. --}}
        @if($documents->isNotEmpty())
            <h2 style="margin-top:1.5rem;">Documents in this batch ({{ $documents->count() }})</h2>
            <ul>
                @foreach($documents as $doc)
                    <li><a href="{{ route('historical.documents.show', $doc) }}">{{ $doc->displayNumber() }}</a>
                        — {{ $doc->document_date?->toDateString() ?? 'no date' }}, {{ number_format((float) $doc->grand_total, 2) }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Actions. --}}
        <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
            @can('historical.import')
                @unless($batch->isPublished())
                    @if($batch->profile)
                        <form method="POST" action="{{ route('historical.batches.normalize', $batch) }}">
                            @csrf <button class="btn" type="submit">Re-normalize</button>
                        </form>
                    @endif

                    @if(($batch->warning_count ?? 0) > 0 && ! $batch->warningsAcknowledged())
                        <form method="POST" action="{{ route('historical.batches.acknowledge', $batch) }}">
                            @csrf <button class="btn" type="submit">Acknowledge {{ $batch->warning_count }} warning(s)</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('historical.batches.destroy', $batch) }}"
                          onsubmit="return confirm('Roll back this draft batch? Everything it created is discarded.');">
                        @csrf @method('DELETE')
                        <button class="btn" type="submit" style="color:#b91c1c;">Roll back draft</button>
                    </form>
                @endunless
            @endcan

            @can('historical.publish')
                @unless($batch->isPublished())
                    @if($blocker)
                        <span style="color:#b45309;">Cannot publish yet: {{ $blocker }}</span>
                    @else
                        <form method="POST" action="{{ route('historical.batches.publish', $batch) }}"
                              onsubmit="return confirm('Publish this batch? Its records become immutable evidence.');">
                            @csrf <button class="btn btn-primary" type="submit">Publish batch</button>
                        </form>
                    @endif
                @endunless
            @endcan
        </div>
    </div>
</x-app-layout>
