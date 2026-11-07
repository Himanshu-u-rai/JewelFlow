<section class="items-analysis-board" aria-label="Sell trend summary">
    <header class="items-analysis-header">
        <div><h2>Sell Trend</h2><p>{{ $sellerPeriodLabel }} · comparing the shown categories.</p></div>
        <form method="GET" action="{{ route('inventory.sell-trend') }}" class="items-sell-period" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
            <label class="jf-sr-only" for="stock-seller-period">Period</label>
            <select id="stock-seller-period" name="seller_period" class="items-analytics-select">
                @foreach(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $period => $label)
                    <option value="{{ $period }}" @selected((string) $period === $sellerPeriod)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            @if(request()->has('seller_period'))
                <a href="{{ route('inventory.sell-trend') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
        </form>
    </header>
    <div class="items-sales-summary">
        <dl class="items-analysis-stats">
            <div><dt>Units sold</dt><dd>{{ number_format($sellerTotalSold) }}</dd></div>
            @can('reports.money')
            <div><dt>Revenue</dt><dd>₹{{ number_format($sellerTotalRevenue, 0) }}</dd></div>
            @endcan
            <div><dt>Categories</dt><dd>{{ number_format($sellerByCategory->count()) }}</dd></div>
        </dl>
        <p>Figures cover up to 10 shown categories,<br>not the whole shop's sales.</p>
    </div>
    @php
        $categoriesByRevenue = $sellerByCategory->sortByDesc('total_revenue')->values();
        $revenueScaleMin = min(0, (float) $sellerByCategory->min('total_revenue'));
        $revenueScaleMax = max(0, (float) $sellerByCategory->max('total_revenue'));
        $revenueScaleRange = $revenueScaleMax > $revenueScaleMin ? $revenueScaleMax - $revenueScaleMin : 1;
        $revenueZero = -$revenueScaleMin / $revenueScaleRange * 100;
    @endphp
    <div class="items-sales-matrix-wrap">
        <table class="items-sales-matrix" role="table" aria-label="Category performance">
            <colgroup><col class="items-sales-index"><col class="items-sales-category-col"><col><col class="items-sales-units-col"><col class="items-sales-share-col"></colgroup>
            <thead role="rowgroup"><tr role="row">
                <th scope="col" class="items-sales-index">#</th><th scope="col">Category</th>
                @can('reports.money')<th scope="col">Revenue
                    @if($sellerByCategory->isNotEmpty())
                        <div class="items-revenue-scale" data-revenue-scale>
                            @foreach([$revenueScaleMin, ($revenueScaleMax + $revenueScaleMin) / 2, $revenueScaleMax] as $tick)
                                <span>₹{{ number_format($tick, floor($tick) == $tick ? 0 : 2) }}</span>
                            @endforeach
                        </div>
                    @endif
                </th>@endcan<th scope="col">Units sold</th><th scope="col">Share of units</th>
            </tr></thead>
            <tbody role="rowgroup">
                @forelse($categoriesByRevenue as $row)
                    @php
                        $revenue = (float) $row['total_revenue'];
                        $barStart = (min(0, $revenue) - $revenueScaleMin) / $revenueScaleRange * 100;
                        $barWidth = abs($revenue) / $revenueScaleRange * 100;
                        $unitShare = $sellerTotalSold > 0 ? (int) $row['sold_count'] / $sellerTotalSold * 100 : 0;
                        $barDetails = ($row['category'] ?? '—')."\nRevenue: ₹".number_format($revenue, 2)
                            ."\nUnits sold: ".number_format($row['sold_count'])
                            ."\nShare of units: ".number_format($unitShare, 1).'%'
                            ."\nPeriod: ".$sellerPeriodLabel;
                    @endphp
                    <tr role="row" data-sales-category="{{ $row['category'] ?? '—' }}">
                        <td role="cell" class="items-sales-index">{{ $loop->iteration }}</td>
                        <th role="rowheader" scope="row">{{ $row['category'] ?? '—' }}</th>
                        @can('reports.money')
                        <td role="cell" data-field="Revenue">
                            <div class="items-sales-measure">
                                <button type="button" class="items-revenue-trigger" data-chart-tip="true" data-tip="{{ $barDetails }}"
                                        aria-label="{{ str_replace("\n", '. ', $barDetails) }}" aria-expanded="false">
                                    <span class="items-revenue-bar {{ $revenue < 0 ? 'is-negative' : '' }}" data-revenue-bar style="--bar-start: {{ $barStart }}%; --bar-width: {{ $barWidth }}%; --bar-zero: {{ $revenueZero }}%" aria-hidden="true"><i></i></span>
                                </button>
                                <strong>₹{{ number_format($row['total_revenue'], 2) }}</strong>
                            </div>
                        </td>
                        @endcan
                        <td role="cell" data-field="Units sold"><strong data-sales-count>{{ number_format($row['sold_count']) }}</strong></td>
                        <td role="cell" data-field="Share of units"><span class="items-sales-share" data-sales-share>{{ number_format($unitShare, 1) }}%</span></td>
                    </tr>
                @empty
                    <tr role="row"><td role="cell" colspan="5" class="items-sales-empty" data-sales-empty>No sales data in this period. Choose another period to compare categories.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <footer class="items-analysis-footer">
        @can('reports.money')
        Sorted by revenue, highest first.
        @if($sellerByCategory->isNotEmpty())
            All bars use the same ₹{{ number_format($revenueScaleMin, floor($revenueScaleMin) == $revenueScaleMin ? 0 : 2) }}–₹{{ number_format($revenueScaleMax, floor($revenueScaleMax) == $revenueScaleMax ? 0 : 2) }} scale.
        @endif
        Hover, focus or tap a bar for details.
        @endcan
        Refreshed every 10 minutes.
    </footer>
</section>
