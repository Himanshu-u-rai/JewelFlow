@php
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Models\Historical\HistoricalImportBatch;

    $batchStatusColors = [
        HistoricalImportBatch::STATUS_DRAFT      => 'bg-slate-100 text-slate-700',
        HistoricalImportBatch::STATUS_REVIEW     => 'bg-amber-100 text-amber-800',
        HistoricalImportBatch::STATUS_PUBLISHING => 'bg-blue-100 text-blue-800',
        HistoricalImportBatch::STATUS_PUBLISHED  => 'bg-emerald-100 text-emerald-800',
        HistoricalImportBatch::STATUS_CANCELLED  => 'bg-rose-100 text-rose-800',
    ];

    $documentStatusColors = [
        HistoricalSalesDocument::STATUS_DRAFT      => 'bg-amber-100 text-amber-800',
        HistoricalSalesDocument::STATUS_PUBLISHED  => 'bg-emerald-100 text-emerald-800',
        HistoricalSalesDocument::STATUS_SUPERSEDED => 'bg-slate-100 text-slate-700',
        HistoricalSalesDocument::STATUS_VOID       => 'bg-rose-100 text-rose-800',
    ];

    $taxColors = [
        HistoricalSalesDocument::TAX_COMPLETE       => 'bg-emerald-100 text-emerald-800',
        HistoricalSalesDocument::TAX_SUMMARY_ONLY   => 'bg-amber-100 text-amber-800',
        HistoricalSalesDocument::TAX_UNKNOWN        => 'bg-slate-100 text-slate-700',
        HistoricalSalesDocument::TAX_NOT_APPLICABLE => 'bg-slate-100 text-slate-500',
    ];
    $taxLabels = [
        HistoricalSalesDocument::TAX_COMPLETE       => 'Tax complete',
        HistoricalSalesDocument::TAX_SUMMARY_ONLY   => 'Summary only',
        HistoricalSalesDocument::TAX_UNKNOWN        => 'Tax unknown',
        HistoricalSalesDocument::TAX_NOT_APPLICABLE => 'Not applicable',
    ];
