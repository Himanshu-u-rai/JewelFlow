@php use App\Models\Historical\HistoricalSalesDocument; @endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:1100px;margin:0 auto;">
        <x-app-alerts />

        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1 style="margin:0;">Historical Sales</h1>
                <p style="color:#475569;margin:.25rem 0 0;">
                    Records of sales made before JewelFlow. Not live invoices — no numbers issued, no stock moved.
                </p>
            </div>
            <div style="display:flex;gap:.5rem;">
                @can('historical.import')
                    <a class="btn" href="{{ route('historical.manual.create') }}">Enter a bill</a>
                    <a class="btn btn-primary" href="{{ route('historical.upload.create') }}">Import a file</a>
                @endcan
            </div>
        </div>

        <h2 style="margin-top:1.5rem;">Import batches</h2>
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <thead><tr>
                <th style="text-align:left;">Label</th><th>Source</th><th>Status</th>
                <th>Rows</th><th>Documents</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($batches as $batch)
                <tr>
                    <td style="text-align:left;">{{ $batch->label }}</td>
                    <td>{{ $batch->source_system ?? '—' }}</td>
                    <td><span class="badge">{{ ucfirst($batch->status) }}</span></td>
                    <td style="text-align:center;">{{ $batch->rows_count }}</td>
                    <td style="text-align:center;">{{ $batch->documents_count }}</td>
                    <td><a href="{{ route('historical.batches.show', $batch) }}">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="6" style="color:#64748b;padding:1rem;">No import batches yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <h2 style="margin-top:1.5rem;">Historical documents</h2>
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <thead><tr>
                <th style="text-align:left;">{{ HistoricalSalesDocument::NUMBER_LABEL }}</th>
                <th>Source</th><th>Date</th><th>FY</th><th>Customer</th>
                <th>Total</th><th>Tax</th><th>Status</th>
            </tr></thead>
            <tbody>
            @forelse($documents as $document)
                <tr>
                    <td style="text-align:left;">
                        <span class="badge" style="background:#0f766e;color:#fff;">{{ HistoricalSalesDocument::BADGE }}</span>
                        <a href="{{ route('historical.documents.show', $document) }}">{{ $document->displayNumber() }}</a>
                    </td>
                    <td>{{ $document->source_system ?? '—' }}</td>
                    <td>{{ $document->document_date?->toDateString() ?? '—' }}</td>
                    <td>{{ $document->financial_year ?? '—' }}</td>
                    <td>{{ data_get($document->customer_snapshot, 'name', '—') }}</td>
                    <td style="text-align:right;">{{ number_format((float) $document->grand_total, 2) }}</td>
                    <td>{{ $document->tax_completeness }}</td>
                    <td>{{ ucfirst($document->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="color:#64748b;padding:1rem;">No historical documents yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div style="margin-top:.75rem;">{{ $documents->links() }}</div>
    </div>
</x-app-layout>
