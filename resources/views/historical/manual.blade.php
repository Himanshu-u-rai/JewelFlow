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
            <a href="{{ route('historical.index') }}"
               class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:border-amber-400 hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
               data-historical-back>
                <svg class="h-4 w-4 shrink-0 text-amber-700" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12.5 5 7.5 10l5 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Back to historical sales</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-manual-page">
        <x-app-alerts />

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex items-start gap-3">
                <span class="inline-flex shrink-0 items-center rounded bg-teal-700 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white">{{ HistoricalSalesDocument::BADGE }}</span>
                <p class="text-sm text-amber-800">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
            </div>
        </div>

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
        {{-- x-data stays the literal "{ lines: [] }" HistoricalMobileUiTest pins —
             seeding/padding happens in x-init instead, so a fresh load, a
             validation-error redisplay (old('lines') flashed by withInput())
             and the preview/edit round trip all share one init path. --}}
        <form method="POST" action="{{ route('historical.manual.preview') }}" data-turbo="false"
              x-data="{ lines: [] }"
              x-init="lines = historicalPadLines(historicalSeedLines(@js(old('lines', []))))"
              class="grid gap-4" data-historical-form="manual">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-historical-card-footer>
                <p class="text-xs text-slate-500">Preview saves nothing. Save a draft or, if permitted, save and publish from the preview.</p>
                <button class="btn btn-primary min-h-[44px]" type="submit" data-historical-manual-next-step>Preview historical bill</button>
            </div>
        </form>
    </div>
</x-app-layout>
