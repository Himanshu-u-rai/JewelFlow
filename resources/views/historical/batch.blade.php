@php
    use App\Models\Historical\HistoricalImportBatch;
    use App\Services\Historical\HistoricalDuplicateDetector;
    use App\Support\Historical\HistoricalMessages;

    $sev = fn ($s) => match ($s) {
        HistoricalMessages::ERROR   => ['text-rose-700', 'Blocking'],
        HistoricalMessages::WARNING => ['text-amber-700', 'Warning'],
        default                     => ['text-slate-600', 'Info'],
    };

    $batchStatusColors = [
        HistoricalImportBatch::STATUS_DRAFT      => 'bg-slate-100 text-slate-700',
        HistoricalImportBatch::STATUS_REVIEW     => 'bg-amber-100 text-amber-800',
        HistoricalImportBatch::STATUS_PUBLISHING => 'bg-blue-100 text-blue-800',
        HistoricalImportBatch::STATUS_PUBLISHED  => 'bg-emerald-100 text-emerald-800',
        HistoricalImportBatch::STATUS_CANCELLED  => 'bg-rose-100 text-rose-800',
    ];

    $isManual = (bool) ($preview['is_manual'] ?? ($batch->preview_generated_at === null && $batch->isManualBatch()));
    $workflowSteps = $preview['workflow_steps'] ?? ($isManual ? ['enter', 'preview', 'review', 'publish'] : null);
    $workflowStep = $batch->isPublished()
        ? 'publish'
        : ($batch->preview_generated_at === null ? ($isManual ? 'preview' : 'map') : 'review');

    // ponytail: duplicate review is built from the current rows page only.
    // Release 1 pages at 100 rows; a batch with duplicates past page 1 is rare
    // and the operator can page to it. Revisit if real files need cross-page review.
    $dupGroups = collect($rows->items())
        ->filter(fn ($r) => collect($r->messages ?: [])
            ->contains(fn ($m) => in_array($m['code'] ?? '', [
                HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
            ], true)))
        ->groupBy('grouping_key');
