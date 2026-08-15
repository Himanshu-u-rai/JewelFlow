@php use App\Models\Historical\HistoricalSalesDocument; @endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:900px;margin:0 auto;">
        <x-app-alerts />

        {{-- The immutable UI contract: badge + disclaimer, wording owned by the model. --}}
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span class="badge" style="background:#0f766e;color:#fff;">{{ HistoricalSalesDocument::BADGE }}</span>
            <h1 style="margin:0;">{{ $document->displayNumber() }}</h1>
        </div>
        <p style="color:#b45309;font-weight:600;margin:.25rem 0 1rem;">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <h3>Document</h3>
                <dl>
                    <dt>{{ HistoricalSalesDocument::NUMBER_LABEL }}</dt><dd>{{ $document->displayNumber() }}</dd>
                    <dt>Series</dt><dd>{{ $document->document_series ?? '—' }}</dd>
                    <dt>Date</dt><dd>{{ $document->document_date?->toDateString() ?? '—' }}</dd>
                    <dt>Financial year</dt><dd>{{ $document->financial_year ?? '—' }}</dd>
                    <dt>Source system</dt><dd>{{ $document->source_system ?? '—' }}</dd>
                    <dt>Status</dt><dd>{{ ucfirst($document->status) }}</dd>
                </dl>
            </div>
            <div>
                <h3>Customer snapshot</h3>
                <dl>
                    <dt>Name</dt><dd>{{ data_get($document->customer_snapshot, 'name', '—') }}</dd>
                    <dt>Mobile</dt><dd>{{ data_get($document->customer_snapshot, 'mobile', '—') }}</dd>
                    <dt>GSTIN</dt><dd>{{ data_get($document->customer_snapshot, 'gstin', '—') }}</dd>
                    <dt>Place of supply</dt><dd>{{ data_get($document->customer_snapshot, 'place_of_supply', '—') }}</dd>
                </dl>
            </div>
        </div>

        <h3>Amounts <small style="color:#64748b;">(display snapshot — creates no ledger or receivable)</small></h3>
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <tbody>
                <tr><td>Taxable</td><td style="text-align:right;">{{ $document->taxable_amount === null ? 'unavailable' : number_format((float) $document->taxable_amount, 2) }}</td></tr>
                <tr><td>Tax completeness</td><td style="text-align:right;">{{ $document->tax_completeness }}</td></tr>
                <tr><td>Metal value</td><td style="text-align:right;">{{ number_format((float) $document->metal_value, 2) }}</td></tr>
                <tr><td>Stone value</td><td style="text-align:right;">{{ number_format((float) $document->stone_value, 2) }}</td></tr>
                <tr><td>Making / labour ({{ $document->making_category ?? 'uncategorised' }}, {{ $document->making_basis ?? 'unknown' }})</td>
                    <td style="text-align:right;">{{ $document->making_amount === null ? ($document->making_value_original ?? '—').' (amount unknown)' : number_format((float) $document->making_amount, 2) }}</td></tr>
                <tr><td>Discount</td><td style="text-align:right;">{{ number_format((float) $document->discount_snapshot, 2) }}</td></tr>
                <tr><td>Rounding</td><td style="text-align:right;">{{ number_format((float) $document->rounding_snapshot, 2) }}</td></tr>
                <tr style="font-weight:700;"><td>Grand total</td><td style="text-align:right;">{{ number_format((float) $document->grand_total, 2) }}</td></tr>
                <tr><td>Paid</td><td style="text-align:right;">{{ $document->paid_amount_snapshot === null ? 'unknown' : number_format((float) $document->paid_amount_snapshot, 2) }}</td></tr>
                <tr><td>Outstanding</td><td style="text-align:right;">{{ $document->outstanding_amount_snapshot === null ? 'unknown' : number_format((float) $document->outstanding_amount_snapshot, 2) }}</td></tr>
            </tbody>
        </table>

        @if($document->lines->isNotEmpty())
            <h3>Line items</h3>
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead><tr><th style="text-align:left;">Item</th><th>SKU</th><th>HSN</th><th>Qty</th><th>Net wt</th><th>Line total</th></tr></thead>
                <tbody>
                @foreach($document->lines as $line)
                    <tr>
                        <td style="text-align:left;">{{ data_get($line->item_snapshot, 'name', '—') }}</td>
                        <td>{{ $line->source_sku ?? '—' }}</td>
                        <td>{{ $line->hsn_snapshot ?? '—' }}</td>
                        <td style="text-align:right;">{{ $line->quantity ?? '—' }}</td>
                        <td style="text-align:right;">{{ $line->net_weight ?? '—' }}</td>
                        <td style="text-align:right;">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p style="color:#64748b;">Header-only document — no itemised lines were recorded.</p>
        @endif

        @can('historical.publish')
            @if($document->status === HistoricalSalesDocument::STATUS_PUBLISHED)
                <form method="POST" action="{{ route('historical.documents.void', $document) }}"
                      onsubmit="return confirm('Void this document? Its number and fingerprint are released.');"
                      style="margin-top:1rem;display:flex;gap:.5rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for voiding (required)" required style="flex:1;">
                    <button class="btn" type="submit">Void</button>
                </form>
            @endif
        @endcan
    </div>
</x-app-layout>