@endphp
<x-app-layout>
    <x-page-header title="Historical Sales" subtitle="Records of sales made before JewelFlow. Not live invoices — no numbers issued, no stock moved.">
        <x-slot:actions>
            @can('historical.import')
                <a href="{{ route('historical.manual.create') }}" class="btn btn-sm min-h-[44px]">Enter a bill</a>
                <a href="{{ route('historical.upload.create') }}" class="btn btn-primary btn-sm min-h-[44px]">Import a file</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-index-page">
        <x-app-alerts />

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex items-start gap-3">
                <span class="inline-flex shrink-0 items-center rounded bg-teal-700 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white">
                    {{ HistoricalSalesDocument::BADGE }}
                </span>
                <p class="text-sm text-amber-800">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
            </div>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-900">Import batches</h2>
                    <p class="mt-1 text-sm text-slate-500">Review uploaded files from mapping through publication.</p>
                </div>
                <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 tabular-nums">{{ number_format($batches->count()) }}</span>
            </div>

            <div class="hidden md:block" data-historical-register="batches-desktop">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="w-16 px-4 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">No.</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Label</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600">Rows</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600">Documents</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($batches as $batch)
                            <tr class="transition-colors hover:bg-slate-50">
                                <td class="w-16 px-4 py-4 text-center text-sm font-medium tabular-nums text-slate-500" data-historical-row-number="batch">{{ $loop->iteration }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-slate-900">{{ $batch->label }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $batch->source_system ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $batchStatusColors[$batch->status] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ ucfirst($batch->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-right tabular-nums text-slate-600">{{ number_format($batch->rows_count) }}</td>
                                <td class="px-6 py-4 text-sm text-right tabular-nums text-slate-600">{{ number_format($batch->documents_count) }}</td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('historical.batches.show', $batch) }}" class="inline-flex items-center justify-center min-h-[44px] rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center text-slate-500 text-sm">
                                    No file imports yet. Import a CSV or XLSX file to begin.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-register="batches-mobile">
                @forelse($batches as $batch)
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold tabular-nums text-slate-500" data-historical-row-number="batch">{{ $loop->iteration }}</span>
                                    <h3 class="min-w-0 text-sm font-semibold text-slate-900">{{ $batch->label }}</h3>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $batch->source_system ?? 'Source unavailable' }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-1 text-xs font-medium {{ $batchStatusColors[$batch->status] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ ucfirst($batch->status) }}
                            </span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-500">Rows</dt>
                                <dd class="mt-1 font-semibold text-slate-800 tabular-nums">{{ number_format($batch->rows_count) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Documents</dt>
                                <dd class="mt-1 font-semibold text-slate-800 tabular-nums">{{ number_format($batch->documents_count) }}</dd>
                            </div>
                        </dl>
                        <a href="{{ route('historical.batches.show', $batch) }}" class="mt-4 inline-flex w-full items-center justify-center min-h-[44px] rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50">Open batch</a>
                    </article>
                @empty
                    <x-empty-state compact title="No file imports yet" description="Import a CSV or XLSX file to begin." />
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-900">Historical documents</h2>
                    <p class="mt-1 text-sm text-slate-500">Reference-only records kept separate from live JewelFlows invoices.</p>
                </div>
                <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 tabular-nums">{{ number_format($documents->total()) }}</span>
            </div>

            <div class="hidden md:block" data-historical-register="documents-desktop">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="w-16 px-4 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">No.</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">{{ HistoricalSalesDocument::NUMBER_LABEL }}</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">FY</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold normal-case tracking-normal text-slate-600">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold normal-case tracking-normal text-slate-600">Total</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">Tax</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold normal-case tracking-normal text-slate-600">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($documents as $document)
                            <tr class="transition-colors hover:bg-slate-50">
                                <td class="w-16 px-4 py-4 text-center text-sm font-medium tabular-nums text-slate-500" data-historical-row-number="document">{{ number_format(($documents->firstItem() ?? 1) + $loop->index) }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide bg-teal-700 text-white shrink-0">
                                            {{ HistoricalSalesDocument::BADGE }}
                                        </span>
                                        <span class="text-sm font-medium text-slate-800 truncate max-w-[220px]">{{ $document->displayNumber() }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">{{ $document->source_system ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">{{ $document->document_date?->toDateString() ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600 whitespace-nowrap">{{ $document->financial_year ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-slate-700 truncate max-w-[180px]">{{ data_get($document->customer_snapshot, 'name', '—') }}</td>
                                <td class="px-6 py-4 text-sm text-right tabular-nums font-medium text-slate-800 whitespace-nowrap">
                                    ₹{{ number_format((float) $document->grand_total, 2) }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap {{ $taxColors[$document->tax_completeness] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ $taxLabels[$document->tax_completeness] ?? ucfirst(str_replace('_', ' ', $document->tax_completeness)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $documentStatusColors[$document->status] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ ucfirst($document->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('historical.documents.show', $document) }}" class="inline-flex items-center justify-center min-h-[44px] rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50" aria-label="View {{ $document->displayNumber() }}">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-10 text-center text-slate-500 text-sm">
                                    No historical documents yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    </table>
                </div>
            </div>

            <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-register="documents-mobile">
                @forelse($documents as $document)
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold tabular-nums text-slate-500" data-historical-row-number="document">{{ number_format(($documents->firstItem() ?? 1) + $loop->index) }}</span>
                                    <span class="inline-flex shrink-0 items-center rounded bg-teal-700 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white">{{ HistoricalSalesDocument::BADGE }}</span>
                                    <h3 class="min-w-0 text-sm font-semibold text-slate-900">{{ $document->displayNumber() }}</h3>
                                </div>
                                <p class="mt-2 text-sm text-slate-700">{{ data_get($document->customer_snapshot, 'name', '—') }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $document->source_system ?? 'Source unavailable' }} · {{ $document->document_date?->toDateString() ?? 'Date unavailable' }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-1 text-xs font-medium {{ $documentStatusColors[$document->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst($document->status) }}</span>
                        </div>
                        <div class="mt-4 flex items-end justify-between gap-3 border-t border-slate-100 pt-3">
                            <div>
                                <p class="text-xs text-slate-500">Total</p>
                                <p class="mt-1 text-base font-semibold text-slate-900 tabular-nums">₹{{ number_format((float) $document->grand_total, 2) }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium {{ $taxColors[$document->tax_completeness] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ $taxLabels[$document->tax_completeness] ?? ucfirst(str_replace('_', ' ', $document->tax_completeness)) }}
                            </span>
                        </div>
                        <a href="{{ route('historical.documents.show', $document) }}" class="mt-4 inline-flex w-full items-center justify-center min-h-[44px] rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50" aria-label="View {{ $document->displayNumber() }}">View record</a>
                    </article>
                @empty
                    <x-empty-state compact title="No historical documents yet" description="Historical records will appear here after entry or import." />
                @endforelse
            </div>

            @if($documents->hasPages())
                <div class="px-6 py-4 border-t border-slate-200">
                    {{ $documents->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
