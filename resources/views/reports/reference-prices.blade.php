<x-app-layout>
    <x-page-header class="individual-report-header" title="Reference Prices" subtitle="Operator-noted prices — memo only">
        <x-slot:actions><a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a></x-slot:actions>
    </x-page-header>
    <div class="content-inner individual-report">
        <section class="ir-surface"><p class="ir-note">These are operator notes for platinum and copper, not daily rates. They never feed pricing, vault balances, GST or reconciliation. Gold and silver use Daily Rates.</p></section>
        @forelse($tier2 as $metal)
            @php
                $records = $timelines[$metal] ?? collect();
                $rows = $records->map(fn ($r) => [
                    'date' => ['display' => optional($r->noted_at)->format('d M Y, h:i A') ?? '—', 'raw' => $r->noted_at?->getTimestamp()],
                    'price' => ['display' => '₹'.number_format((float) $r->reference_price, 2), 'raw' => $r->reference_price],
                    'operator' => $r->notedBy->name ?? '—', 'note' => $r->note ?? '—']);
            @endphp
            <section class="ir-surface">
                <header class="ir-heading"><div><h2>{{ ucfirst($metal) }} reference history</h2><p>{{ $records->count() }} notes · latest 50 · newest first</p></div></header>
                @if($records->isEmpty())
                    <p class="ir-empty">{{ __('No reference noted for :metal yet.', ['metal' => $metal]) }}</p>
                @else
                    <x-report-records :title="ucfirst($metal).' reference history'" :columns="['date' => 'When noted', 'price' => 'Reference price (₹/g)', 'operator' => 'Noted by', 'note' => 'Note']" :rows="$rows" :numeric="['price']" />
                @endif
            </section>
        @empty
            <section class="ir-surface"><p class="ir-empty">No reference-price materials available.</p></section>
        @endforelse
    </div>
</x-app-layout>
