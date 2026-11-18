<x-app-layout>
    <x-page-header class="individual-report-header" title="Stock Aging Report" subtitle="Age and selling value of items currently in stock">
        <x-slot:actions><a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a></x-slot:actions>
    </x-page-header>
    @php
        $totalItems = collect($buckets)->sum('count');
        $totalValue = collect($buckets)->sum('value');
        $slowItems = collect($buckets['91-180 days']['items'])->merge($buckets['180+ days']['items']);
        $ageRows = collect($buckets)->map(fn ($data, $label) => ['age' => $label, 'items' => $data['count'],
            'value' => ['display' => '₹'.number_format($data['value'], 2), 'raw' => $data['value']]])->values();
        $slowRows = $slowItems->take(50)->map(fn ($item) => ['barcode' => $item->barcode,
            'category' => $item->category.($item->sub_category ? ' · '.$item->sub_category : ''),
            'price' => ['display' => '₹'.number_format($item->selling_price, 2), 'raw' => $item->selling_price],
            'days' => (int) $item->created_at->diffInDays(now())]);
    @endphp
    <div class="content-inner individual-report">
        <section class="ir-surface" aria-label="Age distribution">
            <header class="ir-heading"><h2>Age distribution</h2>
                <dl class="ir-metrics"><div><dt>In-stock items</dt><dd>{{ number_format($totalItems) }}</dd></div><div><dt>Selling value</dt><dd>₹{{ number_format($totalValue, 2) }}</dd></div></dl>
            </header>
            <x-report-records title="Age distribution" :columns="['age' => 'Age', 'items' => 'Items', 'value' => 'Selling value']" :rows="$ageRows" :numeric="['items', 'value']" />
            <p class="ir-note">Current in-stock items only. Values use selling prices, not cost. Reports refresh every 10 minutes.</p>
        </section>
        <section class="ir-surface" aria-label="Slow-moving stock">
            <header class="ir-heading"><div><h2>Stock over 90 days</h2><p>{{ number_format($slowItems->count()) }} items · showing up to 50 records</p></div></header>
            <x-report-records title="Slow-moving stock" :columns="['barcode' => 'Barcode', 'category' => 'Category', 'price' => 'Selling price', 'days' => 'Days in stock']" :rows="$slowRows" :numeric="['price', 'days']" />
        </section>
    </div>
</x-app-layout>
