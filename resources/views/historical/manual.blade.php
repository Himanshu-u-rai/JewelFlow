@php
    use App\Models\Historical\HistoricalSalesDocument;
    $taxModes = [
        '' => '— choose —',
        HistoricalSalesDocument::TAX_MODE_EXCLUSIVE     => 'Tax added on top (exclusive)',
        HistoricalSalesDocument::TAX_MODE_INCLUSIVE     => 'Tax already inside total (inclusive)',
        HistoricalSalesDocument::TAX_MODE_UNKNOWN       => 'Unknown',
        HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE => 'Not applicable',
    ];
    $money = ['taxable_amount'=>'Taxable','tax_total'=>'Tax total','cgst'=>'CGST','sgst'=>'SGST','igst'=>'IGST','cess'=>'Cess','discount'=>'Discount','rounding'=>'Rounding','metal_value'=>'Metal value','stone_value'=>'Stone value','paid_amount'=>'Paid','outstanding_amount'=>'Outstanding'];
@endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:900px;margin:0 auto;">
        <x-app-alerts />

        {{-- Blocking findings from a rejected save. --}}
        @if(session('historical_messages'))
            <div style="border:1px solid #fca5a5;background:#fef2f2;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
                @foreach(session('historical_messages') as $m)
                    <div style="color:#b91c1c;">{{ $m['text'] }}</div>
                @endforeach
            </div>
        @endif

        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1 style="margin:0;">Enter a historical bill</h1>
            <a href="{{ route('historical.index') }}">← Historical sales</a>
        </div>
        <p style="color:#b45309;">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        <form method="POST" action="{{ route('historical.manual.preview') }}"
              x-data="{ lines: [] }" style="display:grid;gap:1.25rem;">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            <div><button class="btn btn-primary" type="submit">Preview</button></div>
        </form>
    </div>
</x-app-layout>
