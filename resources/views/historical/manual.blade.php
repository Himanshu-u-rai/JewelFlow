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
    <x-page-header title="Enter a historical bill" subtitle="Records a sale made before JewelFlow. Not a live invoice — no number issued, no stock moved.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-manual-page">
        <x-app-alerts />

        <p class="text-sm text-amber-700 mb-4">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        {{-- Blocking findings from a rejected save. --}}
        @if(session('historical_messages'))
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 mb-4" role="alert">
                @foreach(session('historical_messages') as $m)
                    <div class="text-rose-700 text-sm">{{ $m['text'] }}</div>
                @endforeach
            </div>
        @endif

        {{-- Preview renders a 200 HTML view (not a redirect) so the operator can review
             before anything is written. Turbo Drive requires form responses to redirect,
             so it must be opted out here — see resources/views/export/index.blade.php
             and super-admin/account/index.blade.php for the same pattern. --}}
        <form method="POST" action="{{ route('historical.manual.preview') }}" data-turbo="false"
              x-data="{ lines: [] }" class="grid gap-5">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            <div>
                <button class="btn btn-primary" type="submit">Preview</button>
                <p class="text-xs text-slate-500 mt-2">Nothing is saved yet — the next screen is a read-only preview to review before Confirm Save.</p>
            </div>
        </form>
    </div>
</x-app-layout>
