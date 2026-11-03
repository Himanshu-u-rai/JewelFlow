<section class="items-analysis-board" aria-label="Sell trend summary">
    <header class="items-analysis-header">
        <div><h2>Sell Trend</h2><p>{{ $sellerPeriodLabel }} · comparing the shown categories.</p></div>
        <form method="GET" action="{{ route('inventory.items.index') }}" class="items-sell-period" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
            <input type="hidden" name="view" value="sellers">
            <label class="jf-sr-only" for="stock-seller-period">Period</label>
            <select id="stock-seller-period" name="seller_period" class="items-analytics-select">
                @foreach(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $period => $label)
                    <option value="{{ $period }}" @selected((string) $period === $sellerPeriod)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            @if(request()->has('seller_period'))
                <a href="{{ route('inventory.items.index', ['view' => 'sellers']) }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
        </form>
    </header>
    <div class="items-sales-summary">
        <dl class="items-analysis-stats">
            <div><dt>Units sold</dt><dd>{{ number_format($sellerTotalSold) }}</dd></div>
            <div><dt>Revenue</dt><dd>₹{{ number_format($sellerTotalRevenue, 0) }}</dd></div>
            <div><dt>Categories</dt><dd>{{ number_format($sellerByCategory->count()) }}</dd></div>
        </dl>
        <p>Figures cover up to 10 shown categories,<br>not the whole shop's sales.</p>
    </div>
    @php
        $maxCategoryUnits = max(1, (int) $sellerByCategory->max('sold_count'));
        $maxCategoryRevenue = max(1, (float) $sellerByCategory->max('total_revenue'));
    @endphp
    <div class="items-sales-matrix-wrap">
        <table class="items-sales-matrix" role="table" aria-label="Category performance">
            <thead role="rowgroup"><tr role="row">
                <th scope="col" class="items-sales-index">#</th><th scope="col">Category</th>
                <th scope="col">Units sold</th><th scope="col">Revenue</th><th scope="col">Share of units</th>
            </tr></thead>
            <tbody role="rowgroup">
                @forelse($sellerByCategory as $row)
                    @php
                        $unitPosition = (int) $row['sold_count'] / $maxCategoryUnits * 100;
                        $revenuePosition = max(0, (float) $row['total_revenue']) / $maxCategoryRevenue * 100;
                        $unitShare = $sellerTotalSold > 0 ? (int) $row['sold_count'] / $sellerTotalSold * 100 : 0;
                    @endphp
                    <tr role="row" data-sales-category="{{ $row['category'] ?? '—' }}">
                        <td role="cell" class="items-sales-index">{{ $loop->iteration }}</td>
                        <th role="rowheader" scope="row">{{ $row['category'] ?? '—' }}</th>
                        <td role="cell" data-field="Units sold"><div class="items-sales-measure"><span class="items-sales-dot-track" style="--dot-position: {{ $unitPosition }}%" aria-hidden="true"><i></i></span><strong data-sales-count>{{ number_format($row['sold_count']) }}</strong></div></td>
                        <td role="cell" data-field="Revenue"><div class="items-sales-measure is-revenue"><span class="items-sales-dot-track" style="--dot-position: {{ $revenuePosition }}%" aria-hidden="true"><i></i></span><strong>₹{{ number_format($row['total_revenue'], 0) }}</strong></div></td>
                        <td role="cell" data-field="Share of units"><span class="items-sales-share" data-sales-share>{{ number_format($unitShare, 1) }}%</span></td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" colspan="5" class="items-sales-empty" data-sales-empty>No sales data in this period. Choose another period to compare categories.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <footer class="items-analysis-footer">
        @if($sellerByCategory->count() > 1 && $sellerByCategory->min('sold_count') == $sellerByCategory->max('sold_count'))
            All shown categories are tied at {{ number_format($sellerByCategory->first()['sold_count']) }} units each.
        @else
            Sorted by units sold. Equal counts are tied.
        @endif
        Dots compare each metric within the shown categories · refreshed every 10 minutes
    </footer>
</section>