@endphp
<x-app-layout>
    <x-page-header title="{{ $batch->label }}" subtitle="{{ $batch->source_system ?? 'Manual' }}{{ $batch->source_file_name ? ' · '.$batch->source_file_name : '' }}{{ $batch->profile ? ' · profile “'.$batch->profile->name.'”' : '' }}">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm h-11 min-h-[44px]">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-batch-page grid min-w-0 gap-4" data-historical-batch-page>
        <x-app-alerts />

        @include('historical._workflow-steps', [
            'currentStep' => $workflowStep,
            'workflowSteps' => $workflowSteps,
            'workflowLabel' => $isManual ? 'Historical bill progress' : 'Import progress',
        ])

        <section class="rounded-2xl border border-slate-200 bg-white px-4 py-4 sm:px-6" @if($isManual) data-historical-batch-context="manual" @endif>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if($isManual)
                        <p class="text-base font-semibold text-slate-900">Historical bill review</p>
                        <p class="mt-1 text-sm text-slate-600">Review the saved historical draft and its findings before publishing immutable evidence.</p>
                    @else
                        <p class="text-xs font-semibold normal-case tracking-normal text-slate-500">Historical import batch</p>
                        <p class="mt-1 text-sm text-slate-700">Review normalized records and findings before publishing immutable evidence.</p>
                    @endif
                </div>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $batchStatusColors[$batch->status] ?? 'bg-slate-100 text-slate-700' }}">
                    {{ ucfirst($batch->status) }}
                </span>
            </div>
        </section>

        {{-- Blocking messages carried back from a failed manual save / import. --}}
        @if(session('historical_messages'))
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3" role="alert">
                @foreach(session('historical_messages') as $m)
                    @php [$severityColor, $severityLabel] = $sev($m['severity'] ?? 'info'); @endphp
                    <div class="text-sm {{ $severityColor }}"><strong>{{ $severityLabel }}:</strong> {{ $m['text'] }}</div>
                @endforeach
            </div>
        @endif

        @if($batch->preview_generated_at === null)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <p class="mb-3 text-sm text-slate-500">
                    {{ $isManual
                        ? 'The saved historical draft is not ready for review yet.'
                        : 'No reconciliation preview yet. Confirm the column mapping to normalize this batch.' }}
                </p>
                @can('historical.import')
                    @if($batch->source_file_name)
                        <a class="btn btn-primary btn-sm h-11 min-h-[44px]" href="{{ route('historical.batches.map', $batch) }}">Map columns</a>
                    @endif
                @endcan
            </div>
        @else
            @if($isManual)
                @php
                    $manualMetrics = [
                        ['documents', 'Documents', $preview['document_count'] ?? 0, false],
                        ['item-lines', 'Item lines', $preview['line_count'] ?? 0, false],
                        ['grand-total', 'Grand total', $preview['grand_total'] ?? 0, true],
                        ['taxable-total', 'Taxable total', $preview['taxable_total'] ?? 0, true],
                        ['tax-total', 'Tax total', $preview['tax_total'] ?? 0, true],
                        ['paid', 'Paid', $preview['paid_total'] ?? 0, true],
                        ['outstanding', 'Outstanding', $preview['outstanding_total'] ?? 0, true],
                        ['blocking-errors', 'Blocking errors', $preview['blocking_count'] ?? 0, false],
                        ['warnings', 'Warnings', $preview['warning_count'] ?? 0, false],
                        ['informational-findings', 'Informational findings', $preview['informational_count'] ?? 0, false],
                        ['date', 'Date', $preview['date_from'] ?? '—', false],
                        ['financial-year', 'Financial year', implode(', ', $preview['financial_years'] ?? []) ?: '—', false],
                    ];
                    $manualMakingMappings = collect($preview['making_mappings'] ?? [])->filter(fn ($mapping) =>
                        filled($mapping['label'] ?? null)
                        || ! in_array($mapping['category'] ?? null, [null, 'uncategorised', 'uncategorized'], true)
                        || ! in_array($mapping['basis'] ?? null, [null, 'unknown'], true)
                    );
                @endphp
                <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-batch-summary="manual">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Saved bill summary</h2>
                        <p class="mt-1 text-sm text-slate-500">Document totals and review findings from the saved historical draft.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 sm:p-6 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach($manualMetrics as [$key, $label, $value, $isMoney])
                            <div class="min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3" data-historical-metric="{{ $key }}">
                                <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
                                <div class="mt-1 truncate text-lg font-semibold tabular-nums text-slate-900" data-historical-metric-value>
                                    {{ $isMoney ? number_format((float) $value, 2) : $value }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($manualMakingMappings->isNotEmpty() || ($preview['messages'] ?? []))
                        <div class="grid gap-5 border-t border-slate-200 px-4 py-4 sm:px-6 {{ $manualMakingMappings->isNotEmpty() ? 'lg:grid-cols-2' : '' }}">
                            @if($manualMakingMappings->isNotEmpty())
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-slate-800">Making / labour interpretation</h3>
                                    <ul class="mt-2 grid gap-2 text-sm text-slate-700">
                                        @foreach($manualMakingMappings as $mk)
                                            <li class="rounded-lg bg-slate-50 px-3 py-2">
                                                <span class="font-medium">“{{ $mk['label'] ?? '—' }}”</span>
                                                <span class="text-slate-500"> · {{ $mk['category'] ?? 'uncategorised' }} · {{ $mk['basis'] ?? 'unknown' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($preview['messages'] ?? [])
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-slate-800">Findings</h3>
                                    <div class="mt-2 grid gap-2">
                                        @foreach($preview['messages'] as $code => $m)
                                            @php [$severityColor, $severityLabel] = $sev($m['severity']); @endphp
                                            <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                                <div class="font-semibold {{ $severityColor }}">{{ $severityLabel }} ×{{ $m['count'] ?? 1 }}</div>
                                                <p class="mt-0.5 text-slate-700">{{ $m['text'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </section>
            @else
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6" data-historical-batch-summary="import">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Reconciliation preview</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
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
                        <div class="rounded-xl border border-slate-200 px-3 py-2">
                            <div class="text-xs text-slate-500">{{ $label }}</div>
                            <div class="text-xl font-bold text-slate-800 tabular-nums">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>

                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="py-1.5 text-slate-600">Date range</td><td class="py-1.5 text-right">{{ $preview['date_from'] ?? '—' }} → {{ $preview['date_to'] ?? '—' }}</td></tr>
                        <tr><td class="py-1.5 text-slate-600">Financial years</td><td class="py-1.5 text-right">{{ implode(', ', $preview['financial_years'] ?? []) ?: '—' }}</td></tr>
                        <tr><td class="py-1.5 text-slate-600">Header total</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($preview['grand_total'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-600">Taxable total</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($preview['taxable_total'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-600">Tax total (CGST/SGST/IGST/Cess)</td><td class="py-1.5 text-right tabular-nums">
                            {{ number_format((float) ($preview['cgst_total'] ?? 0), 2) }} /
                            {{ number_format((float) ($preview['sgst_total'] ?? 0), 2) }} /
                            {{ number_format((float) ($preview['igst_total'] ?? 0), 2) }} /
                            {{ number_format((float) ($preview['cess_total'] ?? 0), 2) }}
                        </td></tr>
                        <tr><td class="py-1.5 text-slate-600">Paid / Outstanding</td><td class="py-1.5 text-right tabular-nums">
                            {{ number_format((float) ($preview['paid_total'] ?? 0), 2) }} /
                            {{ number_format((float) ($preview['outstanding_total'] ?? 0), 2) }}
                        </td></tr>
                        <tr><td class="py-1.5 text-slate-600">Tax completeness</td><td class="py-1.5 text-right">
                            @foreach(($preview['tax_completeness'] ?? []) as $k => $v){{ $k }}: {{ $v }}@if(!$loop->last) · @endif @endforeach
                        </td></tr>
                        <tr><td class="py-1.5 text-slate-600">Ignored / informational columns</td><td class="py-1.5 text-right">
                            {{ implode(', ', $preview['ignored_columns'] ?? []) ?: '—' }}
                            @if($preview['informational_columns'] ?? []) · info: {{ implode(', ', $preview['informational_columns']) }} @endif
                        </td></tr>
                    </tbody>
                </table>

                {{-- Making/labour interpretations shown, never assumed (Phase 11). --}}
                @if($preview['making_mappings'] ?? [])
                    <h3 class="text-sm font-semibold text-slate-700 mt-4 mb-1">Making / labour charge interpretations</h3>
                    <ul class="text-sm text-slate-700 list-disc pl-5">
                        @foreach($preview['making_mappings'] as $mk)
                            <li>“{{ $mk['label'] ?? '—' }}” → {{ $mk['category'] ?? 'uncategorised' }} ({{ $mk['basis'] ?? 'unknown' }})</li>
                        @endforeach
                    </ul>
                @endif

                {{-- Message breakdown by code. --}}
                @if($preview['messages'] ?? [])
                    <h3 class="text-sm font-semibold text-slate-700 mt-4 mb-1">Findings</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100">
                        @foreach($preview['messages'] as $code => $m)
                            @php [$severityColor, $severityLabel] = $sev($m['severity']); @endphp
                            <tr>
                                <td class="py-1.5 whitespace-nowrap {{ $severityColor }}"><strong>{{ $severityLabel }}</strong> ×{{ $m['count'] ?? 1 }}</td>
                                <td class="py-1.5 text-slate-700">{{ $m['text'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Sample normalized records. --}}
                @if($preview['samples'] ?? [])
                    <h3 class="text-sm font-semibold text-slate-700 mt-4 mb-1">Sample records</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full text-sm">
                            <thead><tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                <th class="py-2">Number</th><th class="py-2">Date</th><th class="py-2">Customer</th><th class="py-2 text-right">Total</th><th class="py-2">Tax</th><th class="py-2 text-center">Lines</th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach($preview['samples'] as $s)
                                <tr>
                                    <td class="py-2 text-slate-800">{{ $s['number'] }}</td>
                                    <td class="py-2 text-slate-600">{{ $s['date'] ?? '—' }}</td>
                                    <td class="py-2 text-slate-600">{{ $s['customer'] ?? '—' }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ number_format((float) $s['grand_total'], 2) }}</td>
                                    <td class="py-2 text-slate-600">{{ $s['tax'] }}</td>
                                    <td class="py-2 text-center tabular-nums">{{ $s['lines'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @endif
        @endif

        {{-- Duplicate / conflict review (Phase 12). Number collisions never auto-suffix. --}}
        @can('historical.import')
            @if($dupGroups->isNotEmpty())
                <div class="grid gap-3">
                    <h2 class="text-base font-semibold text-slate-800">Duplicate review</h2>
                    @foreach($dupGroups as $key => $groupRows)
                        @php
                            $msg = collect($groupRows->first()->messages ?: [])
                                ->first(fn ($m) => in_array($m['code'] ?? '', [
                                    HistoricalDuplicateDetector::CODE_DUPLICATE_NUMBER,
                                    HistoricalDuplicateDetector::CODE_DUPLICATE_FINGERPRINT,
                                ], true));
                        @endphp
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 sm:p-6">
                            <p class="text-sm text-rose-700 mb-3">{{ $msg['text'] ?? 'Duplicate detected.' }}</p>
                            <form method="POST" action="{{ route('historical.batches.duplicates', $batch) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                                @csrf
                                <input type="hidden" name="grouping_key" value="{{ $key }}">
                                <div>
                                    <label for="dup_action_{{ $loop->index }}">Resolution</label>
                                    <select id="dup_action_{{ $loop->index }}" name="action" required class="w-full min-h-[44px]">
                                        @foreach(HistoricalDuplicateDetector::RESOLUTIONS as $r)
                                            <option value="{{ $r }}">{{ ucfirst(str_replace('_', ' ', $r)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="dup_series_{{ $loop->index }}">Series <span class="text-slate-400 font-normal">(for "new series" only)</span></label>
                                    <input type="text" id="dup_series_{{ $loop->index }}" name="series" placeholder="e.g. B" class="w-full">
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="dup_reason_{{ $loop->index }}">Reason <span class="text-slate-400 font-normal">(recorded)</span></label>
                                    <input type="text" id="dup_reason_{{ $loop->index }}" name="reason" class="w-full">
                                </div>
                                <div class="sm:col-span-2"><button class="btn btn-sm h-11 min-h-[44px]" type="submit">Apply &amp; re-normalize</button></div>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        @endcan

        {{-- Staged rows belong to file imports only. --}}
        @unless($isManual)
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Staged rows</h2>
            </div>
            <div class="hidden md:block" data-historical-register="staged-desktop">
            <div class="overflow-x-auto">
                <table class="w-full min-w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr class="text-left text-xs font-semibold normal-case tracking-normal text-slate-600">
                            <th class="px-4 sm:px-6 py-3">Sheet</th><th class="px-4 sm:px-6 py-3 text-center">Row</th><th class="px-4 sm:px-6 py-3">Severity</th><th class="px-4 sm:px-6 py-3">Findings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $row)
                        @php [$severityColor, $severityLabel] = $sev($row->severity === 'error' ? 'error' : ($row->severity === 'warning' ? 'warning' : 'info')); @endphp
                        <tr class="transition-colors hover:bg-slate-50">
                            <td class="px-4 sm:px-6 py-4 text-sm font-semibold text-slate-900">{{ $row->source_sheet ?? '—' }}</td>
                            <td class="px-4 sm:px-6 py-4 text-center tabular-nums text-slate-600">{{ $row->source_row_number }}</td>
                            <td class="px-4 sm:px-6 py-4 {{ $severityColor }}">{{ ucfirst($row->severity ?? 'ok') }}</td>
                            <td class="px-4 sm:px-6 py-4 text-slate-700">
                                @foreach($row->messages ?: [] as $m){{ $m['text'] }}@if(!$loop->last)<br>@endif @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 sm:px-6 py-10 text-center text-slate-500 text-sm">No staged rows.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            </div>
            <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-register="staged-mobile">
                @forelse($rows as $row)
                    @php [$severityColor, $severityLabel] = $sev($row->severity === 'error' ? 'error' : ($row->severity === 'warning' ? 'warning' : 'info')); @endphp
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-slate-900">{{ $row->source_sheet ?? '—' }}</h3>
                                <p class="mt-1 text-xs text-slate-500">Source row <span class="tabular-nums">{{ $row->source_row_number }}</span></p>
                            </div>
                            <span class="shrink-0 text-xs font-semibold {{ $severityColor }}">{{ ucfirst($row->severity ?? 'ok') }}</span>
                        </div>
                        <div class="mt-3 text-sm text-slate-700">
                            @forelse($row->messages ?: [] as $m)<p>{{ $m['text'] }}</p>@empty<p class="text-slate-500">No findings.</p>@endforelse
                        </div>
                    </article>
                @empty
                    <p class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500">No staged rows.</p>
                @endforelse
            </div>
            <div class="px-4 sm:px-6 py-4 border-t border-slate-200">{{ $rows->links() }}</div>
        </div>
        @endunless

        {{-- Documents produced. --}}
        @if($documents->isNotEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden" @if($isManual) data-historical-batch-documents="manual" @endif>
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                    <h2 class="text-base font-semibold text-slate-900">
                        {{ $isManual ? 'Saved historical bill' : 'Documents in this batch' }}
                        <span class="text-sm font-normal text-slate-500">({{ $documents->count() }})</span>
                    </h2>
                </div>
                <div class="hidden md:block" data-historical-batch-register="documents-desktop">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200"><tr class="text-left text-xs font-semibold normal-case tracking-normal text-slate-600">
                                <th class="px-4 py-3 sm:px-6">Document</th><th class="px-4 py-3 sm:px-6">Date</th><th class="px-4 py-3 text-right sm:px-6">Total</th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($documents as $doc)
                                    <tr class="transition-colors hover:bg-slate-50">
                                        <td class="px-4 py-4 sm:px-6"><a href="{{ route('historical.documents.show', $doc) }}" class="text-teal-700 hover:text-teal-800 font-semibold">{{ $doc->displayNumber() }}</a></td>
                                        <td class="px-4 py-4 text-slate-600 sm:px-6">{{ $doc->document_date?->toDateString() ?? 'no date' }}</td>
                                        <td class="px-4 py-4 text-right font-semibold tabular-nums text-slate-900 sm:px-6">{{ number_format((float) $doc->grand_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-batch-register="documents-mobile">
                    @foreach($documents as $doc)
                        <article class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('historical.documents.show', $doc) }}" class="inline-flex min-h-[44px] items-center text-sm font-semibold text-teal-700 hover:text-teal-800">{{ $doc->displayNumber() }}</a>
                                    <p class="text-xs text-slate-500">{{ $doc->document_date?->toDateString() ?? 'no date' }}</p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ number_format((float) $doc->grand_total, 2) }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Actions. --}}
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:px-6" data-historical-batch-actions>
            @can('historical.import')
                @unless($batch->isPublished())
                    @if($batch->profile)
                        <form method="POST" action="{{ route('historical.batches.normalize', $batch) }}">
                            @csrf <button class="btn btn-sm h-11 min-h-[44px]" type="submit">Re-normalize</button>
                        </form>
                    @endif

                    @if(($batch->warning_count ?? 0) > 0 && ! $batch->warningsAcknowledged())
                        <form method="POST" action="{{ route('historical.batches.acknowledge', $batch) }}">
                            @csrf <button class="btn btn-sm h-11 min-h-[44px]" type="submit">Acknowledge {{ $batch->warning_count }} warning(s)</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('historical.batches.destroy', $batch) }}"
                          onsubmit="return confirm('Roll back this draft batch? Everything it created is discarded.');">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm h-11 min-h-[44px]" type="submit">Roll back draft</button>
                    </form>
                @endunless
            @endcan

            @can('historical.publish')
                @unless($batch->isPublished())
                    @if($blocker)
                        <span class="text-sm text-amber-700" data-historical-publish-blocker>Cannot publish yet: {{ $blocker }}</span>
                    @else
                        <form method="POST" action="{{ route('historical.batches.publish', $batch) }}"
                              onsubmit="return confirm('Publish this batch? Its records become immutable evidence.');">
                            @csrf <button class="btn btn-primary btn-sm h-11 min-h-[44px]" type="submit">Publish batch</button>
                        </form>
                    @endif
                @endunless
            @endcan
        </div>
    </div>
</x-app-layout>
