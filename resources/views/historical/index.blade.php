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
                <a href="{{ route('historical.manual.create') }}" class="btn btn-sm">Enter a bill</a>
                <a href="{{ route('historical.upload.create') }}" class="btn btn-primary btn-sm">Import a file</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-index-page">
        <x-app-alerts />

        <p class="text-sm text-amber-700 mb-6">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Import batches</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Label</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Rows</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Documents</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($batches as $batch)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-6 py-4 text-sm font-medium text-slate-800">{{ $batch->label }}</td>
                                <td class="px-6 py-4 text-sm text-slate-600">{{ $batch->source_system ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $batchStatusColors[$batch->status] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ ucfirst($batch->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-right tabular-nums text-slate-600">{{ number_format($batch->rows_count) }}</td>
                                <td class="px-6 py-4 text-sm text-right tabular-nums text-slate-600">{{ number_format($batch->documents_count) }}</td>
                                <td class="px-6 py-4 text-center">
                                    <a href="{{ route('historical.batches.show', $batch) }}" class="text-teal-700 hover:text-teal-900 text-sm font-medium">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-slate-500 text-sm">
                                    No import batches yet. Import a file or enter a bill to start one.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Historical documents</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ HistoricalSalesDocument::NUMBER_LABEL }}</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Source</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">FY</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Total</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Tax</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($documents as $document)
                            <tr class="hover:bg-slate-50/70">
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
                                    <a href="{{ route('historical.documents.show', $document) }}" class="text-teal-700 hover:text-teal-900 text-sm font-medium" aria-label="View {{ $document->displayNumber() }}">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-10 text-center text-slate-500 text-sm">
                                    No historical documents yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($documents->hasPages())
                <div class="px-6 py-4 border-t border-slate-200">
                    {{ $documents->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
