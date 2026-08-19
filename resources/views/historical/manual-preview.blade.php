@php
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Support\Historical\HistoricalMessages;

    $taxModes = [
        '' => '— choose —',
        HistoricalSalesDocument::TAX_MODE_EXCLUSIVE     => 'Tax added on top (exclusive)',
        HistoricalSalesDocument::TAX_MODE_INCLUSIVE     => 'Tax already inside total (inclusive)',
        HistoricalSalesDocument::TAX_MODE_UNKNOWN       => 'Unknown',
        HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE => 'Not applicable',
    ];
    $money = ['taxable_amount'=>'Taxable','tax_total'=>'Tax total','cgst'=>'CGST','sgst'=>'SGST','igst'=>'IGST','cess'=>'Cess','discount'=>'Discount','rounding'=>'Rounding','metal_value'=>'Metal value','stone_value'=>'Stone value','paid_amount'=>'Paid','outstanding_amount'=>'Outstanding'];

    $customer = $attributes['customer_snapshot'] ?? [];
    $errors   = $messages->ofSeverity(HistoricalMessages::ERROR);
    $warnings = $messages->ofSeverity(HistoricalMessages::WARNING);
    $infos    = $messages->ofSeverity(HistoricalMessages::INFO);
@endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:900px;margin:0 auto;">
        <x-app-alerts />

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1 style="margin:0;">Preview historical bill</h1>
            <a href="{{ route('historical.index') }}">← Historical sales</a>
        </div>
        <p style="color:#b45309;">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
        <p style="color:#64748b;">Nothing has been saved yet. This is a computed preview — review it, then Confirm Save.</p>

        @if($errors !== [])
            <div style="border:1px solid #fca5a5;background:#fef2f2;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
                <strong style="color:#b91c1c;">Blocking — this bill cannot be saved until these are fixed:</strong>
                @foreach($errors as $m)<div style="color:#b91c1c;">{{ $m['text'] }}</div>@endforeach
            </div>
        @endif

        @if($warnings !== [])
            <div style="border:1px solid #fde68a;background:#fffbeb;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
                <strong style="color:#b45309;">Warnings:</strong>
                @foreach($warnings as $m)<div style="color:#b45309;">{{ $m['text'] }}</div>@endforeach
            </div>
        @endif

        @if($infos !== [])
            <div style="border:1px solid #bfdbfe;background:#eff6ff;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
                @foreach($infos as $m)<div style="color:#1e40af;">{{ $m['text'] }}</div>@endforeach
            </div>
        @endif

        <section style="border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-bottom:1rem;">
            <h2 style="margin-top:0;">Document</h2>
            <div>Number: {{ $attributes['original_document_number'] ?? '—' }} @if($attributes['document_series'] ?? null) (series {{ $attributes['document_series'] }}) @endif</div>
            <div>Date: {{ $attributes['document_date'] ?? '—' }}</div>
            <div>Financial year: {{ $attributes['financial_year'] ?? '—' }}</div>
            <div>Source: {{ $attributes['source_system'] ?? '—' }}</div>

            <h3>Customer snapshot (never linked)</h3>
            <div>Name: {{ $customer['name'] ?? '—' }}</div>
            <div>Mobile: {{ $customer['mobile'] ?? '—' }}</div>
            <div>GSTIN: {{ $customer['gstin'] ?? '—' }}</div>
            <div>Place of supply: {{ $customer['place_of_supply'] ?? '—' }}</div>
            <div>Address: {{ $customer['address'] ?? '—' }}</div>

            @if(($suggestions['mobile']['status'] ?? 'none') !== 'none' || ($suggestions['gstin']['status'] ?? 'none') !== 'none' || ($suggestions['name']['status'] ?? 'none') !== 'none')
                <div style="color:#64748b;">
                    Possible existing customer matches (informational — link them after saving, from the document's review screen):
                    @foreach(['mobile' => 'Mobile match', 'gstin' => 'GSTIN match', 'name' => 'Possible name match'] as $key => $label)
                        @php
                            $match = $suggestions[$key] ?? ['status' => 'none', 'customers' => collect()];
                        @endphp
                        @if($match['status'] === 'ambiguous')
                            <div>{{ $label }}: ambiguous — {{ $match['customers']->count() }} customers share this value, not linked automatically</div>
                        @elseif($match['status'] === 'match')
                            <div>{{ $label }}: {{ $match['customers']->pluck('name')->join(', ') }}</div>
                        @endif
                    @endforeach
                </div>
            @endif

            <h3>Making / labour</h3>
            <div>{{ $attributes['making_label_original'] ?? '—' }}: {{ $attributes['making_value_original'] ?? '—' }}
                ({{ $attributes['making_category'] ?? 'uncategorized' }} / {{ $attributes['making_basis'] ?? 'unknown basis' }})
                — {{ number_format((float) ($attributes['making_amount'] ?? 0), 2) }}</div>

            <h3>Tax</h3>
            <div>Mode: {{ $attributes['tax_mode'] ?? '—' }} ({{ $attributes['tax_completeness'] ?? '—' }})</div>

            <h3>Amounts</h3>
            <div>Taxable: {{ number_format((float) ($attributes['taxable_amount'] ?? 0), 2) }}</div>
            <div>Discount: {{ number_format((float) ($attributes['discount_snapshot'] ?? 0), 2) }}</div>
            <div>Rounding: {{ number_format((float) ($attributes['rounding_snapshot'] ?? 0), 2) }}</div>
            <div><strong>Grand total: {{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</strong></div>
            <div>Paid: {{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</div>
            <div>Outstanding: {{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</div>

            @if($lines !== [])
                <h3>Item lines ({{ count($lines) }})</h3>
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr>
                        <th style="text-align:left;">Item</th><th>Qty</th><th>Net wt</th><th>Gross wt</th><th>Stone wt</th><th>Total</th>
                    </tr></thead>
                    <tbody>
                        @foreach($lines as $line)
                            <tr>
                                <td>{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</td>
                                <td>{{ $line['quantity'] ?? '—' }}</td>
                                <td>{{ $line['net_weight'] ?? '—' }}</td>
                                <td>{{ $line['gross_weight'] ?? '—' }}</td>
                                <td>{{ $line['stone_weight'] ?? '—' }}</td>
                                <td>{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="color:#64748b;">Header only — no item lines.</p>
            @endif
        </section>

        {{-- The same fields, prefilled from what was submitted (flashed as old input).
             Edit them and Preview again, or Confirm Save to post these exact values —
             Save re-validates and re-normalizes from scratch; nothing computed above
             is trusted as input. --}}
        <form method="POST" action="{{ route('historical.manual.preview') }}"
              x-data="{ lines: [] }" style="display:grid;gap:1.25rem;">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            <div style="display:flex;gap:.75rem;">
                <button class="btn" type="submit">Edit / Recalculate preview</button>
                <button class="btn btn-primary" type="submit" formaction="{{ route('historical.manual.store') }}">Confirm Save</button>
            </div>
        </form>
    </div>
</x-app-layout>
