<x-app-layout>
    <x-page-header class="individual-report-header" title="Category Sales" subtitle="Compare category movement and revenue over the selected period">
        <x-slot:actions><a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a></x-slot:actions>
    </x-page-header>
    @php
        $formatRows = fn ($rows) => collect($rows)->map(fn ($r) => ['category' => $r['category'] ?: 'Uncategorised',
            'sub_category' => $r['sub_category'] ?? '—', 'units' => $r['sold_count'],
            'revenue' => ['display' => '₹'.number_format($r['total_revenue'] ?? 0, 2), 'raw' => $r['total_revenue'] ?? 0]]);
    @endphp
    <div class="content-inner individual-report">
        <section class="ir-surface">
            <header class="ir-heading"><div><h2>Sales scope</h2><p>Last {{ (int) $period }} days · up to 10 categories per list</p></div>
                <x-responsive-filter-panel id="category-sales-filters" label="Category sales filters" :desktop-popup="true">
                    <form method="GET" action="{{ route('report.sellers') }}" class="ir-filters"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js(request()->has('period')) }" @input="dirty = true" @change="dirty = true">
                        <div class="ir-field"><label for="seller-period">Period</label><select id="seller-period" name="period" class="ir-control">
                            @foreach([7, 30, 90, 365] as $days)<option value="{{ $days }}" @selected((int) $period === $days)>Last {{ $days }} days</option>@endforeach
                        </select></div>
                        <div class="ir-filter-actions"><button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button><a href="{{ route('report.sellers') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a></div>
                    </form>
                </x-responsive-filter-panel>
            </header>
            <p class="ir-note">Counts compare sold item lines by category, not staff performance. Lists are limited to 10; figures are not shop-wide totals. Reports refresh every 10 minutes.</p>
        </section>
        <section class="ir-surface">
            <header class="ir-heading"><h2>Highest movement categories</h2></header>
            <x-report-records title="Highest movement categories" :columns="['category' => 'Category', 'units' => 'Sold lines', 'revenue' => 'Revenue']" :rows="$formatRows($best['by_category'])" :numeric="['units', 'revenue']" />
        </section>
        <div class="ir-two-columns">
            <section class="ir-surface">
                <header class="ir-heading"><h2>Sub-category breakdown</h2></header>
                <x-report-records title="Sub-category sales" :columns="['category' => 'Category', 'sub_category' => 'Sub-category', 'units' => 'Sold lines', 'revenue' => 'Revenue']" :rows="$formatRows($best['by_sub_category'])" :numeric="['units', 'revenue']" />
            </section>
            <section class="ir-surface">
                <header class="ir-heading"><h2>Lowest movement categories</h2></header>
                <x-report-records title="Lowest movement categories" :columns="['category' => 'Category', 'units' => 'Sold lines', 'revenue' => 'Revenue']" :rows="$formatRows($worst)" :numeric="['units', 'revenue']" />
                <p class="ir-note">Categories with sales in this period only; unsold categories are not included.</p>
            </section>
        </div>
    </div>
</x-app-layout>
